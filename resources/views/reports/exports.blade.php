<x-layouts.app title="Exportações">
    <div class="page-heading"><div><h1>Minhas exportações</h1><p>Acompanhe seus relatórios. Os arquivos ficam disponíveis por 24 horas.</p></div><a class="secondary-button" href="{{ route('reports.index') }}">Novo relatório</a></div>
    <div class="report-generation-list" data-report-cards>
        @forelse($exports as $export)
            @php($state = $export->expires_at->isFuture() ? $export->status : 'expired')
            <article class="surface report-generation-card" data-report-card data-export-id="{{ $export->id }}" data-state="{{ $state }}" data-status-url="{{ route('reports.status', $export) }}" aria-busy="{{ in_array($state, ['pending', 'processing']) ? 'true' : 'false' }}">
                <span class="report-spinner" data-report-spinner @if(!in_array($state, ['pending', 'processing'])) hidden @endif aria-hidden="true"></span>
                <div class="report-generation-body"><h3>{{ $export->report_kind === 'audit' ? 'Auditoria' : 'Relatório de contratos' }} · {{ strtoupper($export->format) }}</h3><p role="status" aria-live="polite" data-report-message>{{ ['pending' => 'Preparando relatório…', 'processing' => 'Gerando relatório…', 'ready' => 'Relatório pronto para baixar.', 'failed' => 'Não foi possível concluir a geração.', 'revoked' => 'Acesso ou dados alterados.', 'expired' => 'Relatório expirado.'][$state] ?? 'Relatório indisponível.' }}</p><small class="muted">Solicitado em {{ $export->created_at->format('d/m/Y H:i') }}</small></div>
                <a class="primary-button compact" data-report-download href="{{ route('reports.download', $export) }}" @unless($state === 'ready' && $export->sha256 !== null && $export->size !== null) hidden @endunless>Baixar relatório</a>
                <a class="text-link report-retry-link" href="{{ $export->report_kind === 'audit' && auth()->user()->isAdmin() ? route('administration.index') : route('reports.index') }}">Solicitar novamente</a>
            </article>
        @empty
            <section class="surface contract-section"><p>Nenhuma exportação solicitada.</p></section>
        @endforelse
    </div>
    {{ $exports->links() }}
</x-layouts.app>
