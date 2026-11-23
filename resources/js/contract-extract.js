import { deliverGeneratedPdf } from './document-delivery.js';
import { startDocumentProgress } from './document-progress.js';

export function contractExtractValues(form) {
    return JSON.stringify([...form.elements].filter((field) => field.name && !field.disabled
        && !['_token', '_method', 'version', 'reason'].includes(field.name))
        .map((field) => [field.name, ['checkbox', 'radio'].includes(field.type) ? field.checked : field.value]));
}

function initializeContractPdf(button, urlAttribute, documentName, openPdf) {
    const form = button.closest('form');
    const feedback = button.closest('.contract-document-actions').querySelector('[data-extract-feedback]');
    const errorDialog = urlAttribute === 'contractDocumentUrl'
        ? button.closest('.contract-document-actions').querySelector('[data-contract-document-error]') : null;
    const showError = (message) => {
        if (errorDialog?.showModal) {
            errorDialog.querySelector('[data-contract-document-error-message]').textContent = message;
            if (!errorDialog.open) errorDialog.showModal();
        } else {
            feedback.textContent = message;
            feedback.hidden = false;
        }
    };
    if (errorDialog?.showModal) {
        errorDialog.querySelector('[data-contract-document-error-close]').addEventListener('click', () => errorDialog.close());
        errorDialog.addEventListener('close', () => button.focus({ preventScroll: true }));
    }
    const baseline = contractExtractValues(form);
    button.addEventListener('click', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        if (contractExtractValues(form) !== baseline) {
            showError('Salve as altera\u00E7\u00F5es do contrato antes de emitir ' + documentName + '.');
            return;
        }
        feedback.hidden = true;
        const url = new URL(button.dataset[urlAttribute], form.ownerDocument.baseURI);
        url.searchParams.set('version', form.elements.namedItem('version').value);
        button.disabled = true;
        const finish = startDocumentProgress(form.ownerDocument);
        try {
            const opening = openPdf(url.href);
            if (opening?.then) await opening;
        } catch (error) {
            finish();
            showError(error.message || 'Não foi possível emitir o documento. Tente novamente.');
        } finally {
            finish();
            button.disabled = false;
        }
    });
}

export function initializeContractExtract(button, openPdf = (url) => deliverGeneratedPdf(url, { fallback: 'extrato.pdf' })) {
    initializeContractPdf(button, 'extractUrl', 'o extrato', openPdf);
}

export function initializeContractPublication(button, openPdf = (url) => deliverGeneratedPdf(url, { fallback: 'publicacao.pdf' })) {
    initializeContractPdf(button, 'publicationUrl', 'a publica\u00E7\u00E3o', openPdf);
}

export async function openContractDocument(url, fetcher = fetch, browser = window) {
    await deliverGeneratedPdf(url, { fetcher, browser, fallback: 'contrato.pdf' });
}

export function initializeContractDocument(button, openPdf = openContractDocument) {
    initializeContractPdf(button, 'contractDocumentUrl', 'o contrato', openPdf);
}

if (typeof document !== 'undefined') {
    // Capture after the application's initial date/number formatting has run.
    queueMicrotask(() => {
        document.querySelectorAll('[data-extract-url]').forEach((button) => initializeContractExtract(button));
        document.querySelectorAll('[data-publication-url]').forEach((button) => initializeContractPublication(button));
        document.querySelectorAll('[data-contract-document-url]').forEach((button) => initializeContractDocument(button));
    });
}
