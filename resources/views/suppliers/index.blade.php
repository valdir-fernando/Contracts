<x-layouts.app title="Fornecedores">
    <div class="page-heading"><div><h1>Selecionar fornecedor</h1><p>Use um cadastro existente para preencher nome e documento de um novo contrato.</p></div></div>
    @if(auth()->user()->isAdmin())<p><a class="text-link" href="{{ route('catalogs.index') }}">Cadastrar fornecedor em órgãos e permissões</a></p>
    @else<p class="muted">A lista mostra fornecedores com CNPJ já associados a contratos dos seus órgãos. Dados de pessoas físicas permanecem protegidos.</p>@endif
    <section class="surface contract-section">
        <form method="GET"><label>Buscar por nome ou documento<input name="q" maxlength="255" value="{{ request('q') }}"></label><button class="secondary-button">Buscar fornecedor</button></form>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Nome</th><th>Documento</th><th>Ação</th></tr></thead><tbody>
            @forelse($suppliers as $supplier)<tr><td>{{ $supplier->name }}</td><td>{{ $supplier->document }}</td><td><a class="secondary-button" href="{{ route('contracts.create', ['supplier'=>$supplier->id]) }}">Usar no novo contrato</a></td></tr>
            @empty<tr><td colspan="3">Nenhum fornecedor disponível para esta busca.</td></tr>@endforelse
        </tbody></table></div>
        {{ $suppliers->links() }}
    </section>
    <p><a class="text-link" href="{{ route('contracts.create') }}">Preencher contrato manualmente</a></p>
</x-layouts.app>
