import { formatDecimalField } from './decimal-fields.js';
import { showSupplierDocumentWarning } from './system-message.js';

export function ataProductTotal(quantity, price, places = 2) {
    const units = value => {
        const formatted = formatDecimalField(value);
        if (formatted === null || formatted.startsWith('-')) return null;
        return BigInt(formatted.replaceAll('.', '').replace(',', ''));
    };
    const qty = units(quantity);
    const unitPrice = units(price);
    const scale = 10n ** BigInt(places);
    const value = qty === null || unitPrice === null ? 0n : qty * unitPrice / (10n ** BigInt(8 - places));
    const integer = String(value / scale).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return places ? `${integer},${String(value % scale).padStart(places, '0')}` : integer;
}

export function fitAtaDescription(input, getStyle = element => getComputedStyle(element)) {
    input.style.fontSize = '';
    if (!input.clientHeight || !input.clientWidth) return;
    let size = parseFloat(getStyle(input).fontSize);
    input.style.overflowY = 'hidden';
    while (input.scrollHeight > input.clientHeight + 1 && size > 10) {
        size = Math.max(10, size - 0.5);
        input.style.fontSize = `${size}px`;
    }
    input.style.overflowY = input.scrollHeight > input.clientHeight + 1 ? 'auto' : 'hidden';
}

export function limitAtaQuantity(value, available) {
    if (!String(available ?? '').trim()) return value;
    const quantity = formatDecimalField(value);
    const maximum = formatDecimalField(available);
    if (quantity === null || maximum === null || maximum.startsWith('-')) return value;
    const units = text => BigInt(text.replaceAll('.', '').replace(',', ''));
    return units(quantity) > units(maximum) ? maximum : value;
}

export function initializeAtaProducts(root, fetcher = fetch, warn = showSupplierDocumentWarning) {
    const panel = root.querySelector('.contract-products-preview');
    const feedback = panel.querySelector('[data-ata-item-lookup-url]');
    const field = name => panel.querySelector(`[data-product-field="${name}"]`);
    const item = field('item');
    const opener = panel.querySelector('[data-product-item-search]');
    const description = panel.querySelector('[data-product-description]');
    const fitDescription = () => fitAtaDescription(description);
    description.addEventListener('input', fitDescription);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fitDescription).observe(description.parentElement);
    root.ownerDocument.fonts?.ready.then(fitDescription);
    const manual = panel.querySelector('[data-ata-products-manual]');
    const quantity = panel.querySelector('[data-product-quantity]');
    const total = panel.querySelector('[data-product-line-total]');
    let places = 2;
    const more = panel.querySelector('[data-ata-total-more]');
    const less = panel.querySelector('[data-ata-total-less]');
    const updateTotal = () => {
        total.value = ataProductTotal(quantity.value, field('unit_price').value, places);
        more.disabled = places === 4;
        less.disabled = places === 2;
    };
    more.addEventListener('click', () => { places = Math.min(4, places + 1); updateTotal(); });
    less.addEventListener('click', () => { places = Math.max(2, places - 1); updateTotal(); });
    less.disabled = true;
    const enforceQuantity = () => {
        if (!manual.checked && limitAtaQuantity(quantity.value, field('disp').value) !== quantity.value) {
            quantity.value = '';
            warn(`A Quantidade limite é ${formatDecimalField(field('disp').value)}`, root.ownerDocument);
        }
    };
    quantity.addEventListener('blur', () => { enforceQuantity(); updateTotal(); });
    field('unit_price').addEventListener('blur', updateTotal);
    const applyMode = () => {
        const editable = manual.checked;
        ['code', 'brand', 'unit', 'unit_price'].forEach(name => { field(name).readOnly = !editable; });
        field('disp').readOnly = true;
        quantity.disabled = !editable;
        description.readOnly = !editable;
        description.setAttribute('aria-readonly', String(!editable));
        opener.disabled = editable;
    };
    let timer;
    let controller;
    let sequence = 0;
    let resolved = '';
    const clearFields = () => {
        ['code', 'brand', 'unit'].forEach(name => { field(name).value = ''; });
        ['disp', 'unit_price'].forEach(name => { field(name).value = '0,0000'; });
        total.value = '0,00';
        if (!manual.checked) { quantity.disabled = true; quantity.value = '0,0000'; }
        description.value = '';
        description.style.height = '';
        description.style.fontSize = '';
        description.style.overflowY = 'hidden';
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
    const reset = () => { manual.checked = false; applyMode(); invalidate(); item.value = ''; };
    manual.addEventListener('change', () => {
        clearTimeout(timer);
        controller?.abort();
        controller = null;
        sequence++;
        resolved = '';
        feedback.textContent = '';
        applyMode();
        if (!manual.checked) { clearFields(); quantity.value = '0,0000'; }
    });
    applyMode();
    const lookup = async (showWarning = true) => {
        clearTimeout(timer);
        const ataId = root.dataset.ataLoaded;
        const requested = item.value.trim();
        if (manual.checked || !ataId || root.dataset.editorBusy === 'true' || !requested) return;
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
            quantity.disabled = false;
            enforceQuantity();
            description.value = product.description ?? '';
            fitDescription();
            resolved = key;
            feedback.textContent = '';
        } catch (error) {
            if (current !== sequence || attempt.signal.aborted) return;
            clearFields();
            const message = ['TypeError', 'SyntaxError'].includes(error.name) ? 'Não foi possível consultar o Item. Tente novamente.' : error.message;
            const modalWarning = showWarning || message === 'Nenhum produto encontrado para este Item no Processo da Ata.';
            feedback.textContent = modalWarning ? '' : message;
            if (modalWarning) warn(message, root.ownerDocument);
        } finally {
            if (current === sequence) controller = null;
        }
    };
    item.addEventListener('input', () => { if (manual.checked) return; invalidate(); if (item.value.trim()) timer = setTimeout(() => lookup(false), 350); });
    item.addEventListener('blur', () => lookup());
    opener.addEventListener('mousedown', event => event.preventDefault());
    const openPicker = initializeAtaProductPicker(root, panel, item, lookup, fetcher);
    opener.addEventListener('click', () => { if (item.value.trim()) return lookup(); return openPicker(); });
    ['ata-loading', 'ata-cleared', 'ata-loaded'].forEach(event => root.addEventListener(event, reset));
    initializeAtaProductSave(root, panel, () => places, fetcher, warn);
}

