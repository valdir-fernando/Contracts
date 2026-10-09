<?php

namespace App\Http\Controllers;

use App\Models\AccessScope;
use App\Models\User;
use App\Models\UserAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate([
            'q' => 'nullable|string|max:255', 'role' => ['nullable', Rule::in(['Administrador', 'Usuario'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'deleted'])],
            'scope' => 'nullable|integer|exists:access_scopes,id',
            'sort' => ['nullable', Rule::in(['name', 'username'])],
        ]);
        $users = User::with('scopes');
        if (($filters['status'] ?? '') === 'deleted') {
            $users->onlyTrashed();
        } elseif (in_array($filters['status'] ?? '', ['active', 'inactive'])) {
            $users->where('is_active', $filters['status'] === 'active');
        }
        if ($filters['q'] ?? null) {
            $search = '%'.$filters['q'].'%';
            $users->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('username', 'like', $search));
        }
        if ($filters['role'] ?? null) {
            $users->where('user_type', $filters['role']);
        }
        if ($filters['scope'] ?? null) {
            $users->whereHas('scopes', fn ($query) => $query->whereKey($filters['scope']));
        }

        return view('users.index', [
            'users' => $users->orderBy($filters['sort'] ?? 'name')->orderBy('id')->paginate(15)->withQueryString(),
            'scopes' => AccessScope::orderBy('fund')->orderBy('department')->get(),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', User::class);

        return $this->form(new User(['user_type' => 'Usuario']));
    }

    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return $this->form($user);
    }

    private function form(User $user)
    {
        return view('users.form', [
            'user' => $user->load('scopes'), 'scopes' => AccessScope::orderBy('fund')->orderBy('department')->get(),
            'audits' => $user->exists ? UserAudit::with('actor')->where('user_id', $user->id)->latest('id')->limit(20)->get() : collect(),
        ]);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => Str::upper(trim($request->input('username')))]);
        }

        return $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('users')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'user_type' => ['required', Rule::in(['Administrador', 'Usuario'])],
            'is_active' => 'required|boolean',
            'scope_ids' => [Rule::requiredIf($request->input('user_type') === 'Usuario'), 'array'],
            'scope_ids.*' => 'required|integer|distinct|exists:access_scopes,id',
            'password' => $user ? ['prohibited'] : ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:1024'],
        ], [
            'scope_ids.required' => 'Selecione pelo menos um fundo/secretaria para o usuário.',
            'username.unique' => 'Este nome de usuário já está cadastrado.',
            'username.regex' => 'Use letras sem acentos, números, ponto, hífen ou sublinhado no usuário.',
            'password.confirmed' => 'A confirmação da senha não confere.',
            'password.min' => 'A senha deve ter pelo menos 12 caracteres.',
        ]);
    }

    private function snapshot(User $user): array
    {
        return $user->only(['name', 'username', 'email', 'user_type', 'is_active', 'deleted_at'])
            + ['scope_ids' => $user->scopes()->orderBy('access_scopes.id')->pluck('access_scopes.id')->all()];
    }

    private function audit(Request $request, User $user, string $action, ?array $before, ?string $reason = null): void
    {
        UserAudit::create(['actor_id' => $request->user()->id, 'user_id' => $user->id, 'action' => $action,
            'before' => $before, 'after' => $this->snapshot($user), 'reason' => $reason, 'created_at' => now()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', User::class);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data) {
            $user = User::create(collect($data)->except(['scope_ids', 'is_active'])->all());
            $user->is_active = (bool) $data['is_active'];
            $user->save();
            $user->scopes()->sync($data['scope_ids'] ?? []);
            $this->audit($request, $user, 'created', null);
        });

        return redirect()->route('users.index')->with('status', 'Usuário cadastrado.');
    }

    private function mutate(Request $request, User $target, string $action, callable $change, ?string $reason = null)
    {
        DB::transaction(function () use ($request, $target, $action, $change, $reason) {
            // Lock all active admins first, in a stable order, to protect concurrent changes.
            $admins = User::where('user_type', 'Administrador')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $actor = User::findOrFail($request->user()->id);
            abort_unless($actor->isAdmin(), 403);
            $user = User::withTrashed()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize(match ($action) {
                'deleted' => 'delete', 'restored' => 'restore', default => 'update'
            }, $user);
            $before = $this->snapshot($user);
            $wasAdmin = $user->isAdmin();
            $change($user);
            if ($wasAdmin && ! $user->isAdmin() && $admins->count() <= 1) {
                throw ValidationException::withMessages(['user_type' => 'O último administrador ativo não pode ser desativado, excluído ou ter seu perfil alterado.']);
            }
            $user->session_version++;
            $user->remember_token = null;
            $user->save();
            $this->audit($request, $user, $action, $before, $reason);
            if ($user->id === $request->user()->id && $user->is_active && ! $user->trashed()) {
                $request->session()->put('auth_version', $user->session_version);
            }
        });

        return redirect()->route($request->user()->fresh()?->isAdmin() ? 'users.index' : 'dashboard')->with('status', 'Alteração salva.');
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);
        $data = $this->validated($request, $user);

        return $this->mutate($request, $user, 'updated', function (User $user) use ($data) {
            $user->fill(collect($data)->except(['scope_ids', 'is_active'])->all());
            $user->is_active = (bool) $data['is_active'];
            $user->scopes()->sync($data['scope_ids'] ?? []);
        });
    }

    public function resetPassword(Request $request, User $user)
    {
        Gate::authorize('update', $user);
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:1024']]);

        return $this->mutate($request, $user, 'password_reset', fn (User $user) => $user->password = $data['password']);
    }

    public function destroy(Request $request, User $user)
    {
        Gate::authorize('delete', $user);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000', 'confirmation' => ['required', Rule::in([$user->username])]]);

        return $this->mutate($request, $user, 'deleted', function (User $user) {
            $user->is_active = false;
            $user->delete();
        }, $data['reason']);
    }

    public function restore(Request $request, User $user)
    {
        Gate::authorize('restore', $user);

        return $this->mutate($request, $user, 'restored', function (User $user) {
            $user->restore();
            $user->is_active = true;
        });
    }
}
