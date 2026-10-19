<x-layouts.app title="Dados do fornecedor">
    <div class="page-heading"><div><a class="text-link" href="{{ route('suppliers.index') }}">← Fornecedores</a><h1>{{ $supplier->name }}</h1><p>Dados cadastrais e contratos acessíveis associados ao fornecedor.</p></div><div class="action-toolbar form-actions">@can('create', \App\Models\Contract::class)<a class="primary-button compact" href="{{ route('contracts.create', ['supplier' => $supplier->id]) }}">Usar no novo contrato</a>@endcan @if($canEditSupplier)<button type="button" class="secondary-button" data-modal-open="catalog-supplier">Editar fornecedor</button>@endif</div></div>
    <section class="surface contract-section"><h2>Dados do fornecedor</h2><dl class="contract-fields"><div><dt>Nome</dt><dd>{{ $supplier->name }}</dd></div><div><dt>CPF/CNPJ</dt><dd>{{ $supplier->document ?: 'Não informado' }}</dd></div></dl></section>
    <x-related-contracts :contracts="$contracts"/>
    @if($canEditSupplier)<x-catalog-modal kind="supplier" :supplier-id="$supplier->id" :values="$supplierValues" :revision="$supplierRevision"/>@endif
</x-layouts.app>
