import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaSupplierContacts, formatAtaPhone } from '../../resources/js/ata-supplier-contacts.js';

test('mobile and WhatsApp numbers use the Brazilian display format', () => {
    assert.equal(formatAtaPhone('62999960370'), '(62) 9 9996-0370');
    assert.equal(formatAtaPhone('+55 (62) 99996-0370'), '(62) 9 9996-0370');
    assert.equal(formatAtaPhone('(62) 9 9996-0370'), '(62) 9 9996-0370');
    assert.equal(formatAtaPhone('6233334444'), '(62) 3333-4444');
    assert.equal(formatAtaPhone(null), '');
    assert.equal(formatAtaPhone(''), '');
    assert.equal(formatAtaPhone('123'), '123');
    assert.equal(formatAtaPhone('62999960370 / 62988880000'), '62999960370 / 62988880000');
});

test('contacts load after catalog selection and old responses cannot replace current data', async () => {
    const listeners = {};
    const controls = Object.fromEntries(['fornecedor_documento_snapshot', 'supplier_email', 'supplier_mobile', 'supplier_whatsapp', '_token']
        .map(name => [name, { value: '', addEventListener: (event, fn) => { listeners[event] = fn; } }]));
    controls._token.value = 'csrf';
    const events = {};
    const form = { elements: { namedItem: name => controls[name] }, dataset: { supplierContactsUrl: '/suppliers/lookup?ata_contacts=1' },
        addEventListener: (event, fn) => { events[event] = fn; } };
    const pending = [];
    initializeAtaSupplierContacts(form, (url, options) => new Promise(resolve => pending.push({ url, options, resolve })));
    controls.fornecedor_documento_snapshot.value = '11.222.333/0001-81';
    const first = events['supplier-selected']();
    listeners.input();
    controls.fornecedor_documento_snapshot.value = '19.131.243/0001-97';
    const second = listeners.focusout();
    pending[1].resolve({ ok: true, json: async () => ({ values: { supplier_email: 'atual@example.test', supplier_mobile: '62999990000' } }) });
    await second;
    pending[0].resolve({ ok: true, json: async () => ({ values: { supplier_email: 'antigo@example.test' } }) });
    await first;
    assert.equal(controls.supplier_email.value, 'atual@example.test');
    assert.equal(controls.supplier_mobile.value, '(62) 9 9999-0000');
    assert.equal(controls.supplier_whatsapp.value, '');
    assert.equal(pending[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    listeners.input();
    assert.equal(controls.supplier_email.value, '');
    assert.equal(controls.supplier_mobile.value, '');
    form.dataset.ataSnapshot = 'true';
    controls.supplier_email.value = 'historico@example.test';
    await listeners.focusout();
    assert.equal(pending.length, 2);
    assert.equal(controls.supplier_email.value, 'historico@example.test');
});
