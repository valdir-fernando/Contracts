import test from 'node:test';
import assert from 'node:assert/strict';
import { applyCompanyValues, consultCompany } from '../../resources/js/cnpj-lookup.js';

function fixture() {
    const fields = Object.fromEntries(['name', 'Endereco', 'Fone_Fixo', '_token'].map(name => [name,
        { name, value: name === '_token' ? 'csrf-test' : '', dispatchEvent() {} }]));
    const status = {};
    const form = { dataset: { cnpjLookupUrl: '/cnpj/lookup' }, elements: { namedItem: name => fields[name] }, querySelector: () => status };
    return { form, fields, status };
}
const data = { status: 'found', supplier: { name: 'EMPRESA', Endereco: 'Rua exemplo', Fone_Fixo: '6233334444' } };
const options = { cnpj: '11222333000181', kind: 'supplier', existing: false, isCurrent: () => true };

test('new company fills only empty editable fields, formats phones and reports changed fields', () => {
    const { form, fields } = fixture();
    fields.name.value = 'Digitado pelo usuário';
    fields.Endereco.readOnly = true;
    const changed = applyCompanyValues(form, data.supplier);
    assert.equal(fields.name.value, 'Digitado pelo usuário');
    assert.equal(fields.Endereco.value, '');
    assert.equal(fields.Fone_Fixo.value, '(62) 3333-4444');
    assert.equal(changed.length, 1);
    assert.deepEqual(changed[0][1], { before: '', automatic: '(62) 3333-4444' });
    fields.Fone_Fixo.disabled = true;
    applyCompanyValues(form, { name: 'Atualizado', Fone_Fixo: '6233335555' }, false);
    assert.equal(fields.name.value, 'Atualizado');
    assert.equal(fields.Fone_Fixo.value, '(62) 3333-4444');
});

test('external consultation sends private POST with CSRF and fills only after a current successful response', async () => {
    const { form, fields, status } = fixture();
    const messages = [];
    await consultCompany(form, options, async (url, request) => {
        assert.equal(url, '/cnpj/lookup');
        assert.equal(request.method, 'POST');
        assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf-test');
        assert.deepEqual(JSON.parse(request.body), { cnpj: options.cnpj });
        return { ok: true, json: async () => data };
    }, text => messages.push(text));
    assert.equal(fields.name.value, 'EMPRESA');
    assert.equal(status.textContent, '');
    assert.deepEqual(messages, ['Dados públicos preenchidos. Confira antes de salvar.']);
});

test('late responses and unavailable providers never replace current form values', async () => {
    const { form, fields, status } = fixture();
    let resolve;
    let current = true;
    const pending = consultCompany(form, { ...options, isCurrent: () => current }, () => new Promise(done => { resolve = done; }));
    current = false;
    fields.name.value = 'Novo CNPJ';
    resolve({ ok: true, json: async () => data });
    await pending;
    assert.equal(fields.name.value, 'Novo CNPJ');
    await consultCompany(form, options, async () => ({ ok: false }));
    assert.match(status.textContent, /indisponível/);
    assert.equal(fields.name.value, 'Novo CNPJ');
    await consultCompany(form, options, async () => ({ ok: true, json: async () => ({ status: 'not_found' }) }));
    assert.match(status.textContent, /preencher os dados manualmente/);
});
