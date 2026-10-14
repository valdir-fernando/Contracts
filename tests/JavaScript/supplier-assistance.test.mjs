import test from 'node:test';
import assert from 'node:assert/strict';
import { formatSupplierDocument, initializeSupplierAssistance } from '../../resources/js/supplier-assistance.js';

globalThis.document = { createElement: () => ({ textContent: '', setAttribute() {} }) };
const names = ['fornecedor_documento_snapshot', 'fornecedor_nome_snapshot', 'rg_fornec', 'doc_prof', 'pis_pasep', 'end_fornec', 'socio', 'cpf_socio', 'rg_socio', '_token', 'supplier_id'];
function fixture(values = {}, prefill = {}) {
    const statuses = [];
    const controls = Object.fromEntries(names.map((name) => [name, {
        name, value: values[name] ?? '', readOnly: !['fornecedor_documento_snapshot', '_token', 'supplier_id'].includes(name), listeners: {},
        addEventListener(event, handler) { this.listeners[event] = handler; },
        closest() { return { append: (status) => statuses.push(status) }; },
    }]));
    const summary = names.filter((name) => name !== '_token').map((name) => ({ dataset: { contractSummaryField: name }, textContent: '' }));
    const form = { elements: { namedItem: (name) => controls[name] },
        closest: () => ({ querySelectorAll: () => summary }),
        dataset: { supplierLookupUrl: '/suppliers/lookup', supplierPrefill: JSON.stringify(prefill) },
        listeners: {}, addEventListener(event, handler) { this.listeners[event] = handler; } };
    return { form, controls, statuses, summary, blur: () => controls.fornecedor_documento_snapshot.listeners.focusout(),
        input(value) { controls.fornecedor_documento_snapshot.value = value; controls.fornecedor_documento_snapshot.listeners.input(); } };
}
const response = (values, status = 200) => ({ ok: status === 200, status, json: async () => ({ values }) });
const defer = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };

test('CPF and CNPJ are masked without altering invalid or multiple documents', () => {
    assert.equal(formatSupplierDocument('52998224725'), '529.982.247-25');
    assert.equal(formatSupplierDocument('11222333000181'), '11.222.333/0001-81');
    assert.equal(formatSupplierDocument('11.222.333/0001-81'), '11.222.333/0001-81');
    assert.equal(formatSupplierDocument('123'), '123');
    assert.equal(formatSupplierDocument('CPF: 52998224725'), 'CPF: 52998224725');
});

test('leaving the document field masks it and fills supplier and partner data', async () => {
    const setup = fixture({ fornecedor_documento_snapshot: '11222333000181', _token: 'csrf-test' });
    const calls = [];
    initializeSupplierAssistance(setup.form, async (url, options) => {
        calls.push({ url, options });
        return response({ fornecedor_nome_snapshot: 'Empresa', rg_fornec: 'RG', doc_prof: 'Registro', pis_pasep: 'PIS',
            end_fornec: 'Rua', socio: 'Sócio', cpf_socio: '52998224725', rg_socio: 'RG sócio' });
    });
    await setup.blur();
    assert.equal(setup.controls.fornecedor_documento_snapshot.value, '11.222.333/0001-81');
    assert.equal(setup.controls.cpf_socio.value, '529.982.247-25');
    assert.equal(setup.controls.end_fornec.value, 'Rua');
    assert.equal(setup.controls.rg_socio.value, 'RG sócio');
    assert.equal(setup.summary.find((output) => output.dataset.contractSummaryField === 'fornecedor_nome_snapshot').textContent, 'Empresa');
    assert.equal(setup.summary.find((output) => output.dataset.contractSummaryField === 'fornecedor_documento_snapshot').textContent, '11.222.333/0001-81');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.deepEqual(JSON.parse(calls[0].options.body), { document: '11222333000181' });
});

