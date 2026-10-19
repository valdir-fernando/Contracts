<?php

namespace App\Http\Controllers;

use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\UserAffiliation;
use App\Infrastructure\Security\UserGovernance;
use App\Models\AccessScope;
use App\Models\User;
use App\Models\UserAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private LockActor $actors) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate([
            'q' => 'nullable|string|max:255', 'role' => ['nullable', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'deleted'])],
            'scope' => 'nullable|integer|exists:access_scopes,id',
            'sort' => ['nullable', Rule::in(['name', 'username', 'last_login_at'])],
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
            'users' => $users->orderBy($filters['sort'] ?? 'name', ($filters['sort'] ?? '') === 'last_login_at' ? 'desc' : 'asc')->orderBy('id')->paginate(15)->withQueryString(),
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
            'affiliationOptions' => (new UserAffiliation)->options(),
            'audits' => $user->exists ? UserAudit::with('actor')->where('user_id', $user->id)->latest('id')->limit(20)->get() : collect(),
        ]);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => Str::upper($request->input('name'))]);
        }
        if (is_string($request->input('username'))) {
            $request->merge(['username' => Str::upper(trim($request->input('username')))]);
        }
        if ($request->has('affiliations_present') && is_array($request->input('affiliations'))) {
            $request->merge(['affiliations' => array_values(array_filter($request->input('affiliations'), fn ($value) => $value !== null && $value !== ''))]);
        }

        return $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('users')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'user_type' => ['required', Rule::in(User::ROLES)],
            'is_active' => 'required|boolean',
            'scope_ids' => [Rule::requiredIf(($user === null || $request->input('user_type') === 'Usuario') && ! $request->has('affiliations_present')), 'array'],
            'scope_ids.*' => 'required|integer|distinct|exists:access_scopes,id',
            'affiliations_present' => 'sometimes|accepted',
            'affiliations' => [Rule::requiredIf(($user === null || $request->input('user_type') === 'Usuario') && $request->has('affiliations_present')), 'array', 'max:1'],
            'affiliations.*' => ['required', 'string', 'distinct', 'regex:/^(fund|department|legacy):[1-9][0-9]*$/'],
            'catalog_permissions_present' => 'sometimes|accepted',
            'catalog_permissions' => 'sometimes|array',
            'catalog_permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(User::DELEGATABLE_PERMISSIONS))],
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
        return $user->only(['name', 'username', 'email', 'user_type', 'is_active', 'deleted_at', 'fund', 'department', 'catalog_permissions'])
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
            $actor = $this->actors->lock($request->user());
            Gate::forUser($actor)->authorize('create', User::class);
            abort_unless($actor->isMaster() || $data['user_type'] === 'Usuario', 403);
            $user = User::create(collect($data)->only(['name', 'username', 'email', 'password', 'user_type'])->all());
            $user->is_active = (bool) $data['is_active'];
            $user->catalog_permissions = $data['catalog_permissions'] ?? [];
            $user->save();
            if (isset($data['affiliations_present'])) {
                (new UserAffiliation)->assign($user, $data['affiliations'] ?? []);
            } else {
                $user->scopes()->sync($data['scope_ids'] ?? []);
            }
            $this->audit($request, $user, 'created', null);
        });

        return redirect()->route('users.index')->with('status', 'Usuário cadastrado.');
    }

    private function mutate(Request $request, User $target, string $action, callable $change, ?string $reason = null)
    {
        DB::transaction(function () use ($request, $target, $action, $change, $reason) {
            // Lock all active admins first, in a stable order, to protect concurrent changes.
            $admins = UserGovernance::lockAdministrators();
            $actor = $this->actors->lock($request->user());
            abort_unless($actor->isAdmin(), 403);
            $user = User::withTrashed()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize(match ($action) {
                'deleted' => 'delete', 'restored' => 'restore', default => 'update'
            }, $user);
            $revision = $request->validate(['version' => 'required|integer|min:0']);
            abort_unless((int) $revision['version'] === $user->session_version, 409,
                'Esta conta foi alterada por outra pessoa. Recarregue a página antes de salvar.');
            $before = $this->snapshot($user);
            $previousUser = clone $user;
            $change($user);
            abort_unless($actor->isMaster() || $user->user_type === 'Usuario', 403);
            UserGovernance::protectLastAdministrator($previousUser, $user, $admins);
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
            $user->fill(collect($data)->only(['name', 'username', 'email', 'user_type'])->all());
            $user->is_active = (bool) $data['is_active'];
            if (isset($data['catalog_permissions_present']) || array_key_exists('catalog_permissions', $data)) {
                $user->catalog_permissions = $data['catalog_permissions'] ?? [];
            }
            if (isset($data['affiliations_present'])) {
                (new UserAffiliation)->assign($user, $data['affiliations'] ?? []);
            } else {
                $user->scopes()->sync($data['scope_ids'] ?? []);
            }
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
