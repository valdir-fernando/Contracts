<x-layouts.app title="Relatórios">
    <div class="page-heading reports-page-heading">
        <div>
            <h1>Relatórios de contratos</h1>
        </div>
        <form id="report-filters" method="GET" class="report-scope-selection {{ $canSelectScope ? '' : 'report-year-only' }}" x-data @submit="const from = $el.elements.namedItem('report_end_from'); const to = $el.elements.namedItem('report_end_to'); if (from.value && to.value && from.value > to.value) { $event.preventDefault(); alert('A data inicial (De) não pode ser posterior à data final (Até).'); document.getElementById('report-end-popover').showPopover(); to.focus(); }">
            @if($reportScopeLocked)
                @unless($reportEndActive)
                <input type="hidden" name="report_year" value="{{ $reportYear }}">
                @endunless
                @if($canSelectScope)
                    <input type="hidden" name="report_fund" value="{{ $reportFundId > 0 ? $reportFundId : '' }}">
                    <input type="hidden" name="report_department" value="{{ $reportDepartmentId }}">
                @endif
            @endif
            <label class="field">Ano
                <input type="number" name="report_year" min="1" max="9999" value="{{ $reportYear }}" @disabled($reportScopeLocked) @change="if ($el.checkValidity()) $el.form.requestSubmit()">
            </label>
            @if($canSelectScope)
            <label class="field">Fundo
                <select name="report_fund" @disabled($reportScopeLocked) @change="$refs.department.value = ''; $el.form.requestSubmit()">
                    <option value="" @selected($reportFundId === null)>Todos os Fundos</option>
                    @foreach($reportFunds as $fund)
                        <option value="{{ $fund->id }}" @selected((int) $reportFundId === (int) $fund->id)>{{ $fund->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">Secretaria
                <select name="report_department" x-ref="department" @disabled($reportScopeLocked || !$canSelectDepartment) @change="$el.form.requestSubmit()">
                    <option value=""></option>
                    @foreach($reportDepartments as $department)
                        <option value="{{ $department->id }}" @selected((int) $reportDepartmentId === (int) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
            @endif
            <noscript><button class="secondary-button compact">Aplicar</button></noscript>
        </form>
    </div>
    <nav class="report-panel-navigation" aria-label="Relatórios">
        <button type="button" class="secondary-button compact">Relatório Contrato</button>
        <button type="button" class="secondary-button compact">Relatório Aditivo</button>
        <p class="report-contract-count" role="status">Total de Contratos: {{ number_format($reportContracts->total(), 0, ',', '.') }}</p>
    </nav>
    <section class="surface contract-section report-contract-panel" aria-label="Contratos do Fundo no ano atual">
            <div class="report-list-heading">
                @php
                    $appliedFilters = [];
                    if ($reportCurrent) {
                        $appliedFilters[] = 'Somente contratos vigentes';
                    }
                    if ($reportDepartmentId !== null) {
                        $appliedFilters[] = 'Secretaria: '.$reportDepartments->firstWhere('id', $reportDepartmentId)->name;
                    }
                    foreach ([
                        'Processo contém' => $reportProcess,
                        'Licitação contém' => $supplierFilters['report_bidding'],
                        'Nº da licitação contém' => $supplierFilters['report_bidding_number'],
                        'Nome do fornecedor contém' => $supplierFilters['report_supplier'],
                        'Tipo de contrato' => $reportType,
                    ] as $label => $value) {
                        if ($value !== '') {
                            $appliedFilters[] = $label.': '.$value;
                        }
                    }
                    if ($reportEndFrom !== null) {
                        $appliedFilters[] = 'Final a partir de: '.\Carbon\Carbon::parse($reportEndFrom)->format('d/m/Y');
                    }
                    if ($reportEndTo !== null) {
                        $appliedFilters[] = 'Final até: '.\Carbon\Carbon::parse($reportEndTo)->format('d/m/Y');
                    }
                @endphp
                <h2 class="report-contract-caption">Relatório de {{ $appliedFilters === [] ? 'todos os contratos' : 'contratos' }} do {{ $reportFundName }} {{ $reportEndActive || $reportYear === null ? 'referente a todos os exercícios' : 'referente ao ano de '.$reportYear }}.@if($appliedFilters !== []) Filtros aplicados: {{ implode('; ', $appliedFilters) }}.@endif</h2>
            </div>
            <div class="table-scroll">
                <div class="report-list-clear">
                    <div class="report-validity-options" x-data>
                        <label><input type="radio" name="report_current" value="0" form="report-filters" @checked(!$reportCurrent) @change="$el.form.requestSubmit()"> Todos</label>
                        <span class="report-options-separator" aria-hidden="true"></span>
                        <label><input id="report-current" type="radio" name="report_current" value="1" form="report-filters" @checked($reportCurrent) @change="$el.form.requestSubmit()"> Somente Vigentes</label>
                    </div>
                    <a class="text-link" href="{{ route('reports.index') }}">Limpar filtros</a>
                </div>
                <table class="data-table report-contract-list">
                    <thead><tr><th>Contrato</th><th x-data>
                        Processo
                        <button type="button" popovertarget="report-process-popover" x-ref="trigger" aria-label="Filtrar por processo" title="Filtrar por processo" @class(['report-process-trigger', 'active' => $reportProcess !== ''])><x-nav-icon name="filter"/></button>
                        <div id="report-process-popover" class="report-process-popover" popover @toggle="if ($event.newState === 'open') { const rect = $refs.trigger.getBoundingClientRect(); $el.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 268)) + 'px'; $el.style.top = Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - $el.offsetHeight - 8)) + 'px'; }">
                            <div class="report-process-popover-heading"><label for="report-process-input">Filtrar por processo</label><button type="button" popovertarget="report-process-popover" popovertargetaction="hide" aria-label="Fechar filtro de processo">×</button></div>
                            <input id="report-process-input" class="report-process-filter" type="search" name="report_process" form="report-filters" value="{{ $reportProcess }}" maxlength="255" placeholder="Digite o processo" autofocus>
                            <div class="report-process-popover-actions">
                                <button type="button" class="secondary-button compact" @click="document.getElementById('report-process-input').value = ''; document.getElementById('report-filters').requestSubmit()">Limpar</button>
                                <button type="submit" form="report-filters" class="primary-button compact">Aplicar</button>
                            </div>
                        </div>
                    </th><th x-data>
                        Fornecedor
                        <button type="button" popovertarget="report-supplier-popover" x-ref="trigger" aria-label="Filtrar por licitação e fornecedor" title="Filtrar por licitação e fornecedor" @class(['report-process-trigger', 'active' => collect($supplierFilters)->contains(fn ($value) => $value !== '')])><x-nav-icon name="filter"/></button>
                        <div id="report-supplier-popover" class="report-process-popover" popover @toggle="if ($event.newState === 'open') { const rect = $refs.trigger.getBoundingClientRect(); $el.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 268)) + 'px'; $el.style.top = Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - $el.offsetHeight - 8)) + 'px'; }">
                            <div class="report-process-popover-heading"><span>Filtrar fornecedor</span><button type="button" popovertarget="report-supplier-popover" popovertargetaction="hide" aria-label="Fechar filtro de fornecedor">×</button></div>
                            @foreach(['report_bidding' => 'Licitação', 'report_bidding_number' => 'Nº Licit', 'report_supplier' => 'Fornecedor'] as $name => $label)
                                <label class="report-supplier-filter-field" for="{{ $name }}">{{ $label }}
                                    <input id="{{ $name }}" class="report-process-filter" type="search" name="{{ $name }}" form="report-filters" value="{{ $supplierFilters[$name] }}" maxlength="255" @if($loop->first) autofocus @endif>
                                </label>
                            @endforeach
                            <div class="report-process-popover-actions">
                                <button type="button" class="secondary-button compact" @click="$el.closest('[popover]').querySelectorAll('input').forEach(input => input.value = ''); document.getElementById('report-filters').requestSubmit()">Limpar</button>
                                <button type="submit" form="report-filters" class="primary-button compact">Aplicar</button>
                            </div>
                        </div>
                    </th><th x-data>
                        Objeto
                        <button type="button" popovertarget="report-type-popover" x-ref="trigger" aria-label="Filtrar por tipo de contrato" title="Filtrar por tipo de contrato" @class(['report-process-trigger', 'active' => $reportType !== ''])><x-nav-icon name="filter"/></button>
                        <div id="report-type-popover" class="report-process-popover" popover @toggle="if ($event.newState === 'open') { const rect = $refs.trigger.getBoundingClientRect(); $el.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 268)) + 'px'; $el.style.top = Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - $el.offsetHeight - 8)) + 'px'; }">
                            <div class="report-process-popover-heading"><label for="report-type-input">Tipo Contrato</label><button type="button" popovertarget="report-type-popover" popovertargetaction="hide" aria-label="Fechar filtro de tipo de contrato">×</button></div>
                            <select id="report-type-input" class="report-process-filter" name="report_type" form="report-filters" autofocus>
                                <option value="">Todos os tipos</option>
                                @foreach($reportTypes as $type)
                                    <option value="{{ $type }}" @selected($reportType === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                            <div class="report-process-popover-actions">
                                <button type="button" class="secondary-button compact" @click="document.getElementById('report-type-input').value = ''; document.getElementById('report-filters').requestSubmit()">Limpar</button>
                                <button type="submit" form="report-filters" class="primary-button compact">Aplicar</button>
                            </div>
                        </div>
                    </th><th>Inicio</th><th x-data>
                        Final
                        <button type="button" popovertarget="report-end-popover" x-ref="trigger" aria-label="Filtrar por data final" title="Filtrar por data final" @class(['report-process-trigger', 'active' => $reportEndFrom !== null || $reportEndTo !== null])><x-nav-icon name="filter"/></button>
                        <div id="report-end-popover" class="report-process-popover" popover @toggle="if ($event.newState === 'open') { const rect = $refs.trigger.getBoundingClientRect(); $el.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 268)) + 'px'; $el.style.top = Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - $el.offsetHeight - 8)) + 'px'; }">
                            <div class="report-process-popover-heading"><span>Data final atualizada</span><button type="button" popovertarget="report-end-popover" popovertargetaction="hide" aria-label="Fechar filtro de data final">×</button></div>
                            <input id="report-end-active" type="hidden" name="report_end_active" form="report-filters" value="{{ $reportEndActive ? '1' : '0' }}">
                            <label class="report-supplier-filter-field" for="report-end-from">De<input id="report-end-from" class="report-process-filter" type="date" name="report_end_from" form="report-filters" value="{{ $reportEndFrom }}" autofocus></label>
                            <label class="report-supplier-filter-field" for="report-end-to">Até<input id="report-end-to" class="report-process-filter" type="date" name="report_end_to" form="report-filters" value="{{ $reportEndTo }}"></label>
                            <div class="report-process-popover-actions">
                                <button type="button" class="secondary-button compact" @click="$el.closest('[popover]').querySelectorAll('input').forEach(input => input.value = ''); document.getElementById('report-filters').requestSubmit()">Limpar</button>
                                <button type="submit" form="report-filters" class="primary-button compact" @click="document.getElementById('report-end-active').value = '1'">Aplicar</button>
                            </div>
                        </div>
                    </th><th>VL_Atual</th><th>Ações</th></tr></thead>
                    <tbody>
                        @forelse($reportContracts as $contract)
                            <tr data-row-href="{{ route('contracts.show', $contract) }}">
                                <td><a class="text-link" href="{{ route('contracts.show', $contract) }}">{{ $contract->numero ?: $contract->id }}</a></td>
                                <td>{{ $contract->processo }}</td>
                                <td>
                                    {{ $contract->modalidade ?: 'Licitação' }} nº {{ $contract->n_licit }}<br>
                                    <strong>{{ $contract->fornecedor_nome_snapshot }}</strong>
                                    @php($amendmentNumber = trim((string) ($contract->latest_amendment_number ?? $contract->n_aditivo)))
                                    @php($amendmentProcess = trim((string) ($contract->latest_amendment_number !== null ? $contract->latest_amendment_process : $contract->proc_aditivo)))
                                    @if($amendmentNumber !== '' && $amendmentNumber !== '0')
                                        <br>Aditivo {{ $amendmentNumber }}@if($amendmentProcess !== '') · Processo {{ $amendmentProcess }}@endif
                                    @endif
                                    @if($reportFundId === null)
                                        @php($contractFundName = $reportFunds->firstWhere('id', $contract->fund_id)?->name ?? $contract->fundo_nome_snapshot)
                                        @if(\App\Infrastructure\Managers\ManagerSignatory::isMunicipal($contractFundName ?? ''))
                                            <br>Secretaria: {{ $contract->secretaria_nome_snapshot ?: 'Não informada' }}
                                        @else
                                            <br>Fundo: {{ $contractFundName ?: 'Não informado' }}
                                        @endif
                                    @endif
                                </td>
                                <td>@if(filled($contract->tipo))<b>{{ $contract->tipo }}</b> - @endif{{ $contract->objeto }}</td>
                                <td class="nowrap-cell">{{ $contract->vigencia_inicio?->format('d/m/Y') ?? 'Não informado' }}</td>
                                <td class="nowrap-cell">{{ ($reportEndActive ? $contract->end_date : $contract->vigencia_fim_atual)?->format('d/m/Y') ?? 'Não informado' }}</td>
                                <td class="money-cell">{{ \App\Models\Contract::moneyLabel($contract->valor_acumulado) }}</td>
                                <td><div class="report-row-actions">
                                    <a class="secondary-button compact" href="{{ route('contracts.show', $contract) }}" aria-label="Resumo do contrato {{ $contract->numero ?: $contract->id }}">Resumo</a>
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="8">Nenhum contrato do Fundo selecionado{{ $reportEndActive || $reportYear === null ? '.' : ' para o ano '.$reportYear.'.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="table-footer">{{ $reportContracts->links() }}</div>
    </section>
</x-layouts.app>
