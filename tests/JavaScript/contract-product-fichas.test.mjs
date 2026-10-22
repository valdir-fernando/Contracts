import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractProducts, productBudgetTotals } from '../../resources/js/contract-products.js';

const settle = () => new Promise((resolve) => setImmediate(resolve));

function panel(fichaSummary, budgetResults = {}, fichaValues = null, fichaPanel = undefined) {
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
        emit(name, detail = {}) { return Promise.all((this.listeners[name] ?? []).map((callback) => callback({ preventDefault() {}, stopImmediatePropagation() {}, ...detail }))); }
        setAttribute() {}
        setCustomValidity(message) { this.validityMessage = message; }
        reportValidity() { return !this.validityMessage; }
        focus() {}
        showModal() { this.open = true; }
        close() { this.open = false; }
        getClientRects() { return []; }
    }
    doc = { createElement: () => new Element(), getElementById: () => new Element(), addEventListener() {} };
    let storageListener;
    globalThis.document = doc; globalThis.Node = Element; globalThis.window = { confirm: () => true,
        addEventListener: (name, listener) => { if (name === 'storage') storageListener = listener; } };
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
        if (parsed.pathname.endsWith('budget-lookup')) return response(await (budgetResults[parsed.searchParams.get('ficha')] ?? { status: 'not_found' }));
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
        const data = { contract_version: serverVersion, total_label: 'R$ 20,00', ficha_values: fichaValues ?? [...new Set(items.map((item) => item.Ficha))].map((label) => ({ label, total: '10.0000' })), ficha_summary: fichaSummary,
            product_ficha_values: productBudgetTotals(items),
            ficha_panel: fichaPanel,
            fichas: [...new Set(items.map((item) => item.Ficha))], items: items.filter((item) => !ficha || item.Ficha === ficha) };
        if (ficha === '001' && hold001) return new Promise((resolve) => { release001 = () => resolve(response(data)); });
        return response(data);
    };
    find('[data-contract-products-dialog]').open = true;
    initializeContractProducts(root, fetcher);
    return { find, version, calls, notifyBudgetSaved: () => storageListener({ key: 'contracts-budget-changed' }), hold: () => { hold001 = true; }, release: () => { hold001 = false; release001(); },
        restore: () => Object.entries(previous).forEach(([key, value]) => { if (value === undefined) delete globalThis[key]; else globalThis[key] = value; }) };
}

test('ficha panel warns only when sum exceeds contract value or cannot be checked', async () => {
    for (const [summary, expected] of [
        [{ total: '0.3000', contract_total: '0.3000', difference: '0.0000', matches: true }, /^$/],
        [{ total: '0.3001', contract_total: '0.3000', difference: '0.0001', matches: false }, /ultrapassa o valor do contrato/],
        [{ total: '0.2999', contract_total: '0.3000', difference: '-0.0001', matches: false }, /^$/],
        [{ total: null, contract_total: '0.3000', difference: null, matches: null }, /Não foi possível conferir/],
    ]) {
        const ui = panel(summary);
        try {
            await settle();
            assert.match(ui.find('[data-product-ficha-status]').textContent, expected);
        } finally { ui.restore(); }
    }
});

test('ficha panel uses product totals for fichas missing from value records and preserves registered values', async () => {
    const ui = panel({ difference: '0.0000', matches: true }, {
        '001': { status: 'found', description: 'Material', allocation: '02.01', scope_matches: true },
    }, [{ label: '002', total: '20.0000' }]);
    try {
        await settle();
        const rows = ui.find('[data-product-budget-totals]').children;
        assert.deepEqual(rows.map((row) => row.children[0].textContent), ['002', '001']);
        assert.equal(rows[0].children[1].textContent, '20,0000');
        assert.equal(rows[1].children[1].textContent, '10,0000');
        assert.match(rows[1].children[1].title, /Valor total dos produtos originais/);
        assert.equal(ui.find('[data-product-ficha-status]').textContent, '');
        await rows[1].children[0].children[0].emit('click'); await settle();
        assert.equal(ui.find('[data-product-budget]').value, '001');
        assert.match(ui.find('[data-contract-product-list]').textContent, /Produto 001/);
        assert.doesNotMatch(ui.find('[data-contract-product-list]').textContent, /Produto 002/);
        assert.equal(ui.find('[data-product-budget-totals]').children.length, 2);
    } finally { ui.restore(); }
});

