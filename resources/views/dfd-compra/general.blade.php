<form id="dfd-general-form" method="POST" action="{{ $record ? route('dfd-compra.update', $record->ID) : route('dfd-compra.store') }}">
    @csrf
    @if($record) @method('PUT')<input type="hidden" name="version" value="{{ $value('version') }}"> @endif
    <input type="hidden" name="_dfd_id" value="{{ $record?->ID }}">
    <input type="hidden" name="Categoria" value="{{ $category }}">
    <fieldset class="dfd-fields" :disabled="!editing">
        <div class="dfd-section-grid">
            @foreach(['Necessidade' => '1. Justificativa da necessidade da contratação', 'Desricao' => '2. Descrição sucinta do objeto'] as $key => $label)
                <section class="surface contract-section"><h2><label for="{{ $key }}">{{ $label }}<x-required-mark/></label></h2><textarea id="{{ $key }}" name="{{ $key }}" rows="5" maxlength="15000" required>{{ $value($key) }}</textarea>@error($key)<p class="field-error">{{ $message }}</p>@enderror</section>
            @endforeach
            <section class="surface contract-section dfd-full"><h2>4. Informações gerais</h2>
                <div class="form-grid dfd-general-grid">
                    <div class="field"><label for="Data_Conclusao">Data prevista para conclusão do processo de contratação</label><input id="Data_Conclusao" name="Data_Conclusao" type="date" value="{{ $value('Data_Conclusao') }}"></div>
                    <div class="field"><label for="Prioridade">Grau de prioridade de compra / contratação</label><select id="Prioridade" name="Prioridade"><option value="">Selecione</option>@foreach(array_unique(array_filter(['Baixa', 'Média', 'Alta', $value('Prioridade')])) as $priority)<option @selected($value('Prioridade') === $priority)>{{ $priority }}</option>@endforeach</select></div>
                    <div class="field"><label for="Vigencia">Prazo de vigência da contratação</label><input id="Vigencia" name="Vigencia" value="{{ $value('Vigencia') }}" maxlength="15000" placeholder="Ex.: 12 meses"></div>
                    <div class="field"><label for="Local">Local da entrega / serviço</label><input id="Local" name="Local" value="{{ $value('Local') }}" maxlength="15000" list="dfd-delivery-locations"><datalist id="dfd-delivery-locations"><option value="{{ $fund->name }}">@foreach($departments as $department)<option value="{{ $department->name }}">@endforeach</datalist></div>
                    <div class="field dfd-address"><label for="End_Local">Endereço do local da entrega</label><input id="End_Local" name="End_Local" value="{{ $value('End_Local') }}" maxlength="15000"></div>
                </div>
            </section>
            <div class="dfd-section-stack">
                <section class="surface contract-section"><h2><label for="Vinculacao">5. Vinculação ou dependência com objeto de outra contratação</label></h2><textarea id="Vinculacao" name="Vinculacao" rows="2" maxlength="15000">{{ $value('Vinculacao') }}</textarea></section>
                <section class="surface contract-section"><h2><label for="Observacoes">7. Observações e requisitos necessários para a contratação</label></h2><textarea id="Observacoes" name="Observacoes" rows="3" maxlength="15000">{{ $value('Observacoes') }}</textarea></section>
            </div>
            <section class="surface contract-section"><h2>8. Área requisitante e responsáveis</h2><div class="form-grid">
                <div class="field field-wide"><label for="dfd-requester">Área requisitante — unidade / setor / departamento</label>
                    @if(\App\Services\Legacy\Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA')
                        <select id="dfd-requester" name="department_id"><option value="">{{ $fund->name }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) $value('department_id') === (string) $department->id)>{{ $department->name }}</option>@endforeach</select>
                    @else
                        <input id="dfd-requester" value="{{ $fund->name }}" readonly>
                    @endif
                </div>
                @foreach(['Responsavel' => 'Responsável pela demanda', 'Decreto' => 'Decreto', 'Cargo' => 'Cargo / Função', 'email' => 'E-mail'] as $key => $label)
                    <div @class(['field', 'field-wide' => in_array($key, ['Responsavel', 'email'])])><label for="{{ $key }}">{{ $label }}</label><input id="{{ $key }}" name="{{ $key }}" type="{{ $key === 'email' ? 'email' : 'text' }}" value="{{ $value($key) }}" maxlength="{{ $key === 'email' ? 255 : 15000 }}"></div>
                @endforeach
            </div></section>
            <section class="surface contract-section dfd-full" data-dfd-budget><h2>6. Dotação orçamentária</h2>
                <div class="field"><label for="dfd-budget">Ficha</label><select id="dfd-budget" :name="budgetChanged ? 'budget_id' : null" @change="budgetChanged = true" data-dfd-budget-select><option value="">{{ $value('Ficha') && !$budgetId ? 'Ficha salva: '.$value('Ficha').' (preservada até selecionar outra)' : 'Selecione uma ficha' }}</option>@foreach($budgets as $budget)<option value="{{ $budget->ID_Dotacao }}" @selected((string) $budgetId === (string) $budget->ID_Dotacao) data-budget="{{ json_encode((array) $budget, JSON_UNESCAPED_UNICODE) }}">{{ $budget->Ficha }} — {{ $budget->Descricao }}{{ $budget->Secretaria ? ' / '.$budget->Secretaria : '' }}</option>@endforeach</select></div>
                <div class="dfd-budget-grid">@foreach(['Orgao' => 'Órgão', 'Gestao' => 'Gestão', 'Unidade' => 'Unidade', 'Funcao' => 'Função', 'SubFunc' => 'Subfunção', 'Programa' => 'Programa', 'Projeto' => 'Projeto', 'Fonte' => 'Fonte', 'Codigo' => 'Código'] as $key => $label)<div class="field"><label for="dfd-budget-{{ $key }}">{{ $label }}</label><input id="dfd-budget-{{ $key }}" readonly data-dfd-budget-field="{{ $key }}"></div>@endforeach</div>
                <div class="field"><label for="dfd-budget-Dotacao">Dotação orçamentária</label><input id="dfd-budget-Dotacao" readonly data-dfd-budget-field="Dotacao"></div>
            </section>
        </div>
    </fieldset>
    <div class="form-actions sticky-actions action-toolbar">
        <button type="button" class="section-tab" @disabled(!$record) @if($record) @click="$refs.dfdDelete.showModal()" @endif><x-nav-icon name="trash"/>Excluir</button>
        <a class="section-tab" href="{{ route('dfd-compra.index') }}" data-dfd-class-open><x-nav-icon name="plus"/>Novo</a>
        <button type="button" class="section-tab" :disabled="{{ $record ? 'editing' : 'true' }}" @click="editing = true; $nextTick(() => document.getElementById('Necessidade').focus())"><x-nav-icon name="edit"/>Editar</button>
        <button type="submit" class="section-tab" :disabled="!editing || {{ $category === '' ? 'true' : 'false' }}" @disabled($category === '')><x-nav-icon name="save"/>Salvar</button>
    </div>
</form>
