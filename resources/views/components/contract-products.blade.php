@props(['contract', 'showAccumulatedValue' => false])
@php($codeLabel = mb_strtolower(trim((string) $contract->tipo)) === 'credenciamento' && filled($contract->cargo_credenc) ? 'CATSER' : 'Código')
@if($contract->exists)
<div data-contract-products data-enter-scope data-catser-editable="{{ $codeLabel === 'CATSER' ? 'true' : 'false' }}" data-base="{{ route('contracts.products.index', $contract) }}">
    <p data-product-feedback role="status" aria-live="polite"></p>
    <dialog class="catalog-modal product-picker-dialog" data-product-picker aria-labelledby="product-picker-title">
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
    <div class="product-entry-heading">
        <dialog class="catalog-modal product-item-warning" data-product-budget-warning aria-labelledby="product-budget-warning-title" aria-describedby="product-budget-warning-message">
            <div class="modal-header"><h2 id="product-budget-warning-title">Consulta de ficha</h2></div>
            <p id="product-budget-warning-message" data-product-budget-warning-message></p>
            @if(auth()->user()->canAccessCatalog('budget'))
                <a class="section-tab" data-product-budget-create href="{{ route('catalogs.budgets.create', ['fund_id' => $contract->fund_id, 'department_id' => $contract->department_id]) }}" hidden>Cadastrar nova dotação</a>
            @endif
            <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-product-budget-warning-ok autofocus>OK</button></div>
        </dialog>

                <label class="product-confirm"><input type="checkbox" data-products-show> Exibir produtos do processo</label>
                <span class="product-options-separator" role="separator" aria-orientation="vertical"></span>
                <label class="product-confirm product-manual-option"><input type="checkbox" data-products-manual> Lançamento manual</label>
                <div class="product-contract-summary">
                    <span>
                    <span>Contrato: <strong>{{ $contract->numero ?: 'Não informado' }}</strong></span>
                    <span aria-hidden="true"> · </span>
                    <span>Processo: <strong>{{ $contract->processo ?: 'Não informado' }}</strong></span>
                    </span>
                </div>
    </div>
    <div class="product-entry-layout">
        <div class="product-entry-main">
            <div class="product-budget-row">
                <label>Ficha<input type="text" data-product-budget maxlength="64" autocomplete="off" aria-label="Ficha dos produtos"></label>
                <label>Descrição<input type="text" data-product-budget-description readonly aria-readonly="true"></label>
                <label>Dotação<input type="text" data-product-budget-allocation readonly aria-readonly="true"></label>
            </div>
            <p class="hint" data-product-budget-status role="status" aria-live="polite"></p>
            <div class="product-entry-fields">
                <div class="product-item-search">
                    <label>Item<input type="text" data-product-field="item" maxlength="100"></label>
                    <button type="button" class="product-item-search-button" data-product-item-search aria-label="Buscar item" title="Buscar item"><x-nav-icon name="search"/></button>
                </div>
                <label>{{ $codeLabel }}<input data-product-field="code" maxlength="100" @if($codeLabel !== 'CATSER') readonly @endif></label>
                <label>Marca<input data-product-field="brand" readonly></label>
                <label>UND<input data-product-field="unit" readonly></label>
                <label>Disponível<input data-product-field="disp" readonly aria-label="Disponibilidade calculada" title="Soma lançada para o Item, fornecedor e processo menos QTD do produto; mínimo zero"></label>
                <label>QTD<input data-product-quantity inputmode="decimal" maxlength="40" placeholder="0,0000" aria-label="Quantidade do produto"></label>
                <label>VL. Unitário<input data-product-field="unit_price" readonly inputmode="decimal" maxlength="40" placeholder="0,0000"></label>
                <label>VL. Total<input data-product-line-total readonly value="0,0000"></label>
                <div class="product-selected-description"><textarea data-product-description readonly aria-readonly="true" aria-label="Produto selecionado" rows="1" maxlength="5000"></textarea></div>
            </div>

        </div>
        <aside class="product-budget-summary" aria-label="Valores dos produtos por ficha">
            <div class="product-list-scroll"><table class="data-table"><thead><tr><th>Ficha</th><th>Valor</th></tr></thead><tbody data-product-budget-totals></tbody></table></div>
            <p class="hint" data-product-ficha-status role="status" aria-live="polite"></p>
        </aside>
    </div>
    <p class="hint" data-product-balance></p>
    <div class="product-controls-row">
    <div class="product-entry-actions action-toolbar">
        <button type="button" class="section-tab" data-products-save disabled><x-nav-icon name="save"/>Salvar produto</button>
        <button type="button" class="section-tab" data-products-new><x-nav-icon name="plus"/>Novo Produto</button>
        <button type="button" class="section-tab" data-products-delete disabled><x-nav-icon name="trash"/>Excluir / estornar</button>
        <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
        <button type="button" class="section-tab products-button" data-products-new-ficha><x-nav-icon name="plus"/>Nova Ficha</button>
    </div>
    <div class="product-panel-total"><label>Total da Ficha<input data-product-contract-total readonly aria-readonly="true" value="0,0000"></label></div>
    </div>
    <div class="product-list-scroll product-contract-list"><table class="data-table"><thead><tr><th>Item</th><th>{{ $codeLabel }}</th><th>Produto</th><th>UND</th><th>QTD</th><th>Marca</th><th>VL. Unitário</th><th>VL. Total</th><th>Ficha</th><th>Ação</th></tr></thead><tbody data-contract-product-list></tbody></table></div>
    <div class="product-contract-values">
        <label>Valor Total do Contrato<input data-product-heading-total readonly aria-readonly="true" value="{{ \App\Models\Contract::moneyLabel($contract->valor_total) }}"></label>
        @if($showAccumulatedValue)
            <span class="product-actions-separator" role="separator" aria-orientation="vertical"></span>
            <label class="contract-accumulated-label">Valor Acumulado do Contrato<input data-product-accumulated-total readonly aria-readonly="true" value="{{ \App\Models\Contract::moneyLabel($contract->valor_acumulado) }}"></label>
        @endif
        @if($sessionFund = request()->attributes->get('selected_fund'))
            <div class="product-session-fund" aria-label="Fundo logado">
                <strong data-fit-fund-name>{{ $sessionFund['name'] }}</strong>
            </div>
        @endif
    </div>
</div>
@else
    <p>Salve o contrato com o processo e o fornecedor para selecionar e lançar os produtos já cadastrados na tb_Produtos.</p>
@endif
