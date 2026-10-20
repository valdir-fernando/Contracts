@props(['contract'])
@php
    $historical = $contract->exists && filled($contract->tipo) && !in_array($contract->tipo, \App\Support\ContractFields::CONTRACT_TYPES, true);
    $suggestion = \App\Support\ContractFields::TYPE_CORRECTIONS[$contract->tipo ?? ''] ?? null;
@endphp
<button type="button" class="field-info-button" aria-label="Entenda o tipo de contrato e os valores históricos" aria-describedby="contract-type-help" popovertarget="contract-type-help" data-field-help="contract-type-help"><span aria-hidden="true">i</span></button>
<div id="contract-type-help" class="field-help-popover" popover>
    <p>“Histórico” identifica um valor salvo que não corresponde às opções atuais. Ele é preservado durante a edição para evitar alterações involuntárias. Não significa que o contrato esteja encerrado.</p>
    @if($historical)
        <p>Valor salvo: <strong>{{ $contract->tipo }}</strong>.</p>
        @if($suggestion)
            <p>Correção de grafia sugerida: <strong>{{ $suggestion }}</strong>. Aceitar altera o campo nesta ficha; a correção será gravada somente ao salvar o contrato.</p>
            <button type="button" class="text-link" data-contract-type-correction="{{ $suggestion }}" data-original-type="{{ $contract->tipo }}">Aceitar correção</button>
            <button type="button" class="text-link" data-contract-type-keep>Manter valor histórico</button>
            <p data-contract-type-status role="status" aria-live="polite"></p>
        @else
            <p>Não há correção automática confirmada para esse valor. Você pode mantê-lo ou selecionar o tipo adequado e salvar o contrato.</p>
        @endif
    @endif
</div>
