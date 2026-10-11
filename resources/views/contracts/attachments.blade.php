<section class="surface contract-section">
    <h2>Documentos privados</h2>
    <p class="muted">PDF, JPEG ou PNG, até 10 MB por arquivo. O acesso acompanha as permissões do contrato. Downloads são registrados na auditoria.</p>
    @can('update', $contract)
        <form method="POST" action="{{ route('contracts.attachments.store', $contract) }}" enctype="multipart/form-data">
            @csrf
            <label><span class="field-caption">Nome do documento <x-required-mark/></span><input name="name" maxlength="200" required value="{{ old('name') }}"></label>
            <label><span class="field-caption">Arquivo <x-required-mark/></span><input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
            <button class="secondary-button" type="submit">Anexar documento</button>
        </form>
    @endcan
    @forelse($attachments as $document)
        <details class="audit-entry">
            <summary>{{ $document->name }} · {{ number_format($document->size / 1024, 1, ',', '.') }} KB</summary>
            <p>Anexado em {{ $document->created_at->format('d/m/Y H:i') }} por {{ $document->uploader?->name ?? 'Conta indisponível' }}.</p>
            <p><a class="secondary-button" href="{{ route('contracts.attachments.download', [$contract, $document->id]) }}">Baixar documento</a></p>
            @can('delete', $contract)
                <form method="POST" action="{{ route('contracts.attachments.destroy', [$contract, $document->id]) }}">
                    @csrf @method('DELETE')
                    <p>Para remover, digite {{ $document->id }} e informe o motivo. O arquivo será preservado.</p>
                    <label><span class="field-caption">Confirmação <x-required-mark/></span><input name="confirmation" required></label>
                    <label><span class="field-caption">Motivo <x-required-mark/></span><textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
                    <button class="secondary-button" type="submit">Remover documento</button>
                </form>
            @endcan
        </details>
    @empty
        <p>Nenhum documento anexado.</p>
    @endforelse
    {{ $attachments->withQueryString()->links() }}
    @if(auth()->user()->isAdmin() && $removedAttachments->isNotEmpty())
        <h3>Documentos removidos</h3>
        @foreach($removedAttachments as $document)
            <details class="audit-entry">
                <summary>{{ $document->name }} · removido em {{ $document->deleted_at->format('d/m/Y H:i') }}</summary>
                <form method="POST" action="{{ route('contracts.attachments.restore', [$contract, $document->id]) }}">
                    @csrf
                    <p>Para restaurar, digite {{ $document->id }} e informe o motivo.</p>
                    <label><span class="field-caption">Confirmação <x-required-mark/></span><input name="confirmation" required></label>
                    <label><span class="field-caption">Motivo <x-required-mark/></span><textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
                    <button class="secondary-button" type="submit">Restaurar documento</button>
                </form>
            </details>
        @endforeach
        {{ $removedAttachments->withQueryString()->links() }}
    @endif
</section>
