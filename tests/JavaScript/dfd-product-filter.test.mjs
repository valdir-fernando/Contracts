import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeDfdProductFilter, initializeDfdProductLaunch, formatDfdQuantity } from '../../resources/js/dfd-product-filter.js';

test('launch rejects zero and accepts a fourth fractional digit without rounding', async (t) => {
    const originalFormData = globalThis.FormData;
    t.after(() => { globalThis.FormData = originalFormData; });
    globalThis.FormData = class { constructor(form) { this.quantity = form.quantity.value; } };
    let submit;
    let sent;
    const quantity = { value: '0,0000', setCustomValidity(message) { this.message = message; }, reportValidity() {} };
    const form = { action: '/dfd/products', quantity, querySelector: () => quantity,
        addEventListener: (_, callback) => { submit = callback; } };
    const status = {};
    initializeDfdProductLaunch({ querySelectorAll: () => [form], querySelector: () => status }, async (_, options) => {
        sent = options.body.quantity;
        return { ok: false, json: async () => ({ message: 'Resposta de teste' }) };
    });
    await submit({ preventDefault() {} });
    assert.equal(sent, undefined);
    assert.match(quantity.message, /quantidade positiva/);
    quantity.value = '0,0001';
    await submit({ preventDefault() {} });
    assert.equal(sent, '0,0001');
});

test('quantities use a decimal comma and exactly four decimal places without float conversion', () => {
    for (const [input, expected] of [['2', '2,0000'], ['2,5', '2,5000'], ['0,125', '0,1250'], ['0002,050', '2,0500'], ['0', '0,0000'], ['', ''], ['1654', '1.654,0000'], ['1.654,0000', '1.654,0000'], ['1125984', '1.125.984,0000'], ['1.125.984,0000', '1.125.984,0000'], ['12345678901234567,123', '12.345.678.901.234.567,1230']]) {
        assert.equal(formatDfdQuantity(input), expected);
    }
    for (const input of ['-1', 'abc', '1,23456', '1.2,3', '12.34']) assert.equal(formatDfdQuantity(input), null);
});

test('blur filters names while selected products remain visible and clearing restores all', () => {
    const events = {};
    const query = { value: '', addEventListener: (name, callback) => { events[name] = callback; } };
    const rows = ['Papel A4', 'Caneta azul', 'Álcool líquido'].map((name) => {
        const checkbox = { checked: false, addEventListener: (_, callback) => { checkbox.change = callback; } };
        const quantity = { disabled: true, value: '', setCustomValidity: () => {} };
        const classes = new Set();
        return { dataset: { productName: name }, hidden: false, quantity, classes,
            classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) },
            querySelector: (selector) => selector === '[data-dfd-product-quantity]' ? quantity : selector === '[data-dfd-product-launch-button]' ? null : checkbox };
    });
    const empty = { hidden: true };
    const total = { hidden: true, textContent: '' };
    initializeDfdProductFilter({
        querySelector: (selector) => ({ '[data-dfd-product-query]': query, '[data-dfd-product-empty]': empty, '[data-dfd-product-total]': total })[selector],
        querySelectorAll: (selector) => selector === '[data-dfd-product-row]' ? rows : [],
    });
    assert.equal(total.hidden, true);
    assert.ok(rows.every((row) => row.quantity.disabled));
    rows[1].querySelector().checked = true;
    rows[1].querySelector().change();
    assert.equal(total.hidden, false);
    assert.equal(total.textContent, 'Total de Itens Selecionados: 1');
    assert.equal(rows[1].quantity.disabled, false);
    assert.equal(rows[1].classes.has('dfd-product-selected'), true);
    assert.equal(rows[0].classes.has('dfd-product-selected'), false);
    assert.equal(rows[1].querySelector().disabled, false);
    assert.equal(rows[0].querySelector().disabled, true);
    assert.equal(rows[2].querySelector().disabled, true);
    rows[1].quantity.value = '1.654,0000';
    assert.equal(rows[0].quantity.disabled, true);
    query.value = 'PAP';
    events.input();
    assert.deepEqual(rows.map((row) => row.hidden), [false, false, false]);
    events.blur();
    assert.deepEqual(rows.map((row) => row.hidden), [false, false, true]);
    query.value = 'alcool';
    events.blur();
    assert.deepEqual(rows.map((row) => row.hidden), [true, false, false]);
    query.value = '';
    events.input();
    assert.deepEqual(rows.map((row) => row.hidden), [false, false, false]);
    assert.equal(rows[1].querySelector().checked, true);
    assert.equal(rows[1].quantity.value, '1.654,0000');
    assert.equal(total.textContent, 'Total de Itens Selecionados: 1');
    rows[0].querySelector().checked = true;
    rows[0].querySelector().change();
    assert.equal(total.textContent, 'Total de Itens Selecionados: 1');
    assert.equal(rows[0].querySelector().checked, false);
    assert.equal(rows[1].querySelector().checked, true);
    assert.equal(rows[1].quantity.value, '1.654,0000');
    rows[0].querySelector().checked = false;
    rows[0].querySelector().change();
    query.value = 'inexistente';
    events.blur();
    assert.equal(empty.hidden, true);
    rows[1].querySelector().checked = false;
    rows[1].querySelector().change();
    assert.equal(empty.hidden, false);
    assert.equal(total.hidden, true);
    assert.ok(rows.every((row) => row.quantity.disabled));
    assert.ok(rows.every((row) => row.quantity.value === '0,0000'));
    assert.ok(rows.every((row) => !row.classes.has('dfd-product-selected')));
    assert.ok(rows.every((row) => !row.querySelector().disabled));
    rows[0].querySelector().checked = true;
    rows[0].querySelector().change();
    assert.equal(rows[0].quantity.disabled, false);
    assert.equal(rows[1].querySelector().disabled, true);
    assert.equal(rows[2].querySelector().disabled, true);
    assert.equal(total.textContent, 'Total de Itens Selecionados: 1');
});
