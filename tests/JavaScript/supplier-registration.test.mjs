import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeSupplierRegistration } from '../../resources/js/supplier-registration.js';

function fixture() {
    const controls = Object.fromEntries(['name', 'document', '_token', 'Email_Contato', 'Data_Nasc'].map((name) => [name, {
        name, value: '', dataset: name === 'Data_Nasc' ? { brazilianDate: 'true' } : {}, listeners: {},
        addEventListener(event, callback) { this.listeners[event] = callback; },
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
