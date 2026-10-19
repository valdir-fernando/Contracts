<x-layouts.app title="Minha conta">
    <div class="page-heading account-page-heading"><div class="account-heading-line"><h1>Minha conta</h1><span class="account-heading-divider" aria-hidden="true">/</span><p>Consulte e atualize os dados da sua conta.</p></div></div>
    <section class="surface contract-section account-section account-photo-panel" aria-label="Foto de perfil" data-profile-photo>
        <div class="profile-photo-editor">
            <x-account-avatar :user="auth()->user()" class="account-avatar-large" data-profile-photo-preview/>
            <div>
                <form method="POST" action="{{ route('account.avatar.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="profile-photo-upload-row">
                        <div class="field"><div class="profile-photo-label-row"><label for="profile-photo">Escolher foto</label>@if(auth()->user()->avatar_path)<span aria-hidden="true">/</span><button class="text-link" type="submit" form="profile-photo-remove">Remover foto e usar minha inicial</button>@endif</div><input id="profile-photo" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required data-profile-photo-input aria-describedby="profile-photo-help"></div>
                    <div class="profile-photo-actions">
                        <button class="primary-button compact" type="submit"><x-nav-icon name="save"/>Salvar foto</button>
                    </div>
                    </div>
                    <p id="profile-photo-help" class="hint">JPEG, PNG ou WebP de até 2 MB e 3.000 × 3.000 pixels. A foto será recortada no centro e exibida em círculo.</p>
                    <p class="hint" data-profile-photo-status role="status" aria-live="polite"></p>
                </form>
                @if(auth()->user()->avatar_path)
                    <form id="profile-photo-remove" method="POST" action="{{ route('account.avatar.destroy') }}" hidden>@csrf @method('DELETE')</form>
                @endif
            </div>
        </div>
    </section>
    <section class="surface contract-section account-section">
        <form method="POST" action="{{ route('account.update') }}" class="account-details-form">
            @csrf @method('PUT')
            <input type="hidden" name="version" value="{{ auth()->user()->session_version }}">
            <div class="form-grid">
                <div class="field"><label for="account-name">Nome <x-required-mark/></label><input id="account-name" name="name" value="{{ old('name', auth()->user()->name) }}" required></div>
                <div class="field"><div class="account-username-label"><label for="account-username">Usuário <x-required-mark/></label><small id="account-username-help" class="hint">Letras sem acentos, números, ponto, hífen ou sublinhado.</small></div><input id="account-username" name="username" value="{{ old('username', auth()->user()->username) }}" maxlength="255" aria-describedby="account-username-help" required></div>
                <div class="field"><label for="account-email">E-mail</label><input id="account-email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}"></div>
                <div class="field"><label for="account-role">Perfil <x-required-mark/></label><select id="account-role" @if(auth()->user()->isMaster()) name="user_type" required @else disabled @endif>@foreach(auth()->user()->isMaster() ? \App\Models\User::ROLES : [auth()->user()->user_type] as $role)<option value="{{ $role }}" @selected(old('user_type', auth()->user()->user_type) === $role)>{{ $role === 'Usuario' ? 'Usuário' : $role }}</option>@endforeach</select></div>
                <div class="user-affiliation-row account-affiliation-row">
                    <div class="field"><label for="account-active">Situação <x-required-mark/></label><select id="account-active" @if(auth()->user()->isMaster()) name="is_active" required @else disabled @endif><option value="1" @selected((string) old('is_active', (int) auth()->user()->is_active) === '1')>Ativo</option><option value="0" @selected((string) old('is_active', (int) auth()->user()->is_active) === '0')>Inativo</option></select></div>
                    <div class="field"><label for="account-affiliation">Fundo / secretaria <x-required-mark :when="auth()->user()->isMaster()"/></label>
                        @if(auth()->user()->isMaster())
                            <input type="hidden" name="affiliations_present" value="1">
                            @php($ownAffiliations = old('affiliations', auth()->user()->scopes->map(fn ($scope) => $scope->department_id ? 'department:'.$scope->department_id : ($scope->fund_id ? 'fund:'.$scope->fund_id : 'legacy:'.$scope->id))->all()))
                            <select id="account-affiliation" name="affiliations[]" required>
                                <option value="">Selecione um Fundo ou uma Secretaria</option>
                                @foreach($affiliationOptions as $value => $label)<option value="{{ $value }}" @selected(in_array($value, (array) $ownAffiliations))>{{ $label }}</option>@endforeach
                                @foreach(auth()->user()->scopes as $scope)
                                    @php($existingValue = $scope->department_id ? 'department:'.$scope->department_id : ($scope->fund_id ? 'fund:'.$scope->fund_id : 'legacy:'.$scope->id))
                                    @unless(array_key_exists($existingValue, $affiliationOptions))<option value="legacy:{{ $scope->id }}" @selected(in_array($existingValue, (array) $ownAffiliations) || in_array('legacy:'.$scope->id, (array) $ownAffiliations))>{{ $scope->label }} (vínculo existente)</option>@endunless
                                @endforeach
                            </select>
                        @else
                            <select id="account-affiliation" disabled>@forelse(auth()->user()->scopes as $scope)<option>{{ $scope->label }}</option>@empty<option>Nenhum vínculo</option>@endforelse</select>
                        @endif
                    </div>
                </div>
                <div class="account-password-row">
                <div class="field"><label for="account-current-password">Senha atual <x-required-mark/></label><x-password-control id="account-current-password"><input id="account-current-password" name="current_password" type="password" autocomplete="current-password" required></x-password-control></div>
                <div class="field"><label for="account-password">Nova senha (opcional)</label><x-password-control id="account-password"><input id="account-password" name="password" type="password" autocomplete="new-password" minlength="12" aria-describedby="account-password-help"></x-password-control></div>
                <div class="field account-password-confirmation"><label for="account-password-confirmation">Confirme a nova senha</label><x-password-control id="account-password-confirmation"><input id="account-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" aria-describedby="account-password-help"></x-password-control><p id="account-password-help" class="hint">Use pelo menos 12 caracteres, letras e números. A alteração da senha encerra as outras sessões.</p></div>
                </div>
            </div>
            <button class="primary-button compact"><x-nav-icon name="save"/>Salvar</button>
        </form>
    </section>
</x-layouts.app>
