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
        try {
            const opening = openPdf(url.href);
            if (opening?.then) await opening;
        } catch (error) {
            showError(error.message || 'Não foi possível emitir o documento. Tente novamente.');
        } finally {
            button.disabled = false;
        }
    });
}

export function initializeContractExtract(button, openPdf = (url) => window.open(url, '_blank', 'noopener')) {
    initializeContractPdf(button, 'extractUrl', 'o extrato', openPdf);
}

export function initializeContractPublication(button, openPdf = (url) => window.open(url, '_blank', 'noopener')) {
    initializeContractPdf(button, 'publicationUrl', 'a publica\u00E7\u00E3o', openPdf);
}

export async function openContractDocument(url, fetcher = fetch, browser = window) {
    const response = await fetcher(url, { headers: { Accept: 'application/json' } });
    if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.message || 'Não foi possível emitir o contrato. Confira sua sessão e tente novamente.');
    }
    const objectUrl = URL.createObjectURL(await response.blob());
    const tab = browser.open(objectUrl, '_blank');
    if (tab) tab.opener = null;
    else {
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = 'contrato.pdf';
        link.click();
    }
    browser.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
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
