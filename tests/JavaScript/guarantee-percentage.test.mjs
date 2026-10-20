import test from 'node:test';
import assert from 'node:assert/strict';
import { formatGuaranteePercentage, initializeGuaranteePercentage } from '../../resources/js/guarantee-percentage.js';

test('guarantee uses a comma, two decimals and percent without changing legacy descriptions', () => {
    for (const [value, expected] of [
        ['0', '0,00%'], ['5', '5,00%'], ['5,5', '5,50%'], ['5.125', '5,13%'],
        [' 10,00% ', '10,00%'], ['', ''], ['   ', ''], ['Conforme contrato', 'Conforme contrato'],
    ]) assert.equal(formatGuaranteePercentage(value), expected);
});

test('guarantee formats on opening, blur and submission including FormData', () => {
    const handlers = {};
    const input = { name: 'garantia', value: '5', addEventListener(name, listener) { this[name] = listener; },
        form: { addEventListener(name, listener) { handlers[name] = listener; } } };
    initializeGuaranteePercentage(input);
    assert.equal(input.value, '5,00%');
    input.value = '2,5';
    input.blur();
    assert.equal(input.value, '2,50%');
    input.value = '3';
    handlers.submit();
    assert.equal(input.value, '3,00%');
    input.value = '4.25';
    const formData = new FormData();
    handlers.formdata({ formData });
    assert.equal(formData.get('garantia'), '4,25%');
});

test('guarantee rejects values outside inclusive limits without silently changing the percentage', () => {
    const input = { value: '', listeners: {}, addEventListener(name, listener) { this.listeners[name] = listener; },
        setCustomValidity(message) { this.error = message; } };
    const messages = [];
    initializeGuaranteePercentage(input, message => messages.push(message));
    for (const value of ['0', '100,01', '-1', 'abc']) {
        input.value = value;
        input.listeners.blur();
        assert.equal(input.value, '', value);
        assert.equal(input.error, '');
    }
    for (const value of ['', '0,01', '0,99', '100,00', '50']) {
        input.value = value;
        input.listeners.blur();
        assert.equal(input.error, '', value);
    }
    assert.equal(messages.length, 4);
    assert.match(messages[0], /0,01% e 100,00%/);
});

test('typing or pasting cannot leave more than two decimal digits', () => {
    const input = { value: '', listeners: {}, addEventListener(name, listener) { this.listeners[name] = listener; } };
    const messages = [];
    initializeGuaranteePercentage(input, message => messages.push(message));
    for (const [value, expected] of [['1,234', '1,23'], ['99,9999%', '99,99'], ['0,01', '0,01'], ['5,', '5,'], ['156,36%', ''], ['101', ''], ['100,01', ''], ['a5!,2x', '5,2'], ['1,2,3', '1,23']]) {
        input.value = value;
        input.listeners.input();
        assert.equal(input.value, expected);
    }
    assert.equal(messages.length, 3);
});

test('editing accepts only numbers and one comma with at most two decimal places', () => {
    const input = { value: '5,00%', listeners: {}, selectionStart: 1, selectionEnd: 1,
        addEventListener(name, listener) { this.listeners[name] = listener; } };
    initializeGuaranteePercentage(input);
    input.listeners.focus();
    assert.equal(input.value, '5,00');
    for (const data of ['a', '.', '%', '-', ' ', ',']) {
        let prevented = false;
        input.listeners.beforeinput({ inputType: 'insertText', data, preventDefault() { prevented = true; } });
        assert.equal(prevented, true, data);
    }
    input.value = '5,';
    input.selectionStart = input.selectionEnd = 2;
    let prevented = false;
    input.listeners.beforeinput({ inputType: 'insertText', data: '2', preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
});