test('server panel projection controls original ficha rows and their matching summary', async () => {
    const ui = panel({ difference: '999.0000', matches: false }, {
        '001': { status: 'found', description: 'Material', allocation: '02.01', scope_matches: true },
    }, [{ label: 'AMENDMENT', total: '999.0000' }], {
        rows: [{ label: '001', total: '198000.0000', source: 'products' }],
        summary: { total: '198000.0000', contract_total: '198000.0000', difference: '0.0000', matches: true },
    });
    try {
        await settle();
        const rows = ui.find('[data-product-budget-totals]').children;
        assert.equal(rows.length, 1);
        assert.equal(rows[0].children[0].textContent, '001');
        assert.equal(rows[0].children[1].textContent, '198.000,0000');
        assert.equal(ui.find('[data-product-ficha-status]').textContent, '');
        await rows[0].children[0].children[0].emit('click'); await settle();
        assert.equal(ui.find('[data-product-budget]').value, '001');
        assert.match(ui.find('[data-contract-product-list]').textContent, /Produto 001/);
        assert.equal(ui.find('[data-product-budget-totals]').children.length, 1);
    } finally { ui.restore(); }
});

test('opening and clearing keep products empty; selection filters and ignores late responses', async () => {
    const ui = panel();
    try {
        await settle();
        const select = ui.find('[data-product-budget]');
        const list = ui.find('[data-contract-product-list]');
        assert.deepEqual(ui.find('[data-product-budget-totals]').children.map((row) => row.children[0].textContent), ['001', '002']);
        assert.equal(list.children.length, 0);
        ui.hold();
        select.value = '001'; await select.emit('blur'); await settle();
        select.value = '002'; await select.emit('blur'); await settle();
        assert.equal(list.children.length, 1);
        assert.match(list.textContent, /Produto 002/);
        ui.release(); await settle();
        assert.match(list.textContent, /Produto 002/);
        assert.equal(select.value, '002');
        select.value = ''; await select.emit('blur'); await settle();
        assert.equal(list.children.length, 0);
        select.value = '001'; await select.emit('blur'); await settle();
        assert.equal(list.children.length, 1);
        await ui.find('[data-contract-products-open]').emit('click');
        assert.equal(list.children.length, 0);
        await settle();
        assert.equal(select.value, '');
        assert.equal(list.children.length, 0);
    } finally { ui.restore(); }
});

test('description grows to five lines, scrolls past the limit and shrinks when text is removed', async () => {
    const ui = panel();
    try {
        await settle();
        const description = ui.find('[data-product-description]');
        description.getClientRects = () => [{}];
        description.ownerDocument.defaultView = { getComputedStyle: () => ({ borderTopWidth: '1px', borderBottomWidth: '1px', maxHeight: '118px' }) };
        for (const [contentHeight, height, overflow] of [[55, '57px', 'hidden'], [116, '118px', 'hidden'], [180, '118px', 'auto'], [34, '36px', 'hidden']]) {
            description.scrollHeight = contentHeight;
            await description.emit('input');
            assert.equal(description.style.height, height);
            assert.equal(description.style.overflowY, overflow);
        }
    } finally { ui.restore(); }
});

test('clicking a ficha in the panel fills the input, loads its data and filters products', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01.001', scope_matches: true },
    });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        assert.equal(ficha.list, undefined);
        const select = ui.find('[data-product-budget-totals]').children[0].children[0].children[0];
        await select.emit('click'); await settle();
        assert.equal(ficha.value, '001');
        assert.equal(ui.find('[data-product-budget-description]').value, 'Material');
        assert.equal(ui.find('[data-product-budget-allocation]').value, '02.01.001');
        assert.match(ui.find('[data-contract-product-list]').textContent, /Produto 001/);
        assert.doesNotMatch(ui.find('[data-contract-product-list]').textContent, /Produto 002/);
        ui.version.value = '999';
        await ficha.emit('blur'); await settle();
        ficha.value = '002';
        await select.emit('click'); await settle();
        assert.equal(ficha.value, '002');
    } finally { ui.restore(); }
});

