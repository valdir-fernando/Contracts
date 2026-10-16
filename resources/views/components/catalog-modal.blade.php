@props(['kind', 'returnTo' => 'catalogs.index'])
@php
    $labels = [
        'supplier' => ['Novo fornecedor', '', 'Nome ou razão social', 'Salvar fornecedor'],
        'fund' => ['Novo fundo', 'Cadastre o fundo que será responsável pelos contratos.', 'Nome do fundo', 'Cadastrar fundo'],
        'department' => ['Novo órgão', 'Cadastre uma secretaria ou órgão para organizar os contratos e os acessos.', 'Nome do órgão ou secretaria', 'Cadastrar órgão'],
    ];
    [$title, $description, $nameLabel, $submitLabel] = $labels[$kind];
    $bag = $errors->getBag('catalog-'.$kind);
    $restore = old('kind') === $kind;
@endphp
<dialog id="catalog-{{ $kind }}" @class(['catalog-modal', 'supplier-registration-modal' => $kind === 'supplier']) aria-labelledby="catalog-{{ $kind }}-title" @if($description) aria-describedby="catalog-{{ $kind }}-description" @endif @if($bag->any()) data-modal-auto-open @endif>
    <div class="modal-header">
        <div><span class="section-label">Cadastro</span><h2 id="catalog-{{ $kind }}-title">{{ $title }}</h2></div>
        @if($kind === 'supplier')
            <div class="supplier-header-actions action-toolbar">
                <button class="section-tab" type="submit" form="catalog-supplier-form"><x-nav-icon name="save"/>Salvar</button>
                <button class="section-tab" type="reset" form="catalog-supplier-form"><x-nav-icon name="plus"/>Novo</button>
                <button class="section-tab" type="button" disabled title="Não há fornecedor salvo selecionado para excluir."><x-nav-icon name="trash"/>Excluir</button>
            </div>
        @endif
        <button class="modal-close" type="button" data-modal-close aria-label="Fechar {{ mb_strtolower($title) }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </div>
    @if($description)<p id="catalog-{{ $kind }}-description" class="modal-description">{{ $description }}</p>@endif
    <form method="POST" action="{{ route('catalogs.store') }}" @if($kind === 'supplier') id="catalog-supplier-form" data-supplier-form data-registration-lookup-url="{{ route('suppliers.registration-lookup') }}" @endif>
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        @if($bag->any())<div class="notice notice-error" role="alert">Confira os campos indicados para concluir o cadastro.</div>@endif
        @if($kind === 'supplier')<div class="field-grid supplier-identity-grid">@endif
        <div class="field">
            <label for="catalog-{{ $kind }}-name">{{ $nameLabel }} <x-required-mark/></label>
            <input id="catalog-{{ $kind }}-name" name="name" required maxlength="255" autocomplete="organization" autofocus value="{{ $restore ? old('name') : '' }}" @if($bag->has('name')) aria-invalid="true" aria-describedby="catalog-{{ $kind }}-name-error" @endif>
            @if($bag->has('name'))<p class="field-error" id="catalog-{{ $kind }}-name-error">{{ $bag->first('name') }}</p>@endif
        </div>
        @if($kind !== 'department')
            <div class="field">
                @if($kind === 'supplier')<div class="field-label-row supplier-document-label">@endif
                <label for="catalog-{{ $kind }}-document">CPF/CNPJ <x-required-mark/></label>
                @if($kind === 'supplier')<small class="hint">Pode ser informado com ou sem pontuação.</small></div>@endif
                <input id="catalog-{{ $kind }}-document" name="document" required maxlength="32" inputmode="numeric" data-document-format placeholder="Informe o CPF ou CNPJ" value="{{ $restore ? old('document') : '' }}" @if($bag->has('document')) aria-invalid="true" aria-describedby="catalog-{{ $kind }}-document-error" @endif>
                @if($bag->has('document'))<p class="field-error" id="catalog-{{ $kind }}-document-error">{{ $bag->first('document') }}</p>@endif
                @unless($kind === 'supplier')<small class="hint">Pode ser informado com ou sem pontuação.</small>@endunless
                @if($kind === 'supplier')<p class="hint" role="status" data-registration-lookup-status></p>@endif
            </div>
        @endif
        @if($kind === 'supplier')
            </div>
            @foreach(\App\Support\SupplierFields::GROUPS as $group => $fields)
                <fieldset @class(['supplier-registration-group', 'supplier-registration-basics' => $group === 'Dados cadastrais']) @if($group === 'Dados cadastrais') aria-label="Dados do fornecedor" @endif>
                    @unless($group === 'Dados cadastrais')<legend>{{ $group }}</legend>@endunless
                    <div @class(['field-grid', 'supplier-contact-grid' => $group === 'Pessoa de contato', 'supplier-address-grid' => $group === 'Endereço e contatos do fornecedor', 'supplier-partner-grid' => $group === 'Sócio'])>
                        @foreach($fields as $field => [$label, $type])
                            <div @class(['field', 'supplier-trade-name-field' => $field === 'Nome_Fantasia', 'supplier-address-field' => $field === 'Endereco', 'supplier-partner-name-field' => $field === 'Nome_Socio'])>
                                <label for="catalog-supplier-{{ $field }}">{{ $label }}</label>
                                @if($field === 'Sexo')
                                    <select id="catalog-supplier-{{ $field }}" name="{{ $field }}" @if($bag->has($field)) aria-invalid="true" aria-describedby="catalog-supplier-{{ $field }}-error" @endif>
                                        <option value="">Selecione</option>
                                        @foreach(['Masculino', 'Feminino'] as $sex)
                                            <option value="{{ $sex }}" @selected($restore && old($field) === $sex)>{{ $sex }}</option>
                                        @endforeach
                                    </select>
                                @elseif($type === 'textarea')
                                    <textarea id="catalog-supplier-{{ $field }}" name="{{ $field }}" rows="{{ $field === 'Endereco' ? 1 : 2 }}" maxlength="2000" @if($bag->has($field)) aria-invalid="true" aria-describedby="catalog-supplier-{{ $field }}-error" @endif>{{ $restore ? old($field) : '' }}</textarea>
                                @else
                                    <input id="catalog-supplier-{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $type === 'tel' ? 15 : ($field === 'PIS_PASEP' ? 14 : 255) }}" @if($type === 'tel') data-phone-mask @elseif($field === 'PIS_PASEP') data-pis-mask @endif @if($field === 'CPF_Socio') data-document-format @endif value="{{ $restore ? old($field) : '' }}" @if($bag->has($field)) aria-invalid="true" aria-describedby="catalog-supplier-{{ $field }}-error" @endif>
                                @endif
                                @if($bag->has($field))<p class="field-error" id="catalog-supplier-{{ $field }}-error">{{ $bag->first($field) }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        @endif
        @unless($kind === 'supplier')
            <div class="form-actions modal-actions"><button class="secondary-button" type="button" data-modal-close>Cancelar</button><button class="primary-button" type="submit"><x-nav-icon name="save"/>{{ $submitLabel }}</button></div>
        @endunless
    </form>
</dialog>
