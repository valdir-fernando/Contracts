<x-layouts.app title="Relatórios Ata">
    <div class="page-heading reports-page-heading">
        <div><h1>Relatórios de atas</h1></div>
        <form id="ata-report-filters" method="GET" action="{{ route('reports.atas.index') }}" class="report-scope-selection ata-report-scope" x-data>
            <label class="field">Ano
                <select name="report_year" @disabled($filters['report_end_active'] === '1') @change="$el.form.requestSubmit()">
                    @foreach($years as $year)<option value="{{ $year }}" @selected((string)$year === $filters['report_year'])>{{ $year }}{{ $year === $currentYear ? ' (ano atual)' : '' }}</option>@endforeach
                    <option value="all" @selected($filters['report_year'] === 'all')>Todos os anos</option>
                </select>
            </label>
            @foreach(['fund' => ['Fundo', $funds, 'Todos os Fundos'], 'bidding' => ['Licitação', $biddings, 'Todas as licitações'], 'bidding_number' => ['Nº Licit', $biddingNumbers, 'Todos']] as $key => [$label, $options, $placeholder])
                <label class="field">{{ $label }}
                    <select name="report_{{ $key }}" @change="$el.form.requestSubmit()">
                        <option value="">{{ $placeholder }}</option>
                        @if($filters['report_'.$key] !== '' && !$options->contains($filters['report_'.$key]))<option value="{{ $filters['report_'.$key] }}" selected>{{ $filters['report_'.$key] }}</option>@endif
                        @foreach($options as $option)<option value="{{ $option }}" @selected($filters['report_'.$key] === $option)>{{ $option }}</option>@endforeach
                    </select>
                </label>
            @endforeach
            <a class="secondary-button compact report-pdf-button" href="{{ route('reports.atas.pdf', $filters) }}"><x-nav-icon name="printer"/>Gerar PDF</a>
            <noscript><button class="secondary-button compact">Aplicar</button></noscript>
        </form>
    </div>
    @if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <section class="surface contract-section report-contract-panel" aria-label="Painel Atas">
        <div class="report-list-heading"><h2 class="report-contract-caption">@include('reports.ata-title')</h2><p class="report-contract-count" role="status">Total de Atas: {{ number_format($count, 0, ',', '.') }}</p></div>
        <div class="report-list-clear">
            <div class="report-validity-options" x-data>
                <label><input type="radio" name="report_current" value="0" form="ata-report-filters" @checked($filters['report_current'] === '0') @change="$el.form.requestSubmit()"> Todos</label>
                <span class="report-options-separator" aria-hidden="true"></span>
                <label><input type="radio" name="report_current" value="1" form="ata-report-filters" @checked($filters['report_current'] === '1') @change="$el.form.requestSubmit()"> Somente Vigentes</label>
            </div>
            <a class="text-link" href="{{ route('reports.atas.index') }}">Limpar filtros</a>
        </div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Lista de atas com rolagem">
            <table class="data-table report-contract-list ata-report-list">
                <thead><tr>
                    <th x-data>Ata @include('reports.ata-text-filter', ['name' => 'number', 'label' => 'Filtrar por ata', 'fields' => ['number' => 'Ata']])</th>
                    <th x-data>Processo @include('reports.ata-text-filter', ['name' => 'process', 'label' => 'Filtrar por processo', 'fields' => ['process' => 'Processo']])</th>
                    <th x-data>Fornecedor @include('reports.ata-text-filter', ['name' => 'supplier', 'label' => 'Filtrar fornecedor', 'fields' => ['supplier' => 'Fornecedor']])</th>
                    <th x-data>Objeto @include('reports.ata-text-filter', ['name' => 'object', 'label' => 'Filtrar por objeto', 'fields' => ['object' => 'Objeto']])</th>
                    <th>Início</th>
                    <th x-data>Final
                        <button type="button" popovertarget="ata-end-popover" x-ref="trigger" aria-label="Filtrar por data final" title="Filtrar por data final" @class(['report-process-trigger', 'active' => $filters['report_end_active'] === '1'])><x-nav-icon name="filter"/></button>
                        <div id="ata-end-popover" class="report-process-popover" popover @toggle="if ($event.newState === 'open') { const rect = $refs.trigger.getBoundingClientRect(); $el.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 268)) + 'px'; $el.style.top = Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - $el.offsetHeight - 8)) + 'px'; }">
                            <div class="report-process-popover-heading"><span>Data final da ata</span><button type="button" popovertarget="ata-end-popover" popovertargetaction="hide" aria-label="Fechar filtro de data final">×</button></div>
                            <input type="hidden" name="report_end_active" form="ata-report-filters" value="{{ $filters['report_end_active'] }}">
                            <label class="report-supplier-filter-field" for="ata-end-from">De<input id="ata-end-from" class="report-process-filter" type="date" name="report_end_from" form="ata-report-filters" value="{{ $filters['report_end_from'] }}"></label>
                            <label class="report-supplier-filter-field" for="ata-end-to">Até<input id="ata-end-to" class="report-process-filter" type="date" name="report_end_to" form="ata-report-filters" value="{{ $filters['report_end_to'] }}"></label>
                            <div class="report-process-popover-actions">
                                <button type="button" class="secondary-button compact" @click="$el.closest('[popover]').querySelectorAll('input').forEach(input => input.value = ''); document.getElementById('ata-report-filters').requestSubmit()">Limpar</button>
                                <button type="submit" form="ata-report-filters" class="primary-button compact" @click="$el.closest('[popover]').querySelector('input[type=hidden]').value = '1'">Aplicar</button>
                            </div>
                        </div>
                    </th>
                    <th>Valor Atualizado</th>
                    <th>Ações</th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr><td>{{ $row['number'] }}</td><td>{{ $row['process'] }}</td>
                            <td>{{ $row['bidding'] }}<br>
                                <strong>{{ $row['supplier'] }}</strong>
                                @if($row['amendment'] !== null)
                                    <br>Aditivo {{ $row['amendment']['number'] }}@if($row['amendment']['process'] !== '') · Processo {{ $row['amendment']['process'] }}@endif
                                @endif
                            </td><td>{{ $row['object'] }}</td>
                            <td class="nowrap-cell">{{ $row['starts'] ? \Carbon\Carbon::parse($row['starts'])->format('d/m/Y') : 'Não informado' }}</td>
                            <td class="nowrap-cell">{{ $row['ends'] ? \Carbon\Carbon::parse($row['ends'])->format('d/m/Y') : 'Não informado' }}</td>
                            <td class="money-cell">{{ \App\Models\Contract::moneyLabel($row['amount']) }}</td>
                            <td><div class="report-row-actions"><a class="secondary-button compact" href="{{ route('reports.atas.show', $row['id']) }}" aria-label="Resumo da ata {{ $row['number'] }}">Resumo</a></div></td>
                        </tr>
                    @empty<tr><td colspan="8">Nenhuma ata encontrada para os filtros selecionados.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span>Total registrado: <strong>{{ \App\Models\Contract::moneyLabel($total) }}</strong></span>{{ $rows->links() }}</div>
    </section>
</x-layouts.app>
