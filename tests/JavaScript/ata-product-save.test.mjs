import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaProductSave } from '../../resources/js/ata-products.js';

function fixture(fetcher) {
    const node = (value = '') => ({ value, listeners: {}, children: [],
        addEventListener(event, handler) { this.listeners[event] = handler; },
        replaceChildren() { this.children = []; }, append(child) { this.children.push(child); } });
    const fields = Object.fromEntries(Object.entries({ item: '1', code: '00042', unit: 'UN', brand: 'Marca', unit_price: '6,7890', disp: '2,0000' })
        .map(([name, value]) => [name, node(value)]));
    const button = { ...node(), dataset: { ataProductSaveUrl: '/atas/0/products' }, disabled: false };
    const quantity = node('1,2345');
    const specialQuantity = node('2,0000');
    const description = node('<Descrição>');
    const lineTotal = node('8,38');
    const manual = { checked: true };
    const panel = { querySelector(selector) {
        if (selector.startsWith('[data-product-field=')) return fields[selector.slice(21, -2)];
        return { '[data-ata-product-save-url]': button,
            '[data-product-quantity]': quantity, '[data-product-description]': description,
            '[data-product-special-quantity]': specialQuantity,
            '[data-product-line-total]': lineTotal,
            '[data-ata-products-manual]': manual }[selector];
    } };
    const events = [];
    const warnings = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', csrfToken: 'csrf', editorBusy: 'false' }, listeners: {},
        ownerDocument: { createElement: node }, addEventListener(event, handler) { this.listeners[event] = handler; },
        dispatchEvent(event) { events.push(event); } };
    initializeAtaProductSave(root, panel, () => 2, fetcher, message => warnings.push(message));
    return { root, button, quantity, specialQuantity, fields, description, lineTotal, manual, events, warnings, save: () => button.listeners.click() };
}

test('special quantity is sent only when the current ATA uses that field', async () => {
    const requests = [];
    const ui = fixture(async (url, options) => {
        requests.push(JSON.parse(options.body));
        return { ok: true, json: async () => ({ number: '107/2025', version: 4 }) };
    });
    await ui.save();
    assert.equal('special_quantity' in requests[0], false);
    ui.root.dataset.ataSpecialQuantityName = 'veículos';
    await ui.save();
    assert.equal(requests[1].special_quantity, '2,0000');
    assert.equal(requests[1].quantity, '1,2345');
    assert.equal(requests[1].unit_price, '6,7890');
});

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
    assert.deepEqual(ui.warnings, ['Produto salvo com sucesso.']);
});

test('missing fields prevent requests and server errors keep form values for correction', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return { ok: false, status: 422, json: async () => ({ errors: { unit_price: ['O valor unitário é diferente do cadastrado para este Item.'] } }) }; });
    ui.fields.item.value = '';
    await ui.save();
    assert.equal(calls, 0);
    assert.match(ui.warnings[0], /Item/);
    ui.fields.item.value = '1';
    await ui.save();
    assert.equal(calls, 1);
    assert.equal(ui.warnings.at(-1), 'O valor unitário é diferente do cadastrado para este Item.');
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

test('save failures explain session, permission, conflict and system errors without technical details', async () => {
    for (const [status, expected] of [[401, /sessão terminou/], [403, /permissão/], [404, /não está disponível/],
        [409, /dados da ata mudaram/], [419, /sessão terminou/], [429, /muitas tentativas/], [500, /problema ao salvar/]]) {
        const ui = fixture(async () => ({ ok: false, status, json: async () => ({ message: 'SQLSTATE: select * from tb_PRODUTOS_ATA' }) }));
        await ui.save();
        assert.match(ui.warnings[0], expected);
        assert.doesNotMatch(ui.warnings[0], /SQLSTATE|select|tb_PRODUTOS_ATA/);
        assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
        assert.equal(ui.fields.item.value, '1');
        assert.equal(ui.button.disabled, false);
    }
});

test('uncertain save responses ask for a fresh lookup and never report success', async () => {
    const ui = fixture(async () => ({ ok: true, json: async () => { throw new SyntaxError('Unexpected token'); } }));
    await ui.save();
    assert.match(ui.warnings[0], /Consulte novamente a ata/);
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
});

test('catalog inclusion limit is checked on save while manual inclusion ignores it', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return { ok: true, json: async () => ({ number: '107/2025', version: 4 }) }; });
    ui.manual.checked = false;
    ui.quantity.value = '3';
    await ui.save();
    assert.equal(calls, 0);
    assert.deepEqual(ui.warnings, ['A Quantidade limite é 2,0000']);
    assert.equal(ui.quantity.value, '3');
    ui.manual.checked = true;
    await ui.save();
    assert.equal(calls, 1);
});
