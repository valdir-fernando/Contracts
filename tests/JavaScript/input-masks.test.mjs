import test from 'node:test';
import assert from 'node:assert/strict';
import { attachMask, maskPhone, maskPis, maskDate, isoDate, dateInputAllowed } from '../../resources/js/input-masks.js';

test('phone and WhatsApp masks support landlines and mobiles with DDD and partial input', () => {
    assert.equal(maskPhone('6233334444'), '(62) 3333-4444');
    assert.equal(maskPhone('62999998888'), '(62) 99999-8888');
    assert.equal(maskPhone('(62) 99999-8888'), '(62) 99999-8888');
    assert.equal(maskPhone('62999'), '(62) 999');
    assert.equal(maskPhone(''), '');
    assert.equal(maskPhone('abc62999998888123'), '(62) 99999-8888');
    assert.equal(maskPis('12345678901'), '123.45678.90-1');
    assert.equal(maskPis('123.45678.90-1'), '123.45678.90-1');
    assert.equal(maskPis('1234'), '123.4');
});

test('dates mask digits and validate real calendar dates including leap years without changing ISO transport', () => {
    assert.equal(maskDate('29022024'), '29/02/2024');
    assert.equal(maskDate('2024-02-29'), '29/02/2024');
    assert.equal(maskDate('12ab032026'), '12/03/2026');
    assert.equal(isoDate('29/02/2024'), '2024-02-29');
    assert.equal(isoDate('2024-02-29'), '2024-02-29');
    for (const value of ['29/02/2025', '31/04/2026', '00/01/2026', '01/13/2026', 'texto', '1/1/2026', '123']) {
        assert.equal(isoDate(value), null, value);
    }
});

test('typing and paste cannot retain letters in a date and caret stays at the edited digits', () => {
    const input = { value: '', selectionStart: 0, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setSelectionRange(start) { this.selectionStart = start; } };
    attachMask(input, maskDate, /^[\d/-]*$/, dateInputAllowed);
    let blocked = false;
    input.listeners.beforeinput({ data: 'a', preventDefault() { blocked = true; } });
    assert.equal(blocked, true);
    input.value = '01x012026';
    input.selectionStart = input.value.length;
    input.listeners.input();
    assert.equal(input.value, '01/01/2026');
    assert.equal(input.selectionStart, 10);
    input.value = '02/01/2026';
    input.selectionStart = 2;
    input.listeners.input();
    assert.equal(input.selectionStart, 2);
});

test('month 13 is blocked while typing and impossible pasted dates never remain in the field', () => {
    const input = { value: '', selectionStart: 0, selectionEnd: 0, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; } };
    attachMask(input, maskDate, /^[\d/-]*$/, dateInputAllowed);
    input.value = '12/1';
    input.selectionStart = input.selectionEnd = 4;
    input.listeners.input();
    let blocked = false;
    input.listeners.beforeinput({ data: '3', preventDefault() { blocked = true; } });
    assert.equal(blocked, true);
    assert.equal(input.value, '12/1');
    for (const value of ['12/13/2026', '31/04/2026', '29/02/2026', '00/12/2026']) {
        input.value = value;
        input.selectionStart = input.selectionEnd = value.length;
        input.listeners.input();
        assert.equal(input.value, '12/1', value);
    }
    input.value = '29/02/2024';
    input.selectionStart = input.selectionEnd = 10;
    input.listeners.input();
    assert.equal(input.value, '29/02/2024');
    input.value = '';
    input.selectionStart = input.selectionEnd = 0;
    input.listeners.input();
    assert.equal(input.value, '');
});
