<x-layouts.app title="Minha conta">
    <div class="page-heading"><div><h1>Minha conta</h1><p>Seu perfil e órgãos são gerenciados pelo administrador.</p></div></div>
    <section class="surface contract-section" data-profile-photo>
        <h2>Foto de perfil</h2>
        <div class="profile-photo-editor">
            <x-account-avatar :user="auth()->user()" class="account-avatar-large" data-profile-photo-preview/>
            <div>
                <form method="POST" action="{{ route('account.avatar.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="field"><label for="profile-photo">Escolher foto</label><input id="profile-photo" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required data-profile-photo-input aria-describedby="profile-photo-help"></div>
                    <p id="profile-photo-help" class="hint">JPEG, PNG ou WebP de até 2 MB e 3.000 × 3.000 pixels. A foto será recortada no centro e exibida em círculo.</p>
                    <p class="hint" data-profile-photo-status role="status" aria-live="polite"></p>
                    <button class="primary-button compact" type="submit"><x-nav-icon name="save"/>Salvar foto</button>
                </form>
                @if(auth()->user()->avatar_path)
                    <form method="POST" action="{{ route('account.avatar.destroy') }}" class="profile-photo-remove">@csrf @method('DELETE')<button class="text-link" type="submit">Remover foto e usar minha inicial</button></form>
                @endif
            </div>
        </div>
    </section>
    <section class="surface contract-section"><form method="POST" action="{{ route('account.update') }}">@csrf @method('PUT')<div class="form-grid"><label><span class="field-caption">Nome <x-required-mark/></span><input name="name" value="{{ old('name', auth()->user()->name) }}" required></label><label>E-mail<input name="email" type="email" value="{{ old('email', auth()->user()->email) }}"></label><label><span class="field-caption">Senha atual <x-required-mark/></span><input name="current_password" type="password" autocomplete="current-password" required></label><label>Nova senha (opcional)<input name="password" type="password" autocomplete="new-password" minlength="12"></label><label>Confirme a nova senha<input name="password_confirmation" type="password" autocomplete="new-password"></label></div><p class="muted">Use pelo menos 12 caracteres, letras e números. A alteração da senha encerra as outras sessões.</p><button class="primary-button compact"><x-nav-icon name="save"/>Salvar</button></form></section>
</x-layouts.app>
