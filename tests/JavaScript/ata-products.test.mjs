import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaProducts, initializeAtaProductPicker, ataProductTotal, fitAtaDescription, limitAtaQuantity } from '../../resources/js/ata-products.js';

const response = (product = {}) => ({ ok: true, json: async () => ({ process: 'PROC', product: {
    id: 1, item: '001', code: '00042', brand: 'Marca', unit: 'CX', remaining: '1234.5678', unit_price: '12.3400', description: '<Produto seguro>', ...product,
} }) });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

test('empty-item picker lists safe process data, selects an item and ignores stale responses', async () => {
    const node = () => ({ listeners: {}, children: [], textContent: '',
        addEventListener(event, handler) { this.listeners[event] = handler; },
        append(child) { this.children.push(child); }, replaceChildren() { this.children = []; } });
    const controls = Object.fromEntries(['rows', 'status', 'previous', 'next', 'cancel', 'page', 'context'].map(name => [`[data-product-picker-${name}]`, node()]));
    const dialog = { ...node(), dataset: { ataProductListUrl: '/atas/0/products/catalog' },
        querySelector: selector => controls[selector], showModal() { this.open = true; },
        close() { this.open = false; this.listeners.close(); } };
    const root = { dataset: { ataLoaded: '81', editorBusy: 'false' }, listeners: {},
        ownerDocument: { baseURI: 'http://contracts.test/', createElement: node },
        querySelector: () => ({ value: 'PROC' }), addEventListener(event, handler) { this.listeners[event] = handler; } };
    const item = { value: '' };
    let selections = 0;
    const pending = [];
    const open = initializeAtaProductPicker(root, { querySelector: () => dialog }, item, () => selections++, (url) => {
        const request = deferred(); pending.push({ ...request, url }); return request.promise;
    });
    const loading = open();
    assert.equal(dialog.open, true);
    assert.equal(pending[0].url.pathname, '/atas/81/products/catalog');
    pending[0].resolve({ ok: true, json: async () => ({ process: 'PROC', page: 1, last_page: 1,
        products: [{ item: '2', description: '<texto>', remaining: '1', unit_price: '2' }] }) });
    await loading;
    const row = controls['[data-product-picker-rows]'].children[0];
    assert.equal(row.children[2].textContent, '<texto>');
    row.children[7].children[0].listeners.click();
    assert.equal(item.value, '2');
    assert.equal(selections, 1);
    assert.equal(dialog.open, false);
    const late = open();
    root.listeners['ata-cleared']();
    pending[1].resolve({ ok: true, json: async () => ({ process: 'PROC', products: [{ item: 'old' }] }) });
    await late;
    assert.equal(controls['[data-product-picker-rows]'].children.length, 0);
});

test('description shrinks to fit, stops at 10px with scroll and restores size for short text', () => {
    let length = 1000;
    const input = { style: {}, clientWidth: 200, clientHeight: 76,
        get scrollHeight() { return length * parseFloat(this.style.fontSize || '13') / 100; } };
    const style = () => ({ fontSize: '13px' });
    fitAtaDescription(input, style);
    assert.equal(input.style.fontSize, '10px');
    assert.equal(input.style.overflowY, 'auto');
    length = 700;
    fitAtaDescription(input, style);
    assert.ok(parseFloat(input.style.fontSize) > 10);
    assert.equal(input.style.overflowY, 'hidden');
    length = 100;
    fitAtaDescription(input, style);
    assert.equal(input.style.fontSize, '');
    assert.equal(input.style.overflowY, 'hidden');
});

