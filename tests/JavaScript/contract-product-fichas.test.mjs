import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractProducts } from '../../resources/js/contract-products.js';

const settle = () => new Promise((resolve) => setImmediate(resolve));

function panel(fichaSummary) {
    const previous = Object.fromEntries(['document', 'Node', 'ResizeObserver', 'window'].map((key) => [key, globalThis[key]]));
    let doc;
    class Element {
        constructor() {
            this.children = []; this.listeners = {}; this.value = ''; this.dataset = {}; this.style = {};
            this.classList = { toggle() {} }; this.parentElement = {}; this.ownerDocument = doc;
            this.validityMessage = ''; this.open = false; this.checked = false;
        }
        set textContent(value) { this.text = String(value); this.children = []; }
        get textContent() { return (this.text ?? '') + this.children.map((child) => child.textContent).join(''); }
        append(...nodes) { this.children.push(...nodes); }
        replaceChildren(...nodes) { this.children = nodes; this.text = ''; }
        addEventListener(name, callback) { (this.listeners[name] ??= []).push(callback); }
        emit(name) { return Promise.all((this.listeners[name] ?? []).map((callback) => callback({ preventDefault() {}, stopImmediatePropagation() {} }))); }
        setAttribute() {}
        setCustomValidity(message) { this.validityMessage = message; }
        reportValidity() { return !this.validityMessage; }
        focus() {}
        getClientRects() { return []; }
    }
    doc = { createElement: () => new Element(), getElementById: () => new Element(), addEventListener() {} };
    globalThis.document = doc; globalThis.Node = Element; globalThis.window = { confirm: () => true };
    globalThis.ResizeObserver = class { observe() {} };
    const nodes = new Map();
    const find = (selector) => {
        if (!nodes.has(selector)) nodes.set(selector, new Element());
        return nodes.get(selector);
    };
    const version = { value: '1' };
    const form = {
        elements: { namedItem: (name) => name === 'version' ? version : { value: 'csrf' } },
        querySelector: (selector) => selector === '#department_id' ? null : find(selector), addEventListener() {},
    };
    const fields = ['item', 'code', 'brand', 'unit', 'unit_price', 'disp'].map((field) => {
        const node = find(`[data-product-field="${field}"]`); node.dataset.productField = field; return node;
    });
    const root = { dataset: { base: '/contracts/1/products' }, closest: () => form, querySelector: find,
        querySelectorAll: () => fields, setAttribute() {} };
    find('[data-product-budget]').list = new Element();
    const row = (id, ficha) => ({ id, product_id: null, item: String(id), code: `C${id}`, description: `Produto ${ficha}`,
        brand: 'Marca', unit: 'UND', quantity: '1.0000', unit_price: '10.0000', total: '10.0000', Ficha: ficha });
    let items = [row(1, '001'), row(2, '002')];
    let serverVersion = 1;
    let hold001 = false;
    let release001;
    const calls = [];
    const response = (data) => ({ ok: true, json: async () => data });
    const fetcher = async (url, options) => {
        const parsed = new URL(url, 'http://contracts.test');
        calls.push({ url: parsed, options });
        if (parsed.pathname.endsWith('budget-lookup')) return response({ status: 'not_found' });
        if (options.method === 'POST') {
            const payload = JSON.parse(options.body);
            items.push({ ...row(3, payload.ficha), description: payload.description });
            serverVersion++;
            return response({ contract_version: serverVersion, total_label: 'R$ 30,00' });
        }
        if (options.method === 'DELETE') {
            items = items.filter((item) => item.id !== 3);
            serverVersion++;
            return response({ contract_version: serverVersion, total_label: 'R$ 20,00' });
        }
        const ficha = parsed.searchParams.get('ficha');
        const data = { contract_version: serverVersion, total_label: 'R$ 20,00', ficha_values: [], ficha_summary: fichaSummary,
            fichas: [...new Set(items.map((item) => item.Ficha))], items: items.filter((item) => !ficha || item.Ficha === ficha) };
        if (ficha === '001' && hold001) return new Promise((resolve) => { release001 = () => resolve(response(data)); });
        return response(data);
    };
    initializeContractProducts(root, fetcher);
    return { find, version, calls, hold: () => { hold001 = true; }, release: () => { hold001 = false; release001(); },
        restore: () => Object.entries(previous).forEach(([key, value]) => { if (value === undefined) delete globalThis[key]; else globalThis[key] = value; }) };
}

