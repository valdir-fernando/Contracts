import test from 'node:test';
import assert from 'node:assert/strict';
import { formatArea, initializeArea } from '../../resources/js/contract-area.js';

test('areas use two decimal places and square meters without losing large integer precision', () => {
    for (const [value, expected] of [
        ['0', '0,00M²'], ['120', '120,00M²'], ['1234,5', '1.234,50M²'],
        ['1.234,567 M²', '1.234,57M²'], ['12.3456', '12,35M²'],
        ['999,9999', '1.000,00M²'], ['9007199254740993', '9.007.199.254.740.993,00M²'],
        ['', ''], ['   ', ''], ['Conforme matrícula', 'Conforme matrícula'],
    ]) assert.equal(formatArea(value), expected);
});

test('area is formatted on opening and again on blur, while typing remains free', () => {
    const input = { value: '12', addEventListener(name, listener) { this[name] = listener; } };
    initializeArea(input);
    assert.equal(input.value, '12,00M²');
    input.value = '25,4';
    input.blur();
    assert.equal(input.value, '25,40M²');
    input.blur();
    assert.equal(input.value, '25,40M²');
});
