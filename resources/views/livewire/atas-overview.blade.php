<div class="dashboard-overview atas-overview">
    <div class="page-heading dashboard-page-heading">
        <div><span class="section-label">PAINEL DE ACOMPANHAMENTO DE ATAS</span><div class="overview-heading-line"><h1>Visão geral</h1>
            @if($hasSelectedFund)
                <span class="overview-heading-divider" aria-hidden="true"></span>
                <div class="overview-heading-year"><label for="atas-year">Ano das atas</label><select id="atas-year" wire:model.live="year">
                    @foreach($years as $option)<option value="{{ $option }}">{{ $option }}{{ $option === $currentYear ? ' (ano atual)' : '' }}</option>@endforeach
                    <option value="all">Todos os anos</option>
                </select></div>
                <span class="hint" wire:loading wire:target="year" role="status">Atualizando o ano…</span>
                @error('year')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            @endif
        </div></div>
        <div class="quick-actions"><button class="secondary-button compact" type="button" disabled title="Recurso em breve"><x-nav-icon name="search"/>Consultar atas</button><button class="primary-button compact" type="button" disabled title="Recurso em breve"><x-nav-icon name="plus"/>Nova ata</button></div>
    </div>
    <p class="hint" role="status">O cadastro e o acompanhamento de atas serão disponibilizados em breve.</p>
    @unless($hasSelectedFund)<p class="notice" role="status">Selecione um Fundo no login para visualizar suas atas e indicadores.</p>@endunless
    @if($hasSelectedFund)
    <div class="overview-kpis" aria-label="Indicadores de atas do Fundo selecionado">
        <div class="kpi-card"><span>Atas cadastradas</span><div class="kpi-icon"><x-nav-icon name="documents"/></div><strong>0</strong><small>No seu escopo atual</small></div>
        <div class="kpi-card kpi-success"><span>Vigentes</span><div class="kpi-icon"><x-nav-icon name="check"/></div><strong>0</strong><small>Com vigência conhecida</small></div>
        <div class="kpi-card kpi-danger"><span>Vencidas</span><div class="kpi-icon"><x-nav-icon name="clock"/></div><strong>0</strong><small>Em {{ today()->format('d/m/Y') }}</small></div>
        <div class="overview-alerts" data-expanded="false">
            <header class="panel-header"><div class="overview-alert-heading"><h2 id="atas-alert-title">Alertas de vencimento</h2><span class="overview-expiration-divider" aria-hidden="true"></span><p>0 atas a vencer</p></div></header>
            <section class="surface overview-alert-body" aria-labelledby="atas-alert-title"><div class="overview-alert-list" role="region" aria-label="Atas próximas do vencimento"><div class="alert-empty">Nenhuma ata cadastrada para acompanhamento.</div></div></section>
        </div>
    </div>
    <div class="overview-grid overview-charts">
        <section class="contract-section overview-chart">
            <header class="panel-header"><div><p>Distribuição das atas por vigência</p></div><x-nav-icon name="reports"/></header>
            <div class="validity-chart">
                <div class="donut-chart" style="--donut-fill: #e5ebf4" role="img" aria-label="Nenhuma ata cadastrada"><div class="donut-center"><strong>0</strong><span>atas</span></div></div>
                <ul class="chart-legend">
                    <li><span class="chart-dot" style="--series-color: #10b981" aria-hidden="true"></span><span>Vigente</span><strong>0</strong><small>0,0%</small></li>
                    <li><span class="chart-dot" style="--series-color: #f43f5e" aria-hidden="true"></span><span>Vencida</span><strong>0</strong><small>0,0%</small></li>
                </ul>
            </div>
        </section>
        <section class="contract-section overview-chart">
            <header class="panel-header"><div class="overview-expiration-heading"><h2>Próximos vencimentos</h2><span class="overview-expiration-divider" aria-hidden="true"></span><p>Términos conhecidos nos próximos seis meses</p></div></header>
            <div class="month-chart" aria-label="Términos de atas por mês">
                @foreach($months as $month)
                    <div class="month-column" aria-label="{{ $month }}: 0 atas"><strong class="month-count">0</strong><div class="month-track" aria-hidden="true"><div class="month-bar month-bar-empty" style="--bar-height: 0%"></div></div><span>{{ $month }}</span></div>
                @endforeach
            </div>
            <div class="portfolio-summary"><span>Valor de todas as atas do Fundo realizadas {{ $year === 'all' ? 'em todos os exercícios' : 'no exercício '.$year }}.</span><strong>R$ 0,00</strong></div>
        </section>
    </div>
    @endif
</div>
