import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeInspectorCpf } from '../../resources/js/inspector-registration.js';

function setup(value) {
    const input = { value, listeners: {}, attributes: {}, addEventListener(name, fn) { this.listeners[name] = fn; }, setAttribute(name, value) { this.attributes[name] = value; } };
    const message = {};
    const next = { focused: false, focus() { this.focused = true; } };
    const form = { listeners: {}, events: [], querySelector(selector) { return selector === '[data-inspector-cpf]' ? input : selector === '[name="RG_Fiscal"]' ? next : message; },
        addEventListener(name, fn) { this.listeners[name] = fn; }, dispatchEvent(event) { this.events.push(event.type); } };
    const warnings = [];
    initializeInspectorCpf(form, (message) => warnings.push(message));
    return { input, message, form, warnings, next };
}

test('invalid, incomplete, repeated and CNPJ values are rejected on blur and submit; correction clears warning', () => {
    for (const value of ['111.111.111-11', '529.982.247-24', '529.98', '11.222.333/0001-81']) {
        const { input, message, form, warnings } = setup(value);
        input.listeners.blur();
        assert.equal(message.textContent, 'Informe um CPF válido.');
        assert.equal(input.attributes['aria-invalid'], 'true');
        assert.deepEqual(warnings, ['CPF do fiscal inválido. Verifique os números informados antes de salvar.']);
        let blocked = false;
        form.listeners.submit({ preventDefault() { blocked = true; } });
        assert.equal(blocked, true);
        assert.deepEqual(form.events, ['inspector-cpf-invalid']);
        assert.equal(warnings.length, 1);
        input.value = '529.982.247-25';
        input.listeners.input();
        assert.equal(message.hidden, true);
        assert.equal(input.attributes['aria-invalid'], 'false');
    }
});

test('valid CPF and optional empty field allow submission', () => {
    for (const value of ['529.982.247-25', '52998224725', '']) {
        const { message, form, warnings } = setup(value);
        form.listeners.submit({ preventDefault() { assert.fail('Valid CPF must not block submit'); } });
        assert.equal(message.hidden, true);
        assert.deepEqual(form.events, []);
        assert.deepEqual(warnings, []);
    }
});

test('Enter on invalid CPF shows warning; after correction Enter advances to RG without submitting the form', () => {
    const { input, message, form, warnings, next } = setup('529.982.247-24');
    let prevented = 0;
    const enter = { key: 'Enter', preventDefault() { prevented++; } };
    input.listeners.keydown(enter);
    assert.equal(prevented, 1);
    assert.equal(warnings.length, 1);
    assert.equal(next.focused, false);
    input.value = '529.982.247-25';
    input.listeners.input();
    input.listeners.keydown(enter);
    assert.equal(prevented, 2);
    assert.equal(message.hidden, true);
    assert.equal(warnings.length, 1);
    assert.equal(next.focused, true);
    assert.deepEqual(form.events, ['inspector-cpf-invalid']);
    input.listeners.keydown({ key: 'Tab', preventDefault() { assert.fail('Tab must remain native'); } });
    input.listeners.keydown({ key: 'Enter', isComposing: true, preventDefault() { assert.fail('IME composition must remain native'); } });
});
