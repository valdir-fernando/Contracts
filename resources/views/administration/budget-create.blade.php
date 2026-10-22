<x-dynamic-component :component="request()->header('Sec-Fetch-Dest') === 'iframe' ? 'layouts.budget-panel' : 'layouts.app'" title="Cadastro de Dotação">
    <div class="page-heading catalogs-page-heading"><div><h1>Cadastro de Dotação</h1></div></div>
    @if($errors->any())<div class="surface field-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <section class="surface contract-section budget-registration" aria-label="Cadastro de Dotação" data-budget-registration data-budget-list-url="{{ route('catalogs.budgets.create') }}" data-budget-lookup-url="{{ route('catalogs.budgets.lookup') }}" data-current-ficha="{{ $record?->Ficha }}" @if(session('budget_saved')) data-budget-saved @endif>
        <form id="budget-registration-form" data-enter-scope method="POST" action="{{ $record ? route('catalogs.budgets.update', $record->ID_Dotacao) : route('catalogs.budgets.store') }}">
            @csrf
            @if($record) @method('PUT') <input type="hidden" name="revision" value="{{ $revision }}"> @endif
            <div class="budget-organization-row">
                <label>Fundo<select name="fund_id" data-budget-fund required><option value="">Selecione</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" data-municipal="{{ \App\Services\Legacy\Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA' ? 'true' : 'false' }}" @selected((string) old('fund_id', $fundId) === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select></label>
                <div class="budget-department-row"><label>Secretaria<select name="department_id" data-budget-department @disabled(\App\Services\Legacy\Normalizer::name($funds->firstWhere('id', (int) old('fund_id', $fundId))?->name) !== 'MUNICIPIO DE NIQUELANDIA')><option value="">Sem Secretaria</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) old('department_id', $departmentId) === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></label><button type="button" class="product-item-search-button" data-budget-department-filter disabled aria-label="Filtrar dotações pela Secretaria" title="Filtrar dotações pela Secretaria" aria-pressed="false"><x-nav-icon name="filter"/></button></div>
            </div>
            <div class="budget-code-row">
                @foreach(['Ficha' => 'Ficha', 'Orgao' => 'Órgão', 'Gestao' => 'Gestão', 'Unidade' => 'Unidade', 'Funcao' => 'Função', 'SubFunc' => 'Sub-Função', 'Programa' => 'Programa', 'Projeto' => 'Projeto', 'Codigo' => 'Código', 'Fonte' => 'Fonte'] as $field => $label)
                    <label>{{ $label }}<input name="{{ $field }}" data-budget-part="{{ $field }}" value="{{ old($field, $record?->$field ?? ($field === 'Ficha' ? $initialFicha : '')) }}" inputmode="numeric" pattern="[0-9]+" maxlength="32" required></label>
                @endforeach
            </div>
            <label>Descrição<textarea name="Descricao" rows="1" maxlength="5000" required>{{ old('Descricao', $record?->Descricao) }}</textarea></label>
            <div class="budget-allocation-row">
                <label>Dotação Orçamentária<input data-budget-code readonly aria-readonly="true" value="{{ $record?->Dotacao }}"></label>
                <div class="form-actions action-toolbar budget-registration-actions">
                    <button type="submit" class="section-tab"><x-nav-icon name="save"/>Salvar</button>
                    <a class="section-tab" href="{{ route('catalogs.budgets.create') }}"><x-nav-icon name="plus"/>Novo</a>
                    <button type="button" class="section-tab" data-budget-delete @disabled(!$record)><x-nav-icon name="trash"/>Excluir</button>
                </div>
            </div>
        </form>
        @if($record)
            <form id="budget-delete-form" method="POST" action="{{ route('catalogs.budgets.destroy', $record->ID_Dotacao) }}">@csrf @method('DELETE')<input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="confirm_delete" value="1"></form>
            <dialog class="catalog-modal product-item-warning" data-budget-delete-dialog aria-labelledby="budget-delete-title"><div class="modal-header"><h2 id="budget-delete-title">Excluir dotação</h2></div><p>Confirma a exclusão da ficha {{ $record->Ficha }}?</p><div class="form-actions"><button class="section-tab" type="submit" form="budget-delete-form">Excluir</button><button class="section-tab" type="button" data-budget-delete-cancel>Cancelar</button></div></dialog>
        @endif
        <p class="hint" data-budget-lookup-status role="status" aria-live="polite"></p>
        <dialog class="catalog-modal product-item-warning" data-budget-scope-warning aria-labelledby="budget-scope-warning-title" aria-describedby="budget-scope-warning-message">
            <div class="modal-header"><h2 id="budget-scope-warning-title">Consulta de ficha</h2></div>
            <p id="budget-scope-warning-message">A ficha pertence a outro Fundo ou Secretaria. Confira os dados exibidos.</p>
            <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-budget-scope-warning-ok autofocus>OK</button></div>
        </dialog>
        <p class="hint" data-budget-filter-status role="status" aria-live="polite"></p>
        <div data-budget-list>
            @include('administration.budget-list')
        </div>
    </section>
</x-dynamic-component>
