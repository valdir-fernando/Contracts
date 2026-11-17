import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaSpecialQuantity } from '../../resources/js/ata-special-quantity.js';
import { requestAtaSpecialQuantityName } from '../../resources/js/system-message.js';

function fixture(name, fetcher = async () => ({ ok: true, json: async () => ({ id: 81, number: '107/2025', version: 4, special_quantity_name: name.trim() }) }), confirm = async () => true) {
    const node = () => ({ dataset: {}, listeners: {}, textContent: '', value: '', hidden: true,
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setAttribute(name, value) { this[name] = value; },
        classList: { toggle(name, active) { this[name] = active; } },
    });
    const controls = Object.fromEntries(['special-quantity', 'special-quantity-delete', 'quantity-pricing', 'special-quantity-field', 'special-quantity-label', 'special-quantity-heading'].map(name => [`[data-ata-${name}]`, node()]));
    controls['[data-product-special-quantity]'] = node();
    controls['[data-ata-special-quantity]'].dataset.ataSpecialQuantityUrl = '/atas/0/special-quantity';
    const events = [], warnings = [], requests = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', csrfToken: 'csrf' }, ownerDocument: {}, listeners: {},
        querySelector: selector => controls[selector], querySelectorAll: () => [], dispatchEvent: event => events.push(event),
        addEventListener(name, callback) { this.listeners[name] = callback; } };
    initializeAtaSpecialQuantity(root, async (...args) => { requests.push(args); return fetcher(...args); }, message => warnings.push(message), async () => name, confirm);
    return { root, controls, events, warnings, requests, click: () => controls['[data-ata-special-quantity]'].listeners.click(),
        remove: () => controls['[data-ata-special-quantity-delete]'].listeners.click() };
}

test('delete waits for standard confirmation, clears the special field and reloads product values after success', async () => {
    let approve;
    const ui = fixture('', async () => ({ ok: true, json: async () => ({ id: 81, number: '107/2025', version: 4, special_quantity_name: null }) }), message => {
        assert.match(message, /QTD de veículos/);
        assert.match(message, /todos os valores/);
        return new Promise(resolve => { approve = resolve; });
    });
    ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: 'veículos' } });
    ui.controls['[data-product-special-quantity]'].value = '7,0000';
    const pending = ui.remove();
    await ui.remove();
    assert.equal(ui.requests.length, 0);
    approve(true);
    await pending;
    assert.equal(ui.requests.length, 1);
    const [url, request] = ui.requests[0];
    assert.equal(url, '/atas/81/special-quantity');
    assert.equal(request.method, 'DELETE');
    assert.deepEqual(JSON.parse(request.body), { version: 3 });
    assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(ui.controls['[data-ata-special-quantity-field]'].hidden, true);
    assert.equal(ui.controls['[data-ata-special-quantity-heading]'].hidden, true);
    assert.equal(ui.controls['[data-ata-special-quantity]'].hidden, false);
    assert.equal(ui.controls['[data-product-special-quantity]'].value, '0,0000');
    assert.equal(ui.root.dataset.ataVersion, '4');
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), true);
    assert.match(ui.warnings[0], /excluídos com sucesso/);
});

test('Cancel, changed ATA or changed version during confirmation prevent deletion', async () => {
    for (const change of ['cancel', 'ata', 'version']) {
        let approve;
        const ui = fixture('', undefined, () => new Promise(resolve => { approve = resolve; }));
        ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: 'veículos' } });
        const pending = ui.remove();
        if (change === 'ata') ui.root.dataset.ataLoaded = '82';
        if (change === 'version') ui.root.dataset.ataVersion = '4';
        approve(change !== 'cancel');
        await pending;
        assert.equal(ui.requests.length, 0);
        assert.equal(ui.root.dataset.ataSpecialQuantityName, 'veículos');
        assert.deepEqual(ui.warnings, []);
    }
});

test('deletion errors preserve fields and report plain reasons without server details', async () => {
    for (const status of [401, 403, 409, 500]) {
        const ui = fixture('', async () => ({ ok: false, status, json: async () => ({ message: 'SQLSTATE secret' }) }));
        ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: 'veículos' } });
        ui.controls['[data-product-special-quantity]'].value = '7,0000';
        await ui.remove();
        assert.equal(ui.root.dataset.ataSpecialQuantityName, 'veículos');
        assert.equal(ui.controls['[data-product-special-quantity]'].value, '7,0000');
        assert.equal(ui.root.dataset.ataVersion, '3');
        assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
        assert.equal(ui.warnings.length, 1);
        assert.doesNotMatch(ui.warnings[0], /SQLSTATE|secret/);
    }
});