test('ficha panel displays server exact totals and reports equality, divergence or invalid values', async () => {
    for (const [summary, expected] of [
        [{ total: '0.3000', contract_total: '0.3000', difference: '0.0000', matches: true }, /confere com o valor original/],
        [{ total: '0.3001', contract_total: '0.3000', difference: '0.0001', matches: false }, /Diferença: 0,0001/],
        [{ total: null, contract_total: '0.3000', difference: null, matches: null }, /Não foi possível conferir/],
    ]) {
        const ui = panel(summary);
        try {
            await settle();
            assert.equal(ui.find('[data-product-ficha-total]').textContent, summary.total === null ? '—' : summary.total.replace('.', ','));
            assert.equal(ui.find('[data-product-ficha-contract-total]').textContent, '0,3000');
            assert.match(ui.find('[data-product-ficha-status]').textContent, expected);
        } finally { ui.restore(); }
    }
});

test('selection loads only that ficha, ignores a late old response, and clearing loads all products', async () => {
    const ui = panel();
    try {
        await settle();
        const select = ui.find('[data-product-budget]');
        const list = ui.find('[data-contract-product-list]');
        assert.deepEqual(select.list.children.map((option) => option.value), ['001', '002']);
        assert.equal(list.children.length, 2);
        ui.hold();
        select.value = '001'; await select.emit('change'); await settle();
        select.value = '002'; await select.emit('change'); await settle();
        assert.equal(list.children.length, 1);
        assert.match(list.textContent, /Produto 002/);
        ui.release(); await settle();
        assert.match(list.textContent, /Produto 002/);
        assert.equal(select.value, '002');
        select.value = ''; await select.emit('change'); await settle();
        assert.equal(list.children.length, 2);
    } finally { ui.restore(); }
});

test('saving a new ficha updates options and keeps its list selected; last reversal removes the option', async () => {
    const ui = panel();
    try {
        await settle();
        const select = ui.find('[data-product-budget]');
        const list = ui.find('[data-contract-product-list]');
        select.value = '0003';
        await select.emit('input');
        await select.emit('change'); await settle();
        assert.equal(select.value, '0003');
        assert.deepEqual(select.list.children.map((option) => option.value), ['001', '002']);
        assert.equal(list.children.length, 0);
        const manual = ui.find('[data-products-manual]');
        manual.checked = true; await manual.emit('change');
        ui.find('[data-product-field="item"]').value = '3';
        ui.find('[data-product-description]').value = 'Produto novo';
        ui.find('[data-product-field="unit"]').value = 'UND';
        ui.find('[data-product-field="unit_price"]').value = '10,0000';
        await ui.find('[data-products-save]').emit('click'); await settle();
        assert.equal(ui.version.value, '2');
        assert.deepEqual(select.list.children.map((option) => option.value), ['001', '002', '0003']);
        assert.equal(select.value, '0003');
        assert.equal(list.children.length, 1);
        assert.match(list.textContent, /Produto novo/);
        const post = ui.calls.find((call) => call.options.method === 'POST');
        assert.equal(JSON.parse(post.options.body).ficha, '0003');
        const edit = list.children[0].children[9].children[0];
        await edit.emit('click'); await settle();
        await ui.find('[data-products-delete]').emit('click'); await settle();
        assert.equal(ui.version.value, '3');
        assert.deepEqual(select.list.children.map((option) => option.value), ['001', '002']);
        assert.equal(select.value, '');
        assert.equal(list.children.length, 2);
    } finally { ui.restore(); }
});
