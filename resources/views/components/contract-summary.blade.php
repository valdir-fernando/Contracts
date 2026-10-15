@props(['contract', 'values' => [], 'editable' => false, 'heading' => 'Novo contrato', 'showSupplier' => true])
<div class="contract-heading-title" aria-label="Resumo do contrato e fornecedor">
        <h1 class="sr-only">{{ $heading }}</h1>
        <div class="contract-number-field">
            <label for="contract-summary-number">Nº do Contrato</label>
            <div class="contract-number-control">
                <input id="contract-summary-number" class="contract-number-highlight" type="text" maxlength="100" value="{{ $contract->exists ? $contract->numero : '' }}" placeholder="N° do Contrato" aria-label="Número do contrato para busca" data-contract-number-search>
                <button type="button" class="contract-number-search" data-modal-open="contract-search-dialog" aria-label="Buscar contratos do Fundo selecionado" title="Buscar contratos"><x-nav-icon name="search"/></button>
            </div>
        </div>
        @if($showSupplier)<x-contract-supplier-heading :contract="$contract" :values="$values" :editable="$editable"/>@endif
</div>
<dialog id="contract-search-dialog" class="catalog-modal" aria-labelledby="contract-search-title" data-contract-search-url="{{ route('contracts.lookup') }}">
    <div class="panel-header"><h2 id="contract-search-title">Buscar contratos do Fundo selecionado</h2><button type="button" class="secondary-button" data-modal-close aria-label="Fechar busca de contratos">Fechar</button></div>
    <form data-contract-search-form>
        <label for="contract-search-query">Nº do Contrato</label>
        <div class="contract-search-controls"><input id="contract-search-query" name="q" maxlength="100" placeholder="N° do Contrato" autofocus><button type="submit" class="primary-button">Buscar</button></div>
    </form>
    <p data-contract-search-status role="status" aria-live="polite"></p>
    <ul class="contract-search-results" data-contract-search-results></ul>
    <div class="contract-search-controls"><button type="button" class="secondary-button" data-contract-search-prev disabled>Anterior</button><button type="button" class="secondary-button" data-contract-search-next disabled>Próxima</button></div>
</dialog>
