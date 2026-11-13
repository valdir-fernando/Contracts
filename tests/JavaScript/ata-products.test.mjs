import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaProducts } from '../../resources/js/ata-products.js';

const response = (product = {}) => ({ ok: true, json: async () => ({ process: 'PROC', product: {
    id: 1, item: '001', code: '00042', brand: 'Marca', unit: 'CX', remaining: '1234.5678', unit_price: '12.3400', description: '<Produto seguro>', ...product,
} }) });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

function fixture(fetcher) {
    const control = value => ({ value, listeners: {}, style: {}, scrollHeight: 70,
        addEventListener(name, callback) { this.listeners[name] = callback; } });
    const fields = Object.fromEntries(['item', 'code', 'brand', 'unit', 'disp', 'unit_price'].map(name => [name, control('')]));
    const feedback = { textContent: '', dataset: { ataItemLookupUrl: '/atas/0/products/item-lookup' } };
    const description = control('');
    const opener = control('');
    const panel = { querySelector(selector) {
        if (selector.startsWith('[data-product-field=')) return fields[selector.slice(21, -2)];
        return { '[data-ata-item-lookup-url]': feedback, '[data-product-description]': description, '[data-product-item-search]': opener }[selector];
    } };
    const root = { dataset: { ataLoaded: '107', editorBusy: 'false' }, listeners: {},
        ownerDocument: { baseURI: 'http://contracts.test/', defaultView: { getComputedStyle: () => ({ lineHeight: '19.2px' }) } },
        querySelector(selector) { return selector === '.contract-products-preview' ? panel : { value: 'PROC' }; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
    };
    const warnings = [];
    initializeAtaProducts(root, fetcher, message => warnings.push(message));
    return { root, fields, feedback, description, opener, warnings,
        input(value) { fields.item.value = value; fields.item.listeners.input(); },
        lookup: () => fields.item.listeners.blur(),
    };
}

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
        assert.equal(ui.feedback.textContent, ui.warnings[0]);
    }
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
