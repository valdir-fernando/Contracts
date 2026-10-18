import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeManagerRegistration } from '../../resources/js/manager-registration.js';

function control(value = '') {
    return {
        value, listeners: {}, attributes: {}, selectionStart: value.length, selectionEnd: value.length,
        addEventListener(type, handler) { (this.listeners[type] ??= []).push(handler); },
        emit(type, event = {}) { for (const handler of this.listeners[type] ?? []) handler(event); },
        setAttribute(key, value) { this.attributes[key] = value; },
        setCustomValidity(value) { this.error = value; },
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
        focus() { this.focused = true; },
    };
}

function setup(cpf = '52998224725', feedback = null) {
    const fields = {
        Nome_Gestor: control('João de Araújo'), CPF_Gestor: control(cpf), RG_Gestor: control(),
        Fone_Celular: control('62999399551'), WhatsApp: control('6233334444'),
    };
    const error = {};
    const warnings = [];
    const form = {
        elements: { namedItem: (key) => fields[key] }, listeners: {}, events: [],
        querySelector: (selector) => selector === '[data-manager-cpf-error]' ? error
            : feedback ? { textContent: JSON.stringify(feedback) } : null,
        addEventListener(type, handler) { this.listeners[type] = handler; },
        dispatchEvent(event) { this.events.push(event.type); },
    };
    initializeManagerRegistration(form, (text, onClose) => warnings.push({ text, onClose }));
    return { fields, error, form, warnings };
}

test('name is uppercase on load, typing and composition completion while retaining cursor position', () => {
    const { fields: { Nome_Gestor: name } } = setup();
    assert.equal(name.value, 'JOÃO DE ARAÚJO');
    name.value = 'Márcia Souza';
    name.selectionStart = name.selectionEnd = 6;
    name.emit('input');
    assert.equal(name.value, 'MÁRCIA SOUZA');
    assert.equal(name.selectionStart, 6);
    name.value = 'José';
    name.emit('input', { isComposing: true });
    assert.equal(name.value, 'José');
    name.emit('compositionend');
    assert.equal(name.value, 'JOSÉ');
});

test('CPF and phone masks apply on load and pasted input, supporting fixed and mobile numbers', () => {
    const { fields } = setup();
    assert.equal(fields.CPF_Gestor.value, '529.982.247-25');
    assert.equal(fields.Fone_Celular.value, '(62) 99939-9551');
    assert.equal(fields.WhatsApp.value, '(62) 3333-4444');
    fields.WhatsApp.value = '11987654321';
    fields.WhatsApp.emit('input');
    assert.equal(fields.WhatsApp.value, '(11) 98765-4321');
    let blocked = false;
    fields.Fone_Celular.emit('beforeinput', { data: 'abc', preventDefault() { blocked = true; } });
    assert.equal(blocked, true);
});

test('empty, incomplete, repeated and invalid CPF block submit and Enter; correction clears validity and advances to RG', () => {
    for (const value of ['', '52998', '11111111111', '52998224724', '11222333000181']) {
        const { fields, error, form } = setup(value);
        const cpf = fields.CPF_Gestor;
        cpf.emit('blur');
        assert.equal(error.hidden, false);
        assert.ok(cpf.error);
        let blocked = false;
        form.listeners.submit({ preventDefault() { blocked = true; } });
        assert.equal(blocked, true);
        assert.deepEqual(form.events, ['manager-cpf-invalid']);
        cpf.emit('keydown', { key: 'Enter', preventDefault() {} });
        assert.notEqual(fields.RG_Gestor.focused, true);
        cpf.value = '52998224725';
        cpf.emit('input');
        assert.equal(error.hidden, true);
        assert.equal(cpf.error, '');
        cpf.emit('keydown', { key: 'Enter', preventDefault() {} });
        assert.equal(fields.RG_Gestor.focused, true);
        form.listeners.submit({ preventDefault() { assert.fail('Valid CPF must allow submission'); } });
    }
});

test('native invalid event reveals CPF tab and IME or modified Enter stays native', () => {
    const { fields, form } = setup('');
    fields.CPF_Gestor.emit('invalid', { preventDefault() {} });
    assert.deepEqual(form.events, ['manager-cpf-invalid']);
    for (const extra of [{ isComposing: true }, { keyCode: 229 }, { shiftKey: true }, { ctrlKey: true }]) {
        fields.CPF_Gestor.emit('keydown', { key: 'Enter', ...extra, preventDefault() { assert.fail('Must remain native'); } });
    }
});

test('CPF warnings use the system dialog once per value and return focus to the CPF panel after closing', () => {
    const { fields, form, warnings } = setup('52998224724');
    fields.CPF_Gestor.emit('blur');
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0].text, 'Informe um CPF válido.');
    form.listeners.submit({ preventDefault() {} });
    assert.equal(warnings.length, 1);
    warnings[0].onClose();
    assert.equal(form.events.at(-1), 'manager-cpf-invalid');
    fields.CPF_Gestor.value = '';
    fields.CPF_Gestor.emit('input');
    fields.CPF_Gestor.emit('blur');
    assert.equal(warnings.length, 2);
    assert.equal(warnings[1].text, 'Informe o CPF do gestor.');
});

test('native required and email validation use one system dialog and reveal the invalid field after closing', async () => {
    const { form, warnings } = setup();
    const email = { type: 'email', validity: { valueMissing: false } };
    let prevented = 0;
    form.listeners.invalid({ target: email, preventDefault() { prevented++; } });
    form.listeners.invalid({ target: email, preventDefault() { prevented++; } });
    await Promise.resolve();
    assert.equal(prevented, 2);
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0].text, 'Informe um email válido para o gestor.');
    warnings[0].onClose();
    assert.equal(form.events.at(-1), 'manager-field-invalid');
    form.listeners.invalid({ target: { validity: { valueMissing: true } }, preventDefault() {} });
    await Promise.resolve();
    assert.equal(warnings[1].text, 'Confira os campos obrigatórios para concluir o cadastro.');
});

test('server validation errors and successful save feedback are displayed through the system dialog', () => {
    const errors = setup('52998224725', ['Já existe um gestor com este CPF.', 'Confira o Fundo.']);
    assert.deepEqual(errors.warnings.map((warning) => warning.text), ['Já existe um gestor com este CPF.\nConfira o Fundo.']);
    const success = setup('52998224725', ['Gestor atualizado.']);
    assert.deepEqual(success.warnings.map((warning) => warning.text), ['Gestor atualizado.']);
});
