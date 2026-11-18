import { initializeAtaExtract, openAtaExtract } from './ata-extract.js';
import { showSupplierDocumentWarning } from './system-message.js';

export function initializeAtaPublication(root, buttons, fetcher = fetch, warn = showSupplierDocumentWarning,
    openPdf = (blob, ownerDocument) => openAtaExtract(blob, ownerDocument, window, 'publicacao-ata.pdf')) {
    initializeAtaExtract(root, buttons, fetcher,
        (message, ownerDocument) => warn(message.replaceAll('o extrato', 'a publicação').replaceAll('do extrato', 'da publicação'), ownerDocument),
        openPdf, 'ataPublicationUrl');
}

if (typeof document !== 'undefined') {
    const root = document.querySelector('.ata-editor');
    if (root) initializeAtaPublication(root, [...document.querySelectorAll('[data-ata-publication-url]')]);
}
