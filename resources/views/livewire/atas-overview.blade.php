<div class="dashboard-overview atas-overview">
    @php
        $colors = ['vigente' => '#10b981', 'vencido' => '#f43f5e', 'a_iniciar' => '#3478f6', 'indeterminado' => '#94a3b8'];
        $stops = [];
        $offset = 0;
        foreach ($colors as $key => $color) {
            $next = $offset + ($count ? $statuses[$key] / $count * 100 : 0);
            $stops[] = $color.' '.$offset.'% '.$next.'%';
            $offset = $next;
        }
        $donut = $count ? 'conic-gradient('.implode(', ', $stops).')' : '#e5ebf4';
        $maxMonth = max(1, collect($months)->max('count'));
    @endphp
    <div class="page-heading dashboard-page-heading">
        <div><span class="section-label">PAINEL DE ACOMPANHAMENTO DE ATAS</span><div class="overview-heading-line"><h1>Visão geral</h1>
                <span class="overview-heading-divider" aria-hidden="true"></span>
                <div class="overview-heading-year"><label for="atas-year">Ano das atas</label><select id="atas-year" wire:model.live="year">
                    @foreach($years as $option)<option value="{{ $option }}">{{ $option }}{{ $option === $currentYear ? ' (ano atual)' : '' }}</option>@endforeach
                    <option value="all">Todos os anos</option>
                </select></div>
                <span class="hint" wire:loading wire:target="year" role="status">Atualizando o ano…</span>
                @error('year')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div></div>
    </div>
    <p class="hint">Visão global das atas {{ $year === 'all' ? 'em todos os exercícios' : 'no exercício '.$year }} · {{ number_format($itemCount, 0, ',', '.') }} {{ $itemCount === 1 ? 'item' : 'itens' }} · {{ $procurementCount }} {{ $procurementCount === 1 ? 'processo licitatório' : 'processos licitatórios' }}.</p>
    @if($statuses['indeterminado'] > 0)<p class="notice" role="status">{{ $statuses['indeterminado'] }} {{ $statuses['indeterminado'] === 1 ? 'ata sem vigência definida' : 'atas sem vigência definida' }}. O acompanhamento dos prazos depende das datas de início e término informadas.</p>@endif
    <div class="overview-kpis" aria-label="Indicadores globais de atas">
        <div class="kpi-card" data-kpi="atas-all"><span>Atas cadastradas</span><div class="kpi-icon"><x-nav-icon name="documents"/></div><strong>{{ number_format($count, 0, ',', '.') }}</strong><small>Base global das atas</small></div>
        <div class="kpi-card kpi-success" data-kpi="atas-current"><span>Vigentes</span><div class="kpi-icon"><x-nav-icon name="check"/></div><strong>{{ number_format($statuses['vigente'], 0, ',', '.') }}</strong><small>Com vigência conhecida</small></div>
        <div class="kpi-card kpi-danger" data-kpi="atas-expired"><span>Vencidas</span><div class="kpi-icon"><x-nav-icon name="clock"/></div><strong>{{ number_format($statuses['vencido'], 0, ',', '.') }}</strong><small>Em {{ today()->format('d/m/Y') }}</small></div>
        <div class="overview-alerts" data-expanded="{{ count($alerts) > 4 ? 'true' : 'false' }}">
            <header class="panel-header"><div class="overview-alert-heading"><h2 id="atas-alert-title">Alertas de vencimento</h2><span class="overview-expiration-divider" aria-hidden="true"></span><p>{{ $alertsEnabled ? count($alerts).(count($alerts) === 1 ? ' ata em ' : ' atas em ').$alertDays.' dias' : 'Avisos desativados na sua conta' }}</p></div></header>
            <section class="surface overview-alert-body" aria-labelledby="atas-alert-title"><div class="overview-alert-list" tabindex="0" role="region" aria-label="Atas próximas do vencimento">
                @if($alertsEnabled)
                    @forelse($alerts as $alert)
                        <div class="alert-row"><span class="alert-date"><strong>{{ $alert['day'] }}</strong><span>{{ $alert['month'] }}</span></span><div><div class="alert-contract-heading"><strong>{{ $alert['number'] }}</strong><time class="alert-end-date" datetime="{{ $alert['isoDate'] }}">{{ $alert['date'] }}</time></div><small>{{ $alert['supplier'] }}</small></div></div>
                    @empty<div class="alert-empty">{{ $count === 0 ? 'Nenhuma ata cadastrada neste exercício.' : 'Nenhum vencimento conhecido neste intervalo.' }}</div>@endforelse
                @else<div class="alert-empty">Ative os avisos em Minha conta para acompanhar os prazos.</div>@endif
            </div></section>
        </div>
    </div>
    <div class="overview-grid overview-charts">
        <section class="contract-section overview-chart">
            <header class="panel-header"><div><p>Distribuição das atas por vigência</p></div><x-nav-icon name="reports"/></header>
            <div class="validity-chart">
                <div class="donut-chart" style="--donut-fill: {{ $donut }}" role="img" aria-label="{{ $count ? 'Distribuição de '.$count.' atas, detalhada na legenda' : 'Nenhuma ata cadastrada' }}"><div class="donut-center"><strong>{{ number_format($count, 0, ',', '.') }}</strong><span>atas</span></div></div>
                <ul class="chart-legend">
                    @foreach($colors as $key => $color)
                    <li><span class="chart-dot" style="--series-color: {{ $color }}" aria-hidden="true"></span><span>{{ $statusLabels[$key] }}</span><strong>{{ number_format($statuses[$key], 0, ',', '.') }}</strong><small>{{ number_format($count ? $statuses[$key] / $count * 100 : 0, 1, ',', '.') }}%</small></li>
                    @endforeach
                </ul>
            </div>
        </section>
        <section class="contract-section overview-chart">
            <header class="panel-header"><div class="overview-expiration-heading"><h2>Próximos vencimentos</h2><span class="overview-expiration-divider" aria-hidden="true"></span><p>Términos conhecidos nos próximos seis meses</p></div></header>
            <div class="month-chart" aria-label="Términos de atas por mês">
                @foreach($months as $month)
                    <div class="month-column" aria-label="{{ $month['label'] }}: {{ $month['count'] }} atas"><strong class="month-count">{{ $month['count'] }}</strong><div class="month-track" aria-hidden="true"><div @class(['month-bar', 'month-bar-empty' => $month['count'] === 0]) style="--bar-height: {{ $month['count'] / $maxMonth * 100 }}%; --chart-delay: {{ $loop->index * 65 }}ms"></div></div><span>{{ $month['label'] }}</span></div>
                @endforeach
            </div>
            <div class="portfolio-summary"><span>Valor registrado nas atas {{ $year === 'all' ? 'em todos os exercícios' : 'no exercício '.$year }}.</span><strong>{{ \App\Models\Contract::moneyLabel($total) }}</strong></div>
        </section>
    </div>
</div>
