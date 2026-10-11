<x-layouts.app title="Registro financeiro">
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.finance.index',$contract) }}">← Dotação e empenhos</a><h1>{{ \App\Support\FinancialFields::KINDS[$record->kind] }} {{ $record->number }} / {{ $record->year }}</h1><p>Contrato {{ $contract->numero }} · ID {{ $record->id }} · versão {{ $record->version }} · {{ $record->event_date->format('d/m/Y') }}</p></div><span class="badge">{{ \App\Models\ContractFinancialRecord::STATUSES[$record->status] }}</span></div>
    @if(auth()->user()->isAdmin()||$record->status==='rascunho')<p><a class="secondary-button" href="{{ route('contracts.finance.edit',[$contract,$record->id]) }}">Editar registro financeiro</a></p>
@endif
    <section class="surface contract-section"><dl class="contract-fields">
@foreach(\App\Support\FinancialFields::MAP[$record->kind] as $field=>$definition)<div><dt>{{ $definition[0] }}</dt><dd>{{ $record->display($field,auth()->user()) }}</dd></div>
@endforeach</dl></section>
    <section class="surface contract-section"><h2>Associações explícitas</h2><p>Dotação: @if($budget){{ $budget->number }} / {{ $budget->year }} @if($budget->trashed())(excluída; referência histórica)
@else<a class="text-link" href="{{ route('contracts.finance.show',[$contract,$budget->id]) }}">Ver dotação</a>
@endif @else Não associada
@endif</p><p>Aditivo: @if($amendment){{ $amendment->number }} @if($amendment->trashed())(excluído; referência histórica)
@else<a class="text-link" href="{{ route('contracts.amendments.show',[$contract,$amendment->id]) }}">Ver aditivo</a>
@endif @else Não associado
@endif</p></section>
    @if(auth()->user()->isAdmin())<section class="surface contract-section"><h2>Excluir registro financeiro</h2><p>A exclusão preserva o registro e seus vínculos no histórico.</p><form method="POST" action="{{ route('contracts.finance.destroy',[$contract,$record->id]) }}">
@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $record->version }}"><input type="hidden" name="contract_version" value="{{ $contract->version }}"><div class="field-grid"><label class="field">Confirme o número {{ $record->number }}<input name="confirmation" required></label><label class="field">Motivo<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label></div><button class="danger-button">Excluir registro financeiro</button></form></section>
@endif
</x-layouts.app>
