import { chooseAtaDocumentFormat, chooseAtaProductsLayout, showSupplierDocumentWarning } from './system-message.js';

export function openAtaExtract(blob, ownerDocument = document, browser = window, filename = 'extrato-ata.pdf') {
    const url = URL.createObjectURL(blob);
    const tab = browser.open(url, '_blank');
    if (tab) tab.opener = null;
    else {
        const link = ownerDocument.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
    }
    browser.setTimeout(() => URL.revokeObjectURL(url), 60000);
}

export function initializeAtaExtract(root, buttons, fetcher = fetch, warn = showSupplierDocumentWarning, openPdf = openAtaExtract, urlKey = 'ataExtractUrl', invalidMessage, chooseFormat = chooseAtaDocumentFormat, chooseLayout = chooseAtaProductsLayout) {
    let pending = false;
    buttons.forEach(button => button.addEventListener('click', async () => {
        if (pending || button.disabled) return;
        if (!root.dataset.ataLoaded) { warn('Salve ou consulte uma ata pelo Nº ATA antes de emitir o extrato.', root.ownerDocument); return; }
        if (root.dataset.editorBusy === 'true') { warn('Aguarde a conclusão da operação antes de emitir o extrato.', root.ownerDocument); return; }
        if (root.dataset.ataValidityDirty === 'true') { warn('Salve a vigência antes de emitir o extrato.', root.ownerDocument); return; }
        const ataId = root.dataset.ataLoaded;
        const version = root.dataset.ataVersion;
        pending = true;
        let format = 'pdf';
        let layout = 'continuous';
        if (urlKey === 'ataDocumentUrl') {
            try {
                format = await chooseFormat(root.ownerDocument);
                if (!['pdf', 'docx'].includes(format) || root.dataset.ataLoaded !== ataId || root.dataset.ataVersion !== version) return;
                layout = await chooseLayout(root.ownerDocument);
            } finally {
                pending = false;
            }
            if (!['pdf', 'docx'].includes(format) || root.dataset.ataLoaded !== ataId || root.dataset.ataVersion !== version) return;
            if (!['continuous', 'parallel'].includes(layout)) return;
            if (root.dataset.editorBusy === 'true' || root.dataset.ataValidityDirty === 'true') {
                warn('Os dados mudaram. Salve ou consulte novamente a ata antes de emitir.', root.ownerDocument);
                return;
            }
        }
        pending = true;
        button.disabled = true;
        root.dispatchEvent(new CustomEvent('ata-product-saving', { bubbles: true }));
        try {
            const url = new URL(button.dataset[urlKey].replace('/0/', `/${encodeURIComponent(ataId)}/`), root.ownerDocument.baseURI);
            url.searchParams.set('version', version);
            if (urlKey === 'ataDocumentUrl') {
                url.searchParams.set('format', format);
                url.searchParams.set('products_layout', layout);
            }
            const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (root.dataset.ataLoaded !== ataId || root.dataset.ataVersion !== version) return;
            if (response.redirected || !response.ok) {
                const messages = {
                    401: 'Sua sessão terminou. Entre novamente para emitir o extrato.',
                    403: 'Você não tem permissão para emitir o extrato desta ata.',
                    404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'Os dados da ata, da administração ou do modelo mudaram. Consulte novamente a ata e gere o extrato outra vez.',
                    419: 'Sua sessão terminou. Entre novamente para emitir o extrato.',
                    422: invalidMessage ?? 'Confira os dados e consulte novamente a ata antes de emitir o extrato.',
                    429: 'Foram feitas muitas tentativas. Aguarde um momento e tente novamente.',
                };
                warn(messages[response.redirected ? 401 : response.status] || 'O sistema encontrou um problema ao gerar o extrato. Tente novamente. Se continuar, avise o administrador.', root.ownerDocument);
                return;
            }
            const contentType = format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf';
            if (!response.headers.get('Content-Type')?.includes(contentType)) throw new Error();
            const blob = await response.blob();
            if (root.dataset.ataLoaded === ataId && root.dataset.ataVersion === version) openPdf(blob, root.ownerDocument, format);
        } catch {
            if (root.dataset.ataLoaded === ataId) warn('Não foi possível abrir o extrato. Confira sua conexão e tente novamente.', root.ownerDocument);
        } finally {
            pending = false;
            button.disabled = false;
            root.dispatchEvent(new CustomEvent('ata-product-finished', { bubbles: true }));
        }
    }));
}

if (typeof document !== 'undefined') {
    const root = document.querySelector('.ata-editor');
    if (root) {
        initializeAtaExtract(root, [...document.querySelectorAll('[data-ata-extract-url]')]);
        initializeAtaExtract(root, [...document.querySelectorAll('[data-ata-document-url]')], fetch,
            (message, ownerDocument) => showSupplierDocumentWarning(message.replaceAll('o extrato', 'a ata').replaceAll('do extrato', 'da ata'), ownerDocument),
            (blob, ownerDocument, format) => {
                if (format === 'docx') downloadAtaWord(blob, ownerDocument);
                else openAtaExtract(blob, ownerDocument, window, 'ata.pdf');
            }, 'ataDocumentUrl',
            'Para emitir a ata, salve a opção Admitir Adesão a ATA em SIM e consulte o registro novamente.');
    }
}

export function downloadAtaWord(blob, ownerDocument = document, browser = window) {
    const url = URL.createObjectURL(blob);
    const link = ownerDocument.createElement('a');
    link.href = url;
    link.download = 'ata.docx';
    ownerDocument.body.append(link);
    link.click();
    link.remove();
    browser.setTimeout(() => URL.revokeObjectURL(url), 60000);
}
