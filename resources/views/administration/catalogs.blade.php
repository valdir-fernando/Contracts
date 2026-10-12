<x-layouts.app title="Órgãos e permissões">
    <div class="page-heading"><div><span class="section-label">Cadastros e acesso</span><h1>Órgãos e permissões</h1><p>Organize fundos e órgãos e defina os escopos de acesso aos contratos.</p></div></div>
    <div class="catalog-grid">
        <section class="surface catalog-section" aria-labelledby="funds-title">
            <div class="panel-header"><div><span class="catalog-icon"><x-nav-icon name="catalogs"/></span><h2 id="funds-title">Fundos <span class="catalog-count">{{ $funds->count() }}</span></h2><p>Responsáveis pelos contratos e seus recursos.</p></div><button class="primary-button" type="button" data-modal-open="catalog-fund"><x-nav-icon name="plus"/>Novo fundo</button></div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Nome do fundo</th><th>CPF/CNPJ</th></tr></thead><tbody>
                @forelse($funds as $fund)<tr data-row-href="{{ route('catalogs.show', ['fund', $fund->id]) }}"><td><a class="text-link" href="{{ route('catalogs.show', ['fund', $fund->id]) }}"><strong>{{ $fund->name }}</strong></a></td><td>{{ $fund->document ?: 'Não informado' }}</td></tr>
                @empty<tr><td colspan="2" class="empty-state">Nenhum fundo cadastrado. Adicione o primeiro para organizar os contratos.</td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section class="surface catalog-section" aria-labelledby="departments-title">
            <div class="panel-header"><div><span class="catalog-icon"><x-nav-icon name="suppliers"/></span><h2 id="departments-title">Órgãos e secretarias <span class="catalog-count">{{ $departments->count() }}</span></h2><p>Unidades que participam da gestão dos contratos.</p></div><button class="primary-button" type="button" data-modal-open="catalog-department"><x-nav-icon name="plus"/>Novo órgão</button></div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Nome do órgão ou secretaria</th></tr></thead><tbody>
                @forelse($departments as $department)<tr data-row-href="{{ route('catalogs.show', ['department', $department->id]) }}"><td><a class="text-link" href="{{ route('catalogs.show', ['department', $department->id]) }}"><strong>{{ $department->name }}</strong></a></td></tr>
                @empty<tr><td class="empty-state">Nenhum órgão cadastrado. Adicione uma secretaria ou unidade.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>
    <section class="surface contract-section catalog-supplier-section">
        <div class="panel-header"><div><h2>Fornecedores</h2><p>Cadastros de pessoas e empresas que fornecem bens e serviços.</p></div><button class="primary-button" type="button" data-modal-open="catalog-supplier"><x-nav-icon name="plus"/>Novo fornecedor</button></div>
        <a class="text-link" href="{{ route('suppliers.index') }}">Consultar fornecedores <span aria-hidden="true">→</span></a>
    </section>
    <section class="surface contract-section">
        <div class="panel-header"><div><h2>Escopos de acesso</h2><p>Combine um fundo e um órgão; depois atribua o escopo na gestão de usuários.</p></div></div>
        <form method="POST" action="{{ route('catalogs.store') }}" class="form-grid">
            @csrf<input type="hidden" name="kind" value="scope">
            <label><span class="field-caption">Fundo <x-required-mark/></span><select name="fund_id" required><option value="">Selecione</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected(old('kind') === 'scope' && (string) old('fund_id') === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select></label>
            <label>Órgão ou secretaria<select name="department_id"><option value="">Todos os órgãos do fundo</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('kind') === 'scope' && (string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></label>
            <button class="secondary-button">Criar escopo</button>
        </form>
        <div class="catalog-scopes">@forelse($scopes as $scope)<span class="badge badge-muted">{{ $scope->label }}</span>@empty<p class="muted">Nenhum escopo criado.</p>@endforelse</div>
    </section>
    <x-catalog-modal kind="fund"/>
    <x-catalog-modal kind="department"/>
    <x-catalog-modal kind="supplier"/>
</x-layouts.app>