function fixture(fetcher) {
    const control = value => ({ value, listeners: {}, style: {}, scrollHeight: 70,
        setAttribute(name, value) { this[name] = value; },
        addEventListener(name, callback) { this.listeners[name] = callback; } });
    const fields = Object.fromEntries(['item', 'code', 'brand', 'unit', 'disp', 'unit_price'].map(name => [name, control('')]));
    const feedback = { textContent: '', dataset: { ataItemLookupUrl: '/atas/0/products/item-lookup' } };
    const description = control('');
    const opener = control('');
    const manual = control('');
    const quantity = control('0,0000');
    const total = control('0,00');
    const more = control('');
    const less = control('');
    const panel = { querySelector(selector) {
        if (selector.startsWith('[data-product-field=')) return fields[selector.slice(21, -2)];
        return { '[data-ata-total-more]': more, '[data-ata-total-less]': less,
            '[data-product-line-total]': total, '[data-ata-products-manual]': manual, '[data-product-quantity]': quantity,
            '[data-ata-item-lookup-url]': feedback, '[data-product-description]': description, '[data-product-item-search]': opener }[selector];
    } };
    const root = { dataset: { ataLoaded: '107', editorBusy: 'false' }, listeners: {},
        ownerDocument: { baseURI: 'http://contracts.test/', defaultView: { getComputedStyle: () => ({ lineHeight: '19.2px' }) } },
        querySelector(selector) { return selector === '.contract-products-preview' ? panel : { value: 'PROC' }; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
    };
    const warnings = [];
    initializeAtaProducts(root, fetcher, message => warnings.push(message));
    return { root, fields, feedback, description, opener, warnings, manual, quantity, total, more, less,
        input(value) { fields.item.value = value; fields.item.listeners.input(); },
        lookup: () => fields.item.listeners.blur(),
    };
}

test('total truncates exact multiplication to two decimal places and groups thousands', () => {
    assert.equal(ataProductTotal('1,2345', '6,7890'), '8,38');
    assert.equal(ataProductTotal('0,0001', '0,9999'), '0,00');
    assert.equal(ataProductTotal('1.000,0000', '2,5000'), '2.500,00');
    assert.equal(ataProductTotal('99999999999999.9999', '1.0000'), '99.999.999.999.999,99');
    assert.equal(ataProductTotal('', '2'), '0,00');
    assert.equal(ataProductTotal('inválido', '2'), '0,00');
});

test('quantity limit respects exact decimals, zero, blank availability and large values', () => {
    assert.equal(limitAtaQuantity('1.234,5679', '1.234,5678'), '1.234,5678');
    assert.equal(limitAtaQuantity('0,0001', '0,0000'), '0,0000');
    assert.equal(limitAtaQuantity('25', ''), '25');
    assert.equal(limitAtaQuantity('2,0000', '3,0000'), '2,0000');
    assert.equal(limitAtaQuantity('99999999999999.9999', '99999999999999.9998'), '99.999.999.999.999,9998');
});

test('excess quantity is cleared only on blur with the requested warning outside manual mode', async () => {
    const ui = fixture(async () => response({ remaining: '2.0001' }));
    ui.input('001');
    await ui.lookup();
    assert.equal(ui.quantity.disabled, false);
    ui.quantity.value = '3';
    assert.equal(ui.quantity.listeners.input, undefined);
    assert.equal(ui.quantity.value, '3');
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '');
    assert.deepEqual(ui.warnings, ['A Quantidade limite é 2,0001']);
    assert.equal(ui.total.value, '0,00');
    ui.quantity.value = '2,0001';
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '2,0001');
    assert.equal(ui.warnings.length, 1);
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.quantity.value = '99';
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '99');
    assert.equal(ui.warnings.length, 1);
});

test('quantity and unit price blur both refresh the total', () => {
    const ui = fixture(() => response());
    ui.manual.checked = true;
    ui.quantity.value = '1,2345';
    ui.fields.unit_price.value = '6,7890';
    ui.quantity.listeners.blur();
    assert.equal(ui.total.value, '8,38');
    ui.fields.unit_price.value = '10,0000';
    ui.fields.unit_price.listeners.blur();
    assert.equal(ui.total.value, '12,34');
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.total.value, '0,00');
});

test('decimal buttons recalculate from operands and respect two to four places', () => {
    const ui = fixture(() => response());
    ui.quantity.value = '1,2345';
    ui.fields.unit_price.value = '6,7890';
    ui.more.listeners.click();
    assert.equal(ui.total.value, '8,381');
    ui.more.listeners.click();
    assert.equal(ui.total.value, '8,3810');
    assert.equal(ui.more.disabled, true);
    for (let index = 0; index < 5; index++) ui.less.listeners.click();
    assert.equal(ui.total.value, '8,38');
    assert.equal(ui.less.disabled, true);
    ui.more.listeners.click();
    assert.equal(ui.total.value, '8,381');
});

test('manual mode unlocks entry, preserves typed values and cancels stale lookups', async () => {
    const pending = deferred();
    let calls = 0;
    const ui = fixture(() => { calls++; return pending.promise; });
    ui.input('001');
    const lookup = ui.lookup();
    ui.manual.checked = true;
    ui.manual.listeners.change();
    for (const name of ['code', 'brand', 'unit', 'unit_price']) assert.equal(ui.fields[name].readOnly, false);
    assert.equal(ui.fields.disp.readOnly, true);
    assert.equal(ui.quantity.disabled, false);
    assert.equal(ui.description.readOnly, false);
    ui.fields.brand.value = 'Manual';
    ui.description.value = 'Descrição manual';
    ui.input('002');
    await ui.lookup();
    pending.resolve(response());
    await lookup;
    assert.equal(calls, 1);
    assert.equal(ui.fields.brand.value, 'Manual');
    assert.equal(ui.description.value, 'Descrição manual');
    ui.manual.checked = false;
    ui.manual.listeners.change();
    assert.equal(ui.fields.brand.readOnly, true);
    assert.equal(ui.quantity.disabled, true);
    assert.equal(ui.description.readOnly, true);
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.manual.checked, false);
    assert.equal(ui.fields.unit_price.readOnly, true);
});

