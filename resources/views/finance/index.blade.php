<x-layouts.app title="Dotação e empenhos">
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.show', $contract) }}">← Contrato {{ $contract->numero }}</a><h1>Dotação, fichas e empenhos</h1><p>Registros informados e conferidos, com vínculos explícitos ao contrato.</p></div></div>
    <p>
@foreach(\App\Support\FinancialFields::KINDS as $kind=>$label)<a class="secondary-button" href="{{ route('contracts.finance.create', ['contract'=>$contract, 'kind'=>$kind]) }}">Novo registro: {{ $label }}</a> @endforeach</p>
    <form method="GET" class="surface contract-section"><div class="field-grid"><label class="field">Tipo<select name="kind"><option value="">Todos</option>
@foreach(\App\Support\FinancialFields::KINDS as $kind=>$label)<option value="{{ $kind }}" @selected(($filters['kind']??'')===$kind)>{{ $label }}</option>
@endforeach</select></label><label class="field">Exercício<input type="number" name="year" min="1000" max="9999" value="{{ $filters['year']??'' }}"></label>
@if(auth()->user()->isAdmin())<label class="field">Lista<select name="deleted"><option value="0">Atuais</option><option value="1" @selected($filters['deleted']??false)>Excluídos</option></select></label>
@endif</div><button class="primary-button compact">Filtrar registros</button></form>
    <section class="surface contract-section"><p>Somas de {{ !empty($filters['deleted']) ? 'registros excluídos' : 'registros ativos' }} após conferência, nos filtros selecionados: valores por ficha {{ \App\Models\Contract::moneyLabel($totals['ficha']) }}; empenhos {{ \App\Models\Contract::moneyLabel($totals['empenho']) }}. São indicadores separados; não representam saldo disponível, pagamentos ou acréscimos ao valor do contrato.</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Tipo</th><th>Número / exercício</th><th>Data</th><th>Valor informado</th><th>Estado</th><th>Ações</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ \App\Support\FinancialFields::KINDS[$record->kind] }}</td><td>{{ $record->number }} / {{ $record->year }}</td><td>{{ $record->event_date->format('d/m/Y') }}</td><td>{{ \App\Models\Contract::moneyLabel($record->amount) }}</td><td>{{ \App\Models\ContractFinancialRecord::STATUSES[$record->status] }}</td><td>
@if($record->trashed())<form method="POST" action="{{ route('contracts.finance.restore', [$contract,$record->id]) }}">
@csrf<input type="hidden" name="version" value="{{ $record->version }}"><input type="hidden" name="contract_version" value="{{ $contract->version }}"><label class="field">Confirme {{ $record->number }}<input name="confirmation" required></label><label class="field">Motivo<input name="reason" minlength="5" maxlength="1000" required></label><button class="secondary-button">Restaurar registro</button></form>
@else<a class="text-link" href="{{ route('contracts.finance.show', [$contract,$record->id]) }}">Ver registro</a>
@endif</td></tr>
@empty<tr><td colspan="6">Nenhum registro financeiro nesta lista.</td></tr>
@endforelse</tbody></table></div>{{ $records->links() }}</section>
</x-layouts.app>
