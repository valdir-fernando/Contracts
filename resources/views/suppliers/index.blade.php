<x-layouts.app title="Fornecedores">
    <div class="page-heading suppliers-page-heading"><div><span class="section-label">Cadastros</span><h1>Fornecedores</h1><p>Consulte fornecedores e use seus dados ao preparar um novo contrato.</p></div>
        @if(auth()->user()->isAdmin())<button class="primary-button" type="button" data-modal-open="catalog-supplier"><x-nav-icon name="plus"/>Novo fornecedor</button>@endif
    </div>
    @unless(auth()->user()->isAdmin())<p class="muted">A lista mostra fornecedores com CNPJ já associados a contratos dos seus órgãos. Dados de pessoas físicas permanecem protegidos.</p>@endunless
    <section class="surface contract-section suppliers-list-surface">
        <form method="GET" class="inline-search"><label>Buscar por nome ou documento<input name="q" maxlength="255" value="{{ request('q') }}"></label><button class="secondary-button">Buscar fornecedor</button></form>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Nome</th><th>Documento</th><th>Ação</th></tr></thead><tbody>
            @forelse($suppliers as $supplier)<tr data-row-href="{{ route('suppliers.show', $supplier->id) }}"><td><a class="text-link" href="{{ route('suppliers.show', $supplier->id) }}">{{ $supplier->name }}</a></td><td>{{ $supplier->document }}</td><td><a class="secondary-button" href="{{ route('contracts.create', ['supplier'=>$supplier->id]) }}">Usar no novo contrato</a></td></tr>
            @empty<tr><td colspan="3">Nenhum fornecedor disponível para esta busca.</td></tr>@endforelse
        </tbody></table></div>
        {{ $suppliers->links() }}
    </section>
    <p><a class="text-link" href="{{ route('contracts.create') }}">Preencher contrato manualmente</a></p>
    @if(auth()->user()->isAdmin())<x-catalog-modal kind="supplier" return-to="suppliers.index"/>@endif
</x-layouts.app>
