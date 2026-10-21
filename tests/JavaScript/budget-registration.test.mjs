import test from 'node:test';
import assert from 'node:assert/strict';
import { composeBudgetCode, initializeBudgetDepartment, initializeBudgetLookup } from '../../resources/js/budget-registration.js';

test('budget composition follows the reference, preserves zeros and starts with management', () => {
    const fields = { Orgao: '02', Gestao: '02', Unidade: '0217', Funcao: '04', SubFunc: '122', Programa: '7008', Projeto: '8040', Codigo: '319094', Fonte: '100', Ficha: '20250029' };
    assert.equal(composeBudgetCode(fields), '02.0217.04.122.7008.8040.319094(100)20250029');
    assert.equal(composeBudgetCode({ ...fields, Orgao: '11' }), composeBudgetCode(fields));
    assert.equal(composeBudgetCode({ ...fields, Gestao: '08' }), '08.0217.04.122.7008.8040.319094(100)20250029');
    assert.equal(composeBudgetCode({ ...fields, Fonte: '001', Ficha: '00029' }), '02.0217.04.122.7008.8040.319094(001)00029');
});

test('department stays locked outside municipality and the filter preserves entered values', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { location: { href: 'http://contracts.test/catalogs/budgets/create' } };
    const node = (value = '') => ({ value, disabled: false, listeners: {}, attributes: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
    });
    const fund = node('1'); fund.selectedOptions = [{ dataset: { municipal: 'false' } }];
    const department = node('9');
    const filter = node(); const status = node(); const list = node(); const search = node(); const q = node('UNSAVED SEARCH');
    const selectors = { '[data-budget-fund]': fund, '[data-budget-department]': department, '[data-budget-department-filter]': filter,
        '[data-budget-filter-status]': status, '[data-budget-list]': list, '[name="q"]': q, '.budget-search': search };
    const panel = { dataset: { budgetListUrl: '/catalogs/budgets/create' }, querySelector: (selector) => selectors[selector] };
    const calls = [];
    try {
        initializeBudgetDepartment(panel, async (url) => { calls.push(url); return { ok: true, json: async () => ({ html: '<table>Filtered</table>' }) }; });
        assert.equal(department.disabled, true);
        assert.equal(department.value, '');
        assert.equal(filter.disabled, true);
        fund.selectedOptions = [{ dataset: { municipal: 'true' } }];
        fund.value = '2'; fund.listeners.change();
        assert.equal(department.disabled, false);
        department.value = '10'; department.listeners.change();
        assert.equal(filter.disabled, false);
        filter.listeners.click(); await new Promise(setImmediate);
        assert.equal(calls[0].searchParams.get('filter_fund_id'), '2');
        assert.equal(calls[0].searchParams.get('filter_department_id'), '10');
        assert.equal(calls[0].searchParams.has('q'), false);
        assert.equal(department.value, '10');
        assert.equal(q.value, 'UNSAVED SEARCH');
        assert.equal(list.innerHTML, '<table>Filtered</table>');
        filter.listeners.click(); await new Promise(setImmediate);
        assert.equal(calls[1].searchParams.has('filter_department_id'), false);
        fund.selectedOptions = [{ dataset: { municipal: 'false' } }];
        fund.listeners.change();
        assert.equal(department.disabled, true);
        assert.equal(department.value, '');
    } finally { globalThis.window = previousWindow; }
});

test('ficha blur opens existing record, leaves new fichas in place and ignores outdated lookup', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { location: { href: 'http://contracts.test/catalogs/budgets/create' } };
    const ficha = { value: '', listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; } };
    const save = { disabled: false };
    const status = { textContent: '' };
    const form = { listeners: {}, querySelector: () => save, addEventListener(name, callback) { this.listeners[name] = callback; } };
    const nodes = { '[data-budget-part="Ficha"]': ficha, '#budget-registration-form': form, '[data-budget-lookup-status]': status };
    const panel = { dataset: { budgetLookupUrl: '/catalogs/budgets/lookup', currentFicha: '100' }, querySelector: (selector) => nodes[selector] };
    const navigations = []; const requests = []; let release;
    try {
        initializeBudgetLookup(panel, async (url) => {
            const value = url.searchParams.get('ficha'); requests.push(value);
            if (value === '300') return new Promise((resolve) => { release = () => resolve({ ok: true, json: async () => ({ status: 'found', url: '/edit/300' }) }); });
            return { ok: true, json: async () => value === '200' ? { status: 'found', url: '/edit/200' } : { status: 'not_found' } };
        }, (url) => navigations.push(url));
        ficha.value = '100'; await ficha.listeners.blur();
        assert.equal(requests.length, 0);
        ficha.value = '200'; await ficha.listeners.blur();
        assert.deepEqual(navigations, ['/edit/200']);
        ficha.value = '999'; await ficha.listeners.blur();
        assert.equal(ficha.value, '999');
        assert.equal(navigations.length, 1);
        ficha.value = '300'; const pending = ficha.listeners.blur();
        assert.equal(save.disabled, true);
        let prevented = false; form.listeners.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        ficha.value = '400'; ficha.listeners.input(); release(); await pending;
        assert.equal(navigations.length, 1);
        assert.equal(save.disabled, false);
    } finally { globalThis.window = previousWindow; }
});