test('changing ficha fills its description and allocation automatically and clears missing values', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01.001', scope_matches: true },
        '002': { status: 'found', description: 'Servicos', allocation: '02.01.002', scope_matches: false },
    });
    try {
        await settle();
        globalThis.window.confirm = () => { throw new Error('Lookup must fill without confirmation'); };
        const ficha = ui.find('[data-product-budget]');
        for (const [value, description, allocation] of [['001', 'Material', '02.01.001'], ['002', 'Servicos', '02.01.002'], ['999', '', ''], ['', '', '']]) {
            ficha.value = value;
            await ficha.emit('blur'); await settle();
            assert.equal(ui.find('[data-product-budget-description]').value, description);
            assert.equal(ui.find('[data-product-budget-allocation]').value, allocation);
            if (value === '002' || value === '999') {
                assert.equal(ui.find('[data-product-budget-warning]').open, true);
                assert.equal(ui.find('[data-product-budget-status]').textContent, '');
                assert.match(ui.find('[data-product-budget-warning-message]').textContent, value === '002' ? /outro Fundo ou Secretaria/ : /não encontrada/);
                await ui.find('[data-product-budget-warning-ok]').emit('click');
                assert.equal(ui.find('[data-product-budget-warning]').open, false);
            }
        }
    } finally { ui.restore(); }
});

test('closing products after acknowledging a ficha warning does not query or reopen a modal', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01', scope_matches: false },
    });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        await ui.find('[data-product-budget-totals]').children[0].children[0].children[0].emit('click');
        await settle();
        const warning = ui.find('[data-product-budget-warning]');
        assert.equal(warning.open, true);
        await ui.find('[data-product-budget-warning-ok]').emit('click');
        await warning.emit('close');
        const before = ui.calls.length;
        await ficha.emit('blur', { relatedTarget: ui.find('[data-contract-products-close]') });
        const dialog = ui.find('[data-contract-products-dialog]');
        dialog.open = false;
        await ui.find('[data-contract-products-close]').emit('click');
        await dialog.emit('close');
        await settle();
        assert.equal(ui.calls.length, before);
        assert.equal(warning.open, false);
        let focused = false;
        ficha.focus = () => { focused = true; };
        await warning.emit('close');
        assert.equal(focused, false);
    } finally { ui.restore(); }
});

test('late ficha responses cannot open a warning after closing products', async () => {
    let resolveLookup;
    const ui = panel(undefined, { '001': new Promise((resolve) => { resolveLookup = resolve; }) });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        ficha.value = '001';
        await ficha.emit('blur');
        await settle();
        const dialog = ui.find('[data-contract-products-dialog]');
        dialog.open = false;
        await dialog.emit('close');
        resolveLookup({ status: 'found', description: 'Material', allocation: '02.01', scope_matches: false });
        await settle();
        assert.equal(ui.find('[data-product-budget-warning]').open, false);
        assert.equal(ui.find('[data-product-budget-description]').value, '');
    } finally { ui.restore(); }
});

test('acknowledging a scope warning allows leaving ficha for other fields without repeating it', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01', scope_matches: false },
        '002': { status: 'found', description: 'Servicos', allocation: '03.01', scope_matches: false },
    });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        const warning = ui.find('[data-product-budget-warning]');
        await ui.find('[data-product-budget-totals]').children[0].children[0].children[0].emit('click');
        await settle();
        assert.equal(warning.open, true);
        await ui.find('[data-product-budget-warning-ok]').emit('click');
        await warning.emit('close');
        const lookupCount = () => ui.calls.filter((call) => call.url.pathname.endsWith('budget-lookup')).length;
        const before = lookupCount();
        for (const target of ['[data-product-field="item"]', '[data-product-quantity]', '[data-products-manual]']) {
            await ficha.emit('blur', { relatedTarget: ui.find(target) });
            await settle();
            assert.equal(warning.open, false);
            assert.equal(lookupCount(), before);
            assert.equal(ui.find('[data-product-budget-description]').value, 'Material');
        }
        ficha.value = '002';
        await ficha.emit('input');
        await ficha.emit('blur'); await settle();
        assert.equal(warning.open, true);
        assert.equal(lookupCount(), before + 1);
        assert.equal(ui.find('[data-product-budget-description]').value, 'Servicos');
    } finally { ui.restore(); }
});

