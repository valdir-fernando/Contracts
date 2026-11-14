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
    const ficha = node('001');
    const total = node('0,0000');
    const rows = node();
    const quantity = node('1,2345');
    const description = node('<Descrição>');
    const panel = { querySelector(selector) {
        if (selector.startsWith('[data-product-field=')) return fields[selector.slice(21, -2)];
        return { '[data-ata-product-save-url]': button, '[data-product-budget]': ficha,
            '[data-product-contract-total]': total, '[data-product-budget-totals]': rows,
            '[data-product-quantity]': quantity, '[data-product-description]': description,
            '[data-ata-products-manual]': { checked: true } }[selector];
    } };
    const events = [];
    const warnings = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', csrfToken: 'csrf', editorBusy: 'false' }, listeners: {},
        ownerDocument: { createElement: node }, addEventListener(event, handler) { this.listeners[event] = handler; },
        dispatchEvent(event) { events.push(event); } };
    initializeAtaProductSave(root, panel, () => 2, fetcher, message => warnings.push(message));
    return { root, button, ficha, total, rows, quantity, fields, events, warnings, save: () => button.listeners.click() };
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
    assert.equal(payload.ficha, '001');
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
    ui.ficha.value = '';
    await ui.save();
    assert.equal(calls, 0);
    assert.match(ui.warnings[0], /Ficha/);
    ui.ficha.value = '001';
    await ui.save();
    assert.equal(calls, 1);
    assert.equal(ui.warnings.at(-1), 'Item duplicado.');
    assert.equal(ui.fields.item.value, '1');
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
});

test('loaded ficha totals populate table and selected total without unsafe HTML', () => {
    const ui = fixture(() => {});
    ui.root.listeners['ata-loaded']({ detail: { ficha_totals: [{ ficha: '001', total: '8.3800' }, { ficha: '<002>', total: '12.3400' }] } });
    assert.equal(ui.rows.children.length, 2);
    assert.equal(ui.rows.children[1].children[0].textContent, '<002>');
    assert.equal(ui.total.value, '8,3800');
    ui.ficha.value = '<002>';
    ui.ficha.listeners.input();
    assert.equal(ui.total.value, '12,3400');
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.total.value, '0,0000');
});
