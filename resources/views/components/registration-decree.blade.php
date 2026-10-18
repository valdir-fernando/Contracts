@props(['label', 'tooltipId'])
<button type="button" class="document-action registration-decree" data-registration-decree data-document-state="model-pending" data-message="O modelo deste decreto ainda não foi definido. A geração estará disponível após a configuração do modelo." aria-label="Gerar {{ $label }}" aria-describedby="{{ $tooltipId }}" aria-disabled="true">
    <x-nav-icon name="document-extract"/><span>Decreto</span>
    <span class="document-action-tooltip" role="tooltip" id="{{ $tooltipId }}">O modelo deste decreto ainda não foi definido.</span>
</button>
