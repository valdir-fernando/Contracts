import { startDocumentProgress } from './document-progress.js';
import { showSupplierDocumentWarning } from './system-message.js';

export function documentFilename(headers, fallback) {
    const disposition = headers.get('Content-Disposition') ?? '';
    const encoded = disposition.match(/filename\*=utf-8''([^;]+)/i)?.[1];
    const plain = disposition.match(/filename="?([^";]+)"?/i)?.[1];
    if (encoded) {
        try { return decodeURIComponent(encoded); } catch { /* Use the standard filename. */ }
    }
    return plain || fallback;
}

export async function deliverGeneratedPdf(url, {
    fetcher = fetch, browser = window, ownerDocument = browser.document, sameTab = false, fallback = 'documento.pdf',
} = {}) {
    const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.message || 'Não foi possível emitir o documento. Confira sua sessão e tente novamente.');
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') ?? '';
    const objectUrl = URL.createObjectURL(blob);
    browser.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
    if (!/^attachment\b/i.test(disposition)) {
        if (sameTab) {
            browser.location.assign(objectUrl);
            return;
        }
        const tab = browser.open(objectUrl, '_blank');
        if (tab) {
            tab.opener = null;
            return;
        }
    }

    const link = ownerDocument.createElement('a');
    link.href = objectUrl;
    link.download = documentFilename(response.headers, fallback);
    ownerDocument.body.append(link);
    link.click();
    link.remove();
}

export function initializeGeneratedPdfLinks(ownerDocument, deliver = deliverGeneratedPdf, warn = showSupplierDocumentWarning) {
    const pending = new WeakSet();
    ownerDocument.addEventListener('click', async event => {
        const link = event.target.closest('a[data-dfd-pdf], a[data-service-order-pdf], a[data-document-pdf]');
        if (!link || !link.href || link.hidden || event.defaultPrevented || event.button !== 0
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (pending.has(link)) return;
        pending.add(link);
        const finish = startDocumentProgress(ownerDocument);
        try {
            await deliver(link.href, { ownerDocument, sameTab: link.target !== '_blank' });
        } catch (error) {
            finish();
            warn(error.message, ownerDocument);
        } finally {
            finish();
            pending.delete(link);
        }
    });
}

if (typeof document !== 'undefined') initializeGeneratedPdfLinks(document);
