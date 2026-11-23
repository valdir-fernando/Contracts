@php
    $signedAmendment = $signedAmendment ?? false;
    $signedPrefix = $signedAmendment ? 'ata-amend-signed' : 'ata-signed';
    $signedRoutes = $signedAmendment ? 'atas.amendments.signed-document.' : 'atas.signed-document.';
    $signedParams = $signedAmendment ? ['ata' => 0, 'amendment' => 0] : ['ata' => 0];
@endphp
<div class="ata-signed-document{{ $signedAmendment ? ' ata-amend-signed-document' : '' }}" data-ata-signed-document
    data-preview-url="{{ route($signedRoutes.'preview', $signedParams) }}"
    data-store-url="{{ route($signedRoutes.'store', $signedParams) }}"
    data-remove-url="{{ route($signedRoutes.'destroy', $signedParams) }}"
    data-original-url="{{ route($signedRoutes.'download', $signedParams + ['copy' => 'original']) }}"
    data-redacted-url="{{ route($signedRoutes.'download', $signedParams + ['copy' => 'redacted']) }}"
    @if($signedAmendment)
        data-signed-amendment
        x-effect="$dispatch('ata-signed-amendment-context', { ...selectedAmendmentDocument, number: savedAmendmentNumber })"
        @ata-signed-document-updated="loadedVersion = $event.detail.version; amendmentSignedDocuments[savedAmendmentNumber] = { id: selectedAmendmentDocument.id, copies: $event.detail.signed_document_copies }"
    @else
        @ata-signed-document-updated="loadedVersion = $event.detail.version"
    @endif>
    <div class="field ata-signed-file-field">
        <label for="{{ $signedPrefix }}-file">{{ $signedAmendment ? 'Aditivo assinado (PDF)' : 'Ata assinada (PDF)' }}</label>
        <input id="{{ $signedPrefix }}-file" type="file" accept=".pdf,application/pdf"
            :disabled="busy || !loaded{{ $signedAmendment ? ' || !selectedAmendmentDocument.id' : '' }}" disabled>
    </div>
    <p class="ata-signed-feedback" role="status" data-signed-status></p>
    <dialog class="catalog-modal ata-signed-review" aria-label="Revisar tarjas do PDF" closedby="none">
        <p class="ata-signed-review-instructions">Abra as páginas que contêm CPF e RG dos contratantes e do contratado e arraste sobre cada ocorrência.</p>
        <div class="ata-signed-review-toolbar">
            <button type="button" class="secondary-button" data-signed-first>Primeira Página</button>
            <button type="button" class="secondary-button" data-signed-prev>Anterior</button>
            <span data-signed-page aria-live="polite"></span>
            <button type="button" class="secondary-button" data-signed-next>Próxima</button>
            <button type="button" class="secondary-button" data-signed-last>Última Página</button>
            <button type="button" class="secondary-button" data-signed-undo>Desfazer última tarja da página</button>
        </div>
        <div class="ata-signed-page-preview">
            <canvas aria-label="Prévia da página para marcar tarjas"></canvas>
        </div>
        <p role="status" data-signed-error></p>
        <div class="form-actions">
            <label class="ata-signed-confirm"><input type="checkbox" data-signed-confirm> Confirmo que todos os CPF e RG foram tarjados</label>
            <button type="button" class="secondary-button" data-signed-cancel>Cancelar</button>
            <button type="button" class="primary-button" data-signed-save disabled>Gravar original e cópia tarjada</button>
        </div>
    </dialog>
    <dialog class="catalog-modal ata-signed-progress" data-signed-progress aria-labelledby="{{ $signedPrefix }}-progress-title" closedby="none">
        <div class="modal-header"><h2 id="{{ $signedPrefix }}-progress-title">Gravando o PDF tarjado</h2></div>
        <label for="{{ $signedPrefix }}-save-progress">Progresso estimado</label>
        <span data-signed-percent aria-hidden="true">0%</span>
        <progress id="{{ $signedPrefix }}-save-progress" class="document-progress-bar" data-signed-progress-bar max="100" value="0"></progress>
    </dialog>
    <dialog class="catalog-modal" data-signed-upload-choice aria-labelledby="{{ $signedPrefix }}-upload-choice-title">
        <div class="modal-header"><h2 id="{{ $signedPrefix }}-upload-choice-title">{{ $signedAmendment ? 'PDF do aditivo assinado' : 'PDF da ata assinada' }}</h2></div>
        <form method="dialog" class="form-actions">
            <button type="submit" class="secondary-button" value="cancel" autofocus>Cancelar</button>
            <button type="submit" class="secondary-button" value="original">Salvar original e tarjar depois</button>
            <button type="submit" class="primary-button" value="pair">Tarjar agora</button>
        </form>
    </dialog>
    <dialog class="catalog-modal" data-signed-removal aria-labelledby="{{ $signedPrefix }}-removal-title">
        <div class="modal-header">
            <h2 id="{{ $signedPrefix }}-removal-title">REMOÇÃO DE DOCUMENTOS PDF - Nº {{ $signedAmendment ? 'ADITIVO' : 'ATA' }} <span data-signed-removal-number></span></h2>
            <button type="button" class="modal-close" data-signed-removal-close aria-label="Fechar" title="Fechar" autofocus>×</button>
        </div>
        <table class="data-table">
            <thead><tr><th scope="col">Documento</th><th scope="col">Tipo</th><th scope="col">Ação</th></tr></thead>
            <tbody>
                <tr data-signed-row="original" hidden>
                    <td><a class="text-link" data-signed-file>{{ $signedAmendment ? 'aditivo' : 'ata' }}-original.pdf</a></td>
                    <td>Original</td>
                    <td><button type="button" class="danger-button" data-signed-remove-copy="original" aria-label="Remover PDF original">Remover</button></td>
                </tr>
                <tr data-signed-row="redacted" hidden>
                    <td><a class="text-link" data-signed-file>{{ $signedAmendment ? 'aditivo' : 'ata' }}-tarjada.pdf</a></td>
                    <td>Tarjado</td>
                    <td><button type="button" class="danger-button" data-signed-remove-copy="redacted" aria-label="Remover PDF tarjado">Remover</button></td>
                </tr>
                <tr data-signed-removal-empty hidden><td colspan="3">Nenhum PDF disponível.</td></tr>
            </tbody>
        </table>
        <p role="status" data-signed-removal-status></p>
    </dialog>
</div>
