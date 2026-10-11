<x-layouts.app title="Detalhes do contrato">
    <div class="page-heading"><div><a class="text-link" href="{{ route('contracts.index') }}">← Contratos</a><h1>Contrato {{ $contract->numero ?: 'sem número' }}</h1><p>{{ $contract->fornecedor_nome_snapshot ?: 'Fornecedor não informado' }}</p></div><span class="badge">{{ \App\Models\Contract::VALIDITY[$contract->validity] }}</span></div>
    <p><a class="secondary-button" href="{{ route('contracts.pdf', $contract) }}">Exportar resumo PDF</a></p>
    @can('update', $contract)<p><a class="secondary-button" href="{{ route('contracts.edit', $contract) }}">Editar contrato</a></p>@endcan
    <p><a class="secondary-button" href="{{ route('contracts.finance.index', $contract) }}">Dotação, fichas e empenhos</a></p>
    @if(session('warning'))<div class="notice notice-warning" role="status">{{ session('warning') }}</div>@endif
    @if($contract->vigencia_em_revisao)<div class="notice notice-warning">A vigência está em revisão. Alterar as datas não substitui a conciliação dos aditivos ou das pendências históricas. @if(auth()->user()->isAdmin())<a class="text-link" href="{{ route('contracts.review.edit',$contract) }}">Conferir vigência e valor acumulado</a>@endif</div>@endif
    <div class="contract-reference muted">Situação temporal em {{ today()->format('d/m/Y') }}; consulte os dados de distrato e paralisação para a situação administrativa.</div>
    @if(!$contract->ownership_resolved)<div class="notice notice-warning">O órgão deste contrato ainda não foi conciliado. A consulta está restrita a administradores.</div>@endif
    @if($contract->quality_issues)
        <section class="surface contract-section"><h2>Pendências de qualidade ({{ count($contract->quality_issues) }})</h2><p class="muted">Os dados históricos foram preservados. Estas ocorrências precisam de revisão.</p><ul class="quality-list">@foreach($contract->quality_issues as $issue)<li><strong>{{ \App\Support\ContractFields::label($issue['field']) }}:</strong> {{ $issue['message'] }}</li>@endforeach</ul></section>
    @endif
    @foreach($groups as $group => $fields)
        <section class="surface contract-section"><h2>{{ $group }}</h2><dl class="contract-fields">@foreach($fields as $source => $definition)<div><dt>{{ \App\Support\ContractFields::label($source) }}</dt><dd>{{ $contract->displayField($source, auth()->user()) }}</dd></div>@endforeach</dl></section>
    @endforeach
    <section class="surface contract-section"><h2>Situação administrativa</h2><p>{{ \App\Models\Contract::STATUSES[$contract->administrative_status] ?? 'Não classificada' }}</p><p class="muted">Classificação interna; não certifica a situação jurídica do instrumento.</p></section>
    <section class="surface contract-section"><h2>Aditivos e itens históricos</h2><p class="muted">Vínculos identificados por evidências ou confirmados por administrador. Valores e prazos não são aplicados automaticamente.</p>
        @forelse($history as $entry)<details class="audit-entry"><summary>{{ $entry->kind === 'amendment' ? 'Aditivo' : 'Item' }} · {{ $entry->legacy_id }} · {{ $entry->description }}</summary><p>{{ $entry->event_date?->format('d/m/Y') }} · {{ \App\Models\Contract::moneyLabel($entry->amount) }} · {{ $entry->match_status }}</p>
        @if(auth()->user()->isAdmin())<dl class="contract-fields">@foreach($entry->source->raw_payload as $field => $value)<div><dt>{{ $field }}</dt><dd>{{ $value ?? 'Não informado' }}</dd></div>@endforeach</dl>@endif
        @foreach($entry->quality_issues as $issue)<p>{{ $issue['field'] }}: {{ $issue['message'] }}</p>@endforeach</details>
        @empty<p>Nenhum histórico vinculado. Registros pendentes são tratados na conciliação administrativa.</p>@endforelse
    </section>
    @can('delete', $contract)<section class="surface contract-section"><h2>Excluir contrato</h2><p>Digite {{ $contract->numero ?: $contract->id }} e informe o motivo. O histórico será preservado.</p><form method="POST" action="{{ route('contracts.destroy', $contract) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $contract->version }}"><label>Confirmação<input name="confirmation" required></label><label>Motivo<textarea name="reason" minlength="5" required></textarea></label><button class="secondary-button" type="submit">Confirmar exclusão</button></form></section>@endcan
    @include('contracts.attachments')
    <section class="surface contract-section"><h2>Aditivos cadastrados no sistema</h2><p>Consulte e prepare aditivos documentais, separados do histórico legado e dos valores do contrato.</p><a class="secondary-button" href="{{ route('contracts.amendments.index', $contract) }}">Gerenciar aditivos</a></section>
    @if($audits->isNotEmpty())
        <section class="surface contract-section"><h2>Histórico de alterações</h2><p class="muted">Últimos 20 eventos. O histórico completo permanece preservado.</p>
            @foreach($audits as $audit)<details class="audit-entry"><summary>{{ $audit->created_at->format('d/m/Y H:i') }} · {{ ['created' => 'Cadastro', 'updated' => 'Edição', 'deleted' => 'Exclusão', 'restored' => 'Restauração', 'history_linked' => 'Conciliação'][$audit->action] ?? $audit->action }} · {{ $audit->actor?->name ?? 'Conta indisponível' }}</summary><p>Motivo: {{ $audit->reason ?: 'Cadastro inicial' }}</p>
                @foreach($audit->changes as $column => $change)<p><strong>{{ \App\Support\ContractFields::labelColumn($column) }}:</strong> {{ is_scalar($change['before']) ? $change['before'] : 'Não informado' }} → {{ is_scalar($change['after']) ? $change['after'] : 'Não informado' }}</p>@endforeach
            </details>@endforeach
        </section>
    @endif
</x-layouts.app>
