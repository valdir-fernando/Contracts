<x-layouts.app title="Detalhes do contrato">
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.index') }}">← Contratos</a><h1>Contrato {{ $contract->numero ?: 'sem número' }}</h1><p>{{ $contract->fornecedor_nome_snapshot ?: 'Fornecedor não informado' }}</p></div><span class="badge">{{ \App\Models\Contract::VALIDITY[$contract->validity] }}</span></div>
    @can('update', $contract)<p><a class="secondary-button" href="{{ route('contracts.edit', $contract) }}">Editar contrato</a></p>@endcan
    @if(session('warning'))<div class="notice notice-warning" role="status">{{ session('warning') }}</div>@endif
    @if($contract->vigencia_em_revisao)<div class="notice notice-warning">A vigência está em revisão. Alterar as datas não substitui a conciliação dos aditivos ou das pendências históricas.</div>@endif
    <div class="contract-reference muted">Situação temporal em {{ today()->format('d/m/Y') }}; consulte os dados de distrato e paralisação para a situação administrativa.</div>
    @if(!$contract->ownership_resolved)<div class="notice notice-warning">O órgão deste contrato ainda não foi conciliado. A consulta está restrita a administradores.</div>@endif
    @if($contract->quality_issues)
        <section class="surface contract-section"><h2>Pendências de qualidade ({{ count($contract->quality_issues) }})</h2><p class="muted">Os dados históricos foram preservados. Estas ocorrências precisam de revisão.</p><ul class="quality-list">@foreach($contract->quality_issues as $issue)<li><strong>{{ \App\Support\ContractFields::label($issue['field']) }}:</strong> {{ $issue['message'] }}</li>@endforeach</ul></section>
    @endif
    @foreach($groups as $group => $fields)
        <section class="surface contract-section"><h2>{{ $group }}</h2><dl class="contract-fields">@foreach($fields as $source => $definition)<div><dt>{{ \App\Support\ContractFields::label($source) }}</dt><dd>{{ $contract->displayField($source, auth()->user()) }}</dd></div>@endforeach</dl></section>
    @endforeach
    @if($audits->isNotEmpty())
        <section class="surface contract-section"><h2>Histórico de alterações</h2><p class="muted">Últimos 20 eventos. O histórico completo permanece preservado.</p>
            @foreach($audits as $audit)<details class="audit-entry"><summary>{{ $audit->created_at->format('d/m/Y H:i') }} · {{ $audit->action === 'created' ? 'Cadastro' : 'Edição' }} · {{ $audit->actor?->name ?? 'Conta indisponível' }}</summary><p>Motivo: {{ $audit->reason ?: 'Cadastro inicial' }}</p>
                @foreach($audit->changes as $column => $change)<p><strong>{{ \App\Support\ContractFields::labelColumn($column) }}:</strong> {{ is_scalar($change['before']) ? $change['before'] : 'Não informado' }} → {{ is_scalar($change['after']) ? $change['after'] : 'Não informado' }}</p>@endforeach
            </details>@endforeach
        </section>
    @endif
</x-layouts.app>
