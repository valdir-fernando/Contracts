export function contractExtractValues(form) {
    return JSON.stringify([...form.elements].filter((field) => field.name && !field.disabled
        && !['_token', '_method', 'version', 'reason'].includes(field.name))
        .map((field) => [field.name, ['checkbox', 'radio'].includes(field.type) ? field.checked : field.value]));
}

function initializeContractPdf(button, urlAttribute, documentName, openPdf) {
    const form = button.closest('form');
    const feedback = button.closest('.contract-document-actions').querySelector('[data-extract-feedback]');
    const baseline = contractExtractValues(form);
    button.addEventListener('click', (event) => {
        event.preventDefault();
        if (contractExtractValues(form) !== baseline) {
            feedback.textContent = 'Salve as altera\u00E7\u00F5es do contrato antes de emitir ' + documentName + '.';
            feedback.hidden = false;
            return;
        }
        feedback.hidden = true;
        const url = new URL(button.dataset[urlAttribute], form.ownerDocument.baseURI);
        url.searchParams.set('version', form.elements.namedItem('version').value);
        openPdf(url.href);
    });
}

export function initializeContractExtract(button, openPdf = (url) => window.open(url, '_blank', 'noopener')) {
    initializeContractPdf(button, 'extractUrl', 'o extrato', openPdf);
}

export function initializeContractPublication(button, openPdf = (url) => window.open(url, '_blank', 'noopener')) {
    initializeContractPdf(button, 'publicationUrl', 'a publica\u00E7\u00E3o', openPdf);
}

if (typeof document !== 'undefined') {
    // Capture after the application's initial date/number formatting has run.
    queueMicrotask(() => {
        document.querySelectorAll('[data-extract-url]').forEach((button) => initializeContractExtract(button));
        document.querySelectorAll('[data-publication-url]').forEach((button) => initializeContractPublication(button));
    });
}