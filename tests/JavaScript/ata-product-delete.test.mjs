import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaProductDelete } from '../../resources/js/ata-product-delete.js';
import { confirmSystemMessage } from '../../resources/js/system-message.js';

function fixture(fetcher, confirm = async () => true) {
    const button = { dataset: { ataProductDeleteUrl: '/atas/0/products' }, listeners: {},
        addEventListener(name, handler) { this.listeners[name] = handler; } };
    const item = { value: '2' };
    const quantity = { value: '4,0000' };
    const selected = [];
    const panel = { querySelectorAll: () => selected, querySelector: selector => ({ '[data-ata-product-delete-url]': button,
        '[data-product-field="item"]': item, '[data-product-quantity]': quantity })[selector] };
    const events = [], warnings = [], confirmations = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', csrfToken: 'csrf' }, ownerDocument: {},
        dispatchEvent(event) { events.push(event); } };
    initializeAtaProductDelete(root, panel, fetcher, message => warnings.push(message), message => { confirmations.push(message); return confirm(); });
    return { root, item, quantity, selected, button, events, warnings, confirmations, remove: () => button.listeners.click() };
}

test('one or many selected items always require OK and delete all selected rows instead of the form quantity', async () => {
    for (const ids of [[12], [12, 15]]) {
        let decide;
        const requests = [];
        const ui = fixture(async (url, options) => { requests.push(JSON.parse(options.body)); return { ok: true,
            json: async () => ({ number: '107/2025', version: 4, deleted: true, removed_count: ids.length }) }; },
        () => new Promise(resolve => { decide = resolve; }));
        ui.selected.push(...ids.map(id => ({ value: String(id) })));
        ui.item.value = '';
        ui.quantity.value = '';
        const pending = ui.remove();
        assert.equal(requests.length, 0);
        assert.match(ui.confirmations[0], /exclusão completa/);
        decide(true);
        await pending;
        assert.deepEqual(requests[0], { product_ids: ids, version: 3 });
    }
    const ui = fixture(() => assert.fail('Cancel cannot delete'), async () => false);
    ui.selected.push({ value: '12' });
    await ui.remove();
    assert.equal(ui.confirmations.length, 1);
});

test('removal waits for OK, prevents duplicate requests and refreshes list after partial or full removal', async () => {
    for (const deleted of [false, true]) {
        let decide;
        const requests = [];
        const ui = fixture(async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => ({ number: '107/2025', version: 4, deleted }) }; },
            () => new Promise(resolve => { decide = resolve; }));
        const removing = ui.remove();
        await ui.remove();
        assert.equal(requests.length, 0);
        assert.match(ui.confirmations[0], /4,0000 do Item 2/);
        decide(true);
        await removing;
        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, '/atas/81/products');
        assert.equal(requests[0].options.method, 'DELETE');
        assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
        assert.deepEqual(JSON.parse(requests[0].options.body), { item: '2', quantity: '4,0000', version: 3 });
        assert.deepEqual(ui.events.map(event => event.type), ['ata-product-saving', 'ata-product-finished', 'ata-saved']);
        assert.match(ui.warnings[0], deleted ? /Item excluído com sucesso/ : /Quantidade retirada com sucesso/);
        assert.equal(ui.button.disabled, false);
    }
});

test('Cancel or changing the ata during confirmation prevents any removal request', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; }, async () => false);
    await ui.remove();
    assert.equal(calls, 0);
    assert.deepEqual(ui.warnings, []);
    assert.equal(ui.button.disabled, false);
    let decide;
    const stale = fixture(async () => { calls++; }, () => new Promise(resolve => { decide = resolve; }));
    const pending = stale.remove();
    stale.root.dataset.ataLoaded = '82';
    decide(true);
    await pending;
    assert.equal(calls, 0);
    assert.equal(stale.events.some(event => event.type === 'ata-saved'), false);
});

test('missing item and invalid quantities never open confirmation or call the server', async () => {
    const ui = fixture(() => { throw new Error('Unexpected request'); });
    for (const quantity of ['', '0', '-1', 'NaN']) {
        ui.quantity.value = quantity;
        await ui.remove();
    }
    ui.quantity.value = '1';
    ui.item.value = '';
    await ui.remove();
    assert.equal(ui.confirmations.length, 0);
    assert.equal(ui.warnings.length, 5);
});

test('limit failures preserve quantity and show the reason while internal errors stay private', async () => {
    for (const [status, expected] of [[422, /máxima.*10,0000/], [403, /permissão/], [409, /foi alterada/], [500, /problema ao excluir/]]) {
        const ui = fixture(async () => ({ ok: false, status, json: async () => ({
            errors: { quantity: ['A quantidade máxima que pode ser excluída deste Item é 10,0000.'] }, message: 'SQLSTATE select tb_PRODUTOS_ATA',
        }) }));
        await ui.remove();
        assert.match(ui.warnings[0], expected);
        assert.doesNotMatch(ui.warnings[0], /SQLSTATE|tb_PRODUTOS_ATA/);
        assert.equal(ui.quantity.value, '4,0000');
        assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
    }
    const ui = fixture(async () => { throw new TypeError('Network'); });
    await ui.remove();
    assert.match(ui.warnings[0], /Não foi possível confirmar a exclusão/);
});

test('standard confirmation displays OK and Cancelar and treats escape as cancellation', async () => {
    let dialog;
    const paragraph = {};
    const ownerDocument = { querySelector: () => dialog, body: { append(value) { dialog = value; } },
        createElement: () => ({ attributes: {}, listeners: {},
            setAttribute(name, value) { this.attributes[name] = value; }, querySelector: () => paragraph,
            addEventListener(name, handler) { this.listeners[name] = handler; }, showModal() { this.open = true; } }) };
    const pending = confirmSystemMessage('<Item 2>', ownerDocument);
    assert.equal(dialog.attributes.role, 'alertdialog');
    assert.match(dialog.innerHTML, /Gestão Sistem informa:/);
    assert.match(dialog.innerHTML, />OK</);
    assert.match(dialog.innerHTML, />Cancelar</);
    assert.equal(paragraph.textContent, '<Item 2>');
    dialog.returnValue = 'ok';
    dialog.open = false;
    dialog.listeners.close();
    assert.equal(await pending, true);
    for (const value of ['cancel', '']) {
        const cancelled = confirmSystemMessage('Confirma?', ownerDocument);
        dialog.returnValue = value;
        dialog.open = false;
        dialog.listeners.close();
        assert.equal(await cancelled, false);
    }
});
