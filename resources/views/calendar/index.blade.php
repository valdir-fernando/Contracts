<x-layouts.app title="Calendário e alertas">
    <div class="page-heading"><div><h1>Calendário de vencimentos</h1><p>Datas conhecidas dos contratos dos seus órgãos. Referência: {{ today()->format('d/m/Y') }}.</p></div></div>
    <section class="surface contract-section calendar-panel">
        <div class="calendar-month-toolbar">
            <div class="calendar-month-heading"><span class="catalog-icon"><x-nav-icon name="calendar"/></span><div><span class="section-label">Agenda de contratos</span><h2>{{ $month->translatedFormat('F \d\e Y') }}</h2></div></div>
            <nav class="calendar-month-nav" aria-label="Navegar entre meses">
                @if($month->format('Y-m') > '1900-01')<a class="secondary-button" href="{{ route('calendar.index', ['month' => $month->subMonth()->format('Y-m')]) }}" aria-label="Mês anterior"><x-nav-icon name="chevron-left"/></a>@endif
                <a class="secondary-button calendar-current-month" href="{{ route('calendar.index') }}">Este mês</a>
                @if($month->format('Y-m') < '2199-12')<a class="secondary-button" href="{{ route('calendar.index', ['month' => $month->addMonth()->format('Y-m')]) }}" aria-label="Próximo mês"><span class="calendar-next"><x-nav-icon name="chevron-left"/></span></a>@endif
            </nav>
        </div>
        <form class="calendar-filter" method="GET" action="{{ route('calendar.index') }}">
            <label><span class="field-caption">Mês <x-required-mark/></span><select name="month_number" required>@foreach(\App\Support\BrazilianLocale::MONTHS as $number => $name)<option value="{{ $number }}" @selected($month->month === $number)>{{ $name }}</option>@endforeach</select></label><label><span class="field-caption">Ano <x-required-mark/></span><input type="number" name="calendar_year" value="{{ $month->year }}" min="1900" max="2199" required></label>
            <button class="secondary-button">Consultar mês</button>
        </form>
        <p class="calendar-month-summary"><span class="calendar-summary-icon"><x-nav-icon name="clock"/></span><span>{{ number_format($contracts->total(), 0, ',', '.') }} {{ $contracts->total() === 1 ? 'vencimento' : 'vencimentos' }} em {{ $month->format('m/Y') }}. {{ number_format($reviewCount, 0, ',', '.') }} {{ $reviewCount === 1 ? 'contrato em revisão' : 'contratos em revisão' }} e {{ $unknownCount }} com vigência indeterminada ficam fora do calendário.</span></p>
        <p class="muted">Situação temporal; consulte a classificação administrativa e os documentos antes de tomar decisões jurídicas.</p>
        <div class="calendar-legend" aria-label="Legenda do calendário"><span><i class="calendar-key key-today" aria-hidden="true"></i>Hoje</span><span><i class="calendar-key key-past" aria-hidden="true"></i>Data passada</span><span><i class="calendar-key key-soon" aria-hidden="true"></i>Próximos 7 dias</span><span><i class="calendar-key key-upcoming" aria-hidden="true"></i>Próximos vencimentos</span></div>
        <div class="table-scroll"><table class="data-table calendar-grid"><caption>Vencimentos por dia — {{ $month->format('m/Y') }}</caption><thead><tr>@foreach(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $weekday)<th scope="col">{{ $weekday }}</th>@endforeach</tr></thead><tbody>
            @for($cell = 0, $offset = $month->dayOfWeekIso - 1, $length = (int) ceil(($offset + $month->daysInMonth) / 7) * 7; $cell < $length; $cell++)
                @if($cell % 7 === 0)<tr>@endif
                @php
                    $day = $cell - $offset + 1;
                    $date = $day > 0 && $day <= $month->daysInMonth ? $month->day($day) : null;
                    $dayCount = $date ? ($counts[$date->toDateString()] ?? 0) : 0;
                @endphp
                <td @class(['outside' => !$date, 'today' => $date?->isToday(), 'weekend' => $cell % 7 >= 5,
                    'has-events' => $dayCount > 0, 'past-events' => $dayCount && $date->lt(today()),
                    'soon-events' => $dayCount && $date->betweenIncluded(today(), today()->addDays(7))])>
                    @if($date)
                        <strong @if($date->isToday()) aria-label="Hoje, {{ $date->format('d/m/Y') }}" @endif>{{ $day }}</strong>
                        @if($dayCount)<p class="calendar-event-count" aria-label="{{ $dayCount }} {{ $dayCount === 1 ? 'contrato vence' : 'contratos vencem' }} em {{ $date->format('d/m/Y') }}"><span>{{ $dayCount }}</span><span class="calendar-event-word">{{ $dayCount === 1 ? 'contrato' : 'contratos' }}</span></p>@endif
                    @endif
                </td>
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
        <form class="calendar-preferences" method="POST" action="{{ route('calendar.preferences') }}">
            @csrf @method('PUT')
            <label>Exibir avisos<select name="enabled"><option value="1" @selected($user->expiration_alert_enabled)>Sim</option><option value="0" @selected(!$user->expiration_alert_enabled)>Não</option></select></label>
            <label><span class="field-caption">Antecedência em dias <x-required-mark/></span><input name="days" type="number" min="1" max="365" value="{{ old('days', $user->expiration_alert_days) }}" required></label>
            <button class="secondary-button">Salvar preferências</button>
        </form>
    </section>
</x-layouts.app>
