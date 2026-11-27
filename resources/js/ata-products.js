import { formatDecimalField } from './decimal-fields.js';
import { requestAtaBalanceCorrection, showSupplierDocumentWarning } from './system-message.js';
import { initializeAtaProductDelete } from './ata-product-delete.js';

function ataDecimalUnits(value) {
    const formatted = formatDecimalField(value);
    if (formatted === null || formatted.startsWith('-')) return null;
    return BigInt(formatted.replaceAll('.', '').replace(',', ''));
}

function ataProductTotalUnits(quantity, price, places) {
    const qty = ataDecimalUnits(quantity);
    const unitPrice = ataDecimalUnits(price);
    return qty === null || unitPrice === null ? null : qty * unitPrice / (10n ** BigInt(8 - places));
}

function formatAtaTotalUnits(value, places) {
    const scale = 10n ** BigInt(places);
    const integer = String(value / scale).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return places ? `${integer},${String(value % scale).padStart(places, '0')}` : integer;
}

export function ataProductTotal(quantity, price, places = 2) {
    return formatAtaTotalUnits(ataProductTotalUnits(quantity, price, places) ?? 0n, places);
}

export function ataAmendmentBalanceLineTotals(products, quantities, limitToOriginal = true) {
    const items = {};
    for (const product of products) {
        const quantity = quantities[product.id];
        if (!quantity?.trim()) continue;
        if (limitToOriginal && (!product.quantity || limitAtaQuantity(quantity, product.quantity) !== quantity)) continue;
        const amount = ataProductTotalUnits(quantity, product.unit_price, 4);
        if (amount === null) continue;
        items[product.id] = formatAtaTotalUnits(amount, 4);
    }
    return items;
}

