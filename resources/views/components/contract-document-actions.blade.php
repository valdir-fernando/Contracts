@props(['contract'])
<div class="contract-document-actions" x-data="{ message: '' }">
    <nav aria-label="Emissão de documentos do contrato">
        @foreach(['contract' => ['Contrato', 'contracts'], 'extract' => ['Extrato', 'document-extract'], 'publication' => ['Publicação', 'document-publication']] as $kind => [$label, $icon])
            @php
                $authorized = auth()->user()->canIssueContracts();
                $ready = $authorized && $contract->exists && (
                    ($kind === 'contract' && \App\Infrastructure\Documents\SupplyContractPdf::modelFor($contract->tipo) !== null)
                    || ($kind === 'extract' && \App\Infrastructure\Documents\SupplyContractExtract::modelFor($contract->tipo) !== null)
                    || $kind === 'publication'
                );
                $message = ! $authorized ? 'Você não possui autorização para emitir documentos de contratos.' : ($contract->exists
                    ? 'A emissão de '.$label.' depende da configuração do modelo desse documento.'
                    : 'Salve o contrato antes de emitir '.$label.'.');
            @endphp
            <button type="button" class="document-action" aria-label="Emitir {{ $label }}" aria-describedby="document-tip-{{ $kind }}" aria-disabled="{{ $ready ? 'false' : 'true' }}" data-document-kind="{{ $kind }}" data-document-state="{{ $ready ? 'ready' : ($contract->exists ? 'model-pending' : 'save-required') }}" data-message="{{ $message }}" @if($ready && $kind === 'contract') data-contract-document-url="{{ route('contracts.document', ['contract' => $contract->id, 'version' => $contract->version]) }}" @elseif($ready && $kind === 'extract') data-extract-url="{{ route('contracts.extract', ['contract' => $contract->id, 'version' => $contract->version]) }}" @elseif($ready && $kind === 'publication') data-publication-url="{{ route('contracts.publication', ['contract' => $contract->id, 'version' => $contract->version]) }}" @endif @click="message = $el.dataset.documentState === 'ready' ? '' : $el.dataset.message">
                <x-nav-icon :name="$icon"/><span>{{ $label }}</span>
                <span class="document-action-tooltip" role="tooltip" id="document-tip-{{ $kind }}">{{ $ready ? match ($kind) { 'contract' => 'Abrir contrato de '.(match (\App\Infrastructure\Documents\SupplyContractPdf::modelFor($contract->tipo)) { 'property-lease' => 'locação de imóvel', 'services' => 'serviços diversos', 'works' => 'obras', default => 'fornecimento' }).' em PDF.', 'extract' => 'Abrir extrato de '.(\App\Infrastructure\Documents\SupplyContractExtract::modelFor($contract->tipo) === 'property-lease' ? 'locação de imóvel' : 'fornecimento').' em PDF.', default => 'Abrir publicação do contrato em PDF.' } : $message }}</span>
            </button>
        @endforeach
    </nav>
    <p class="document-action-message" role="status" x-show="message" x-text="message" x-cloak></p>
    <p class="document-action-message" role="status" data-extract-feedback hidden></p>
    <dialog class="catalog-modal product-item-warning" data-contract-document-error aria-labelledby="contract-document-error-title">
        <div class="modal-header"><h2 id="contract-document-error-title">Emissão do contrato</h2></div>
        <p data-contract-document-error-message></p>
        <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-contract-document-error-close autofocus>OK</button></div>
    </dialog>
</div>
