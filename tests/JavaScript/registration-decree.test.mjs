import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeInspectorDecree } from '../../resources/js/registration-decree.js';

function fixture(valid = true) {
    const button = { disabled: false };
    let submit;
    const form = {
        action: '/inspectors/decree', ownerDocument: {}, checkValidity: () => valid,
        querySelector: () => button, addEventListener: (_, callback) => { submit = callback; },
    };
    const warnings = [];
    return { form, button, warnings, warn: (message) => warnings.push(message), submit: (submitter) => submit({ submitter, preventDefault() {} }) };
}

test('decree blocks incomplete fields with the standard message before requesting', async () => {
    const state = fixture(false);
    initializeInspectorDecree(state.form, () => { throw new Error('Unexpected request'); }, state.warn);
    await state.submit();
    assert.match(state.warnings[0], /Selecione um fiscal/);
    assert.equal(state.button.disabled, false);
});

test('decree prevents duplicate submissions and restores the button after contextual errors', async () => {
    const original = globalThis.FormData;
    globalThis.FormData = class {};
    try {
        const state = fixture();
        state.form.querySelector = () => null;
        let resolve;
        let calls = 0;
        initializeInspectorDecree(state.form, (_, options) => {
            calls++;
            assert.equal(options.method, 'POST');
            assert.equal(options.credentials, 'same-origin');
            return new Promise((done) => { resolve = done; });
        }, state.warn);
        const pending = state.submit(state.button);
        assert.equal(state.button.disabled, true);
        await state.submit();
        assert.equal(calls, 1);
        resolve({ ok: false, json: async () => ({ errors: { scope: ['Informe o escopo.'] } }) });
        await pending;
        assert.deepEqual(state.warnings, ['Informe o escopo.']);
        assert.equal(state.button.disabled, false);
    } finally {
        globalThis.FormData = original;
    }
});

test('decree refuses an expired session HTML response instead of downloading it as a PDF', async () => {
    const original = globalThis.FormData;
    globalThis.FormData = class {};
    try {
        const state = fixture();
        initializeInspectorDecree(state.form, async () => ({ ok: true, headers: { get: () => 'text/html' } }), state.warn);
        await state.submit();
        assert.match(state.warnings[0], /sessão pode ter expirado/);
        assert.equal(state.button.disabled, false);
    } finally {
        globalThis.FormData = original;
    }
});

test('decree downloads the returned PDF and removes the temporary link', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const original = globalThis.FormData;
    globalThis.FormData = class {};
    try {
        const state = fixture();
        let clicked = 0;
        let removed = false;
        const link = { click() { clicked++; }, remove() { removed = true; } };
        state.form.ownerDocument = { createElement: () => link, body: { append() {} } };
        initializeInspectorDecree(state.form, async () => new Response(new Blob(['%PDF-test']), {
            headers: { 'Content-Type': 'application/pdf' },
        }), state.warn);
        await state.submit();
        assert.equal(clicked, 1);
        assert.equal(link.download, 'decreto-fiscais.pdf');
        assert.match(link.href, /^blob:/);
        assert.equal(removed, true);
        assert.deepEqual(state.warnings, []);
        assert.equal(state.button.disabled, false);
        context.mock.timers.tick(60000);
    } finally {
        globalThis.FormData = original;
    }
});
