<x-layouts.app title="Contratos">
    <div class="page-heading contract-archive-heading" x-data>
        <div><span class="section-label">ACERVO CONTRATUAL</span><h1>{{ (['vigente' => 'Contratos vigentes', 'vencido' => 'Contratos vencidos', 'em_revisao' => 'Contratos com vigência em revisão', 'a_iniciar' => 'Contratos a iniciar', 'indeterminado' => 'Contratos com vigência indeterminada'][request('validity')] ?? 'Contratos') }}</h1><p>Consulte os contratos dos órgãos autorizados para sua conta.</p></div>
        @can('create', \App\Models\Contract::class)
            <nav class="quick-actions" aria-label="Ações de contratos">
                <a class="primary-button compact" href="{{ route('contracts.create') }}"><x-nav-icon name="plus"/>Novo contrato</a>
                <button type="button" class="secondary-button compact" aria-haspopup="dialog" aria-controls="contract-amendment-panel" @click="$refs.amendmentPanel.showModal()"><x-nav-icon name="edit"/>Aditivo</button>
                <button type="button" class="secondary-button compact" aria-haspopup="dialog" aria-controls="contract-termination-panel" @click="$refs.terminationPanel.showModal()"><x-nav-icon name="document-termination"/>Distrato</button>
            </nav>
            <dialog id="contract-amendment-panel" class="catalog-modal contract-future-panel" x-ref="amendmentPanel" aria-labelledby="contract-amendment-panel-title">
                <div class="modal-header"><h2 id="contract-amendment-panel-title">Aditivo</h2><button type="button" class="modal-close" aria-label="Fechar painel de aditivo" @click="$refs.amendmentPanel.close()">×</button></div>
            </dialog>
            <dialog id="contract-termination-panel" class="catalog-modal contract-future-panel" x-ref="terminationPanel" aria-labelledby="contract-termination-panel-title">
                <div class="modal-header"><h2 id="contract-termination-panel-title">Distrato</h2><button type="button" class="modal-close" aria-label="Fechar painel de distrato" @click="$refs.terminationPanel.close()">×</button></div>
            </dialog>
        @endcan
    </div>
    @if(auth()->user()->isAdmin())<nav class="view-tabs" aria-label="Visualização dos contratos"><a href="{{ route('contracts.index') }}" @class(['active' => !request()->boolean('deleted')]) @unless(request()->boolean('deleted')) aria-current="page" @endunless>Contratos atuais</a><a href="{{ route('contracts.index', ['deleted' => 1]) }}" @class(['active' => request()->boolean('deleted')]) @if(request()->boolean('deleted')) aria-current="page" @endif>Contratos excluídos</a></nav>@endif
    <section class="surface contract-archive-surface">
        <details class="filter-disclosure" open data-mobile-disclosure data-active-filters="{{ collect(request()->except(['page', 'deleted', 'sort', 'direction']))->filter(fn($value) => $value !== null && $value !== '')->isNotEmpty() ? 'true' : 'false' }}">
            <summary><x-nav-icon name="search"/>Filtros e pesquisa</summary>
        <form class="filter-bar contract-filters" method="GET" action="{{ route('contracts.index') }}">
            <input type="hidden" name="deleted" value="{{ request('deleted', 0) }}"><div><label for="q">Buscar</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Número, processo, fornecedor ou documento"></div>
            <div><label for="fund">Fundo</label><select id="fund" name="fund"><option value="">Todos os autorizados</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) request('fund') === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select></div>
            <div><label for="department">Secretaria</label><select id="department" name="department"><option value="">Todas as autorizadas</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) request('department') === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></div>
            <div><label for="year">Exercício</label><select id="year" name="year"><option value="">Todos</option>@foreach($years as $year)<option value="{{ $year }}" @selected((string) request('year') === (string) $year)>{{ $year }}</option>@endforeach</select></div>
            <div><label for="type">Tipo</label><select id="type" name="type"><option value="">Todos</option>@foreach($types as $type)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>@endforeach</select></div>
            <div><label for="validity">Vigência</label><select id="validity" name="validity"><option value="">Todas</option>@foreach(\App\Models\Contract::REPORT_VALIDITY as $value => $label)<option value="{{ $value }}" @selected(request('validity') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="sort">Ordenar por</label><select id="sort" name="sort">@foreach(['exercicio'=>'Exercício','numero'=>'Contrato','valor_total'=>'Valor atualizado','data_contrato'=>'Assinatura'] as $value=>$label)<option value="{{ $value }}" @selected(request('sort','exercicio')===$value)>{{ $label }}</option>@endforeach</select></div><div><label for="direction">Direção</label><select id="direction" name="direction"><option value="desc">Decrescente</option><option value="asc" @selected(request('direction')==='asc')>Crescente</option></select></div>
            <div class="filter-actions"><label class="checkbox-label"><input type="checkbox" name="review" value="1" @checked(request()->boolean('review'))>Somente pendências de vigência ou órgão</label><a class="text-link" href="{{ route('contracts.index') }}">Limpar filtros</a><button class="primary-button compact" type="submit"><x-nav-icon name="search"/>Filtrar</button></div>
            <details class="contract-section" @if(collect(request()->only(['object','commitment','budget','modality','status','from','to','min_value','max_value']))->filter()->isNotEmpty()) open @endif><summary>Pesquisa avançada</summary>
                <div class="form-grid">
                    @foreach(['object'=>'Objeto contém','commitment'=>'Empenho contém','budget'=>'Ficha contém'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" maxlength="255" value="{{ request($key) }}"></label>@endforeach
                    <label>Modalidade<select name="modality"><option value="">Todas</option>@foreach($modalities as $modality)<option value="{{ $modality }}" @selected(request('modality') === $modality)>{{ $modality }}</option>@endforeach</select></label>
                    <label>Situação administrativa<select name="status"><option value="">Todas</option>@foreach(\App\Models\Contract::STATUSES as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
                    <label>Data do período<select name="date_field">@foreach(['data_contrato'=>'Assinatura','vigencia_inicio'=>'Início de vigência','end'=>'Término atualizado'] as $key=>$label)<option value="{{ $key }}" @selected(request('date_field','vigencia_inicio') === $key)>{{ $label }}</option>@endforeach</select></label>
                    <label>De<input type="date" name="from" value="{{ request('from') }}"></label><label>Até<input type="date" name="to" value="{{ request('to') }}"></label>
                    <label>Valor atualizado mínimo<input name="min_value" inputmode="decimal" placeholder="Ex.: 1.908,6300" value="{{ request('min_value') }}"></label>
                    <label>Valor atualizado máximo<input name="max_value" inputmode="decimal" value="{{ request('max_value') }}"></label>
                </div><button class="secondary-button" type="submit">Aplicar pesquisa avançada</button>
            </details>
        </form>
        </details>
        <div class="registry-toolbar"><span><strong>{{ number_format($contracts->total(), 0, ',', '.') }}</strong> {{ $contracts->total() === 1 ? 'contrato encontrado' : 'contratos encontrados' }} · Referência em {{ today()->format('d/m/Y') }}</span>@unless(request()->boolean('deleted'))<a class="text-link" href="{{ route('reports.index', request()->except(['page','deleted'])) }}">Abrir estes filtros nos relatórios e exportações →</a>@endunless</div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Contrato / processo</th><th>Fornecedor</th><th>Fundo / secretaria</th><th>Tipo / situação administrativa</th><th>Vigência</th><th>Valor atualizado</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>
            @forelse($contracts as $contract)
                <tr @unless($contract->trashed()) data-row-href="{{ route('contracts.show', $contract) }}" @endunless><td>@if($contract->trashed())<strong>{{ $contract->numero ?: 'Sem número' }}</strong>@else<a class="text-link" href="{{ route('contracts.show', $contract) }}"><strong>{{ $contract->numero ?: 'Sem número' }}</strong></a>@endif<small>Processo: {{ $contract->processo ?: 'Não informado' }}</small></td>
                    <td class="scope-cell"><strong>{{ $contract->fornecedor_nome_snapshot ?: 'Não informado' }}</strong><small>{{ $contract->displayField('CPF_CNPJ', auth()->user()) }}</small></td>
                    <td class="scope-cell"><strong>{{ $contract->fundo_nome_snapshot ?: 'Não informado' }}</strong><small>{{ $contract->secretaria_nome_snapshot ?: 'Secretaria não informada' }}</small>@if(!$contract->ownership_resolved)<span class="badge badge-warning">Órgão pendente</span>@endif</td>
                    <td>{{ $contract->tipo ?: 'Não informado' }}<small>{{ \App\Models\Contract::STATUSES[$contract->administrative_status] }}</small></td><td><span @class(['badge', 'badge-success' => $contract->report_validity === 'vigente', 'badge-warning' => in_array($contract->report_validity, ['em_revisao', 'indeterminado']), 'badge-muted' => $contract->report_validity === 'vencido'])>{{ \App\Models\Contract::VALIDITY[$contract->report_validity] }}</span><small>Término: {{ $contract->vigencia_fim_atual?->format('d/m/Y') ?? 'Não informado' }}</small></td>
                    <td class="money-cell">{{ \App\Models\Contract::moneyLabel($contract->valor_acumulado) }}</td><td>@if($contract->trashed())<form method="POST" action="{{ route('contracts.restore', $contract) }}">@csrf<input type="hidden" name="version" value="{{ $contract->version }}"><label><span class="field-caption">Confirme {{ $contract->numero ?: $contract->id }} <x-required-mark/></span><input name="confirmation" required></label><label><span class="field-caption">Motivo <x-required-mark/></span><input name="reason" minlength="5" required></label><button class="secondary-button">Restaurar</button></form>@else<a class="secondary-button" href="{{ route('contracts.show', $contract) }}">Ver detalhes</a>@endif</td></tr>
            @empty<tr><td colspan="7" class="empty-state">Nenhum contrato encontrado para estes filtros ou órgãos autorizados.</td></tr>@endforelse
        </tbody></table></div>
        <div class="table-footer"><span>{{ $contracts->total() }} {{ $contracts->total() === 1 ? 'contrato' : 'contratos' }}</span>{{ $contracts->links() }}</div>
    </section>
</x-layouts.app>
