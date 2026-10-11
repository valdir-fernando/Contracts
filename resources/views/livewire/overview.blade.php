<div>
    @if($alertsEnabled)
        <section class="surface contract-section"><h2>Alertas de vencimento</h2><p>{{ $alertCount }} contratos vencem entre hoje e os próximos {{ $alertDays }} dias.</p>
            @forelse($alerts as $contract)<p><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero ?: 'Sem número' }}</a> · {{ $contract->end_date->format('d/m/Y') }}</p>@empty<p>Nenhum vencimento conhecido neste intervalo.</p>@endforelse
            <p><a class="text-link" href="{{ route('calendar.index') }}">Consultar calendário e configurar alertas</a></p>
        </section>
    @endif
    <section class="surface contract-section"><h2>Visão geral dos órgãos autorizados</h2><div class="overview-kpis"><div><span>Contratos cadastrados</span><strong>{{ $count }}</strong><small>No seu escopo atual</small></div><div><span>Vencidos</span><strong>{{ $expired }}</strong><small>Em {{ today()->format('d/m/Y') }}</small></div><div><span>Em revisão</span><strong>{{ $review }}</strong><small>Vigências ou órgãos</small></div></div><p>Indicadores temporais; não certificam situação jurídica.</p></section>
    <section class="surface contract-section"><h2>Distribuição e vencimentos</h2><p>Valor inicial dos contratos acessíveis: {{ \App\Models\Contract::moneyLabel($metrics['summary']['total']) }}. {{ $metrics['summary']['missing'] }} sem valor inicial informado.</p>
        @foreach(['validities'=>'Situação temporal','months'=>'Términos conhecidos por mês','funds'=>'Órgãos com mais contratos (até 10)'] as $key=>$title)
            <h3>{{ $title }}</h3><div class="metrics-bars">
            @foreach($metrics[$key] as $metric)<div class="metric-bar"><span>{{ $metric['label'] }}</span><meter min="0" max="{{ max(1, collect($metrics[$key])->max('count')) }}" value="{{ $metric['count'] }}" aria-label="{{ $metric['label'] }}: {{ $metric['count'] }} contratos">{{ $metric['count'] }}</meter><strong>{{ $metric['count'] }}</strong></div>@endforeach
            </div>
        @endforeach
        <p>Os vencimentos excluem períodos indeterminados e vigências em revisão. Os indicadores consideram todo o seu escopo, independentemente da busca de contratos recentes.</p>
    </section>
    <section class="surface contract-section"><h2>Contratos recentes</h2><label for="overview-search">Buscar contrato, processo ou fornecedor</label><input id="overview-search" wire:model.live.debounce.300ms="search" maxlength="255"><span wire:loading role="status">Atualizando…</span><div class="table-scroll"><table class="data-table"><thead><tr><th>Contrato</th><th>Fornecedor</th><th>Vigência</th><th>Valor inicial</th></tr></thead><tbody>@forelse($recent as $contract)<tr wire:key="contract-{{ $contract->id }}"><td><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero }}</a></td><td>{{ $contract->fornecedor_nome_snapshot }}</td><td>{{ \App\Models\Contract::VALIDITY[$contract->validity] }}</td><td>{{ \App\Models\Contract::moneyLabel($contract->valor_total) }}</td></tr>@empty<tr><td colspan="4">Nenhum contrato encontrado.</td></tr>@endforelse</tbody></table></div>{{ $recent->links() }}</section>
</div>