test('Novo clears product fields while preserving the consulted ficha without repeating its warning', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01', scope_matches: false },
    });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        ficha.value = '001';
        await ficha.emit('blur'); await settle();
        const warning = ui.find('[data-product-budget-warning]');
        assert.equal(warning.open, true);
        await ui.find('[data-product-budget-warning-ok]').emit('click');
        await warning.emit('close');
        const manual = ui.find('[data-products-manual]');
        manual.checked = true;
        await manual.emit('change');
        ui.find('[data-product-field="item"]').value = '123';
        ui.find('[data-product-description]').value = 'Produto novo';
        ui.find('[data-product-quantity]').value = '5';
        const before = ui.calls.length;
        for (let attempt = 0; attempt < 2; attempt++) {
            await ui.find('[data-products-new]').emit('click'); await settle();
            assert.equal(warning.open, false);
            assert.equal(ui.calls.length, before);
            assert.equal(ficha.value, '001');
            assert.equal(ui.find('[data-product-budget-description]').value, 'Material');
            assert.equal(ui.find('[data-product-budget-allocation]').value, '02.01');
            assert.equal(ui.find('[data-product-field="item"]').value, '');
            assert.equal(ui.find('[data-product-description]').value, '');
            assert.equal(ui.find('[data-product-quantity]').value, '');
            assert.equal(manual.checked, false);
        }
    } finally { ui.restore(); }
});

test('Nova Ficha clears product and budget fields, empties products and focuses ficha despite a pending list', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01', scope_matches: true },
    });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        ficha.value = '001';
        await ficha.emit('blur'); await settle();
        assert.equal(ui.find('[data-contract-product-list]').children.length, 1);
        const manual = ui.find('[data-products-manual]');
        manual.checked = true; await manual.emit('change');
        ui.find('[data-product-field="item"]').value = '123';
        ui.find('[data-product-description]').value = 'Produto novo';
        ui.find('[data-product-quantity]').value = '5';
        ui.hold();
        // An unchanged ficha still refreshes the list; its late response must not restore it.
        await ficha.emit('blur'); await settle();
        let focused = false;
        ficha.focus = () => { focused = true; };
        const before = ui.calls.length;
        await ui.find('[data-products-new-ficha]').emit('click');
        assert.equal(focused, true);
        assert.equal(ficha.value, '');
        assert.equal(ui.find('[data-product-budget-description]').value, '');
        assert.equal(ui.find('[data-product-budget-allocation]').value, '');
        assert.equal(ui.find('[data-product-field="item"]').value, '');
        assert.equal(ui.find('[data-product-description]').value, '');
        assert.equal(ui.find('[data-product-quantity]').value, '');
        assert.equal(manual.checked, false);
        assert.equal(ui.find('[data-product-budget-totals]').children.length, 2);
        assert.equal(ui.find('[data-contract-product-list]').children.length, 0);
        assert.equal(ui.calls.length, before);
        ui.release(); await settle();
        assert.equal(ui.find('[data-contract-product-list]').children.length, 0);
        assert.equal(ui.find('[data-product-budget-warning]').open, false);
    } finally { ui.restore(); }
});

test('scope warnings name only the mismatched fund or, when fund matches, the department', async () => {
    for (const [data, expected, excluded] of [
        [{ fund_matches: false, department_matches: true, fund: 'FMS', department: 'PROCURADORIA' }, /outro Fundo: FMS/, /Secretaria|PROCURADORIA/],
        [{ fund_matches: false, department_matches: false, fund: 'FMS', department: 'PROCURADORIA' }, /outro Fundo: FMS/, /Secretaria|PROCURADORIA/],
        [{ fund_matches: true, department_matches: false, fund: 'MUNICIPIO', department: 'PROCURADORIA' }, /outra Secretaria: PROCURADORIA/, /Fundo|MUNICIPIO/],
        [{ fund_matches: true, department_matches: false, department: '' }, /não possui Secretaria informada/, /Fundo/],
    ]) {
        const ui = panel(undefined, { '001': { status: 'found', scope_matches: false, description: 'Material', allocation: '02.01', ...data } });
        try {
            await settle();
            const ficha = ui.find('[data-product-budget]');
            ficha.value = '001'; await ficha.emit('blur'); await settle();
            assert.equal(ui.find('[data-product-budget-warning]').open, true);
            const text = ui.find('[data-product-budget-warning-message]').textContent;
            assert.match(text, expected);
            assert.doesNotMatch(text, excluded);
            assert.equal(ui.find('[data-product-budget-description]').value, 'Material');
            await ui.find('[data-product-budget-warning-ok]').emit('click');
            await ficha.emit('blur'); await settle();
            assert.equal(ui.find('[data-product-budget-warning]').open, false);
        } finally { ui.restore(); }
    }
});

