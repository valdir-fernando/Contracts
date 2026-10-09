<x-layouts.app title="Contratos">
    <div class="page-heading"><div><h1>Contratos</h1><p>Consulte os contratos dos órgãos autorizados para sua conta.</p></div>@can('create', \App\Models\Contract::class)<a class="primary-button compact" href="{{ route('contracts.create') }}">Novo contrato</a>@endcan</div>
    <section class="surface">
        <form class="filter-bar contract-filters" method="GET" action="{{ route('contracts.index') }}">
            <div><label for="q">Buscar</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Número, processo, fornecedor ou documento"></div>
            <div><label for="fund">Fundo</label><select id="fund" name="fund"><option value="">Todos os autorizados</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected((string) request('fund') === (string) $fund->id)>{{ $fund->name }}</option>@endforeach</select></div>
            <div><label for="department">Secretaria</label><select id="department" name="department"><option value="">Todas as autorizadas</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) request('department') === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></div>
            <div><label for="year">Exercício</label><select id="year" name="year"><option value="">Todos</option>@foreach($years as $year)<option value="{{ $year }}" @selected((string) request('year') === (string) $year)>{{ $year }}</option>@endforeach</select></div>
            <div><label for="validity">Vigência</label><select id="validity" name="validity"><option value="">Todas</option>@foreach(\App\Models\Contract::VALIDITY as $value => $label)<option value="{{ $value }}" @selected(request('validity') === $value)>{{ $label }}</option>@endforeach</select></div>
            <button class="secondary-button" type="submit">Filtrar</button><a class="text-link" href="{{ route('contracts.index') }}">Limpar</a>
        </form>
        <div class="contract-reference muted">Situação temporal em {{ today()->format('d/m/Y') }}. Valores iniciais informados; a situação jurídica consta no detalhe.</div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Contrato / processo</th><th>Fornecedor</th><th>Fundo / secretaria</th><th>Vigência</th><th>Valor inicial</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>
            @forelse($contracts as $contract)
                <tr><td><a class="text-link" href="{{ route('contracts.show', $contract) }}"><strong>{{ $contract->numero ?: 'Sem número' }}</strong></a><small>Processo: {{ $contract->processo ?: 'Não informado' }}</small></td>
                    <td class="scope-cell"><strong>{{ $contract->fornecedor_nome_snapshot ?: 'Não informado' }}</strong><small>{{ $contract->displayField('CPF_CNPJ', auth()->user()) }}</small></td>
                    <td class="scope-cell"><strong>{{ $contract->fundo_nome_snapshot ?: 'Não informado' }}</strong><small>{{ $contract->secretaria_nome_snapshot ?: 'Secretaria não informada' }}</small>@if(!$contract->ownership_resolved)<span class="badge badge-warning">Órgão pendente</span>@endif</td>
                    <td><span @class(['badge', 'badge-warning' => in_array($contract->validity, ['em_revisao', 'indeterminado']), 'badge-muted' => $contract->validity === 'vencido'])>{{ \App\Models\Contract::VALIDITY[$contract->validity] }}</span><small>Término: {{ $contract->end_date?->format('d/m/Y') ?? 'Não informado' }}</small></td>
                    <td class="money-cell">{{ \App\Models\Contract::moneyLabel($contract->valor_total) }}</td><td><a class="secondary-button" href="{{ route('contracts.show', $contract) }}">Ver detalhes</a></td></tr>
            @empty<tr><td colspan="6" class="empty-state">Nenhum contrato encontrado para estes filtros ou órgãos autorizados.</td></tr>@endforelse
        </tbody></table></div>
        <div class="table-footer"><span>{{ $contracts->total() }} {{ $contracts->total() === 1 ? 'contrato' : 'contratos' }}</span>{{ $contracts->links() }}</div>
    </section>
</x-layouts.app>