test('empty OK does not write, change visibility, quantity, version or show success', async () => {
    for (const name of ['', '   ']) {
        const ui = fixture(name);
        ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: 'veículos' } });
        ui.controls['[data-product-special-quantity]'].value = '7,0000';
        ui.events.length = 0;
        await ui.click();
        assert.deepEqual(ui.requests, []);
        assert.deepEqual(ui.events, []);
        assert.deepEqual(ui.warnings, []);
        assert.equal(ui.root.dataset.ataVersion, '3');
        assert.equal(ui.controls['[data-ata-special-quantity-field]'].hidden, false);
        assert.equal(ui.controls['[data-product-special-quantity]'].value, '7,0000');
    }
});

test('filled OK persists the trimmed name and displays an ATA-specific field and list column', async () => {
    const ui = fixture(' veículos ');
    await ui.click();
    const [url, request] = ui.requests[0];
    assert.equal(url, '/atas/81/special-quantity');
    assert.equal(request.method, 'PATCH');
    assert.deepEqual(JSON.parse(request.body), { version: 3, special_quantity_name: 'veículos' });
    assert.equal(ui.root.dataset.ataVersion, '4');
    assert.equal(ui.controls['[data-ata-special-quantity]'].hidden, true);
    assert.equal(ui.controls['[data-ata-special-quantity-heading]'].textContent, 'QTD de veículos');
    assert.equal(ui.controls['[data-ata-special-quantity-heading]'].hidden, false);
    assert.equal(ui.controls['[data-ata-quantity-pricing]'].classList['has-special-quantity'], true);
    assert.equal(ui.events.some(event => event.type === 'ata-special-quantity-updated'), true);
    ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: null } });
    assert.equal(ui.controls['[data-ata-special-quantity]'].hidden, false);
    assert.equal(ui.controls['[data-ata-special-quantity-field]'].hidden, true);
    assert.equal(ui.controls['[data-product-special-quantity]'].disabled, true);
    ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: 'veículos' } });
    assert.equal(ui.controls['[data-ata-special-quantity]'].hidden, true);
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.controls['[data-ata-special-quantity]'].hidden, false);
});

test('failed name writes keep the previous configuration and explain errors safely', async () => {
    for (const status of [403, 409, 422, 500]) {
        const ui = fixture('novo', async () => ({ ok: false, status }));
        ui.root.listeners['ata-loaded']({ detail: { special_quantity_name: 'atual' } });
        await ui.click();
        assert.equal(ui.root.dataset.ataSpecialQuantityName, 'atual');
        assert.equal(ui.root.dataset.ataVersion, '3');
        assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
        assert.equal(ui.warnings.length, 1);
    }
});

test('switching ATA while the prompt is open cannot configure another ATA', async () => {
    const ui = fixture('novo');
    const pending = ui.click();
    ui.root.dataset.ataLoaded = '82';
    await pending;
    assert.deepEqual(ui.requests, []);
});

test('standard dialog has only OK, accepts an empty name, trims names, and ignores Escape', async () => {
    let dialog;
    const input = { value: '', focus() {} };
    const ownerDocument = {
        querySelector: () => dialog,
        createElement: () => ({ setAttribute() {}, querySelector: () => input,
            addEventListener(name, callback) { this[name] = callback; },
            showModal() { this.open = true; },
        }), body: { append(node) { dialog = node; } },
    };
    for (const [value, returnValue, expected] of [['', 'ok', ''], ['   ', 'ok', ''], [' veículos ', 'ok', 'veículos'], ['veículos', '', '']]) {
        const pending = requestAtaSpecialQuantityName(ownerDocument);
        assert.equal((dialog.innerHTML.match(/<button/g) || []).length, 1);
        assert.doesNotMatch(dialog.innerHTML, /Cancelar|required/);
        input.value = value;
        dialog.returnValue = returnValue;
        dialog.open = false;
        dialog.close();
        assert.equal(await pending, expected);
    }
});
