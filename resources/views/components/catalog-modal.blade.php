@props(['kind', 'returnTo' => 'catalogs.index'])
@php
    $labels = [
        'supplier' => ['Novo fornecedor', 'Cadastre nome e CPF/CNPJ para usar nos contratos.', 'Nome ou razão social', 'Cadastrar fornecedor'],
        'fund' => ['Novo fundo', 'Cadastre o fundo que será responsável pelos contratos.', 'Nome do fundo', 'Cadastrar fundo'],
        'department' => ['Novo órgão', 'Cadastre uma secretaria ou órgão para organizar os contratos e os acessos.', 'Nome do órgão ou secretaria', 'Cadastrar órgão'],
    ];
    [$title, $description, $nameLabel, $submitLabel] = $labels[$kind];
    $bag = $errors->getBag('catalog-'.$kind);
    $restore = old('kind') === $kind;
@endphp
<dialog id="catalog-{{ $kind }}" class="catalog-modal" aria-labelledby="catalog-{{ $kind }}-title" aria-describedby="catalog-{{ $kind }}-description" @if($bag->any()) data-modal-auto-open @endif>
    <div class="modal-header">
        <div><span class="section-label">Cadastro</span><h2 id="catalog-{{ $kind }}-title">{{ $title }}</h2></div>
        <button class="modal-close" type="button" data-modal-close aria-label="Fechar {{ mb_strtolower($title) }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </div>
    <p id="catalog-{{ $kind }}-description" class="modal-description">{{ $description }}</p>
    <form method="POST" action="{{ route('catalogs.store') }}">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        @if($bag->any())<div class="notice notice-error" role="alert">Confira os campos indicados para concluir o cadastro.</div>@endif
        <div class="field">
            <label for="catalog-{{ $kind }}-name">{{ $nameLabel }} <x-required-mark/></label>
            <input id="catalog-{{ $kind }}-name" name="name" required maxlength="255" autocomplete="organization" autofocus value="{{ $restore ? old('name') : '' }}" @if($bag->has('name')) aria-invalid="true" aria-describedby="catalog-{{ $kind }}-name-error" @endif>
            @if($bag->has('name'))<p class="field-error" id="catalog-{{ $kind }}-name-error">{{ $bag->first('name') }}</p>@endif
        </div>
        @if($kind !== 'department')
            <div class="field">
                <label for="catalog-{{ $kind }}-document">CPF/CNPJ <x-required-mark/></label>
                <input id="catalog-{{ $kind }}-document" name="document" required maxlength="32" inputmode="numeric" data-document-format placeholder="Informe o CPF ou CNPJ" value="{{ $restore ? old('document') : '' }}" @if($bag->has('document')) aria-invalid="true" aria-describedby="catalog-{{ $kind }}-document-error" @endif>
                @if($bag->has('document'))<p class="field-error" id="catalog-{{ $kind }}-document-error">{{ $bag->first('document') }}</p>@endif
                <small class="hint">Pode ser informado com ou sem pontuação.</small>
            </div>
        @endif
        <div class="form-actions modal-actions"><button class="secondary-button" type="button" data-modal-close>Cancelar</button><button class="primary-button" type="submit">{{ $submitLabel }}</button></div>
    </form>
</dialog>
