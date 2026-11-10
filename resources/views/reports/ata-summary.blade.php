<x-layouts.app title="Resumo da ata">
    <x-slot:headerClose><a class="secondary-button contract-summary-close" href="{{ route('reports.atas.index') }}" aria-label="Fechar resumo da ata" title="Fechar">&times;</a></x-slot:headerClose>
    <x-slot:contractSummary>
        <div class="contract-heading-title contract-summary-readonly ata-summary-heading" aria-label="Resumo da ata e fornecedor">
            <h1 class="sr-only">Resumo da ata {{ $ata['number'] }}</h1>
            <div class="contract-number-field">
                <label for="ata-summary-number">Nº da Ata</label>
                <div class="contract-number-control"><input id="ata-summary-number" class="contract-number-highlight" type="text" value="{{ $ata['number'] }}" aria-label="Número da ata" readonly></div>
            </div>
            <p class="contract-heading-supplier"><span class="sr-only">Fornecedor: </span><span class="ata-summary-supplier"><strong data-fit-supplier-name>{{ $ata['supplier'] ?: 'Fornecedor não informado' }}</strong></span></p>
        </div>
    </x-slot:contractSummary>
    <section class="surface detail-summary contract-information-summary ata-information-summary" aria-label="Dados da ata">
        <div><span>Ata</span><strong>{{ $ata['number'] }}</strong></div>
        <div><span>Processo</span><strong>{{ $ata['process'] }}</strong></div>
        <div><span>Licitação</span><strong>{{ $ata['bidding'] }}</strong></div>
        <div><span>Número da licitação</span><strong>{{ $ata['bidding_number'] }}</strong></div>
        @foreach(['date' => 'Data da ata', 'starts' => 'Início da vigência', 'ends' => 'Final atualizado'] as $key => $label)
            <div><span>{{ $label }}</span><strong>{{ $ata[$key] ? \Carbon\Carbon::parse($ata[$key])->format('d/m/Y') : 'Não informado' }}</strong></div>
        @endforeach
        <div><span>Valor Atualizado</span><strong>{{ \App\Models\Contract::moneyLabel($total) }}</strong></div>
        <div class="ata-summary-period-object">
            <div><span>Período</span><strong>{{ $ata['period'] ?: 'Não informado' }}</strong></div>
            <div class="ata-summary-object">
                <span>Objeto da ata</span><strong>{{ $ata['object'] ?: 'Não informado' }}</strong>
                @if($ata['amendment'])<div class="ata-summary-amendment"><span>Último aditivo</span><strong>{{ $ata['amendment']['number'] }} · Processo {{ $ata['amendment']['process'] ?: 'não informado' }}</strong></div>@endif
            </div>
        </div>
    </section>
    <section class="surface contract-section ata-summary-list-card ata-participant-funds" aria-labelledby="ata-funds-title" x-data="{ showFunds: false }">
        <div class="panel-header">
            <h2 id="ata-funds-title">Fundos participantes</h2>
            <label class="ata-funds-toggle"><input type="checkbox" autocomplete="off" x-model="showFunds" aria-controls="ata-participants-list" aria-expanded="false" :aria-expanded="showFunds">Exibir Fundos Participantes</label>
        </div>
        <div id="ata-participants-list" class="table-scroll" tabindex="0" role="region" aria-label="Fundos participantes da ata" x-cloak x-show="showFunds">
            <table class="data-table"><thead><tr><th>Fundo</th><th>CNPJ</th><th>Participação</th></tr></thead><tbody>
                @forelse($funds as $fund)
                    <tr @class(['ata-managing-fund' => $fund['manager']])><td>@if($fund['manager'])<strong>{{ $fund['name'] }}</strong>@else{{ $fund['name'] }}@endif</td><td>{{ $fund['cnpj'] }}</td><td>@if($fund['manager'])<strong>Fundo gerenciador</strong>@else Participante @endif</td></tr>
                @empty<tr><td colspan="3">Nenhum fundo participante informado.</td></tr>@endforelse
            </tbody></table>
        </div>
    </section>
    <section class="surface contract-section ata-summary-list-card ata-summary-products" aria-labelledby="ata-products-title">
        <div class="panel-header"><h2 id="ata-products-title">Produtos da ata</h2></div>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Produtos da ata">
                <table class="data-table"><thead><tr><th>Item</th><th>Discriminação</th><th>Marca</th><th>Unidade</th><th>Quantidade</th><th>Valor unitário</th><th>Valor total</th><th>Origem</th></tr></thead><tbody>
                    @forelse($products as $product)
                        <tr><td>{{ $product['item'] }}</td><td>{{ $product['description'] }}</td><td>{{ $product['brand'] ?: 'Não informada' }}</td><td>{{ $product['unit'] }}</td><td>{{ number_format((float) $product['quantity'], 4, ',', '.') }}</td><td class="money-cell">{{ \App\Models\Contract::moneyLabel($product['unit_price']) }}</td><td class="money-cell">{{ \App\Models\Contract::moneyLabel($product['amount']) }}</td><td>{{ $product['amendment'] ? 'Aditivo '.$product['amendment'] : 'Ata original' }}</td></tr>
                    @empty<tr><td colspan="8">Nenhum produto cadastrado nesta ata.</td></tr>@endforelse
                </tbody></table>
            </div>
        <div class="table-footer"><span>Total registrado: <strong>{{ \App\Models\Contract::moneyLabel($total) }}</strong></span></div>
    </section>
</x-layouts.app>
