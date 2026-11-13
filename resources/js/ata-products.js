import { formatDecimalField } from './decimal-fields.js';
import { showSupplierDocumentWarning } from './system-message.js';

export function initializeAtaProducts(root, fetcher = fetch, warn = showSupplierDocumentWarning) {
    const panel = root.querySelector('.contract-products-preview');
    const feedback = panel.querySelector('[data-ata-item-lookup-url]');
    const field = name => panel.querySelector(`[data-product-field="${name}"]`);
    const item = field('item');
    const opener = panel.querySelector('[data-product-item-search]');
    const description = panel.querySelector('[data-product-description]');
    let timer;
    let controller;
    let sequence = 0;
    let resolved = '';
    const clearFields = () => {
        ['code', 'brand', 'unit'].forEach(name => { field(name).value = ''; });
        ['disp', 'unit_price'].forEach(name => { field(name).value = '0,0000'; });
        description.value = '';
        description.style.height = '';
    };
    const invalidate = () => {
        clearTimeout(timer);
        controller?.abort();
        controller = null;
        sequence++;
        resolved = '';
        feedback.textContent = '';
        clearFields();
    };
    const reset = () => { invalidate(); item.value = ''; };
    const lookup = async (showWarning = true) => {
        clearTimeout(timer);
        const ataId = root.dataset.ataLoaded;
        const requested = item.value.trim();
        if (!ataId || root.dataset.editorBusy === 'true' || !requested) return;
        const key = JSON.stringify([ataId, requested]);
        if (resolved === key) return;
        invalidate();
        const current = sequence;
        const attempt = new AbortController();
        controller = attempt;
        feedback.textContent = 'Consultando item…';
        try {
            const url = new URL(feedback.dataset.ataItemLookupUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), root.ownerDocument.baseURI);
            url.searchParams.set('item', requested);
            const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: attempt.signal });
            const data = await response.json();
            if (current !== sequence || attempt.signal.aborted || root.dataset.ataLoaded !== ataId || item.value.trim() !== requested) return;
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Não foi possível consultar o Item.');
            if (data.process !== root.querySelector('[name="ata_process"]').value.trim()) {
                throw new Error('O Processo da ata foi alterado. Consulte novamente pelo Nº ATA.');
            }
            const product = data.product;
            ['code', 'brand', 'unit'].forEach(name => { field(name).value = product[name] ?? ''; });
            field('disp').value = formatDecimalField(product.remaining);
            field('unit_price').value = formatDecimalField(product.unit_price);
            description.value = product.description ?? '';
            // Let the description grow to the same five-line limit as contract products.
            description.style.height = 'auto';
            const lineHeight = parseFloat(root.ownerDocument.defaultView.getComputedStyle(description).lineHeight);
            description.style.height = `${Math.min(description.scrollHeight + 2, lineHeight * 5 + 14)}px`;
            resolved = key;
            feedback.textContent = '';
        } catch (error) {
            if (current !== sequence || attempt.signal.aborted) return;
            clearFields();
            const message = ['TypeError', 'SyntaxError'].includes(error.name) ? 'Não foi possível consultar o Item. Tente novamente.' : error.message;
            feedback.textContent = message;
            if (showWarning) warn(message, root.ownerDocument);
        } finally {
            if (current === sequence) controller = null;
        }
    };
    item.addEventListener('input', () => { invalidate(); if (item.value.trim()) timer = setTimeout(() => lookup(false), 350); });
    item.addEventListener('blur', () => lookup());
    opener.addEventListener('mousedown', event => event.preventDefault());
    opener.addEventListener('click', () => lookup());
    ['ata-loading', 'ata-cleared', 'ata-loaded'].forEach(event => root.addEventListener(event, reset));
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('.ata-editor').forEach(root => initializeAtaProducts(root));
}
