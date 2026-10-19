<?php

namespace App\Http\Controllers;

use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\UserAffiliation;
use App\Infrastructure\Security\UserGovernance;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Models\UserAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(private LockActor $actors) {}

    public function edit()
    {
        return view('account.edit', ['affiliationOptions' => (new UserAffiliation)->options()]);
    }

    public function update(Request $request)
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => Str::upper(trim($request->input('username')))]);
        }
        if (is_array($request->input('affiliations'))) {
            $request->merge(['affiliations' => array_values(array_filter($request->input('affiliations'), fn ($value) => $value !== null && $value !== ''))]);
        }
        $administrative = $request->user()->isMaster();
        $data = $request->validate(['name' => 'required|string|max:255',
            'username' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('users')->ignore($request->user()->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)],
            'current_password' => 'required|current_password',
            'password' => ['nullable', 'confirmed', Password::min(12)->letters()->numbers(), 'max:1024'],
            'version' => 'sometimes|required|integer|min:0',
            'user_id' => 'prohibited', 'id' => 'prohibited', 'scope_ids' => 'prohibited',
            'catalog_permissions' => 'prohibited', 'catalog_permissions_present' => 'prohibited',
            'user_type' => $administrative ? ['sometimes', 'required', Rule::in(User::ROLES)] : ['prohibited'],
            'is_active' => $administrative ? ['sometimes', 'required', 'boolean'] : ['prohibited'],
            'affiliations_present' => $administrative ? ['sometimes', 'accepted'] : ['prohibited'],
            'affiliations' => $administrative ? ['array', 'max:1', Rule::requiredIf($request->has('affiliations_present'))] : ['prohibited'],
            'affiliations.*' => ['required', 'string', 'regex:/^(fund|department|legacy):[1-9][0-9]*$/'],
        ]);
        $administrativeChange = array_intersect(['user_type', 'is_active', 'affiliations_present', 'affiliations'], array_keys($data)) !== [];
        DB::transaction(function () use ($request, $data, $administrativeChange) {
            // Same lock order as administrative mutations protects the last active administrator.
            $admins = $administrativeChange ? UserGovernance::lockAdministrators() : collect();
            $user = $this->actors->lock($request->user());
            if ($administrativeChange) {
                abort_unless($user->isMaster(), 403);
                Gate::forUser($user)->authorize('update', $user);
                $request->validate(['version' => 'required|integer|min:0']);
            }
            if (isset($data['version'])) {
                abort_unless((int) $data['version'] === $user->session_version, 409, 'Sua conta foi alterada. Recarregue a página antes de salvar.');
            }
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha atual foi alterada. Entre novamente.']);
            }
            abort_unless($user->is_active && ! $user->trashed(), 403);
            $before = $user->only(['name', 'username', 'email', 'user_type', 'is_active', 'fund', 'department'])
                + ['scope_ids' => $user->scopes()->orderBy('access_scopes.id')->pluck('access_scopes.id')->all()];
            $previousUser = clone $user;
            $user->name = $data['name'];
            $identityChanged = isset($data['username']) && $data['username'] !== $user->username;
            if (isset($data['username'])) {
                $user->username = $data['username'];
            }
            if ($user->email !== ($data['email'] ?? null)) {
                $user->email_verified_at = null;
            }
            $user->email = $data['email'] ?? null;
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            if ($administrativeChange) {
                $user->user_type = $data['user_type'] ?? $user->user_type;
                $user->is_active = isset($data['is_active']) ? (bool) $data['is_active'] : $user->is_active;
                UserGovernance::protectLastAdministrator($previousUser, $user, $admins);
                if (isset($data['affiliations_present'])) {
                    (new UserAffiliation)->assign($user, $data['affiliations'] ?? []);
                }
            }
            if (! empty($data['password']) || $identityChanged || $administrativeChange || isset($data['version'])) {
                $user->session_version++;
                $user->remember_token = null;
            }
            $user->save();
            UserAudit::create(['actor_id' => $user->id, 'user_id' => $user->id, 'action' => 'updated', 'before' => $before,
                'after' => $user->only(['name', 'username', 'email', 'user_type', 'is_active', 'fund', 'department'])
                    + ['scope_ids' => $user->scopes()->orderBy('access_scopes.id')->pluck('access_scopes.id')->all()], 'created_at' => now()]);
            ActivityEvent::create(['actor_id' => $user->id, 'action' => empty($data['password']) ? 'account_updated' : 'own_password_changed',
                'metadata' => [], 'created_at' => now()]);
            $request->session()->put('auth_version', $user->session_version);
            Auth::setUser($user);
        });
        if (! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }
        $request->session()->regenerate();

        return redirect()->route('account.edit')->with('status', 'Conta atualizada.');
    }
}
