<x-layouts.app title="Registro financeiro">
    @php($read = fn($key,$default=null) => old($key, data_get($values,$key,$default)))
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.finance.index',$contract) }}">← Dotação e empenhos</a><h1>{{ $record->exists ? 'Editar' : 'Novo' }} registro: {{ \App\Support\FinancialFields::KINDS[$record->kind] }}</h1><p>Contrato {{ $contract->numero }}. Os valores e os saldos do contrato são preservados.</p></div></div>
    @if($conflict)<div class="notice notice-warning" role="alert">O contrato ou o registro mudou. Seus dados estão abaixo para consulta. <a href="{{ $record->exists ? route('contracts.finance.edit',[$contract,$record->id]) : route('contracts.finance.create',['contract'=>$contract,'kind'=>$record->kind]) }}">Recarregar dados atuais</a></div>
@endif
    <form method="POST" action="{{ $record->exists ? route('contracts.finance.update',[$contract,$record->id]) : route('contracts.finance.store',$contract) }}" class="surface contract-section">
@csrf @if($record->exists)
@method('PUT')<input type="hidden" name="version" value="{{ $read('version',$record->version) }}">
@endif<input type="hidden" name="contract_version" value="{{ $read('contract_version',$contract->version) }}"><input type="hidden" name="kind" value="{{ $record->kind }}">
        <div class="field-grid"><label class="field"><span class="field-caption">Identificador do registro <x-required-mark/></span><input name="number" maxlength="64" value="{{ $read('number',$record->number) }}" required></label><label class="field"><span class="field-caption">Exercício <x-required-mark/></span><input name="year" type="number" min="1000" max="9999" value="{{ $read('year',$record->year) }}" required></label><label class="field"><span class="field-caption">Data do registro <x-required-mark/></span><input name="event_date" type="date" value="{{ $read('event_date',$record->event_date?->format('Y-m-d')) }}" required></label>
        @foreach(\App\Support\FinancialFields::MAP[$record->kind] as $field=>$definition)<label @class(['field', 'field-wide' => $definition[1] === 'textarea'])><span class="field-caption">{{ $definition[0] }} <x-required-mark :when="in_array($field,\App\Support\FinancialFields::REQUIRED[$record->kind])"/></span>
            @if(str_starts_with($definition[1],'readonly'))<input value="{{ $record->display($field,auth()->user()) }}" readonly>
            @elseif($definition[1]==='textarea')<textarea name="fields[{{ $field }}]" maxlength="15000" @required(in_array($field,\App\Support\FinancialFields::REQUIRED[$record->kind]))>{{ $read('fields.'.$field,$record->payload[$field]??'') }}</textarea>
            @else<input name="fields[{{ $field }}]" value="{{ $read('fields.'.$field,$record->payload[$field]??'') }}" maxlength="15000" @if($definition[1]==='money')inputmode="decimal"
@endif @required(in_array($field,\App\Support\FinancialFields::REQUIRED[$record->kind]))>
@endif
        </label>
@endforeach
        @if($record->kind!=='dotacao')<label class="field">Dotação associada<select name="budget_id"><option value="">Sem associação</option>
@if($record->budget_id&&!$budgets->contains('id',$record->budget_id))<option value="{{ $record->budget_id }}" @selected((string)$read('budget_id',$record->budget_id)===(string)$record->budget_id)>Referência histórica #{{ $record->budget_id }} (excluída)</option>
@endif @foreach($budgets as $budget)<option value="{{ $budget->id }}" @selected((string)$read('budget_id',$record->budget_id)===(string)$budget->id)>{{ $budget->number }} / {{ $budget->year }} — ficha {{ $budget->payload['Ficha']??'' }}</option>
@endforeach</select></label>
@endif
        <label class="field">Aditivo associado<select name="amendment_id"><option value="">Sem associação</option>
@if($record->amendment_id&&!$amendments->contains('id',$record->amendment_id))<option value="{{ $record->amendment_id }}" @selected((string)$read('amendment_id',$record->amendment_id)===(string)$record->amendment_id)>Referência histórica #{{ $record->amendment_id }} (excluída)</option>
@endif @foreach($amendments as $amendment)<option value="{{ $amendment->id }}" @selected((string)$read('amendment_id',$record->amendment_id)===(string)$amendment->id)>{{ $amendment->number }}</option>
@endforeach</select></label>
        @if(auth()->user()->isAdmin())<label class="field">Estado<select name="status">
@foreach(\App\Models\ContractFinancialRecord::STATUSES as $status=>$label)<option value="{{ $status }}" @selected($read('status',$record->status)===$status)>{{ $label }}</option>
@endforeach</select></label>
@else<p>Usuários preparam rascunhos; o administrador registra após conferência.</p>
@endif
        <label class="field field-wide"><span class="field-caption">Justificativa <x-required-mark/></span><textarea name="reason" minlength="5" maxlength="1000" required>{{ $read('reason') }}</textarea></label></div><div class="form-actions"><a class="secondary-button" href="{{ route('contracts.finance.index', $contract) }}">Cancelar</a><button class="primary-button compact" @disabled($conflict)>Salvar registro financeiro</button></div>
    </form>
</x-layouts.app>
