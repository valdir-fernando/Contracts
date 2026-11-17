import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaProductSave } from '../../resources/js/ata-products.js';

function fixture(fetcher) {
    const node = (value = '') => ({ value, listeners: {}, children: [],
        addEventListener(event, handler) { this.listeners[event] = handler; },
        replaceChildren() { this.children = []; }, append(child) { this.children.push(child); } });
    const fields = Object.fromEntries(Object.entries({ item: '1', code: '00042', unit: 'UN', brand: 'Marca', unit_price: '6,7890' })
        .map(([name, value]) => [name, node(value)]));
    const button = { ...node(), dataset: { ataProductSaveUrl: '/atas/0/products' }, disabled: false };
    const quantity = node('1,2345');
    const description = node('<Descrição>');
    const lineTotal = node('8,38');
    const panel = { querySelector(selector) {
        if (selector.startsWith('[data-product-field=')) return fields[selector.slice(21, -2)];
        return { '[data-ata-product-save-url]': button,
            '[data-product-quantity]': quantity, '[data-product-description]': description,
            '[data-product-line-total]': lineTotal,
            '[data-ata-products-manual]': { checked: true } }[selector];
    } };
    const events = [];
    const warnings = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', csrfToken: 'csrf', editorBusy: 'false' }, listeners: {},
        ownerDocument: { createElement: node }, addEventListener(event, handler) { this.listeners[event] = handler; },
        dispatchEvent(event) { events.push(event); } };
    initializeAtaProductSave(root, panel, () => 2, fetcher, message => warnings.push(message));
    return { root, button, quantity, fields, description, lineTotal, events, warnings, save: () => button.listeners.click() };
}

test('save posts explicit fields, prevents parallel requests and refreshes the saved ata', async () => {
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    const requests = [];
    const ui = fixture((url, options) => { requests.push({ url, options }); return pending; });
    const saving = ui.save();
    await ui.save();
    assert.equal(requests.length, 1);
    assert.equal(ui.button.disabled, true);
    assert.equal(requests[0].url, '/atas/81/products');
    const payload = JSON.parse(requests[0].options.body);
    assert.equal(payload.version, 3);
    assert.equal(payload.ficha, undefined);
    assert.equal(payload.places, 2);
    assert.equal(payload.quantity, '1,2345');
    assert.equal(payload.total, undefined);
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    finish({ ok: true, json: async () => ({ number: '107/2025', version: 4 }) });
    await saving;
    assert.equal(ui.button.disabled, false);
    assert.deepEqual(ui.events.map(event => event.type), ['ata-product-saving', 'ata-product-finished', 'ata-saved']);
    assert.equal(ui.events.at(-1).detail.version, 4);
});

test('missing fields prevent requests and server errors keep form values for correction', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return { ok: false, json: async () => ({ errors: { item: ['Item duplicado.'] } }) }; });
    ui.fields.item.value = '';
    await ui.save();
    assert.equal(calls, 0);
    assert.match(ui.warnings[0], /Item/);
    ui.fields.item.value = '1';
    await ui.save();
    assert.equal(calls, 1);
    assert.equal(ui.warnings.at(-1), 'Item duplicado.');
    assert.equal(ui.fields.item.value, '1');
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
});

test('saving accepts empty code and brand', async () => {
    let payload;
    const ui = fixture(async (url, options) => {
        payload = JSON.parse(options.body);
        return { ok: true, json: async () => ({ number: '107/2025', version: 4 }) };
    });
    ui.fields.code.value = '';
    ui.fields.brand.value = '';
    await ui.save();
    assert.equal(payload.code, '');
    assert.equal(payload.brand, '');
    assert.equal(ui.events.at(-1).type, 'ata-saved');
});

test('each required product field cancels saving with the standard warning when blank', async () => {
    for (const [name, label] of [['item', 'Item'], ['unit', 'UND'], ['quantity', 'QTD'], ['unit_price', 'VL. Unitário'],
        ['lineTotal', 'VL. Total'], ['description', 'Descrição']]) {
        let calls = 0;
        const ui = fixture(async () => { calls++; });
        (ui.fields[name] ?? ui[name]).value = '  ';
        await ui.save();
        assert.equal(calls, 0, name);
        assert.equal(ui.events.length, 0);
        assert.deepEqual(ui.warnings, [`Preencha os campos obrigatórios: ${label}.`]);
    }
});

test('network failure reports the standard warning, preserves inputs and releases saving', async () => {
    const ui = fixture(async () => { throw new TypeError('Network'); });
    await ui.save();
    assert.match(ui.warnings[0], /Não foi possível confirmar a gravação/);
    assert.equal(ui.fields.item.value, '1');
    assert.equal(ui.button.disabled, false);
    assert.deepEqual(ui.events.map(event => event.type), ['ata-product-saving', 'ata-product-finished']);
});
