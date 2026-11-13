import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDecimalField, initializeDecimalInput, decimalSubmissionValue, initializeDecimalFields } from '../../resources/js/decimal-fields.js';

test('money and quantities retain four decimals and exact large values', () => {
    for (const [value, expected] of [
        ['', '0,0000'], [null, '0,0000'], ['0', '0,0000'], ['12', '12,0000'],
        ['12,5', '12,5000'], ['1234.5678', '1.234,5678'], ['1.234,5678', '1.234,5678'],
        ['99999999999999.9999', '99.999.999.999.999,9999'], ['-2.0001', '-2,0001'],
        ['0002,050', '2,0500'], ['Não informado', '0,0000'],
    ]) assert.equal(formatDecimalField(value), expected);
    for (const value of ['abc', '1,23456', '1.2,3', '1e3', '1,2,3']) assert.equal(formatDecimalField(value), null);
});

function field(value, quantity = false) {
    const listeners = {};
    return { value, dataset: {}, listeners, selected: false,
        hasAttribute: name => quantity && name === 'data-quantity',
        addEventListener: (event, callback) => { listeners[event] = callback; },
        select() { this.selected = true; },
    };
}

test('empty optional fields show zero without persisting zero until explicitly entered', () => {
    const input = field('');
    initializeDecimalInput(input);
    assert.equal(input.value, '0,0000');
    assert.equal(decimalSubmissionValue(input), '');
    input.listeners.focus();
    assert.equal(input.selected, true);
    input.value = '0';
    input.listeners.input();
    assert.equal(decimalSubmissionValue(input), '0,0000');
    input.value = '';
    input.listeners.input();
    assert.equal(decimalSubmissionValue(input), '');
    assert.equal(input.value, '0,0000');
});

test('catalog writes, resets and edits preserve formatting and field identity', () => {
    const input = field('1234.5678', true);
    const state = initializeDecimalInput(input);
    assert.equal(input.dataset.decimalKind, 'quantity');
    assert.equal(input.value, '1.234,5678');
    input.value = '2,5';
    input.listeners.input();
    state.format();
    assert.equal(input.value, '2,5000');
    assert.equal(decimalSubmissionValue(input), '2,5000');
    input.value = '99999999999999.9999';
    state.format();
    assert.equal(input.value, '99.999.999.999.999,9999');
    assert.equal(initializeDecimalInput(input), state);
    assert.equal(initializeDecimalInput(field('0')).blank, false);
    assert.equal(input.value.includes('NaN'), false);
});

test('delegation formats blur, submit, asynchronous FormData and newly inserted fields', (t) => {
    const originalObserver = globalThis.MutationObserver;
    t.after(() => { globalThis.MutationObserver = originalObserver; });
    let rescan;
    globalThis.MutationObserver = class { constructor(callback) { rescan = callback; } observe() {} };
    const inputs = [field(''), field('1234.5678')];
    inputs.forEach((input, index) => { input.name = `field${index}`; input.matches = () => true; });
    const events = {};
    const doc = { body: {}, querySelectorAll: selector => selector.startsWith('input') ? inputs : [],
        addEventListener: (name, callback) => { events[name] = callback; } };
    initializeDecimalFields(doc);
    const form = { querySelectorAll: () => inputs };
    inputs[1].value = '2,5';
    inputs[1].listeners.input();
    events.focusout({ target: inputs[1] });
    assert.equal(inputs[1].value, '2,5000');
    const formData = new Map([['field0', inputs[0].value], ['field1', inputs[1].value]]);
    events.formdata({ target: form, formData });
    assert.equal(formData.get('field0'), '');
    assert.equal(formData.get('field1'), '2,5000');
    inputs[1].value = '12';
    events.submit({ target: form });
    assert.equal(inputs[1].value, '12,0000');
    const added = field('9876.5432');
    inputs.push(added);
    rescan();
    assert.equal(added.value, '9.876,5432');
});
