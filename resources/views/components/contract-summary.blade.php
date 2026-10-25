@props(['contract', 'values' => [], 'editable' => false, 'heading' => 'Novo contrato', 'showSupplier' => true, 'readonlyNumber' => false, 'showEdit' => false])
<div @class(['contract-heading-title', 'contract-summary-readonly' => $readonlyNumber]) aria-label="Resumo do contrato e fornecedor">
        <h1 class="sr-only">{{ $heading }}</h1>
        <div class="contract-number-field">
            <label for="contract-summary-number">Nº do Contrato</label>
            <div class="contract-number-control" data-enter-scope>
                <input id="contract-summary-number" class="contract-number-highlight" type="text" maxlength="100" value="{{ $contract->exists ? $contract->numero : '' }}" placeholder="N° do Contrato" aria-label="{{ $readonlyNumber ? 'Número do contrato' : 'Número do contrato para busca' }}" @readonly($readonlyNumber) data-contract-number-search>
                @unless($readonlyNumber)<button type="button" class="contract-number-search" data-contract-search-open aria-label="Buscar contratos do Fundo selecionado" title="Buscar contratos"><x-nav-icon name="search"/></button>@endunless
            </div>
        </div>
        @if($showSupplier)<x-contract-supplier-heading :contract="$contract" :values="$values" :editable="$editable"/>@endif
        @if($showEdit)
            @can('update', $contract)<div class="contract-summary-edit"><a class="primary-button compact" href="{{ route('contracts.edit', $contract) }}"><x-nav-icon name="edit"/>Editar contrato</a></div>@endcan
        @endif
</div>
@unless($readonlyNumber)
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
@endunless
