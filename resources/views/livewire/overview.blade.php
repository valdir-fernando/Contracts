<div class="dashboard-overview" x-data="{
    chartObserver: null,
    updateAlertHeight() {
        const charts = this.$el.querySelector('.overview-charts');
        const panel = this.$el.querySelector('.overview-alert-body');
        if (charts &amp;&amp; panel) {
            this.$el.style.setProperty('--overview-alert-max-height', Math.max(128, Math.floor(charts.getBoundingClientRect().bottom - panel.getBoundingClientRect().top)) + 'px');
        }
    },
    init() {
        this.$nextTick(() => {
            const charts = this.$el.querySelector('.overview-charts');
            if (charts) {
                this.chartObserver = new ResizeObserver(() => this.updateAlertHeight());
                this.chartObserver.observe(charts);
            }
            this.updateAlertHeight();
        });
    },
    destroy() { this.chartObserver?.disconnect(); }
}" x-on:resize.window="updateAlertHeight()">
    @php
        $current = collect($metrics['validities'])->firstWhere('key', 'vigente')['count'] ?? 0;
        $series = [
            'vigente' => '#10b981', 'vencido' => '#f43f5e',
        ];
        $temporalCount = collect($metrics['validities'])->whereIn('key', array_keys($series))->sum('count');
        $stops = [];
        $offset = 0;
        foreach ($series as $key => $color) {
            $value = collect($metrics['validities'])->firstWhere('key', $key)['count'] ?? 0;
            $next = $offset + ($temporalCount ? $value / $temporalCount * 100 : 0);
            $stops[] = $color.' '.$offset.'% '.$next.'%';
            $offset = $next;
        }
        $donut = $temporalCount ? 'conic-gradient('.implode(', ', $stops).')' : '#e5ebf4';
        $maxMonth = max(1, collect($metrics['months'])->max('count'));
    @endphp
    <div class="page-heading dashboard-page-heading"><div><span class="section-label">PAINEL DE ACOMPANHAMENTO</span><div class="overview-heading-line"><h1>Visão geral</h1>
    @if($hasSelectedFund)
        <span class="overview-heading-divider" aria-hidden="true"></span>
        <div class="overview-heading-year"><label for="overview-year">Ano dos contratos</label><select id="overview-year" wire:model.live="year">
            @foreach($years as $option)<option value="{{ $option }}">{{ $option }}{{ $option === $currentYear ? ' (ano atual)' : '' }}</option>@endforeach
            <option value="all">Todos os anos</option>
        </select></div>
        <span class="hint" wire:loading wire:target="year" role="status">Atualizando o ano…</span>
        @error('year')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @endif
