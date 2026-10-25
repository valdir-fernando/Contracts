@props(['contract'])
<dialog id="contract-amendments-panel" class="catalog-modal contract-amendments-dialog" data-contract-amendments-panel data-summary-url="{{ route('contracts.show', $contract) }}" aria-labelledby="contract-amendments-title">
    <div class="modal-header">
        <h2 id="contract-amendments-title">Gerenciar aditivos · Contrato {{ $contract->numero ?: $contract->id }}</h2>
        <div class="product-title-supplier"><x-contract-supplier-heading :contract="$contract"/></div>
        <button type="button" class="modal-close" data-contract-amendments-close aria-label="Fechar gerenciamento de aditivos">×</button>
    </div>
    <p data-contract-amendments-loading role="status" hidden>Carregando aditivos…</p>
    <iframe name="contracts-amendments-panel" title="Aditivos do contrato {{ $contract->numero ?: $contract->id }}" data-contract-amendments-frame></iframe>
</dialog>
