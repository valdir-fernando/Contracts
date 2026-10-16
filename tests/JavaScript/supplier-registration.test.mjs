import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeSupplierRegistration, validSupplierDocument, showSupplierDocumentWarning, navigateSupplierFields } from '../../resources/js/supplier-registration.js';

test('Tab follows supplier fields in row order and Shift+Tab reverses, skipping unavailable fields', () => {
    let focused;
    const field = (name, visible = true, disabled = false) => ({
        name, disabled, getClientRects: () => visible ? [{}] : [], focus() { focused = name; },
    });
    const fields = [field('name'), field('document'), field('hidden', false), field('disabled', true, true), field('Nome_Fantasia'), field('RG'), field('Data_Nasc'), field('Sexo')];
    const form = { querySelectorAll: () => fields };
    let prevented = false;
    const move = (target, shiftKey = false, key = 'Tab') => {
        prevented = false;
        navigateSupplierFields({ target, key, shiftKey, preventDefault() { prevented = true; } }, form);
    };
    move(fields[1]);
    assert.equal(focused, 'Nome_Fantasia');
    assert.equal(prevented, true);
    move(fields[6]);
    assert.equal(focused, 'Sexo');
    move(fields[7], true);
    assert.equal(focused, 'Data_Nasc');
    move(fields[0], true);
    assert.equal(prevented, false);
    move(fields[7]);
    assert.equal(prevented, false);
    move(fields[1], false, 'Enter');
    assert.equal(prevented, false);
});

test('document warning uses the requested title and accessible modal, reusing it with safe text', () => {
    const message = { textContent: '' };
    const attributes = {};
    let created = 0;
    let current;
    const dialog = {
        open: false,
        setAttribute(name, value) { attributes[name] = value; },
        querySelector: () => message,
        showModal() { this.open = true; },
    };
    const doc = {
        querySelector: () => current,
        createElement(tag) { assert.equal(tag, 'dialog'); created++; return dialog; },
        body: { append(element) { current = element; } },
    };
    showSupplierDocumentWarning('CPF inválido', doc);
    assert.match(dialog.innerHTML, /Gestão Sistem informa:/);
    assert.equal(attributes.role, 'alertdialog');
    assert.equal(attributes['aria-labelledby'], 'supplier-document-warning-title');
    assert.equal(attributes['aria-describedby'], 'supplier-document-warning-message');
    assert.equal(dialog.open, true);
    assert.equal(message.textContent, 'CPF inválido');
    dialog.open = false;
    showSupplierDocumentWarning('<b>outro documento</b>', doc);
    assert.equal(message.textContent, '<b>outro documento</b>');
    assert.equal(created, 1);
    assert.equal(dialog.open, true);
});

function fixture() {
    const controls = Object.fromEntries(['name', 'document', '_token', 'Email_Contato', 'Data_Nasc'].map((name) => [name, {
        name, value: '', dataset: name === 'Data_Nasc' ? { brazilianDate: 'true' } : {}, listeners: {},
        addEventListener(event, callback) { this.listeners[event] = callback; },
        setCustomValidity(message) { this.validationMessage = message; },
    }]));
    const save = { disabled: false };
    const status = { textContent: '' };
    const form = { dataset: { registrationLookupUrl: '/suppliers/registration-lookup' }, listeners: {},
        elements: { namedItem: (name) => controls[name] },
        querySelector: (selector) => selector.startsWith('button') ? save : status,
        querySelectorAll: (selector) => selector.startsWith('.supplier-registration-group') ? [controls.Email_Contato, controls.Data_Nasc] : [],
        addEventListener(event, callback) { this.listeners[event] = callback; } };
    return { controls, save, status, form,
        input(value) { controls.document.value = value; controls.document.listeners.input(); },
        blur: () => controls.document.listeners.focusout(),
        submit() { let blocked = false; form.listeners.submit?.({ preventDefault() { blocked = true; } }); return blocked; } };
}
const response = (found, values = null, status = 200) => ({ ok: status === 200, status, json: async () => ({ found, values }) });
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };

test('validates both check digits, repeated digits, length and unexpected characters', () => {
    for (const value of ['52998224725', '529.982.247-25', '11222333000181', '11.222.333/0001-81']) {
        assert.equal(validSupplierDocument(value), true, value);
    }
    for (const value of ['', '123', '52998224715', '52998224724', '11222333000171', '11222333000180', '11111111111', '00000000000000', 'a52998224725']) {
        assert.equal(validSupplierDocument(value), false, value);
    }
});

