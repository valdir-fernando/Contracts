import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeGuaranteeMessage } from '../../resources/js/guarantee-message.js';

test('guarantee words stay hidden until blur with a value and hide again while editing or clearing', () => {
    const listeners = {};
    const field = { value: '1.000,0000', addEventListener: (name, callback) => { listeners[name] = callback; } };
    const message = { hidden: false };
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