export function initializeAtaProductSave(root, panel, getPlaces, fetcher, warn) {
    const button = panel.querySelector('[data-ata-product-save-url]');
    if (!button) return;
    const field = name => panel.querySelector(`[data-product-field="${name}"]`);
    const ficha = panel.querySelector('[data-product-budget]');
    const fichaTotal = panel.querySelector('[data-product-contract-total]');
    const fichaRows = panel.querySelector('[data-product-budget-totals]');
    let totals = [];
    let saving = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    const refreshFicha = () => { fichaTotal.value = formatDecimalField(totals.find(row => row.ficha === ficha.value.trim())?.total ?? '0'); };
    ficha.addEventListener('input', refreshFicha);
    root.addEventListener('ata-loaded', ({ detail }) => {
        totals = detail.ficha_totals ?? [];
        fichaRows.replaceChildren();
        for (const entry of totals) {
            const row = root.ownerDocument.createElement('tr');
            for (const value of [entry.ficha, formatDecimalField(entry.total)]) {
                const cell = root.ownerDocument.createElement('td');
                cell.textContent = value;
                row.append(cell);
            }
            fichaRows.append(row);
        }
        refreshFicha();
    });
    root.addEventListener('ata-cleared', () => { totals = []; fichaRows.replaceChildren(); refreshFicha(); });
    button.addEventListener('click', async () => {
        if (saving || button.disabled || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        const payload = {
            version: Number(root.dataset.ataVersion), manual: panel.querySelector('[data-ata-products-manual]').checked,
            ficha: ficha.value.trim(), item: field('item').value.trim(), code: field('code').value.trim(),
            unit: field('unit').value.trim(), brand: field('brand').value.trim(),
            description: panel.querySelector('[data-product-description]').value.trim(),
            quantity: panel.querySelector('[data-product-quantity]').value.trim(), unit_price: field('unit_price').value.trim(), places: getPlaces(),
        };
        const labels = { ficha: 'Ficha', item: 'Item', code: 'Código', unit: 'UND', quantity: 'QTD', unit_price: 'VL. Unitário', description: 'Descrição' };
        const missing = Object.entries(labels).filter(([key]) => !payload[key]).map(([, label]) => label);
        if (missing.length) { warn(`Preencha os campos obrigatórios: ${missing.join(', ')}.`, root.ownerDocument); return; }
        const ataId = root.dataset.ataLoaded;
        saving = true;
        button.disabled = true;
        notify('ata-product-saving');
        let saved;
        try {
            const url = button.dataset.ataProductSaveUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`);
            const response = await fetcher(url, { method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, body: JSON.stringify(payload) });
            const data = await response.json();
            if (root.dataset.ataLoaded !== ataId) return;
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Não foi possível salvar o produto.');
            saved = data;
        } catch (error) {
            warn(['TypeError', 'SyntaxError'].includes(error.name) ? 'Não foi possível confirmar a gravação. Consulte novamente a ata antes de tentar salvar.' : error.message, root.ownerDocument);
        } finally {
            saving = false;
            button.disabled = false;
            notify('ata-product-finished');
        }
        if (saved) {
            notify('ata-saved', saved);
            warn('Produto salvo com sucesso.', root.ownerDocument);
        }
    });
}

export function initializeAtaProductPicker(root, panel, item, lookup, fetcher) {
    const dialog = panel.querySelector('[data-product-picker]');
    if (!dialog) return () => {};
    const find = selector => dialog.querySelector(selector);
    const rows = find('[data-product-picker-rows]');
    const status = find('[data-product-picker-status]');
    const previous = find('[data-product-picker-previous]');
    const next = find('[data-product-picker-next]');
    let page = 1;
    let sequence = 0;
    let controller;
    const cancel = () => { sequence++; controller?.abort(); };
    dialog.addEventListener('close', cancel);
    find('[data-product-picker-cancel]').addEventListener('click', () => dialog.close());
    ['ata-loading', 'ata-cleared', 'ata-loaded'].forEach(event => root.addEventListener(event, () => { cancel(); if (dialog.open) dialog.close(); }));
    const load = async requestedPage => {
        cancel();
        const ataId = root.dataset.ataLoaded;
        if (!ataId || root.dataset.editorBusy === 'true') return;
        const current = sequence;
        controller = new AbortController();
        const attempt = controller;
        rows.replaceChildren();
        previous.disabled = next.disabled = true;
        status.textContent = 'Consultando produtos…';
        find('[data-product-picker-page]').textContent = '';
        find('[data-product-picker-context]').textContent = `Processo: ${root.querySelector('[name="ata_process"]').value}`;
        if (!dialog.open) dialog.showModal();
        try {
            const url = new URL(dialog.dataset.ataProductListUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`), root.ownerDocument.baseURI);
            url.searchParams.set('page', requestedPage);
            const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: attempt.signal });
            const data = await response.json();
            if (current !== sequence || attempt.signal.aborted || root.dataset.ataLoaded !== ataId) return;
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Não foi possível listar os produtos.');
            if (data.process !== root.querySelector('[name="ata_process"]').value.trim()) throw new Error('O Processo da ata foi alterado. Consulte novamente pelo Nº ATA.');
            for (const product of data.products) {
                const row = root.ownerDocument.createElement('tr');
                for (const value of [product.item, product.code, product.description, product.brand, product.unit,
                    formatDecimalField(product.remaining), formatDecimalField(product.unit_price)]) {
                    const cell = root.ownerDocument.createElement('td');
                    cell.textContent = value ?? '';
                    row.append(cell);
                }
                const cell = root.ownerDocument.createElement('td');
                const button = root.ownerDocument.createElement('button');
                button.type = 'button';
                button.className = 'section-tab';
                button.textContent = 'Selecionar';
                button.addEventListener('click', () => { item.value = product.item; dialog.close(); lookup(); });
                cell.append(button);
                row.append(cell);
                rows.append(row);
            }
            page = data.page;
            previous.disabled = page <= 1;
            next.disabled = page >= data.last_page;
            find('[data-product-picker-page]').textContent = `Página ${page} de ${data.last_page}`;
            status.textContent = data.products.length ? '' : 'Nenhum produto encontrado no processo da ata.';
        } catch (error) {
            if (current !== sequence || attempt.signal.aborted) return;
            status.textContent = ['TypeError', 'SyntaxError'].includes(error.name) ? 'Não foi possível listar os produtos. Tente novamente.' : error.message;
        }
    };
    previous.addEventListener('click', () => load(page - 1));
    next.addEventListener('click', () => load(page + 1));
    return () => load(1);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('.ata-editor').forEach(root => initializeAtaProducts(root));
}
