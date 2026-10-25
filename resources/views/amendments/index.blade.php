<x-layouts.app title="Aditivos do contrato">
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.show', $contract) }}">← Contrato {{ $contract->numero }}</a><h1 class="amendment-supplier-heading"><span>{{ $contract->fornecedor_nome_snapshot ?: 'Fornecedor não informado' }}</span><span class="amendment-supplier-separator" aria-hidden="true"></span><span class="amendment-supplier-document">{{ $contract->displayField('CPF_CNPJ', auth()->user()) }}</span></h1></div><a class="primary-button compact" href="{{ route('contracts.amendments.create', $contract) }}"><x-nav-icon name="plus"/>Novo aditivo</a></div>
    @include('amendments.list')
</x-layouts.app>
