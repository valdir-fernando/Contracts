<x-layouts.app title="Detalhes do contrato">
    <x-slot:contractSummary><x-contract-summary :contract="$contract" :readonly-number="true" :show-edit="true" :heading="session('contract_created') ? 'Novo contrato' : 'Contrato'"/></x-slot:contractSummary>
    <x-slot:headerClose><a class="secondary-button contract-summary-close" href="{{ route('contracts.index') }}" aria-label="Fechar resumo do contrato" title="Fechar">&times;</a></x-slot:headerClose>
    <section class="surface detail-summary contract-organizations-summary" aria-label="Resumo do contrato">
        <div><span>Fundo Contratante</span><strong>{{ $contract->fundo_nome_snapshot ?: 'Não informado' }}</strong></div>
        <div><span>Secretaria</span><strong>{{ $contract->secretaria_nome_snapshot ?: 'Não informada' }}</strong></div>
    </section>
    <section class="surface detail-summary contract-information-summary" aria-label="Dados do contrato">
        <div><span>Tipo do Contrato</span><strong>{{ $contract->tipo ?: 'Não informado' }}</strong></div>
        <div><span>Data do contrato</span><strong>{{ $contract->data_contrato?->format('d/m/Y') ?? 'Não informada' }}</strong></div>
        <div><span>Licitação</span><strong>{{ $contract->modalidade ?: 'Não informada' }}</strong></div>
        <div><span>Número da licitação</span><strong>{{ $contract->n_licit ?: 'Não informado' }}</strong></div>
        <div><span>Fiscal do contrato</span><strong>{{ $contract->nome_fiscal ?: 'Não informado' }}</strong></div>
        <div><span>Início do contrato</span><strong>{{ $contract->vigencia_inicio?->format('d/m/Y') ?? 'Não informado' }}</strong></div>
        <div><span>Final atualizado</span><strong>{{ $contract->end_date?->format('d/m/Y') ?? 'Não informado' }}</strong></div>
        <div><span>Valor atualizado</span><strong>{{ \App\Models\Contract::moneyLabel($contract->valor_acumulado ?? $contract->valor_total) }}</strong></div>
        <div class="contract-information-object"><span>Objeto</span><strong>{{ $contract->objeto ?: 'Não informado' }}</strong></div>
        @if($serviceOrders->isNotEmpty())
            <div class="contract-information-object contract-information-service-orders">
                <span>Ordens de Serviço</span>
                <div class="contract-service-order-summary-list">
                    @foreach($serviceOrders as $serviceOrder)
                        <strong><span>Nº {{ $serviceOrder->number }}</span><span>Data: {{ $serviceOrder->issued_on?->format('d/m/Y') ?? 'Não informada' }}</span><span>Período da Obra/Serviço: {{ $serviceOrder->deadline }}</span></strong>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
    <div id="contract-amendments" class="panel-header">@can('update', $contract)<a class="primary-button compact" href="{{ route('contracts.amendments.create', $contract) }}"><x-nav-icon name="plus"/>Novo aditivo</a>@endcan</div>
    @include('amendments.list')
</x-layouts.app>
