import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeFittedField } from '../../resources/js/fit-field.js';

test('fits the selected label after a user choice, lookup, or resize and restores the default size', t => {
    let resize;
    const original = globalThis.ResizeObserver;
    globalThis.ResizeObserver = class {
        constructor(callback) { resize = callback; }
        observe() {}
    };
    t.after(() => { globalThis.ResizeObserver = original; });

    const select = new EventTarget();
    Object.assign(select, {
        style: {}, clientWidth: 221, selectedOptions: [{ label: 'short' }],
        ownerDocument: {
            createElement: () => ({ getContext: () => ({ measureText: text => ({ width: text === 'short' ? 40 : 320 }) }) }),
            defaultView: { getComputedStyle: () => ({ fontFamily: 'sans-serif', fontSize: '16px', fontWeight: '400',
                fontStyle: 'normal', paddingLeft: '12px', paddingRight: '48px' }) },
        },
    });
    initializeFittedField(select);
    assert.equal(select.style.fontSize, '');

    select.selectedOptions[0].label = 'long';
    select.dispatchEvent(new Event('change'));
    assert.equal(select.style.fontSize, '8px');

    select.clientWidth = 301;
    resize();
    assert.equal(select.style.fontSize, '12px');

    select.selectedOptions[0].label = 'short';
    select.dispatchEvent(new Event('fit-field'));
    assert.equal(select.style.fontSize, '');

    const input = Object.assign(new EventTarget(), { ownerDocument: select.ownerDocument, clientWidth: 251, style: {}, value: 'short' });
    initializeFittedField(input);
    assert.equal(input.style.fontSize, '');
    input.value = 'long';
    input.dispatchEvent(new Event('input'));
    assert.equal(input.style.fontSize, '9.5px');
    input.clientWidth = 161;
    resize();
    assert.equal(input.style.fontSize, '8px');
    input.value = 'short';
    input.dispatchEvent(new Event('fit-field'));
    assert.equal(input.style.fontSize, '');
});
