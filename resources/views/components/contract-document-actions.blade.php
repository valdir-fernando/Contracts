@props(['contract'])
<div class="contract-document-actions" x-data="{ message: '' }">
    <nav aria-label="Emissão de documentos do contrato">
        @foreach(['contract' => ['Contrato', 'contracts'], 'extract' => ['Extrato', 'document-extract'], 'publication' => ['Publicação', 'document-publication'], 'termination' => ['Distrato', 'document-termination']] as $kind => [$label, $icon])
            @php
                $message = $contract->exists
                    ? 'A emissão de '.$label.' depende da configuração do modelo desse documento.'
                    : 'Salve o contrato antes de emitir '.$label.'.';
            @endphp
            <button type="button" class="document-action" aria-label="Emitir {{ $label }}" aria-describedby="document-tip-{{ $kind }}" aria-disabled="true" data-document-kind="{{ $kind }}" data-document-state="{{ $contract->exists ? 'model-pending' : 'save-required' }}" data-message="{{ $message }}" @click="message = $el.dataset.message">
                <x-nav-icon :name="$icon"/><span>{{ $label }}</span>
                <span class="document-action-tooltip" role="tooltip" id="document-tip-{{ $kind }}">{{ $message }}</span>
            </button>
        @endforeach
    </nav>
    <p class="document-action-message" role="status" x-show="message" x-text="message" x-cloak></p>
</div>