export function ataAnnualAmendmentTotals(products, quantities, index, manualPrices = {}) {
    const prices = {};
    const lines = {};
    let total = 0n;
    const percentage = /^(\d+)(?:[,.](\d+))?%?$/.exec(index);
    const [, whole = '0', fraction = ''] = percentage ?? [];
    const divisor = 100n * 10n ** BigInt(fraction.length);
    const multiplier = divisor + BigInt(whole + fraction);
    for (const product of products) {
        const unitPrice = String(product.unit_price ?? '').trim() ? ataDecimalUnits(product.unit_price) : null;
        if (percentage && unitPrice !== null) {
            const adjusted = unitPrice * multiplier / divisor;
            if (String(adjusted).length <= 18) prices[product.id] = formatAtaTotalUnits(adjusted, 4);
        }
        const quantity = quantities[product.id];
        const price = manualPrices[product.id] ?? prices[product.id];
        if (!quantity?.trim() || !price?.trim()) continue;
        const amount = ataProductTotalUnits(quantity, price, 2);
        if (amount === null) continue;
        lines[product.id] = formatAtaTotalUnits(amount, 2);
        total += amount;
    }
    return { prices, lines, total: formatAtaTotalUnits(total, 2) };
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

export function initializeAtaAmendmentBalanceCorrection(root, requestCorrection = requestAtaBalanceCorrection) {
    let pending = false;
    root.addEventListener('focusout', async ({ target }) => {
        if (pending || target.disabled || !target.matches('input[data-amendment-original-quantity]')) return;
        const entered = target.value;
        const original = target.dataset.amendmentOriginalQuantity;
        if (limitAtaQuantity(entered, original) === entered) return;
        const { ataLoaded, ataVersion } = root.dataset;
        const validate = value => value !== '' && ataDecimalUnits(value) !== null && limitAtaQuantity(value, original) === value;
        pending = true;
        try {
            const replacement = await requestCorrection(entered, original, validate, root.ownerDocument);
            if (!target.isConnected || target.disabled || target.dataset.amendmentOriginalQuantity !== original
                || root.dataset.ataLoaded !== ataLoaded || root.dataset.ataVersion !== ataVersion) return;
            target.value = validate(replacement) ? replacement : '';
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.focus({ preventScroll: true });
        } finally {
            pending = false;
        }
    });
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
    const specialQuantity = panel.querySelector('[data-product-special-quantity]');
    const entryOrder = [item, field('code'), field('brand'), field('unit'), quantity, specialQuantity, field('unit_price'), description].filter(Boolean);
    const activeEntryOrder = () => entryOrder.filter(control => control !== specialQuantity || root.dataset.ataSpecialQuantityName);
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
    // QTD also selects how much to remove; inclusion limits are checked when saving.
    quantity.addEventListener('blur', updateTotal);
    field('unit_price').addEventListener('blur', updateTotal);
    const applyMode = () => {
        const editable = manual.checked;
        ['code', 'brand', 'unit', 'unit_price'].forEach(name => { field(name).readOnly = !editable; });
        field('disp').readOnly = true;
        quantity.disabled = !editable;
        description.readOnly = !editable;
        description.setAttribute('aria-readonly', String(!editable));
        opener.disabled = editable;
        const tabOrder = editable ? activeEntryOrder() : [item, quantity, ...(root.dataset.ataSpecialQuantityName && specialQuantity ? [specialQuantity] : [])];
        panel.querySelectorAll('input, textarea, button, a[href], [tabindex]').forEach(control => {
            const ownerDialog = control.closest('dialog');
            if (!ownerDialog || !panel.contains(ownerDialog)) control.tabIndex = tabOrder.includes(control) ? 0 : -1;
        });
    };
    let controller;
    let sequence = 0;
    let resolved = '';
    const clearFields = () => {
        if (specialQuantity) specialQuantity.value = '0,0000';
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
        controller?.abort();
        controller = null;
        sequence++;
        resolved = '';
        feedback.textContent = '';
        clearFields();
    };
    const reset = () => { manual.checked = false; applyMode(); invalidate(); item.value = ''; };
    panel.querySelector('[data-products-new]').addEventListener('click', () => {
        if (!root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        reset();
        places = 2;
        updateTotal();
        item.focus();
    });
    const dialog = root.querySelector('#ata-products-dialog');
    root.addEventListener('ata-products-opened', () => {
        if (!dialog?.open || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        applyMode();
        item.focus();
        item.select();
    });
    dialog?.addEventListener('close', event => {
        if (event.target !== dialog) return;
        reset();
        places = 2;
        updateTotal();
    });
    manual.addEventListener('change', () => {
        controller?.abort();
        controller = null;
        sequence++;
        resolved = '';
        feedback.textContent = '';
        applyMode();
        if (!manual.checked) { clearFields(); quantity.value = '0,0000'; }
    });
    applyMode();
    root.addEventListener('ata-special-quantity-configured', () => {
        const disabled = quantity.disabled;
        applyMode();
        quantity.disabled = disabled;
    });
    root.addEventListener('ata-product-selected', async ({ detail }) => {
        if (root.dataset.editorBusy === 'true' || root.dataset.ataLoaded !== detail.ataId) return;
        invalidate();
        const product = detail.product;
        item.value = String(product.item ?? '');
        ['code', 'brand', 'unit'].forEach(name => { field(name).value = product[name] ?? ''; });
        field('unit_price').value = formatDecimalField(product.unit_price);
        field('disp').value = '';
        quantity.value = formatDecimalField(product.quantity);
        if (specialQuantity) specialQuantity.value = formatDecimalField(product.special_quantity ?? '0');
        quantity.disabled = false;
        description.value = product.description ?? '';
        fitDescription();
        // Show the recorded total, which may include rounding from previous entries.
        places = 4;
        more.disabled = true;
        less.disabled = false;
        total.value = formatDecimalField(product.total);
        const selectedItem = item.value.trim();
        resolved = JSON.stringify([detail.ataId, selectedItem]);
        if (manual.checked) return;
        const current = sequence;
        const attempt = new AbortController();
        controller = attempt;
        try {
            const url = new URL(feedback.dataset.ataItemLookupUrl.replace('/0/', `/${encodeURIComponent(detail.ataId)}/`), root.ownerDocument.baseURI);
            url.searchParams.set('item', selectedItem);
            const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: attempt.signal });
            if (current !== sequence || attempt.signal.aborted || root.dataset.ataLoaded !== detail.ataId || item.value.trim() !== selectedItem) return;
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (current !== sequence || attempt.signal.aborted || root.dataset.ataLoaded !== detail.ataId || item.value.trim() !== selectedItem) return;
            if (data.process !== detail.process) throw new Error();
            field('disp').value = formatDecimalField(data.product.remaining);
        } catch {
            if (current === sequence && !attempt.signal.aborted && item.value.trim() === selectedItem) {
                warn('Não foi possível consultar o saldo. Os dados salvos do item foram mantidos.', root.ownerDocument);
            }
        } finally {
            if (current === sequence) controller = null;
        }
    });
    const lookup = async (showWarning = true) => {
        const ataId = root.dataset.ataLoaded;
        const requested = item.value.trim();
        if (manual.checked || !ataId || root.dataset.editorBusy === 'true') return;
        if (!requested) { invalidate(); return; }
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
            const exhausted = formatDecimalField(product.remaining) === '0,0000';
            quantity.disabled = false;
            if (exhausted) quantity.value = '0,0000';
            description.value = product.description ?? '';
            fitDescription();
            resolved = key;
            feedback.textContent = '';
            updateTotal();
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
    item.addEventListener('blur', () => { if (!manual.checked) return lookup(); });
    entryOrder.forEach(control => control.addEventListener('keydown', async event => {
        if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey
            || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true') return;
        if (manual.checked) {
            const order = activeEntryOrder();
            const index = order.indexOf(control);
            const next = order[(index + (event.shiftKey ? -1 : 1) + order.length) % order.length];
            if (next && !next.disabled) { event.preventDefault(); next.focus(); }
            return;
        }
        if (control === specialQuantity) { event.preventDefault(); (event.shiftKey ? quantity : item).focus(); return; }
        if (control === quantity) { event.preventDefault(); (root.dataset.ataSpecialQuantityName && specialQuantity && !event.shiftKey ? specialQuantity : item).focus(); return; }
        if (control !== item || event.shiftKey || !item.value.trim()) return;
        event.preventDefault();
        const ataId = root.dataset.ataLoaded;
        const requested = item.value.trim();
        await lookup();
        if (root.ownerDocument.activeElement !== item || root.dataset.ataLoaded !== ataId
            || item.value.trim() !== requested || root.dataset.editorBusy === 'true') return;
        if (!quantity.disabled) quantity.focus();
    }));
    opener.addEventListener('mousedown', event => event.preventDefault());
    const openPicker = initializeAtaProductPicker(root, panel, item, lookup, fetcher);
    opener.addEventListener('click', () => {
        if (manual.checked) return;
        if (item.value.trim()) return lookup();
        return openPicker();
    });
    ['ata-loading', 'ata-cleared', 'ata-loaded'].forEach(event => root.addEventListener(event, reset));
    initializeAtaProductSave(root, panel, () => places, fetcher, warn);
    initializeAtaProductDelete(root, panel, fetcher, warn);
}

export function initializeAtaProductSave(root, panel, getPlaces, fetcher, warn) {
    const button = panel.querySelector('[data-ata-product-save-url]');
    if (!button) return;
    const field = name => panel.querySelector(`[data-product-field="${name}"]`);
    let saving = false;
    const notify = (name, detail = {}) => root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    button.addEventListener('click', async () => {
        if (saving || button.disabled || !root.dataset.ataLoaded || root.dataset.editorBusy === 'true' || root.dataset.ataPublicationLocked === 'true') return;
        const payload = {
            version: Number(root.dataset.ataVersion), manual: panel.querySelector('[data-ata-products-manual]').checked,
            item: field('item').value.trim(), code: field('code').value.trim(),
            unit: field('unit').value.trim(), brand: field('brand').value.trim(),
            description: panel.querySelector('[data-product-description]').value.trim(),
            quantity: panel.querySelector('[data-product-quantity]').value.trim(), unit_price: field('unit_price').value.trim(), places: getPlaces(),
        };
        const labels = { item: 'Item', unit: 'UND', quantity: 'QTD', unit_price: 'VL. Unitário', description: 'Descrição' };
        if (root.dataset.ataSpecialQuantityName) payload.special_quantity = panel.querySelector('[data-product-special-quantity]').value.trim() || null;
        const missing = Object.entries(labels).filter(([key]) => !payload[key]).map(([, label]) => label);
        if (!panel.querySelector('[data-product-line-total]').value.trim()) missing.push('VL. Total');
        if (missing.length) { warn(`Preencha os campos obrigatórios: ${missing.join(', ')}.`, root.ownerDocument); return; }
        if (!payload.manual && limitAtaQuantity(payload.quantity, field('disp').value) !== payload.quantity) {
            warn(`A Quantidade limite é ${formatDecimalField(field('disp').value)}`, root.ownerDocument); return;
        }
        const ataId = root.dataset.ataLoaded;
        saving = true;
        button.disabled = true;
        notify('ata-product-saving');
        let saved;
        let failureMessage;
        try {
            const url = button.dataset.ataProductSaveUrl.replace('/0/', `/${encodeURIComponent(ataId)}/`);
            const response = await fetcher(url, { method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, body: JSON.stringify(payload) });
            if (root.dataset.ataLoaded !== ataId) return;
            if (response.redirected || !response.ok) {
                const messages = {
                    401: 'Sua sessão terminou. Entre novamente e consulte a ata antes de salvar.',
                    403: 'Você não tem permissão para salvar produtos nesta ata.',
                    404: 'A ata não está disponível. Consulte novamente pelo Nº ATA.',
                    409: 'Os dados da ata mudaram ou precisam de conferência. Consulte novamente pelo Nº ATA antes de salvar.',
                    419: 'Sua sessão terminou. Entre novamente e consulte a ata antes de salvar.',
                    422: 'Há campos inválidos no produto. Confira os dados informados.',
                    429: 'Foram feitas muitas tentativas em pouco tempo. Aguarde um momento antes de tentar novamente.',
                };
                failureMessage = messages[response.redirected ? 401 : response.status]
                    || 'O sistema encontrou um problema ao salvar o produto. Consulte novamente a ata antes de tentar salvar. Se o erro continuar, avise o administrador.';
                if (response.status === 422 && !response.redirected) {
                    const data = await response.json();
                    failureMessage = Object.values(data.errors ?? {}).flat().join(' ') || failureMessage;
                }
                throw new Error(failureMessage);
            }
            const data = await response.json();
            if (root.dataset.ataLoaded !== ataId) return;
            saved = data;
        } catch (error) {
            if (root.dataset.ataLoaded === ataId) {
                warn(failureMessage || 'Não foi possível confirmar a gravação. Consulte novamente a ata antes de tentar salvar.', root.ownerDocument);
            }
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
    document.querySelectorAll('.ata-editor').forEach(root => {
        initializeAtaProducts(root);
        initializeAtaAmendmentBalanceCorrection(root);
        root.addEventListener('ata-amendment-balance-input', ({ target, detail }) => {
            const { products, quantities, limitToOriginal, annualIndex, manualPrices } = detail;
            let calculation;
            if (annualIndex !== null) {
                calculation = ataAnnualAmendmentTotals(products, quantities, annualIndex, manualPrices);
            } else {
                const lines = ataAmendmentBalanceLineTotals(products, quantities, limitToOriginal);
                const total = Object.values(lines).reduce((sum, amount) => sum + ataDecimalUnits(amount), 0n);
                calculation = { prices: {}, lines, total: formatAtaTotalUnits(total, 4) };
            }
            target.dispatchEvent(new CustomEvent('ata-amendment-balance-calculated', {
                bubbles: true, detail: calculation,
            }));
        });
    });
}
