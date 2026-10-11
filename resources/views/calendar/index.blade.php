<x-layouts.app title="Calendário e alertas">
    <div class="page-heading"><div><h1>Calendário de vencimentos</h1><p>Datas conhecidas dos contratos dos seus órgãos. Referência: {{ today()->format('d/m/Y') }}.</p></div></div>
    <section class="surface contract-section">
        <form method="GET" action="{{ route('calendar.index') }}">
            <label>Mês<input type="month" name="month" value="{{ $month->format('Y-m') }}" min="1900-01" max="2199-12" required></label>
            <button class="secondary-button">Consultar mês</button>
        </form>
        <p>{{ $contracts->total() }} vencimentos em {{ $month->format('m/Y') }}. {{ $reviewCount }} contratos em revisão e {{ $unknownCount }} com vigência indeterminada ficam fora do calendário.</p>
        <p class="muted">Situação temporal; consulte a classificação administrativa e os documentos antes de tomar decisões jurídicas.</p>
        <div class="table-scroll"><table class="data-table"><caption>Vencimentos por dia — {{ $month->format('m/Y') }}</caption><thead><tr>@foreach(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $weekday)<th scope="col">{{ $weekday }}</th>@endforeach</tr></thead><tbody>
            @for($cell = 0, $offset = $month->dayOfWeekIso - 1, $length = (int) ceil(($offset + $month->daysInMonth) / 7) * 7; $cell < $length; $cell++)
                @if($cell % 7 === 0)<tr>@endif
                @php($day = $cell - $offset + 1)
                <td>@if($day > 0 && $day <= $month->daysInMonth)
                    @php($date = $month->day($day))
                    <strong>{{ $day }}</strong>
                    @if($counts[$date->toDateString()] ?? 0)<p>{{ $counts[$date->toDateString()] }} contratos</p>@endif
                @endif</td>
                @if($cell % 7 === 6)</tr>@endif
            @endfor
        </tbody></table></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Vencimento</th><th>Contrato</th><th>Fornecedor</th><th>Órgão</th><th>Situação administrativa</th></tr></thead><tbody>
            @forelse($contracts as $contract)<tr><td>{{ $contract->end_date->format('d/m/Y') }}</td><td><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero ?: 'Sem número' }}</a></td><td>{{ $contract->fornecedor_nome_snapshot }}</td><td>{{ $contract->fundo_nome_snapshot }}</td><td>{{ \App\Models\Contract::STATUSES[$contract->administrative_status] ?? 'Não classificada' }}</td></tr>
            @empty<tr><td colspan="5">Nenhum vencimento conhecido neste mês.</td></tr>@endforelse
        </tbody></table></div>
        {{ $contracts->links() }}
    </section>
    <section class="surface contract-section">
        <h2>Meus alertas de vencimento</h2>
        <p>Os avisos aparecem no painel inicial, do dia de hoje até a antecedência escolhida. O escopo e as datas são recalculados ao abrir o painel.</p>
        <form method="POST" action="{{ route('calendar.preferences') }}">
            @csrf @method('PUT')
            <label>Exibir avisos<select name="enabled"><option value="1" @selected($user->expiration_alert_enabled)>Sim</option><option value="0" @selected(!$user->expiration_alert_enabled)>Não</option></select></label>
            <label>Antecedência em dias<input name="days" type="number" min="1" max="365" value="{{ old('days', $user->expiration_alert_days) }}" required></label>
            <button class="secondary-button">Salvar preferências</button>
        </form>
    </section>
</x-layouts.app>
