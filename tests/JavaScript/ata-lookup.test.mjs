import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaLookup } from '../../resources/js/ata-lookup.js';

function node(value = '') {
    return { value, textContent: '', dataset: {}, listeners: {}, children: [], options: [],
        setAttribute(name, value) { this[name] = value; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
        dispatchEvent() {}, append(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
    };
}

function panel(fetcher) {
    let warning;
    const navigations = [];
    const warningText = node();
    const names = ['fornecedor_documento_snapshot', 'fornecedor_nome_snapshot', 'supplier_id', 'supplier_email', 'supplier_mobile', 'supplier_whatsapp',
        'cpf_socio', 'rg_socio', 'Data_ATA', 'Data_Orçamento', 'admitir_adesao', 'bidding', 'bidding_number', 'managing_fund_id'];
    const controls = Object.fromEntries(names.map(name => [name, node()]));
    controls.Data_ATA.type = 'text';
    controls.Data_Orçamento.type = 'text';
    const total = node();
    const headingTotal = node();
    const lineTotal = node();
    const fundHeading = node();
    fundHeading.textContent = 'FUNDO GERENCIADOR';
    const products = node();
    const productsTitle = node();
    const productsManagingFund = node();
    const contractor = node();
    contractor.dataset.ataContractorField = 'cpf_gestor';
    const form = { dataset: {}, elements: { namedItem: name => controls[name] },
        closest: () => null, querySelectorAll: () => Object.values(controls).slice(0, 8) };
    const events = [];
    const root = { dataset: { ataLookupUrl: '/atas/lookup' },
        listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; },
        ownerDocument: { baseURI: 'http://contracts.test/',
            defaultView: { location: { assign: url => navigations.push(url) } },
            body: { append(dialog) { warning = dialog; } },
            createElement(tag) {
                if (tag !== 'dialog') return node();
                return { ...node(), attributes: {}, setAttribute(name, value) { this.attributes[name] = value; },
                    querySelector: () => warningText, showModal() { this.open = true; } };
            },
            querySelector: selector => selector === '[data-ata-managing-fund-heading]' ? fundHeading
                : selector === '[data-supplier-document-warning]' ? warning : null },
        querySelector(selector) {
            if (selector === '[data-ata-new]') return { href: 'http://contracts.test/atas/create' };
            if (selector.startsWith('[name=')) return controls[selector.slice(7, -2)];
            return { '[data-ata-supplier-form]': form, '#ata-total': total, '[data-contract-product-list]': products,
                '[data-product-line-total]': lineTotal,
                '[data-ata-products-number]': productsTitle, '[data-ata-products-managing-fund]': productsManagingFund }[selector];
        },
        querySelectorAll(selector) { return selector === '[data-ata-contractor-field]' ? [contractor] : [headingTotal]; },
        dispatchEvent(event) { events.push(event); },
    };
    const number = node();
    const opener = node();
    const status = node();
    initializeAtaLookup(root, number, opener, status, fetcher);
    return { root, controls, number, opener, status, products, productsTitle, productsManagingFund, total, headingTotal, fundHeading, contractor, form, events,
        navigations, warningText, get warning() { return warning; } };
}

const saved = (id, number) => ({ id, number, process: `PROCESSO-${id}`, date: '2026-01-02', admitir_adesao: 'SIM', bidding: 'PREGÃO', bidding_number: '123',
    managing_fund_id: '1', managing_fund_name: 'Fundo salvo', version: 1, budget_date: '2025-12-20',
    contractor: { cpf_gestor: '52998224725' }, supplier: { fornecedor_nome_snapshot: `Fornecedor ${id}`, fornecedor_documento_snapshot: '11222333000181' },
    participants: [{ id: 20, FUNDO: 'Participante salvo' }], total: '1234.5678',
    products: [{ item: '1', description: '<Produto salvo>', unit: 'UN', quantity: '0.0001', unit_price: '1.1234', total: '1234.5678' }],
});

test('successful deletion opens the same fresh page as New without restoring the previous draft', async () => {
    const ui = panel(async () => ({ ok: true, json: async () => saved(7, '107/2025') }));
    ui.number.value = '107/25';
    await ui.number.listeners.blur();
    assert.equal(ui.root.dataset.ataLoaded, '7');
    ui.root.listeners['ata-deleted']();
    assert.deepEqual(ui.navigations, ['http://contracts.test/atas/create']);
    assert.equal(ui.events.at(-1).type, 'ata-loaded');
});

test('selection checkboxes fill the first item selected in click order and promote the next when unchecked', async () => {
    const data = saved(81, '107/2025');
    data.products = [1, 2, 3].map(id => ({ ...data.products[0], id, item: String(id) }));
    const ui = panel(async () => ({ ok: true, json: async () => data }));
    ui.number.value = '107/25';
    await ui.number.listeners.blur();
    const boxes = ui.products.children.map(row => row.children[9].children[0]);
    const latest = () => ui.events.filter(event => event.type === 'ata-product-selected').at(-1)?.detail.product.item;
    assert.equal(boxes[1].type, 'checkbox');
    assert.equal(boxes[1].value, '2');
    boxes[1].checked = true;
    boxes[1].listeners.change();
    assert.equal(latest(), '2');
    boxes[0].checked = true;
    boxes[0].listeners.change();
    assert.equal(latest(), '2');
    ui.products.children[2].listeners.click();
    assert.equal(latest(), '2');
    boxes[1].checked = false;
    boxes[1].listeners.change();
    assert.equal(latest(), '1');
    ui.root.dataset.editorBusy = 'true';
    boxes[2].checked = true;
    boxes[2].listeners.change();
    assert.equal(boxes[2].checked, false);
});

test('configured ATA lists exact special quantities alongside standard quantities', async () => {
    const data = saved(81, '107/2025');
    data.special_quantity_name = 'veículos';
    data.products[0].special_quantity = '1234.5678';
    const ui = panel(async () => ({ ok: true, json: async () => data }));
    ui.number.value = '107/25';
    await ui.number.listeners.blur();
    const row = ui.products.children[0];
    assert.equal(row.children[5].hidden, false);
    assert.equal(row.children[5].children[0].textContent, '1.234,5678');
    assert.equal(row.children[4].children[0].textContent, '0,0001');
});

test('the amendment list only shows the special quantity column when an original product has a value', async () => {
    const data = saved(81, '107/2025');
    data.special_quantity_name = 'veículos';
    data.products[0].special_quantity = '9.0000';
    data.amendment_base_products = [{ ...data.products[0], special_quantity: null }];
    const ui = panel(async () => ({ ok: true, json: async () => data }));
    ui.number.value = '107/25';
    await ui.number.listeners.blur();
    assert.equal(ui.events.at(-1).detail.amendment_special_quantity_label, '');
    assert.equal(ui.events.at(-1).detail.amendment_base_products[0].special_quantity, '');

    data.amendment_base_products.push({ ...data.products[0], id: 2, special_quantity: '0.0000' });
    await ui.number.listeners.blur();
    assert.equal(ui.events.at(-1).detail.amendment_special_quantity_label, 'QTD de veículos');
    assert.equal(ui.events.at(-1).detail.amendment_base_products[1].special_quantity, '0,0000');
});

test('the amendment panel receives the updated ATA total with exact decimal formatting', async () => {
    const data = saved(81, '107/2025');
    data.amendment_updated_total = '1234567890123.0001';
    const ui = panel(async () => ({ ok: true, json: async () => data }));
    ui.number.value = '107/25';
    await ui.number.listeners.blur();
    assert.equal(ui.events.at(-1).detail.amendment_updated_total, '1.234.567.890.123,0001');
});

test('the amendment panel receives product quantities and calculated totals with exact decimal formatting', async () => {
    const data = saved(81, '107/2025');
    data.amendment_base_total = '12345.6780';
    data.amendment_base_products = [
        { id: 1, quantity: '1234.5678', total: '12345.6780' },
        { id: 2, quantity: '0.0001' },
    ];
    const ui = panel(async () => ({ ok: true, json: async () => data }));
    ui.number.value = '107/25';
    await ui.number.listeners.blur();
    assert.deepEqual(ui.events.at(-1).detail.amendment_base_products.map(product => product.quantity), ['1.234,5678', '0,0001']);
    assert.deepEqual(ui.events.at(-1).detail.amendment_base_products.map(product => product.total), ['12.345,6780', '']);
    assert.equal(ui.events.at(-1).detail.amendment_base_total, '12.345,6780');
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
    assert.equal(ui.productsTitle.textContent, '001/2026');
    assert.equal(ui.fundHeading.textContent, 'Fundo salvo');
    assert.equal(ui.productsManagingFund.textContent, 'Fundo salvo');
    assert.equal(ui.controls.Data_ATA.value, '02/01/2026');
    assert.equal(ui.controls.Data_Orçamento.value, '20/12/2025');
    assert.equal(ui.controls.admitir_adesao.value, 'SIM');
    assert.equal(ui.controls.bidding.value, 'PREGÃO');
    assert.equal(ui.controls.fornecedor_nome_snapshot.value, 'Fornecedor 1');
    assert.equal(ui.controls.fornecedor_documento_snapshot.value, '11.222.333/0001-81');
    assert.equal(ui.contractor.textContent, '529.982.247-25');
    assert.equal(ui.total.value, '1.234,5678');
    assert.equal(ui.headingTotal.value, '1.234,5678');
    assert.equal(ui.products.children[0].children[2].children[0].textContent, '<Produto salvo>');
    assert.equal(ui.products.children[0].children[2].children[0].className, 'ata-product-cell-content');
    assert.equal(ui.products.children[0].children[4].children[0].textContent, '0,0001');
    assert.equal(ui.products.children[0].children.length, 10);
    assert.equal(ui.products.children[0].children[5].hidden, true);
    assert.equal(ui.form.dataset.ataSnapshot, 'true');
    assert.equal(ui.root.dataset.ataSupplierDocument, '11.222.333/0001-81');
    assert.equal(ui.events.at(-1).type, 'ata-loaded');
    assert.equal(ui.events.at(-1).detail.participants[0].FUNDO, 'Participante salvo');
    assert.equal(ui.status.textContent, '');
    assert.equal(ui.warning, undefined);
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
    assert.equal(ui.productsTitle.textContent, '002/2026');
    assert.equal(ui.controls.fornecedor_nome_snapshot.value, 'Fornecedor 2');
    ui.number.value = '';
    ui.number.listeners.input();
    assert.equal(ui.controls.fornecedor_nome_snapshot.value, '');
    assert.equal(ui.controls.admitir_adesao.value, '');
    assert.equal(ui.products.children.length, 0);
    assert.equal(ui.productsTitle.textContent, '');
    assert.equal(ui.total.value, '0,0000');
    assert.equal(ui.form.dataset.ataSnapshot, '');
    assert.equal(ui.fundHeading.textContent, 'FUNDO GERENCIADOR');
    assert.equal(ui.productsManagingFund.textContent, 'Não informado');
});

test('saved product rows select their original data by click or keyboard and ignore busy or old contexts', async () => {
    const data = saved(1, '001/2026');
    const ui = panel(async () => ({ ok: true, json: async () => data }));
    ui.number.value = '1/26';
    await ui.number.listeners.blur();
    const row = ui.products.children[0];
    assert.equal(row.tabIndex, -1);
    row.listeners.click();
    assert.equal(ui.events.at(-1).type, 'ata-product-selected');
    assert.deepEqual(ui.events.at(-1).detail, { ataId: '1', process: 'PROCESSO-1', product: data.products[0] });
    let prevented = false;
    row.listeners.keydown({ key: 'Enter', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    const count = ui.events.length;
    ui.root.dataset.editorBusy = 'true';
    row.listeners.click();
    assert.equal(ui.events.length, count);
    ui.root.dataset.editorBusy = 'false';
    ui.root.dataset.ataLoaded = '2';
    row.listeners.click();
    assert.equal(ui.events.length, count);
});

test('not found, ambiguity and network errors clear stale data and do not invent a loaded ata', async () => {
    for (const [status, expected] of [[404, /Nenhuma ata disponível/], [409, /mais de uma ata cadastrada/]]) {
        const ui = panel(async () => ({ ok: false, status, json: async () => ({ message: 'Mensagem interna' }) }));
        ui.number.value = '1/26';
        ui.controls.fornecedor_nome_snapshot.value = 'Dado antigo';
        await ui.opener.listeners.click();
        assert.equal(ui.controls.fornecedor_nome_snapshot.value, '');
        assert.equal(ui.status.textContent, '');
        assert.match(ui.warningText.textContent, expected);
        assert.equal(ui.warning.open, true);
        assert.equal(ui.warning.attributes.role, 'alertdialog');
        assert.match(ui.warning.innerHTML, /Gestão Sistem informa:/);
        assert.equal(ui.events.some(event => event.type === 'ata-loaded'), false);
    }
    const ui = panel(async () => { throw new TypeError('Network'); });
    ui.number.value = '1/26';
    await ui.number.listeners.blur();
    assert.equal(ui.status.textContent, '');
    assert.match(ui.warningText.textContent, /Confira sua conexão/);
    assert.equal(ui.events.at(-1).type, 'ata-cleared');
});

test('lookup errors use plain language in the standard dialog without exposing server details', async () => {
    for (const [status, expected] of [[401, /sessão terminou/], [403, /permissão/], [419, /sessão terminou/],
        [422, /107\/25/], [429, /muitas consultas/], [500, /problema ao consultar/], [503, /temporariamente indisponível/]]) {
        const ui = panel(async () => ({ ok: false, status, json: async () => ({
            message: "SQLSTATE[42S22]: Unknown column COD_PROD; select * from tb_PRODUTOS_ATA",
            errors: { number: ['Detalhe interno'] },
        }) }));
        ui.number.value = '107/25';
        await ui.number.listeners.blur();
        assert.match(ui.warningText.textContent, expected);
        assert.doesNotMatch(ui.warningText.textContent, /SQLSTATE|COD_PROD|tb_PRODUTOS_ATA|Detalhe interno/);
        assert.equal(ui.warning.open, true);
        assert.equal(ui.status.textContent, '');
        assert.equal(ui.root.dataset.ataLoaded, '');
    }
});

test('unreadable responses and login redirects explain the failure in the standard dialog', async () => {
    for (const [response, expected] of [
        [{ ok: true, json: async () => { throw new SyntaxError('Unexpected token <'); } }, /resposta que não pôde ser lida/],
        [{ ok: true, redirected: true }, /sessão terminou/],
        [{ ok: false, status: 500, json: async () => { throw new SyntaxError('HTML'); } }, /problema ao consultar/],
    ]) {
        const ui = panel(async () => response);
        ui.number.value = '107/25';
        await ui.number.listeners.blur();
        assert.match(ui.warningText.textContent, expected);
        assert.equal(ui.status.textContent, '');
    }
});

test('errors from superseded requests do not open a dialog or clear the current ata', async () => {
    let rejectFirst;
    const ui = panel(() => new Promise((resolve, reject) => { rejectFirst = reject; }));
    ui.number.value = '1/26';
    const pending = ui.number.listeners.blur();
    ui.number.value = '2/26';
    ui.number.listeners.input();
    rejectFirst(new TypeError('Network'));
    await pending;
    assert.equal(ui.warning, undefined);
    assert.equal(ui.status.textContent, '');
});

test('successful save waits for released editor state before loading the issued ata', async () => {
    let requests = 0;
    const ui = panel(async () => { requests++; return { ok: true, json: async () => saved(1, '001/26') }; });
    ui.root.dataset.editorBusy = 'true';
    ui.root.listeners['ata-saved']({ detail: { number: '001/26' } });
    // Alpine updates bound attributes at the end of this event turn.
    ui.root.dataset.editorBusy = 'false';
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(requests, 1);
    assert.equal(ui.root.dataset.ataLoaded, '1');
    assert.equal(ui.number.value, '001/26');
    assert.equal(ui.products.children.length, 1);
    assert.equal(ui.products.children[0].children[0].children[0].textContent, '1');
});

test('confirmed amendment total is available while the ATA reload is pending', async () => {
    let complete;
    const ui = panel(() => new Promise(resolve => { complete = resolve; }));
    ui.root.listeners['ata-saved']({ detail: { number: '001/26', amendment_updated_total: '1234.5678' } });
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(ui.events.at(-1).type, 'ata-loading');
    assert.equal(ui.events.at(-1).detail.amendment_updated_total, '1.234,5678');

    complete({ ok: true, json: async () => ({ ...saved(1, '001/26'), amendment_updated_total: '1234.5678' }) });
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(ui.events.at(-1).type, 'ata-loaded');
    assert.equal(ui.events.at(-1).detail.amendment_updated_total, '1.234,5678');
});
