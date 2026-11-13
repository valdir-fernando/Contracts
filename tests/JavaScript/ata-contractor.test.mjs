import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaContractor, formatAtaContractorField } from '../../resources/js/ata-contractor.js';

test('manager CPF displays its mask while missing and protected values remain unchanged', () => {
    assert.equal(formatAtaContractorField('cpf_gestor', '52998224725'), '529.982.247-25');
    assert.equal(formatAtaContractorField('cpf_gestor', '529.982.247-25'), '529.982.247-25');
    assert.equal(formatAtaContractorField('cpf_gestor', null), '');
    assert.equal(formatAtaContractorField('cpf_gestor', undefined), '');
    assert.equal(formatAtaContractorField('rg_gestor', '12345678901'), '12345678901');
});

test('fund changes clear old data and discard responses from previous selections', async () => {
    const field = { dataset: { ataContractorField: 'nome_gestor' }, textContent: '' };
    const status = { textContent: '' };
    const panel = { dataset: { ataContractorUrl: '/atas/funds/0/contractor-defaults' },
        querySelectorAll: () => [field], querySelector: () => status };
    let change;
    const select = { value: '', addEventListener: (_, fn) => { change = fn; } };
    const rootEvents = {};
    const root = { dataset: {}, addEventListener: (name, callback) => { rootEvents[name] = callback; },
        querySelector: selector => selector === '#ata-managing-fund' ? select : panel };
    const pending = [];
    await initializeAtaContractor(root, (url, options) => new Promise(resolve => pending.push({ url, options, resolve })));
    select.value = '1';
    const first = change();
    select.value = '2';
    const second = change();
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(pending[1].url, '/atas/funds/2/contractor-defaults');
    pending[1].resolve({ ok: true, json: async () => ({ values: { nome_gestor: 'Gestora atual' } }) });
    await second;
    pending[0].resolve({ ok: true, json: async () => ({ values: { nome_gestor: 'Gestor anterior' } }) });
    await first;
    assert.equal(field.textContent, 'Gestora atual');
    select.value = '';
    await change();
    assert.equal(field.textContent, '');
    assert.equal(status.textContent, '');
    select.value = '3';
    const third = change();
    rootEvents['ata-loading']();
    field.textContent = 'Snapshot salvo';
    pending[2].resolve({ ok: true, json: async () => ({ values: { nome_gestor: 'Catálogo atual' } }) });
    await third;
    assert.equal(pending[2].options.signal.aborted, true);
    assert.equal(field.textContent, 'Snapshot salvo');
});
