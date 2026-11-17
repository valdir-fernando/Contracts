@props(['contract' => null, 'showAccumulatedValue' => false, 'preview' => false, 'documentLabel' => 'Contrato', 'itemLookupUrl' => null])
@php($codeLabel = !$preview && mb_strtolower(trim((string) $contract->tipo)) === 'credenciamento' && filled($contract->cargo_credenc) ? 'CATSER' : 'Código')
@if($preview || $contract->exists)
@if($preview)<fieldset disabled :disabled="!loaded || busy" class="contract-products-preview" aria-label="Produtos da ata">@endif
<div class="contract-products-panel" @unless($preview) data-contract-products data-catser-editable="{{ $codeLabel === 'CATSER' ? 'true' : 'false' }}" data-base="{{ route('contracts.products.index', $contract) }}" @endunless data-enter-scope>
    @unless($preview)<p data-product-feedback role="status" aria-live="polite"></p>@endunless
    <dialog class="catalog-modal product-picker-dialog" data-product-picker @if($preview) data-ata-product-list-url="{{ route('atas.products.catalog', ['ata' => 0]) }}" @endif aria-labelledby="product-picker-title">
        <div class="modal-header"><h2 id="product-picker-title">Selecionar produto</h2></div>
        <p data-product-picker-context></p>
        <p data-product-picker-status role="status" aria-live="polite"></p>
        <div class="product-list-scroll product-picker-list"><table class="data-table"><thead><tr><th>Item</th><th>{{ $codeLabel }}</th><th>Produto</th><th>Marca</th><th>UND</th><th>QTD</th><th>VL. Unitário</th><th>Ação</th></tr></thead><tbody data-product-picker-rows></tbody></table></div>
        <div class="form-actions action-toolbar product-picker-navigation">
            <button type="button" class="section-tab" data-product-picker-previous disabled><x-nav-icon name="arrow" /> Anterior</button>
            <span data-product-picker-page></span>
            <button type="button" class="section-tab" data-product-picker-next disabled>Próxima <x-nav-icon name="arrow" /></button>
            <button type="button" class="section-tab" data-product-picker-cancel>Cancelar</button>
        </div>
    </dialog>
    <dialog class="catalog-modal product-item-warning" data-product-item-warning aria-labelledby="product-item-warning-title" aria-describedby="product-item-warning-message">
        <div class="modal-header"><h2 id="product-item-warning-title">Consulta de item</h2></div>
        <p id="product-item-warning-message" data-product-item-warning-message></p>
        <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-product-item-warning-ok autofocus>OK</button></div>
    </dialog>
    @if($preview)
    <div class="product-entry-layout">
        <div class="product-entry-main">
    @endif
    <div class="product-entry-heading">
        @unless($preview)
        <dialog class="catalog-modal product-item-warning" data-product-budget-warning aria-labelledby="product-budget-warning-title" aria-describedby="product-budget-warning-message">
            <div class="modal-header"><h2 id="product-budget-warning-title">Consulta de ficha</h2></div>
            <p id="product-budget-warning-message" data-product-budget-warning-message></p>
            @if(!$preview && auth()->user()->canAccessCatalog('budget'))
                <a class="section-tab" data-product-budget-create href="{{ route('catalogs.budgets.create', ['fund_id' => $contract->fund_id, 'department_id' => $contract->department_id]) }}" hidden>Cadastrar nova dotação</a>
            @endif
            <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-product-budget-warning-ok autofocus>OK</button></div>
        </dialog>
        @endunless

                @unless($preview)
                <label class="product-confirm"><input type="checkbox" data-products-show> Exibir produtos do processo</label>
                <span class="product-options-separator" role="separator" aria-orientation="vertical"></span>
                <label class="product-confirm product-manual-option"><input type="checkbox" data-products-manual> Lançamento manual</label>
                @endunless
                <div class="product-contract-summary">
                    <span @if($preview) class="ata-product-process-budget" @endif>
                    @if($preview)
                        <label class="product-confirm product-manual-option"><input type="checkbox" data-ata-products-manual> Lançamento Manual</label>
                    @endif
                    @unless($preview)
                    <span>{{ $documentLabel }}: <strong>{{ $contract?->numero ?: 'Não informado' }}</strong></span>
                    <span aria-hidden="true"> · </span>
                    @endunless
                    @if($preview)
                    <span class="product-entry-actions action-toolbar ata-product-heading-actions">
                        <span class="ata-product-document-actions" role="group" aria-label="Documentos da ATA">
                            <button type="button" class="section-tab document-action" data-ata-document="ata" :disabled="!loaded || busy"><x-nav-icon name="contracts"/>ATA</button>
                            <button type="button" class="section-tab document-action" data-ata-document="extract" data-ata-extract-url="{{ route('atas.extract', ['ata' => 0]) }}" :disabled="!loaded || busy"><x-nav-icon name="document-extract"/>EXTRATO</button>
                            <button type="button" class="section-tab document-action" data-ata-document="publication" :disabled="!loaded || busy"><x-nav-icon name="document-publication"/>PUBLICAÇÃO</button>
                        </span>
                        <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
                        <button type="button" class="section-tab" data-products-save data-ata-product-save-url="{{ route('atas.products.store', ['ata' => 0]) }}" :disabled="!loaded || busy"><x-nav-icon name="save"/>Salvar produto</button>
                        <button type="button" class="section-tab" data-products-new :disabled="!loaded || busy"><x-nav-icon name="plus"/>Novo Produto</button>
                        <button type="button" class="section-tab" data-products-delete data-ata-product-delete-url="{{ route('atas.products.destroy', ['ata' => 0]) }}" :disabled="!loaded || busy"><x-nav-icon name="trash"/>Excluir</button>
                    </span>
                    @else
                    <span>Processo: <strong>{{ $contract?->processo ?: 'Não informado' }}</strong></span>
                    @endif
                    </span>
                </div>
    </div>
    @unless($preview)
    <div class="product-entry-layout">
        <div class="product-entry-main">
    @endunless
            @unless($preview)
            <div class="product-budget-row">
                <label>Ficha<input type="text" data-product-budget maxlength="64" autocomplete="off" aria-label="Ficha dos produtos"></label>
                <label>Descrição<input type="text" data-product-budget-description readonly aria-readonly="true"></label>
                <label>Dotação<input type="text" data-product-budget-allocation readonly aria-readonly="true"></label>
            </div>
            @endunless
            @unless($preview)<p class="hint" data-product-budget-status role="status" aria-live="polite"></p>@endunless
            @if($preview)<p data-product-feedback role="status" aria-live="polite" data-ata-item-lookup-url="{{ $itemLookupUrl }}"></p>@endif
            <div class="product-entry-fields">
                <div class="product-item-search">
                    <label><span>Item<x-required-mark :when="$preview"/></span><input type="text" data-product-field="item" maxlength="100" @required($preview) @if($preview) autofocus @endif></label>
                    <button type="button" class="product-item-search-button" data-product-item-search aria-label="Buscar item" title="Buscar item"><x-nav-icon name="search"/></button>
                </div>
                <label><span>{{ $codeLabel }}</span><input data-product-field="code" maxlength="100" @if($codeLabel !== 'CATSER') readonly @endif></label>
                <label>Marca<input data-product-field="brand" readonly></label>
                <label><span>UND<x-required-mark :when="$preview"/></span><input data-product-field="unit" readonly @required($preview)></label>
                <label>Disponível<input data-product-field="disp" data-quantity readonly aria-label="Disponibilidade calculada" title="{{ $preview ? 'Saldo disponível do item no processo da ata.' : 'Soma lançada para o Item, fornecedor e processo menos QTD do produto; mínimo zero' }}"></label>
                @if($preview)
                    <div class="ata-quantity-pricing" data-ata-quantity-pricing>
                    <div class="ata-product-quantity-field">
                        <div class="ata-product-quantity-label">
                            <label for="ata-product-quantity"><span>QTD<x-required-mark/></span></label>
                            <button type="button" class="text-link ata-special-quantity-link" data-ata-special-quantity data-ata-special-quantity-url="{{ route('atas.special-quantity.update', ['ata' => 0]) }}" tabindex="-1" title="Para adicionar uma QTD de item diferente da QTD padrão">QTD Especial</button>
                        </div>
                        <input id="ata-product-quantity" data-product-quantity data-quantity inputmode="decimal" maxlength="40" value="0,0000" aria-label="Quantidade do produto" required disabled>
                    </div>
                    <div class="ata-product-quantity-field" data-ata-special-quantity-field hidden>
                        <div class="ata-product-quantity-label">
                            <label for="ata-special-quantity-value"><span data-ata-special-quantity-label></span></label>
                            <button type="button" class="text-link ata-special-quantity-link" data-ata-special-quantity-delete tabindex="-1" title="Excluir a QTD Especial e seus valores desta ata">Excluir QTD</button>
                        </div>
                        <input id="ata-special-quantity-value" data-product-special-quantity data-quantity inputmode="decimal" maxlength="40" aria-label="Quantidade especial" value="0,0000">
                    </div>
                @else
                    <label><span>QTD</span><input data-product-quantity data-quantity inputmode="decimal" maxlength="40" value="0,0000" aria-label="Quantidade do produto"></label>
                @endif
                <label><span>VL. Unitário<x-required-mark :when="$preview"/></span><input data-product-field="unit_price" readonly data-money inputmode="decimal" maxlength="40" value="0,0000" @required($preview)></label>
                @if($preview)</div>@endif
                <label>@if($preview)<span class="ata-total-label"><button type="button" data-ata-total-more aria-label="Aumentar casas decimais" title="Aumentar casas decimais">+</button><button type="button" data-ata-total-less aria-label="Diminuir casas decimais" title="Diminuir casas decimais">−</button><span>VL. Total<x-required-mark/></span></span>@else VL. Total @endif<input @unless($preview) data-money @endunless data-product-line-total readonly @required($preview) value="{{ $preview ? '0,00' : '0,0000' }}"></label>
                <div class="product-selected-description">@if($preview)<label for="ata-product-description"><span>Descrição<x-required-mark/></span></label>@endif<textarea @if($preview) id="ata-product-description" required @endif data-product-description readonly aria-readonly="true" aria-label="Produto selecionado" rows="{{ $preview ? 3 : 1 }}" maxlength="5000"></textarea></div>
            </div>

        </div>
        @unless($preview)
        <aside class="product-budget-summary" aria-label="Valores dos produtos por ficha">
            <div class="product-list-scroll"><table class="data-table"><thead><tr><th>Ficha</th><th>Valor</th></tr></thead><tbody data-product-budget-totals></tbody></table></div>
            <p class="hint" data-product-ficha-status role="status" aria-live="polite"></p>
        </aside>
        @endunless
    </div>
    <p class="hint" data-product-balance></p>
    @unless($preview)
    <div class="product-controls-row">
    <div class="product-entry-actions action-toolbar">
        <button type="button" class="section-tab" data-products-save disabled><x-nav-icon name="save"/>Salvar produto</button>
        <button type="button" class="section-tab" data-products-new @disabled($preview)><x-nav-icon name="plus"/>Novo Produto</button>
        <button type="button" class="section-tab" data-products-delete disabled><x-nav-icon name="trash"/>{{ $preview ? 'Excluir' : 'Excluir / estornar' }}</button>
        <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
        <button type="button" class="section-tab products-button" data-products-new-ficha @disabled($preview)><x-nav-icon name="plus"/>Nova Ficha</button>
    </div>
    <div class="product-panel-total"><label>Total da Ficha<input data-money data-product-contract-total readonly aria-readonly="true" value="0,0000"></label></div>
    </div>
    @endunless
    <div class="product-list-scroll product-contract-list"><table class="data-table"><thead><tr><th>Item</th><th>{{ $codeLabel }}</th><th>Produto</th><th>UND</th><th>QTD</th>@if($preview)<th data-ata-special-quantity-heading hidden></th>@endif<th>Marca</th><th>VL. Unitário</th><th>VL. Total</th>@unless($preview)<th>Ficha</th>@endunless@if($preview)
