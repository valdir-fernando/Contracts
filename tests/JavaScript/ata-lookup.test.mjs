import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaLookup } from '../../resources/js/ata-lookup.js';

function node(value = '') {
    return { value, textContent: '', dataset: {}, listeners: {}, children: [], options: [],
        addEventListener(name, callback) { this.listeners[name] = callback; },
        dispatchEvent() {}, append(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
    };
}

function panel(fetcher) {
    const names = ['fornecedor_documento_snapshot', 'fornecedor_nome_snapshot', 'supplier_id', 'supplier_email', 'supplier_mobile', 'supplier_whatsapp',
        'cpf_socio', 'rg_socio', 'Data_ATA', 'bidding', 'bidding_number', 'managing_fund_id'];
    const controls = Object.fromEntries(names.map(name => [name, node()]));
    controls.Data_ATA.type = 'text';
    const total = node();
    const headingTotal = node();
    const products = node();
    const contractor = node();
    contractor.dataset.ataContractorField = 'cpf_gestor';
    const form = { dataset: {}, elements: { namedItem: name => controls[name] },
        closest: () => null, querySelectorAll: () => Object.values(controls).slice(0, 8) };
    const events = [];
    const root = { dataset: { ataLookupUrl: '/atas/lookup' },
        ownerDocument: { baseURI: 'http://contracts.test/', createElement: () => node() },
        querySelector(selector) {
            if (selector.startsWith('[name=')) return controls[selector.slice(7, -2)];
            return { '[data-ata-supplier-form]': form, '#ata-total': total, '[data-contract-product-list]': products }[selector];
        },
        querySelectorAll(selector) { return selector === '[data-ata-contractor-field]' ? [contractor] : [headingTotal]; },
        dispatchEvent(event) { events.push(event); },
    };
    const number = node();
    const opener = node();
    const status = node();
    initializeAtaLookup(root, number, opener, status, fetcher);
    return { root, controls, number, opener, status, products, total, headingTotal, contractor, form, events };
}

const saved = (id, number) => ({ id, number, process: `PROCESSO-${id}`, date: '2026-01-02', bidding: 'PREGÃO', bidding_number: '123',
    managing_fund_id: '1', managing_fund_name: 'Fundo salvo', version: 1,
    contractor: { cpf_gestor: '52998224725' }, supplier: { fornecedor_nome_snapshot: `Fornecedor ${id}`, fornecedor_documento_snapshot: '11222333000181' },
    participants: [{ id: 20, FUNDO: 'Participante salvo' }], total: '1234.5678',
    products: [{ item: '1', description: '<Produto salvo>', unit: 'UN', quantity: '0.0001', unit_price: '1.1234', total: '1234.5678' }],
});

test('blur loads persisted fields, participants and products with exact monetary formatting', async () => {
    let request;
    const ui = panel(async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => saved(1, '001/2026') };
    });
    ui.number.value = '1/26';
    await ui.number.listeners.blur();
    assert.equal(request.url.searchParams.get('number'), '1/26');
    assert.equal(request.options.cache, 'no-store');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(ui.number.value, '001/2026');
    assert.equal(ui.controls.Data_ATA.value, '02/01/2026');
    assert.equal(ui.controls.bidding.value, 'PREGÃO');
    assert.equal(ui.controls.fornecedor_nome_snapshot.value, 'Fornecedor 1');
    assert.equal(ui.controls.fornecedor_documento_snapshot.value, '11.222.333/0001-81');
    assert.equal(ui.contractor.textContent, '529.982.247-25');
    assert.equal(ui.total.value, '1.234,5678');
    assert.equal(ui.headingTotal.value, '1.234,5678');
    assert.equal(ui.products.children[0].children[2].textContent, '<Produto salvo>');
    assert.equal(ui.products.children[0].children[4].textContent, '0,0001');
    assert.equal(ui.form.dataset.ataSnapshot, 'true');
    assert.equal(ui.events.at(-1).type, 'ata-loaded');
    assert.equal(ui.events.at(-1).detail.participants[0].FUNDO, 'Participante salvo');
});

test('editing the number aborts pending requests and late responses cannot replace another ata', async () => {
    const pending = [];
    const ui = panel((url, options) => new Promise(resolve => pending.push({ url, options, resolve })));
    ui.number.value = '1/26';
    const first = ui.number.listeners.blur();
    ui.number.value = '2/26';
    ui.number.listeners.input();
    const second = ui.number.listeners.blur();
    pending[1].resolve({ ok: true, json: async () => saved(2, '002/2026') });
    await second;
    pending[0].resolve({ ok: true, json: async () => saved(1, '001/2026') });
    await first;
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(ui.number.value, '002/2026');
    assert.equal(ui.controls.fornecedor_nome_snapshot.value, 'Fornecedor 2');
    ui.number.value = '';
    ui.number.listeners.input();
    assert.equal(ui.controls.fornecedor_nome_snapshot.value, '');
    assert.equal(ui.products.children.length, 0);
    assert.equal(ui.total.value, '0,0000');
    assert.equal(ui.form.dataset.ataSnapshot, '');
});

test('not found, ambiguity and network errors clear stale data and do not invent a loaded ata', async () => {
    for (const message of ['Nenhuma ata disponível.', 'Mais de uma ata disponível.']) {
        const ui = panel(async () => ({ ok: false, json: async () => ({ message }) }));
        ui.number.value = '1/26';
        ui.controls.fornecedor_nome_snapshot.value = 'Dado antigo';
        await ui.opener.listeners.click();
        assert.equal(ui.controls.fornecedor_nome_snapshot.value, '');
        assert.equal(ui.status.textContent, message);
        assert.equal(ui.events.some(event => event.type === 'ata-loaded'), false);
    }
    const ui = panel(async () => { throw new TypeError('Network'); });
    ui.number.value = '1/26';
    await ui.number.listeners.blur();
    assert.match(ui.status.textContent, /Não foi possível consultar/);
    assert.equal(ui.events.at(-1).type, 'ata-cleared');
});