test('item lookup fills code, brand, unit, availability, price and description with exact formatting', async () => {
    let request;
    const ui = fixture(async (url, options) => { request = { url, options }; return response(); });
    ui.input('001');
    await ui.lookup();
    assert.equal(request.url.pathname, '/atas/107/products/item-lookup');
    assert.equal(request.url.searchParams.get('item'), '001');
    assert.equal(request.url.searchParams.has('process'), false);
    assert.equal(request.options.cache, 'no-store');
    assert.equal(ui.fields.code.value, '00042');
    assert.equal(ui.fields.brand.value, 'Marca');
    assert.equal(ui.fields.unit.value, 'CX');
    assert.equal(ui.fields.disp.value, '1.234,5678');
    assert.equal(ui.fields.unit_price.value, '12,3400');
    assert.equal(ui.description.value, '<Produto seguro>');
    assert.deepEqual(ui.warnings, []);
});

test('editing item clears previous fields and a late response cannot overwrite the new item', async () => {
    const pending = [];
    const ui = fixture((url, options) => { const request = deferred(); pending.push({ ...request, options }); return request.promise; });
    ui.input('001');
    const first = ui.lookup();
    ui.input('002');
    const second = ui.lookup();
    pending[1].resolve(response({ item: '002', code: 'NOVO' }));
    await second;
    pending[0].resolve(response({ code: 'OBSOLETO' }));
    await first;
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(ui.fields.code.value, 'NOVO');
    ui.input('');
    assert.equal(ui.fields.code.value, '');
    assert.equal(ui.fields.disp.value, '0,0000');
    assert.equal(ui.description.value, '');
});

test('clearing or switching ata cancels lookup and never applies a product from the previous context', async () => {
    const request = deferred();
    const ui = fixture(() => request.promise);
    ui.input('001');
    const pending = ui.lookup();
    ui.root.listeners['ata-cleared']();
    ui.root.dataset.ataLoaded = '108';
    request.resolve(response());
    await pending;
    assert.equal(ui.fields.item.value, '');
    assert.equal(ui.fields.code.value, '');
    assert.deepEqual(ui.warnings, []);
});

test('not found, duplicate and network failure clear stale values and show the suite warning', async () => {
    for (const fetcher of [async () => ({ ok: false, json: async () => ({ message: 'Item não encontrado.' }) }),
        async () => ({ ok: false, json: async () => ({ message: 'Item duplicado.' }) }),
        async () => { throw new TypeError('Network'); }]) {
        const ui = fixture(fetcher);
        ui.fields.code.value = 'ANTIGO';
        ui.input('001');
        await ui.lookup();
        assert.equal(ui.fields.code.value, '');
        assert.equal(ui.fields.unit_price.value, '0,0000');
        assert.equal(ui.warnings.length, 1);
        assert.equal(ui.feedback.textContent, '');
    }
});

test('missing item displays the exact requested message in the suite warning', async () => {
    const message = 'Nenhum produto encontrado para este Item no Processo da Ata.';
    const ui = fixture(async () => ({ ok: false, json: async () => ({ message }) }));
    ui.input('999');
    await ui.lookup();
    assert.deepEqual(ui.warnings, [message]);
    assert.equal(ui.feedback.textContent, '');
});

test('lookup is unavailable before loading ata and repeated blur does not duplicate a successful request', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return response(); });
    ui.root.dataset.ataLoaded = '';
    ui.input('001');
    await ui.lookup();
    assert.equal(calls, 0);
    ui.root.dataset.ataLoaded = '107';
    await ui.lookup();
    await ui.lookup();
    assert.equal(calls, 1);
});

test('a server response for a changed process cannot populate fields under the old process', async () => {
    const ui = fixture(async () => ({ ok: true, json: async () => ({ process: 'OUTRO', product: {} }) }));
    ui.input('001');
    await ui.lookup();
    assert.equal(ui.fields.code.value, '');
    assert.match(ui.warnings[0], /Processo da ata foi alterado/);
});
