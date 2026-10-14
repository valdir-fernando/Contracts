<div>
    @php
        $current = collect($metrics['validities'])->firstWhere('key', 'vigente')['count'] ?? 0;
        $series = [
            'vigente' => '#10b981', 'vencido' => '#f43f5e', 'em_revisao' => '#f59e0b',
            'a_iniciar' => '#0ea5e9', 'indeterminado' => '#94a3b8',
        ];
        $stops = [];
        $offset = 0;
        foreach ($series as $key => $color) {
            $value = collect($metrics['validities'])->firstWhere('key', $key)['count'] ?? 0;
            $next = $offset + ($count ? $value / $count * 100 : 0);
            $stops[] = $color.' '.$offset.'% '.$next.'%';
            $offset = $next;
        }
        $donut = $count ? 'conic-gradient('.implode(', ', $stops).')' : '#e5ebf4';
        $maxMonth = max(1, collect($metrics['months'])->max('count'));
    @endphp
    @unless($hasSelectedFund)<p class="notice" role="status">Selecione um Fundo acima para visualizar seus contratos e indicadores.</p>@endunless
    @if($hasSelectedFund)
    <div class="overview-year-filter">
        <div class="field"><label for="overview-year">Ano dos contratos</label><select id="overview-year" wire:model.live="year">
            @foreach($years as $option)<option value="{{ $option }}">{{ $option }}{{ $option === $currentYear ? ' (ano atual)' : '' }}</option>@endforeach
            <option value="all">Todos os anos</option>
        </select></div>
        <p class="hint">{{ $year === 'all' ? 'Todos os exercícios, incluindo contratos sem ano informado.' : 'Indicadores e contratos do exercício '.$year.'.' }}</p>
        <span class="hint" wire:loading wire:target="year" role="status">Atualizando o ano…</span>
        @error('year')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="overview-kpis" aria-label="Indicadores do Fundo selecionado">
        <a class="kpi-card" data-kpi="all" href="{{ route('contracts.index', $yearFilters) }}" aria-label="Ver todos os {{ $count }} contratos cadastrados"><span>Contratos cadastrados</span><div class="kpi-icon"><x-nav-icon name="contracts"/></div><strong data-count-up="{{ $count }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($count, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($count, 0, ',', '.') }}</span></strong><small>No seu escopo atual <x-nav-icon name="arrow"/></small></a>
        <a class="kpi-card kpi-success" data-kpi="current" href="{{ route('contracts.index', $yearFilters + ['validity' => 'vigente']) }}" aria-label="Ver {{ $current }} contratos vigentes"><span>Vigentes</span><div class="kpi-icon"><x-nav-icon name="check"/></div><strong data-count-up="{{ $current }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($current, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($current, 0, ',', '.') }}</span></strong><small>Com vigência conhecida <x-nav-icon name="arrow"/></small></a>
        <a class="kpi-card kpi-danger" data-kpi="expired" href="{{ route('contracts.index', $yearFilters + ['validity' => 'vencido']) }}" aria-label="Ver {{ $expired }} contratos vencidos"><span>Vencidos</span><div class="kpi-icon"><x-nav-icon name="clock"/></div><strong data-count-up="{{ $expired }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($expired, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($expired, 0, ',', '.') }}</span></strong><small>Em {{ today()->format('d/m/Y') }} <x-nav-icon name="arrow"/></small></a>
        <a class="kpi-card kpi-warning" data-kpi="review" href="{{ route('contracts.index', $yearFilters + ['review' => 1]) }}" aria-label="Ver {{ $review }} contratos em revisão"><span>Em revisão</span><div class="kpi-icon"><x-nav-icon name="warning"/></div><strong data-count-up="{{ $review }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($review, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($review, 0, ',', '.') }}</span></strong><small>Vigências ou órgãos <x-nav-icon name="arrow"/></small></a>
    </div>

    <div class="section-heading"><h2>Distribuição e vencimentos</h2><span>Indicadores temporais do Fundo selecionado</span></div>
    <div class="overview-grid">
        <section class="surface contract-section">
            <header class="panel-header"><div><h2>Situação temporal</h2><p>Distribuição dos contratos por vigência</p></div><x-nav-icon name="reports"/></header>
            <div class="validity-chart">
                <div class="donut-chart" style="--donut-fill: {{ $donut }}" role="img" aria-label="Distribuição temporal de {{ $count }} contratos, detalhada na legenda"><div class="donut-center"><strong>{{ number_format($count, 0, ',', '.') }}</strong><span>contratos</span></div></div>
                <ul class="chart-legend">
                    @foreach($series as $key => $color)
                        @php($metric = collect($metrics['validities'])->firstWhere('key', $key))
                        <li><span class="chart-dot" style="--series-color: {{ $color }}" aria-hidden="true"></span><span>{{ $metric['label'] }}</span><strong>{{ number_format($metric['count'], 0, ',', '.') }}</strong></li>
                    @endforeach
                </ul>
            </div>
            <p class="chart-note">Indicadores temporais; não certificam situação jurídica.</p>
        </section>
        <section class="surface contract-section">
            <header class="panel-header"><div><h2>Próximos vencimentos</h2><p>Términos conhecidos nos próximos seis meses</p></div><a class="text-link" href="{{ route('calendar.index') }}">Ver calendário →</a></header>
            <div class="month-chart" aria-label="Términos conhecidos por mês">
                @foreach($metrics['months'] as $metric)
                    <div class="month-column" aria-label="{{ $metric['label'] }}: {{ $metric['count'] }} contratos"><strong class="month-count">{{ $metric['count'] }}</strong><div class="month-track" aria-hidden="true"><div class="month-bar" style="--bar-height: {{ $metric['count'] / $maxMonth * 100 }}%; --chart-delay: {{ $loop->index * 65 }}ms"></div></div><span>{{ $metric['label'] }}</span></div>
                @endforeach
            </div>
            <div class="portfolio-summary"><span>Valor inicial dos contratos do Fundo</span><strong>{{ \App\Models\Contract::moneyLabel($metrics['summary']['total']) }}</strong></div>
            <p class="chart-note">{{ $metrics['summary']['missing'] }} sem valor inicial informado. Vigências em revisão ou indeterminadas ficam fora dos vencimentos.</p>
        </section>
    </div>

    <div class="overview-grid">
        <section class="surface contract-section">
            <header class="panel-header"><div><h2>Contratos do Fundo</h2><p>Distribuição dos contratos no Fundo selecionado</p></div><x-nav-icon name="catalogs"/></header>
            <div class="metrics-bars">
                @forelse($metrics['funds'] as $metric)
                    <div class="metric-bar"><span title="{{ $metric['label'] }}">{{ $metric['label'] }}</span><meter style="--chart-delay: {{ $loop->index * 55 }}ms" min="0" max="{{ max(1, collect($metrics['funds'])->max('count')) }}" value="{{ $metric['count'] }}" aria-label="{{ $metric['label'] }}: {{ $metric['count'] }} contratos">{{ $metric['count'] }}</meter><strong>{{ $metric['count'] }}</strong></div>
                @empty<div class="empty-state">Nenhum contrato nos seus órgãos autorizados.</div>@endforelse
            </div>
        </section>
        <section class="surface contract-section">
            <header class="panel-header"><div><h2>{{ $alertsEnabled ? 'Alertas de vencimento' : 'Acompanhe os prazos' }}</h2><p>{{ $alertsEnabled ? 'Antecedência de '.$alertDays.' dias' : 'Avisos desativados na sua conta' }}</p></div><a class="text-link" href="{{ route('calendar.index') }}">Configurar →</a></header>
            @if($alertsEnabled)
                <p class="chart-note">{{ $alertCount }} contratos vencem entre hoje e os próximos {{ $alertDays }} dias.</p>
                @forelse($alerts as $contract)
                    <div class="alert-row"><span class="alert-date"><strong>{{ $contract->end_date->format('d') }}</strong><span>{{ $contract->end_date->translatedFormat('M') }}</span></span><div><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero ?: 'Sem número' }}</a><small>{{ $contract->fornecedor_nome_snapshot }}</small></div><span class="badge badge-warning">{{ $contract->end_date->format('d/m/Y') }}</span></div>
                @empty<div class="alert-empty"><span><x-nav-icon name="check"/></span>Nenhum vencimento conhecido neste intervalo.</div>@endforelse
            @else<div class="alert-empty"><span><x-nav-icon name="calendar"/></span>Ative os avisos para acompanhar os próximos prazos.</div>@endif
        </section>
    </div>

    <section class="surface recent-panel">
        <header class="panel-header"><div><h2>Contratos recentes</h2><p>Últimos cadastros do Fundo selecionado</p></div><a class="text-link" href="{{ route('contracts.index', $yearFilters) }}">Ver todos →</a></header>
        <div class="recent-toolbar"><label class="search-field" for="overview-search"><span class="sr-only">Buscar contrato, processo ou fornecedor</span><x-nav-icon name="search"/><input id="overview-search" wire:model.live.debounce.300ms="search" maxlength="255" placeholder="Buscar contrato, processo ou fornecedor…"></label><span class="hint" wire:loading role="status">Atualizando…</span></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Contrato / processo</th><th>Fornecedor</th><th>Vigência</th><th>Término</th><th class="money-cell">Valor inicial</th></tr></thead><tbody>
            @forelse($recent as $contract)
                <tr wire:key="contract-{{ $contract->id }}" data-row-href="{{ route('contracts.show', $contract) }}"><td><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero ?: 'Sem número' }}</a><small>{{ $contract->processo ?: 'Processo não informado' }}</small></td><td class="scope-cell">{{ $contract->fornecedor_nome_snapshot ?: 'Não informado' }}</td><td><span @class(['badge', 'badge-success' => $contract->validity === 'vigente', 'badge-warning' => in_array($contract->validity, ['em_revisao', 'indeterminado']), 'badge-muted' => $contract->validity === 'vencido'])>{{ \App\Models\Contract::VALIDITY[$contract->validity] }}</span></td><td>{{ $contract->end_date?->format('d/m/Y') ?? 'Não informado' }}</td><td class="money-cell">{{ \App\Models\Contract::moneyLabel($contract->valor_total) }}</td></tr>
            @empty<tr><td colspan="5" class="empty-state"><x-nav-icon name="search"/><p>Nenhum contrato encontrado para esta busca.</p></td></tr>@endforelse
        </tbody></table></div>
        <div class="table-footer">{{ $recent->links('components.pagination', ['livewire' => true]) }}</div>
    </section>
    @endif
</div>