<th aria-label="Selecionar produtos para exclusão"></th>
@else
<th>Ação</th>
@endif</tr></thead><tbody data-contract-product-list></tbody></table></div>
    <div class="product-contract-values">
        <label>Valor Total {{ $documentLabel === 'ATA' ? 'da ATA' : 'do Contrato' }}<input data-money data-product-heading-total readonly aria-readonly="true" value="{{ $contract?->valor_total ?? '0,0000' }}"></label>
        @if($showAccumulatedValue)
            <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
            <label class="contract-accumulated-label">Valor Acumulado do Contrato<input data-money data-product-accumulated-total readonly aria-readonly="true" value="{{ $contract->valor_acumulado ?? '0,0000' }}"></label>
        @endif
        @if($preview)
            <div class="product-session-fund" aria-label="Órgão Gerenciador">
                Órgão Gerenciador: <strong data-ata-products-managing-fund>Não informado</strong>
            </div>
        @elseif($sessionFund = request()->attributes->get('selected_fund'))
            <div class="product-session-fund" aria-label="Fundo logado">
                <strong data-fit-fund-name>{{ $sessionFund['name'] }}</strong>
            </div>
        @endif
    </div>
</div>
@if($preview)</fieldset>@endif
@else
    <p>Salve o contrato com o processo e o fornecedor para selecionar e lançar os produtos já cadastrados na tb_Produtos.</p>
@endif
