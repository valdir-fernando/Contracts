@props(['contract', 'values' => [], 'editable' => false, 'heading' => 'Novo contrato'])
@php
    $supplier = $editable ? ($values['fornecedor_nome_snapshot'] ?? $contract->fornecedor_nome_snapshot) : $contract->fornecedor_nome_snapshot;
@endphp
<div class="contract-heading-title" aria-label="Resumo do contrato e fornecedor">
        <h1>{{ $heading }}@if($contract->exists) <strong class="contract-number-highlight">{{ $contract->numero ?: 'Sem número' }}</strong>@endif</h1>
        <span class="sr-only">Fornecedor</span>
        <p class="contract-heading-supplier" @if($editable) data-contract-summary-field="fornecedor_nome_snapshot" @endif>{{ filled($supplier) ? $supplier : 'Não informado' }}</p>
</div>
