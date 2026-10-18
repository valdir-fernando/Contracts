<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.login', ['funds' => DB::table('contract_funds')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
            'fund_id' => ['required', 'integer', 'exists:contract_funds,id'],
        ], [
            'username.required' => 'Informe seu nome de usuário.',
            'username.max' => 'O nome de usuário deve ter no máximo 255 caracteres.',
            'password.required' => 'Informe sua senha.',
            'password.max' => 'A senha informada é muito longa.',
            'fund_id.required' => 'Selecione o Fundo para entrar.',
            'fund_id.exists' => 'Selecione um Fundo cadastrado.',
        ]);
        $credentials['username'] = Str::upper(trim($credentials['username']));
        $key = 'login:'.hash('sha256', $credentials['username'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.']);
        }
        try {
            DB::transaction(function () use ($request, $credentials, $key) {
                if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
                    // Count failures even when recording the event is unavailable.
                    RateLimiter::hit($key, 60);
                    ActivityEvent::create(['actor_id' => null, 'action' => 'login_failed', 'metadata' => [], 'created_at' => now()]);

                    return;
                }
                $user = User::whereKey(Auth::id())->lockForUpdate()->first();
                if (! $user || ! $user->is_active || $user->session_version !== Auth::user()->session_version
                    || ! Hash::check($credentials['password'], $user->password)) {
                    RateLimiter::hit($key, 60);
                    throw ValidationException::withMessages(['username' => 'Seu acesso foi atualizado. Entre novamente.']);
                }
                if (! app(SelectedFund::class)->current($user, (int) $credentials['fund_id'])) {
                    RateLimiter::hit($key, 60);
                    throw ValidationException::withMessages(['fund_id' => 'Sua conta não possui acesso ao Fundo selecionado.']);
                }
                $user->forceFill(['last_login_at' => now()])->save();
                ActivityEvent::create(['actor_id' => $user->id, 'action' => 'login', 'metadata' => ['fund_id' => (int) $credentials['fund_id']], 'created_at' => now()]);
                Auth::setUser($user);
            });
        } catch (\Throwable $error) {
            // Session/cookies are not rolled back with database writes.
            $this->terminateSession($request, false);
            throw $error;
        }
        if (! Auth::check()) {
            throw ValidationException::withMessages(['username' => 'Não foi possível entrar. Confira seu usuário e sua senha.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('auth_version', Auth::user()->session_version);
        $request->session()->put('fund_id', (int) $credentials['fund_id']);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        try {
            ActivityEvent::create(['actor_id' => Auth::id(), 'action' => 'logout', 'metadata' => [], 'created_at' => now()]);
        } finally {
            $this->terminateSession($request, true);
        }

        return redirect()->route('login');
    }

    private function terminateSession(Request $request, bool $rotateRememberToken): void
    {
        try {
            if ($rotateRememberToken) {
                Auth::logout();
            }
        } finally {
            // Also clears queued recaller cookies; no database write is needed here.
            try {
                if (! $rotateRememberToken || Auth::hasUser()) {
                    Auth::logoutCurrentDevice();
                }
            } finally {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
    }
}
