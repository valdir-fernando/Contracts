<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ], [
            'username.required' => 'Informe seu nome de usuário.',
            'username.max' => 'O nome de usuário deve ter no máximo 255 caracteres.',
            'password.required' => 'Informe sua senha.',
            'password.max' => 'A senha informada é muito longa.',
        ]);
        $credentials['username'] = Str::upper(trim($credentials['username']));
        $key = 'login:'.hash('sha256', $credentials['username'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.']);
        }
        if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Não foi possível entrar. Confira seu usuário e sua senha.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('auth_version', Auth::user()->session_version);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
