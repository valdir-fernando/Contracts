import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeInspectorLookup } from '../../resources/js/inspector-registration.js';

function setup(fetcher, original = '') {
    const input = { value: '529.982.247-25', listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } };
    const status = {};
    const navigations = [], warnings = [], submissions = [];
    const form = { dataset: { inspectorLookupUrl: '/inspectors/lookup', inspectorOriginalCpf: original }, listeners: {},
        elements: { namedItem: () => ({ value: 'csrf-test' }) }, querySelector: (selector) => selector === '[data-inspector-cpf]' ? input : status,
        addEventListener(name, fn) { this.listeners[name] = fn; }, requestSubmit(button) { submissions.push(button); } };
    initializeInspectorLookup(form, fetcher, (url) => navigations.push(url), (message) => warnings.push(message));
    return { input, status, form, navigations, warnings, submissions };
}
const reply = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });

test('existing CPF opens the existing editor, sending CPF privately with CSRF and no automatic mutation', async () => {
    const fixture = setup(async (url, options) => {
        assert.equal(url, '/inspectors/lookup');
        assert.equal(options.method, 'POST');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-test');
        assert.deepEqual(JSON.parse(options.body), { cpf: '52998224725' });
        return reply({ found: true, edit_url: '/catalogs/list/inspector?edit=7' });
    });
    await fixture.input.listeners.blur();
    assert.deepEqual(fixture.navigations, ['/catalogs/list/inspector?edit=7']);
    assert.deepEqual(fixture.submissions, []);
});

test('late responses cannot open a fiscal after the CPF changes and unchanged edit CPF needs no lookup', async () => {
    let resolve;
    const fixture = setup(() => new Promise(done => { resolve = done; }));
    const pending = fixture.input.listeners.blur();
    fixture.input.value = '111.444.777-35'; fixture.input.listeners.input();
    resolve(reply({ found: true, edit_url: '/old' }));
    await pending;
    assert.deepEqual(fixture.navigations, []);
    const editing = setup(() => assert.fail('Unchanged CPF must not navigate'), '52998224725');
    await editing.input.listeners.blur();
    assert.deepEqual(editing.navigations, []);
});

test('submit waits for lookup and resumes only for an unmatched CPF', async () => {
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

test('duplicates and failed lookups do not navigate or resume saving, and invalid CPF is never queried', async () => {
    for (const status of [409, 403, 500]) {
        const fixture = setup(async () => reply({}, status));
        await fixture.input.listeners.blur();
        assert.equal(fixture.warnings.length, 1);
        assert.deepEqual(fixture.navigations, []);
        assert.deepEqual(fixture.submissions, []);
    }
    const fixture = setup(() => assert.fail('Invalid CPF must not be sent'));
    fixture.input.value = '11111111111';
    await fixture.input.listeners.blur();
});
