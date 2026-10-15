import test from 'node:test';
import assert from 'node:assert/strict';
import { guardSupplierCatalogClose } from '../../resources/js/supplier-picker-guard.js';

test('catalog close locks during registration and validation reopening, and unlocks on dismissal', () => {
    const dialog = new EventTarget();
    const close = new EventTarget();
    const frame = new EventTarget();
    let open = false;
    frame.contentDocument = { querySelector: () => open ? {} : null };
    let notify;
    class Observer {
        constructor(callback) { notify = callback; }
        observe() {}
        disconnect() {}
    }
    guardSupplierCatalogClose(dialog, close, frame, Observer);
    let closed = 0;
    close.addEventListener('click', () => { closed++; });
    frame.dispatchEvent(new Event('load'));
    assert.equal(close.disabled, false);
    open = true;
    notify();
    assert.equal(close.disabled, true);
    close.dispatchEvent(new Event('click', { cancelable: true }));
    assert.equal(closed, 0);
    const escape = new Event('cancel', { cancelable: true });
    dialog.dispatchEvent(escape);
    assert.equal(escape.defaultPrevented, true);
    frame.dispatchEvent(new Event('load'));
    assert.equal(close.disabled, true);
    open = false;
    notify();
    assert.equal(close.disabled, false);
    close.dispatchEvent(new Event('click', { cancelable: true }));
    assert.equal(closed, 1);
    const availableEscape = new Event('cancel', { cancelable: true });
    dialog.dispatchEvent(availableEscape);
    assert.equal(availableEscape.defaultPrevented, false);
});
