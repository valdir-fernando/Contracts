@props(['contract', 'values' => [], 'editable' => false])
@php($supplier = $editable ? ($values['fornecedor_nome_snapshot'] ?? $contract->fornecedor_nome_snapshot) : $contract->fornecedor_nome_snapshot)
<p class="contract-heading-supplier" @if($editable) data-contract-summary-field="fornecedor_nome_snapshot" @endif><span class="sr-only">Fornecedor: </span>{{ filled($supplier) ? $supplier : 'Nome / Razão Social' }}</p>