</div></div><div class="quick-actions">@can('create', \App\Models\Contract::class)<a class="primary-button compact" href="{{ route('contracts.create') }}"><x-nav-icon name="plus"/>Novo contrato</a>@endcan</div></div>
    @unless(auth()->user()->isAdmin())
        <div class="dashboard-access"><span class="badge">Usuário · órgãos autorizados</span><details><summary>Ver meu escopo</summary><ul class="scope-list">@forelse(auth()->user()->scopes as $scope)<li>{{ $scope->label }}</li>@empty<li>Nenhum órgão vinculado. Entre em contato com um administrador.</li>@endforelse</ul></details></div>
    @endunless

    @unless($hasSelectedFund)<p class="notice" role="status">Selecione um Fundo acima para visualizar seus contratos e indicadores.</p>@endunless
    @if($hasSelectedFund)
    <dialog id="overview-alert-preferences" class="catalog-modal overview-alert-preferences" x-ref="alertPreferences" wire:ignore.self x-on:alert-days-saved.window="$el.close()" aria-labelledby="overview-alert-preferences-title">
        <div class="modal-header"><h2 id="overview-alert-preferences-title">Antecedência dos alertas</h2><button class="modal-close" type="button" x-on:click="$refs.alertPreferences.close()" aria-label="Fechar">×</button></div>
        <form wire:submit="saveAlertDays">
            <div class="field"><label for="overview-alert-days">Quantidade de dias</label><input id="overview-alert-days" type="number" min="1" max="365" required wire:model="alertDaysInput" autofocus @error('alertDaysInput') aria-invalid="true" aria-describedby="overview-alert-days-error" @enderror>
                @error('alertDaysInput')<p class="field-error" id="overview-alert-days-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="quick-actions"><button class="secondary-button" type="button" x-on:click="$refs.alertPreferences.close()">Cancelar</button><button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="saveAlertDays">Salvar</button></div>
        </form>
    </dialog>
    <div class="overview-kpis" aria-label="Indicadores do Fundo selecionado">
        <div class="kpi-card" data-kpi="all"><span>Contratos cadastrados</span><div class="kpi-icon"><x-nav-icon name="contracts"/></div><strong data-count-up="{{ $count }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($count, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($count, 0, ',', '.') }}</span></strong><small>No seu escopo atual</small></div>
        <div class="kpi-card kpi-success" data-kpi="current"><span>Vigentes</span><div class="kpi-icon"><x-nav-icon name="check"/></div><strong data-count-up="{{ $current }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($current, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($current, 0, ',', '.') }}</span></strong><small>Com vigência conhecida</small></div>
        <div class="kpi-card kpi-danger" data-kpi="expired"><span>Vencidos</span><div class="kpi-icon"><x-nav-icon name="clock"/></div><strong data-count-up="{{ $expired }}"><span data-count-up-value aria-hidden="true" wire:ignore>{{ number_format($expired, 0, ',', '.') }}</span><span class="sr-only">{{ number_format($expired, 0, ',', '.') }}</span></strong><small>Em {{ today()->format('d/m/Y') }}</small></div>
        <div class="overview-alerts" data-expanded="{{ $alertsEnabled && $alertCount > 4 ? 'true' : 'false' }}">
            <header class="panel-header"><div class="overview-alert-heading"><h2 id="overview-alert-title">{{ $alertsEnabled ? 'Alertas de Vencimento' : 'Acompanhe os prazos' }}</h2>@if($alertsEnabled)<a class="text-link" href="#overview-alert-preferences" x-on:click.prevent="$refs.alertPreferences.showModal()" aria-haspopup="dialog">Antecedência de {{ $alertDays }} dias</a>@else<p>Avisos desativados na sua conta</p>@endif@if($alertsEnabled)<span class="overview-expiration-divider" aria-hidden="true"></span><p>{{ $alertCount }} contratos vencem em {{ $alertDays }} dias.</p>@endif</div></header>
            <section class="surface overview-alert-body" aria-labelledby="overview-alert-title">
            @if($alertsEnabled)
                <div class="overview-alert-list" tabindex="0" role="region" aria-label="Contratos próximos do vencimento">
                @forelse($alerts as $contract)
                    <div class="alert-row"><span class="alert-date"><strong>{{ $contract->vigencia_fim_atual->format('d') }}</strong><span>{{ $contract->vigencia_fim_atual->translatedFormat('M') }}</span></span><div><div class="alert-contract-heading"><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero ?: 'Sem número' }}</a><time class="alert-end-date" datetime="{{ $contract->vigencia_fim_atual->format('Y-m-d') }}" aria-label="Término do contrato: {{ $contract->vigencia_fim_atual->format('d/m/Y') }}">{{ $contract->vigencia_fim_atual->format('d/m/Y') }}</time></div><small>{{ $contract->fornecedor_nome_snapshot }}</small></div></div>
                @empty<div class="alert-empty"><span><x-nav-icon name="check"/></span>Nenhum vencimento conhecido neste intervalo.</div>@endforelse
                </div>
            @else<div class="alert-empty"><span><x-nav-icon name="calendar"/></span>Ative os avisos para acompanhar os próximos prazos.</div>@endif
            </section>
        </div>
    </div>

    <div class="overview-grid overview-charts">
        <section class="contract-section overview-chart">
            <header class="panel-header"><div><p>Distribuição dos contratos por vigência</p></div><x-nav-icon name="reports"/></header>
            <div class="validity-chart">
                <div class="donut-chart" style="--donut-fill: {{ $donut }}" role="img" aria-label="Distribuição temporal de {{ $temporalCount }} contratos, detalhada na legenda"><div class="donut-center"><strong>{{ number_format($temporalCount, 0, ',', '.') }}</strong><span>contratos</span></div></div>
                <ul class="chart-legend">
                    @foreach($series as $key => $color)
                        @php($metric = collect($metrics['validities'])->firstWhere('key', $key))
                        <li><span class="chart-dot" style="--series-color: {{ $color }}" aria-hidden="true"></span><span>{{ $metric['label'] }}</span><strong>{{ number_format($metric['count'], 0, ',', '.') }}</strong><small>{{ number_format($temporalCount ? $metric['count'] / $temporalCount * 100 : 0, 1, ',', '.') }}%</small></li>
                    @endforeach
                </ul>
            </div>
        </section>
        <section class="contract-section overview-chart">
            <header class="panel-header"><div class="overview-expiration-heading"><h2>Próximos vencimentos</h2><span class="overview-expiration-divider" aria-hidden="true"></span><p>Términos conhecidos nos próximos seis meses</p></div><a class="text-link" href="{{ route('calendar.index') }}">Ver calendário →</a></header>
            <div class="month-chart" aria-label="Términos conhecidos por mês">
                @foreach($metrics['months'] as $metric)
                    <div class="month-column" aria-label="{{ $metric['label'] }}: {{ $metric['count'] }} contratos"><strong class="month-count">{{ $metric['count'] }}</strong><div class="month-track" aria-hidden="true"><div @class(['month-bar', 'month-bar-empty' => $metric['count'] === 0]) style="--bar-height: {{ $metric['count'] / $maxMonth * 100 }}%; --chart-delay: {{ $loop->index * 65 }}ms"></div></div><span>{{ $metric['label'] }}</span></div>
                @endforeach
            </div>
            <div class="portfolio-summary"><span>Valor de todos os contratos do Fundo realizados {{ $year === 'all' ? 'em todos os exercícios' : 'no exercício '.$year }}.</span><strong>{{ \App\Models\Contract::moneyLabel($metrics['portfolioValue']['total']) }}</strong></div>
        </section>
    </div>


    @endif
</div>
