<x-layouts.auth>
    <main class="auth-shell">
        <div class="auth-form-area">
        <a class="auth-brand auth-login-brand" href="{{ route('login') }}"><x-brand-mark/>Contracts</a>
        <section class="login-card" aria-labelledby="login-title">
            <header class="login-header">
                <span class="section-label">ACESSO AO SISTEMA</span>
                <h1 id="login-title">Bem-vindo ao Contracts</h1>
                <p class="login-description">Selecione o Fundo e entre com seu usuário e senha.</p>
            </header>
            <form action="{{ route('login.store') }}" method="POST" data-login-form>
                @csrf
                <div class="field">
                    <label for="fund_id">Fundo <x-required-mark/></label>
                    <select id="fund_id" name="fund_id" required @error('fund_id') aria-invalid="true" aria-describedby="fund-error" @enderror>
                        <option value="">Selecione o Fundo</option>
                        @foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) old('fund_id') === (string) $fund->id)>{{ $fund->name }}</option>@endforeach
                    </select>
                    @error('fund_id')<p class="field-error" id="fund-error" role="alert">{{ $message }}</p>@enderror
                    @if($funds->isEmpty())<p class="hint">Nenhum Fundo cadastrado. Entre em contato com o administrador.</p>@endif
                </div>
                <div class="field">
                    <label for="username">Usuário <x-required-mark/></label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" placeholder="Seu nome de usuário" autocomplete="username" maxlength="255" required autofocus @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
                    @error('username') <p class="field-error" id="username-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="password">Senha <x-required-mark/></label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" placeholder="Digite sua senha" autocomplete="current-password" maxlength="1024" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        <button class="password-toggle" type="button" aria-label="Mostrar senha" aria-controls="password" aria-pressed="false" data-password-toggle><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button class="primary-button" type="submit" data-submit-button><span data-submit-label>Entrar</span><x-nav-icon name="arrow"/></button>
            </form>
            <p class="login-footer">Precisa de acesso? Entre em contato com o administrador do seu órgão.</p>
        </section>
        </div>
    </main>
</x-layouts.auth>
