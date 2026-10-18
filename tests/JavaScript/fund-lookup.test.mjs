import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeFundLookup } from '../../resources/js/fund-registration.js';

function setup(fetcher, original = '') {
    const input = { value: '11.222.333/0001-81', listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } };
    const status = {};
    const navigations = [], warnings = [], submissions = [];
    const form = { dataset: { fundLookupUrl: '/funds/lookup', fundOriginalCnpj: original }, listeners: {},
        elements: { namedItem: () => ({ value: 'csrf-test' }) }, querySelector: (selector) => selector === '[data-fund-cnpj]' ? input : status,
        addEventListener(name, fn) { this.listeners[name] = fn; }, requestSubmit(button) { submissions.push(button); } };
    initializeFundLookup(form, fetcher, (url) => navigations.push(url), (message) => warnings.push(message));
    return { input, status, form, navigations, warnings, submissions };
}
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });

test('existing CNPJ opens the existing editor, sending CNPJ privately with CSRF and no automatic mutation', async () => {
    const fixture = setup(async (url, options) => {
        assert.equal(url, '/funds/lookup');
        assert.equal(options.method, 'POST');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-test');
        assert.deepEqual(JSON.parse(options.body), { cnpj: '11222333000181' });
        return reply({ found: true, edit_url: '/funds?edit=7' });
    });
    await fixture.input.listeners.blur();
    assert.deepEqual(fixture.navigations, ['/funds?edit=7']);
    assert.deepEqual(fixture.submissions, []);
});

test('late responses cannot open a fiscal after the CNPJ changes and unchanged edit CNPJ needs no lookup', async () => {
    let resolve;
    const fixture = setup(() => new Promise(done => { resolve = done; }));
    const pending = fixture.input.listeners.blur();
    fixture.input.value = '04.252.011/0001-10'; fixture.input.listeners.input();
    resolve(reply({ found: true, edit_url: '/old' }));
    await pending;
    assert.deepEqual(fixture.navigations, []);
    const editing = setup(() => assert.fail('Unchanged CNPJ must not navigate'), '11222333000181');
    await editing.input.listeners.blur();
    assert.deepEqual(editing.navigations, []);
});

test('submit waits for lookup and resumes only for an unmatched CNPJ', async () => {
    let resolve;
    const fixture = setup(() => new Promise(done => { resolve = done; }));
    const pending = fixture.input.listeners.blur();
    let prevented = false;
    fixture.form.listeners.submit({ preventDefault() { prevented = true; }, submitter: 'save' });
    fixture.form.listeners.submit({ preventDefault() {}, submitter: 'save' });
    assert.equal(prevented, true);
    assert.deepEqual(fixture.submissions, []);
    resolve(reply({ found: false, edit_url: null }));
    await pending; await Promise.resolve();
    assert.deepEqual(fixture.submissions, ['save']);
    fixture.form.listeners.submit({ preventDefault() { assert.fail('Completed unmatched lookup must allow saving'); } });
});

test('duplicates and failed lookups do not navigate or resume saving, and invalid CNPJ is never queried', async () => {
    for (const status of [409, 403, 500]) {
        const fixture = setup(async () => reply({}, status));
        await fixture.input.listeners.blur();
        assert.equal(fixture.warnings.length, 1);
        assert.deepEqual(fixture.navigations, []);
        assert.deepEqual(fixture.submissions, []);
    }
    const fixture = setup(() => assert.fail('Invalid CNPJ must not be sent'));
    fixture.input.value = '11111111111';
    await fixture.input.listeners.blur();
});

test('new fund waits for external consultation before submitting and existing fund opens comparison in its editor', async () => {
    let completeExternal;
    let externalStarted;
    const started = new Promise(done => { externalStarted = done; });
    const fixture = setup(async url => {
        if (url === '/funds/lookup') return reply({ found: false });
        externalStarted();
        return new Promise(done => { completeExternal = done; });
    });
    fixture.form.dataset.cnpjLookupUrl = '/cnpj/lookup';
    const pending = fixture.input.listeners.blur();
    await started;
    fixture.form.listeners.submit({ preventDefault() {}, submitter: 'save' });
    await Promise.resolve();
    assert.deepEqual(fixture.submissions, []);
    completeExternal(reply({ status: 'not_found' }));
    await pending; await Promise.resolve();
    assert.deepEqual(fixture.submissions, ['save']);
    const existing = setup(async () => reply({ found: true, edit_url: '/funds?edit=7' }));
    existing.form.dataset.cnpjLookupUrl = '/cnpj/lookup';
    await existing.input.listeners.blur();
    assert.deepEqual(existing.navigations, ['/funds?edit=7&consultar_cnpj=1']);
});
