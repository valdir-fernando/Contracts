<x-layouts.auth>
    <main class="auth-shell">
        <section class="login-card" aria-labelledby="login-title">
            <header class="login-header">
                <h1 id="login-title">Contracts</h1>
                <p class="login-description">Entre com seu usuário e senha para acessar.</p>
            </header>
            <form action="{{ route('login.store') }}" method="POST" data-login-form>
                @csrf
                <div class="field">
                    <label for="username">Usuário</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" placeholder="Seu nome de usuário" autocomplete="username" maxlength="255" required autofocus @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
                    @error('username') <p class="field-error" id="username-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="password">Senha</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" placeholder="Digite sua senha" autocomplete="current-password" maxlength="1024" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        <button class="password-toggle" type="button" aria-label="Mostrar senha" aria-controls="password" aria-pressed="false" data-password-toggle><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button class="primary-button" type="submit" data-submit-button><span data-submit-label>Entrar</span></button>
            </form>
        </section>
    </main>
</x-layouts.auth>