test('typing and change do not query ficha until the input loses focus', async () => {
    const ui = panel(undefined, {
        '001': { status: 'found', description: 'Material', allocation: '02.01.001', scope_matches: true },
    });
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        const before = ui.calls.length;
        for (const value of ['0', '00', '001']) {
            ficha.value = value;
            await ficha.emit('input');
        }
        await ficha.emit('change');
        await new Promise((resolve) => setTimeout(resolve, 250));
        assert.equal(ui.calls.length, before);
        assert.equal(ui.find('[data-product-budget-warning]').open, false);
        await ficha.emit('blur'); await settle();
        assert.equal(ui.calls.filter((call) => call.url.pathname.endsWith('budget-lookup')).length, 1);
        assert.equal(ui.find('[data-product-budget-description]').value, 'Material');
        assert.match(ui.find('[data-contract-product-list]').textContent, /Produto 001/);
    } finally { ui.restore(); }
});

test('only manual entry unlocks unit price and description', async () => {
    const ui = panel();
    try {
        await settle();
        const manual = ui.find('[data-products-manual]');
        const price = ui.find('[data-product-field="unit_price"]');
        const description = ui.find('[data-product-description]');
        for (const enabled of [false, true, false]) {
            manual.checked = enabled;
            await manual.emit('change');
            assert.equal(price.readOnly, !enabled);
            assert.equal(description.readOnly, !enabled);
        }
    } finally { ui.restore(); }
});

test('saving a budget in another tab reloads current ficha and closes the old missing warning', async () => {
    const results = {};
    const ui = panel(undefined, results);
    try {
        await settle();
        const ficha = ui.find('[data-product-budget]');
        ficha.value = 'NEW';
        await ficha.emit('blur'); await settle();
        assert.equal(ui.find('[data-product-budget-warning]').open, true);
        results.NEW = { status: 'found', description: 'New budget', allocation: '02.01', scope_matches: true };
        ui.notifyBudgetSaved(); await settle();
        assert.equal(ficha.value, 'NEW');
        assert.equal(ui.find('[data-product-budget-description]').value, 'New budget');
        assert.equal(ui.find('[data-product-budget-allocation]').value, '02.01');
        assert.equal(ui.find('[data-product-budget-warning]').open, false);
    } finally { ui.restore(); }
});

test('saving a new ficha updates the ficha panel; last reversal removes it', async () => {
    const ui = panel();
    try {
        await settle();
        const select = ui.find('[data-product-budget]');
        const list = ui.find('[data-contract-product-list]');
        select.value = '0003';
        await select.emit('input');
        await select.emit('blur'); await settle();
        assert.equal(select.value, '0003');
        assert.deepEqual(ui.find('[data-product-budget-totals]').children.map((row) => row.children[0].textContent), ['001', '002']);
        assert.equal(list.children.length, 0);
        const manual = ui.find('[data-products-manual]');
        manual.checked = true; await manual.emit('change');
        ui.find('[data-product-field="item"]').value = '3';
        ui.find('[data-product-description]').value = 'Produto novo';
        ui.find('[data-product-field="unit"]').value = 'UND';
        ui.find('[data-product-field="unit_price"]').value = '10,0000';
        await ui.find('[data-products-save]').emit('click'); await settle();
        assert.equal(ui.version.value, '2');
        assert.deepEqual(ui.find('[data-product-budget-totals]').children.map((row) => row.children[0].textContent), ['001', '002', '0003']);
        assert.equal(select.value, '0003');
        assert.equal(list.children.length, 1);
        assert.match(list.textContent, /Produto novo/);
        const post = ui.calls.find((call) => call.options.method === 'POST');
        assert.equal(JSON.parse(post.options.body).ficha, '0003');
        const edit = list.children[0].children[9].children[0];
        await edit.emit('click'); await settle();
        await ui.find('[data-products-delete]').emit('click'); await settle();
        assert.equal(ui.version.value, '3');
        assert.deepEqual(ui.find('[data-product-budget-totals]').children.map((row) => row.children[0].textContent), ['001', '002']);
        assert.equal(select.value, '');
        assert.equal(list.children.length, 0);
    } finally { ui.restore(); }
});
