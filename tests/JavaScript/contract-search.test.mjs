import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractSearch } from '../../resources/js/contract-search.js';

function fixture(value = '') {
    const node = () => ({ value: '', listeners: {}, children: [], disabled: false,
        addEventListener(name, callback) { this.listeners[name] = callback; },
        focus() {}, replaceChildren() { this.children = []; }, append(child) { this.children.push(child); } });
    const number = node(); number.value = value;
    const opener = node();
    const query = node();
    const form = node(); form.elements = { q: query };
    const results = node();
    const status = node();
    const previous = node(); const next = node();
    const dialog = node(); dialog.dataset = { contractSearchUrl: '/contracts/lookup' };
    dialog.showModal = () => { dialog.open = true; };
    dialog.querySelector = (selector) => ({ '[data-contract-search-form]': form, '[data-contract-search-results]': results,
        '[data-contract-search-status]': status, '[data-contract-search-prev]': previous, '[data-contract-search-next]': next })[selector];
    const doc = { baseURI: 'http://contracts.test/', body: { classList: { add() {} } }, createElement: node,
        querySelector: (selector) => selector === '[data-contract-number-search]' ? number : opener };
    return { doc, dialog, number, opener, results, status, click: () => opener.listeners.click() };
}
const found = (contracts, last_page = 1) => ({ ok: true, json: async () => ({ contracts, page: 1, last_page }) });
const contract = { number: '001/26', process: 'P-1', url: '/contracts/1/edit' };

test('filled number searches exact and opens the unique contract without opening the dialog', async () => {
    const setup = fixture(' 001/26 ');
    let destination;
    initializeContractSearch(setup.dialog, setup.doc, async (url) => {
        assert.equal(url.searchParams.get('q'), '001/26');
        assert.equal(url.searchParams.get('exact'), '1');
        return found([contract]);
    }, (url) => { destination = url; });
    await setup.click();
    assert.equal(destination, '/contracts/1/edit');
    assert.equal(setup.dialog.open, undefined);
});

test('empty number opens consultation directly; absent or ambiguous exact matches open selection', async () => {
    for (const matches of [null, [], [contract, { ...contract, url: '/contracts/2/edit' }]]) {
        const setup = fixture(matches === null ? '' : '001/26');
        let calls = 0;
        initializeContractSearch(setup.dialog, setup.doc, async (url) => {
            calls++;
            if (url.searchParams.has('exact')) return found(matches);
            return found([contract]);
        }, () => assert.fail('Ambiguous result must not navigate'));
        await setup.click();
        assert.equal(setup.dialog.open, true);
        assert.equal(calls, matches === null ? 1 : 2);
        assert.equal(setup.results.children.length, 1);
        assert.equal(setup.opener.disabled, false);
    }
});

test('changing the number discards a late exact response', async () => {
    const setup = fixture('001/26');
    let resolve;
    initializeContractSearch(setup.dialog, setup.doc, () => new Promise((done) => { resolve = done; }), () => assert.fail('Stale response'));
    const pending = setup.click();
    setup.number.value = '002/26';
    setup.number.listeners.input();
    resolve(found([contract]));
    await pending;
    assert.equal(setup.dialog.open, undefined);
    assert.equal(setup.opener.disabled, false);
});

test('failed direct lookup offers retry in consultation without navigating', async () => {
    const setup = fixture('001/26');
    initializeContractSearch(setup.dialog, setup.doc, async () => ({ ok: false }), () => assert.fail('Failed request'));
    await setup.click();
    assert.equal(setup.dialog.open, true);
    assert.match(setup.status.textContent, /Não foi possível/);
    assert.equal(setup.opener.disabled, false);
});
