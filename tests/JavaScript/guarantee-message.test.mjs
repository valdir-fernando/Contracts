import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeGuaranteeMessage, formatGuaranteeAmount, guaranteeAmountWords } from '../../resources/js/guarantee-message.js';

test('converts exact amounts to Brazilian monetary words', () => {
    for (const [value, expected] of [['', ''], ['invalid', ''], ['0', 'zero reais'], ['1', 'um real'], ['0,01', 'um centavo'], ['100', 'cem reais'], ['1000', 'mil reais'], ['1234,56', 'mil, duzentos e trinta e quatro reais e cinquenta e seis centavos'], ['1000000', 'um milhão de reais'], ['2000100', 'dois milhões e cem reais'], ['1.9999', 'dois reais']]) {
        assert.equal(guaranteeAmountWords(value), expected);
    }
});

test('guarantee amount stays blank and formats exact monetary values without losing precision', () => {
    for (const [value, expected] of [['', ''], ['   ', ''], ['1234.5', '1.234,50'], ['1234,56', '1.234,56'], ['1000.0000', '1.000,00'], ['1234.5678', '1.234,5678'], ['99999999999999.9999', '99.999.999.999.999,9999'], ['0', '0,00'], ['invalid', 'invalid']]) {
        assert.equal(formatGuaranteeAmount(value), expected);
    }
});

test('guarantee words stay hidden until blur with a value and hide again while editing or clearing', () => {
    const listeners = {};
    const field = { value: '1.000,0000', addEventListener: (name, callback) => { listeners[name] = callback; } };
    const message = { hidden: false, textContent: 'Mil reais' };
    initializeGuaranteeMessage(field, message);
    assert.equal(message.hidden, true);
    listeners.blur();
    assert.equal(message.hidden, false);
    field.value = '0';
    listeners.input();
    assert.equal(message.hidden, true);
    listeners.blur();
    assert.equal(message.hidden, false);
    field.value = '   ';
    listeners.input();
    listeners.blur();
    assert.equal(message.hidden, true);
});

test('generates and updates guarantee words from the entered amount and clears them on empty blur', () => {
    const listeners = {};
    const field = { value: '', addEventListener: (name, callback) => { listeners[name] = callback; } };
    const message = { hidden: false, textContent: '' };
    initializeGuaranteeMessage(field, message);
    field.value = '1000';
    listeners.blur();
    assert.equal(field.value, '1.000,00');
    assert.equal(message.hidden, false);
    assert.equal(message.textContent, 'mil reais');
    field.value = '25,50';
    listeners.blur();
    assert.equal(message.textContent, 'vinte e cinco reais e cinquenta centavos');
    field.value = '';
    listeners.blur();
    assert.equal(message.textContent, '');
    assert.equal(message.hidden, true);
});

test('saved contract keeps persisted words and warns until the amount is restored or saved', () => {
    const listeners = {};
    const field = { value: '1000.0000', addEventListener: (name, callback) => { listeners[name] = callback; } };
    const message = { textContent: 'Mil reais', dataset: { contractSaved: 'true' } };
    const notice = { hidden: true };
    initializeGuaranteeMessage(field, message, notice);
    field.value = '2000';
    listeners.input();
    assert.equal(notice.hidden, false);
    listeners.blur();
    assert.equal(message.textContent, 'Mil reais');
    assert.equal(notice.hidden, false);
    field.value = '1000';
    listeners.input();
    assert.equal(notice.hidden, true);
});