test('blur immediately warns about invalid CPF/CNPJ without querying; editing clears the warning', async () => {
    const setup = fixture();
    let calls = 0;
    const messages = [];
    initializeSupplierRegistration(setup.form, async () => { calls++; return response(false); }, (message) => messages.push(message));
    for (const value of ['63374102116', '52998224724', '11222333000180', '11111111111', '123']) {
        setup.input(value);
        assert.equal(setup.status.textContent, '');
        await setup.blur();
        assert.equal(setup.status.textContent, '');
        assert.match(messages.at(-1), /inválido\. Verifique antes de efetuar o registro\./);
        assert.equal(setup.controls.document.validationMessage, messages.at(-1));
    }
    assert.equal(messages[0], 'CPF 633.741.021-16 é um CPF inválido. Verifique antes de efetuar o registro.');
    assert.equal(messages[2], 'CNPJ 11.222.333/0001-80 é um CNPJ inválido. Verifique antes de efetuar o registro.');
    assert.equal(messages.length, 5);
    setup.input('');
    await setup.blur();
    assert.equal(messages.length, 5);
    assert.equal(calls, 0);
    setup.input('52998224725');
    assert.equal(setup.status.textContent, '');
    assert.equal(setup.controls.document.validationMessage, '');
    await setup.blur();
    assert.equal(calls, 1);
    assert.equal(messages.length, 5);
    assert.match(setup.status.textContent, /Fornecedor não cadastrado/);
});

test('Novo clears restored values and cancels a pending lookup without clearing the CSRF token', async () => {
    const setup = fixture();
    setup.controls.name.value = 'Nome anterior';
    setup.controls.Email_Contato.value = 'anterior@example.com';
    setup.controls._token.value = 'csrf-token';
    const request = deferred();
    initializeSupplierRegistration(setup.form, () => request.promise);
    setup.input('11222333000181');
    const pending = setup.blur();
    let cancelledNativeReset = false;
    setup.form.listeners.reset({ preventDefault() { cancelledNativeReset = true; } });
    request.resolve(response(true, { name: 'Resposta obsoleta' }));
    await pending;
    assert.equal(cancelledNativeReset, true);
    assert.equal(setup.controls.name.value, '');
    assert.equal(setup.controls.document.value, '');
    assert.equal(setup.controls.Email_Contato.value, '');
    assert.equal(setup.controls._token.value, 'csrf-token');
    assert.equal(setup.status.textContent, '');
    assert.equal(setup.save.disabled, false);
});

test('duplicate supplier fills active fields without locking save; changing document restores manual values', async () => {
    const setup = fixture();
    setup.controls.name.value = 'Nome digitado';
    const replies = [response(true, { name: 'Cadastrado', Email_Contato: 'contato@example.com', Data_Nasc: '1980-05-20', _token: 'never apply' }), response(false)];
    initializeSupplierRegistration(setup.form, async () => replies.shift());
    setup.input('11222333000181');
    await setup.blur();
    assert.equal(setup.controls.document.value, '11.222.333/0001-81');
    assert.equal(setup.controls.name.value, 'Cadastrado');
    assert.equal(setup.controls.Email_Contato.value, 'contato@example.com');
    assert.equal(setup.controls.Data_Nasc.value, '20/05/1980');
    assert.equal(setup.controls._token.value, '');
    assert.equal(setup.save.disabled, false);
    assert.equal(setup.submit(), false);
    setup.input('52998224725');
    assert.equal(setup.controls.name.value, 'Nome digitado');
    assert.equal(setup.controls.Email_Contato.value, '');
    assert.equal(setup.submit(), false);
    await setup.blur();
    assert.equal(setup.save.disabled, false);
    assert.equal(setup.submit(), false);
});

test('save remains available during lookup and late responses cannot overwrite a new document', async () => {
    const setup = fixture();
    const old = deferred();
    let calls = 0;
    initializeSupplierRegistration(setup.form, () => ++calls === 1 ? old.promise : Promise.resolve(response(false)));
    setup.input('11222333000181');
    const pending = setup.blur();
    assert.equal(setup.save.disabled, false);
    assert.equal(setup.submit(), false);
    setup.input('52998224725');
    await setup.blur();
    old.resolve(response(true, { name: 'Obsoleto' }));
    await pending;
    assert.equal(setup.controls.name.value, '');
    assert.equal(setup.save.disabled, false);
});

test('invalid, ambiguous and failed requests show feedback without locking save', async () => {
    for (const code of [409, 422, 403, 500]) {
        const setup = fixture();
        setup.controls.document.value = '11222333000181';
        initializeSupplierRegistration(setup.form, async () => response(false, null, code));
        await setup.blur();
        assert.equal(setup.save.disabled, false);
        assert.equal(setup.submit(), false);
        assert.notEqual(setup.status.textContent, '');
    }
    const setup = fixture();
    let called = false;
    initializeSupplierRegistration(setup.form, async () => { called = true; throw new Error('offline'); });
    setup.input('123');
    await setup.blur();
    assert.equal(called, false);
    setup.input('11222333000181');
    await setup.blur();
    assert.equal(setup.save.disabled, false);
    assert.match(setup.status.textContent, /Não foi possível/);
});