test('catalog prefill is masked immediately and clears old automatic values when document changes', () => {
    const values = { fornecedor_documento_snapshot: '52998224725', fornecedor_nome_snapshot: 'Pessoa', cpf_socio: '11144477735', end_fornec: 'Rua' };
    const setup = fixture(values, values);
    initializeSupplierAssistance(setup.form, async () => response({}));
    assert.equal(setup.controls.fornecedor_documento_snapshot.value, '529.982.247-25');
    assert.equal(setup.controls.cpf_socio.value, '111.444.777-35');
    setup.controls.end_fornec.value = 'Endereço digitado';
    setup.input('11222333000181');
    assert.equal(setup.controls.fornecedor_nome_snapshot.value, '');
    assert.equal(setup.controls.cpf_socio.value, '');
    assert.equal(setup.controls.end_fornec.value, '');
    assert.equal(setup.summary.find((output) => output.dataset.contractSummaryField === 'fornecedor_nome_snapshot').textContent, 'Não informado');
});

test('a delayed old response cannot overwrite the newly selected supplier', async () => {
    const setup = fixture({ fornecedor_documento_snapshot: '11222333000181' });
    const first = defer();
    const second = defer();
    let calls = 0;
    initializeSupplierAssistance(setup.form, () => ++calls === 1 ? first.promise : second.promise);
    const initial = setup.blur();
    setup.input('19131243000197');
    const latest = setup.blur();
    second.resolve(response({ fornecedor_nome_snapshot: 'Fornecedor atual' }));
    await latest;
    first.resolve(response({ fornecedor_nome_snapshot: 'Fornecedor antigo' }));
    await initial;
    assert.equal(setup.controls.fornecedor_nome_snapshot.value, 'Fornecedor atual');
});

test('catalog values overwrite manipulated readonly fields and submission waits for lookup completion', async () => {
    const setup = fixture({ fornecedor_documento_snapshot: '11222333000181' });
    const request = defer();
    initializeSupplierAssistance(setup.form, () => request.promise);
    const pending = setup.blur();
    let prevented = false;
    setup.form.listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    setup.controls.end_fornec.value = 'Endereço editado';
    request.resolve(response({ fornecedor_nome_snapshot: 'Empresa', end_fornec: 'Endereço cadastrado' }));
    await pending;
    assert.equal(setup.controls.end_fornec.value, 'Endereço cadastrado');
    prevented = false;
    setup.form.listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
});

test('missing and ambiguous matches never fill guessed data and incomplete input makes no request', async () => {
    for (const status of [404, 409, 422]) {
        const setup = fixture({ fornecedor_documento_snapshot: '11222333000181' });
        initializeSupplierAssistance(setup.form, async () => response({}, status));
        await setup.blur();
        assert.equal(setup.controls.fornecedor_nome_snapshot.value, '');
        assert.ok(setup.statuses[0].textContent.length > 0);
    }
    const setup = fixture({ fornecedor_documento_snapshot: '123' });
    let called = false;
    initializeSupplierAssistance(setup.form, async () => { called = true; return response({}); });
    await setup.blur();
    assert.equal(called, false);
});

test('changing document clears the selected catalog ID and every readonly value', () => {
    const values = Object.fromEntries(names.map((name) => [name, name === 'fornecedor_documento_snapshot' ? '11222333000181' : 'old-value']));
    const setup = fixture(values, values);
    initializeSupplierAssistance(setup.form, async () => response({}));
    setup.input('19131243000197');
    assert.equal(setup.controls.supplier_id.value, '');
    for (const field of Object.values(setup.controls).filter((field) => field.readOnly)) assert.equal(field.value, '');
});

test('an unchanged existing document preserves the stored snapshot without a lookup', async () => {
    const setup = fixture({ fornecedor_documento_snapshot: '11222333000181', fornecedor_nome_snapshot: 'Snapshot histórico' });
    setup.form.dataset.supplierOriginalDocument = '11222333000181';
    let called = false;
    initializeSupplierAssistance(setup.form, async () => { called = true; return response({ fornecedor_nome_snapshot: 'Nome atual' }); });
    await setup.blur();
    assert.equal(called, false);
    assert.equal(setup.controls.fornecedor_nome_snapshot.value, 'Snapshot histórico');
});
