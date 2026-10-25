import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeInspectorAssistance } from '../../resources/js/inspector-assistance.js';

function setup(fetcher, preserve = true, disabled = false, savedName = 'Fiscal histórico') {
    globalThis.location = { href: 'https://contracts.test/contracts/create' };
    const events = {};
    const formEvents = {};
    const department = { value: '10', disabled, addEventListener: (event, handler) => { events[event] = handler; } };
    const settings = { dataset: { url: '/contracts/inspector-defaults', contractId: preserve ? '42' : '', preserveFiscal: String(preserve) } };
    const status = { textContent: '' };
    const name = { textContent: savedName };
    const decree = { textContent: 'Decreto histórico' };
    const form = {
        elements: { namedItem: () => department },
        querySelector: (selector) => selector === '[data-inspector-assistance]' ? settings
            : selector === '[data-inspector-status]' ? status
                : selector === '[data-contractor-field="nome_fiscal"]' ? name
                    : selector === '[data-contractor-field="decreto_fiscal"]' ? decree : null,
        addEventListener: (event, handler) => { formEvents[event] = handler; },
    };
    initializeInspectorAssistance(form, fetcher);
    return { department, name, decree, status, change: () => events.change(), submit: (event) => formEvents.submit(event) };
}

test('opening an existing contract without a saved inspector does not request the current catalog', async () => {
    let calls = 0;
    const state = setup(async () => {
        calls++;
        return { ok: true, json: async () => ({ values: { nome_fiscal: 'Fiscal atual do catálogo' } }) };
    }, true, false, '');
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(calls, 0);
    assert.equal(state.name.textContent, '');
    assert.equal(state.status.textContent, '');
});

test('preserves historical inspector until department changes, then uses safe text and server values', async () => {
    let calls = 0;
    const state = setup(async (url) => {
        calls++;
        assert.equal(url.searchParams.get('department_id'), '10');
        assert.equal(url.searchParams.get('contract_id'), '42');
        return { ok: true, json: async () => ({ values: { nome_fiscal: '<script>Fiscal</script>', decreto_fiscal: '123' } }) };
    });
    assert.equal(calls, 0);
    assert.equal(state.name.textContent, 'Fiscal histórico');
    await state.change();
    assert.equal(state.name.textContent, '<script>Fiscal</script>');
    assert.equal(state.decree.textContent, '123');
});

test('cancels outdated inspector results and prevents save during a pending lookup', async () => {
    const requests = [];
    const state = setup((url, options) => new Promise((resolve) => requests.push({ url, options, resolve })));
    const first = state.change();
    let prevented = false;
    state.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    state.department.value = '20';
    const second = state.change();
    assert.equal(requests[0].options.signal.aborted, true);
    requests[1].resolve({ ok: true, json: async () => ({ values: { nome_fiscal: 'Fiscal novo' } }) });
    await second;
    requests[0].resolve({ ok: true, json: async () => ({ values: { nome_fiscal: 'Fiscal antigo' } }) });
    await first;
    assert.equal(state.name.textContent, 'Fiscal novo');
    prevented = false;
    state.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, false);
});

test('looks up fund inspector on creation without sending a disabled department, and handles absence', async () => {
    const state = setup(async (url) => {
        assert.equal(url.searchParams.has('department_id'), false);
        assert.equal(url.searchParams.has('contract_id'), false);
        return { ok: true, json: async () => ({ values: { nome_fiscal: null } }) };
    }, false, true);
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(state.name.textContent, '');
    assert.match(state.status.textContent, /Nenhum fiscal único/);
});

test('clears old inspector values and reports denied lookup without guessing a replacement', async () => {
    const state = setup(async () => ({ ok: false, status: 403 }));
    await state.change();
    assert.equal(state.name.textContent, '');
    assert.match(state.status.textContent, /Não foi possível/);
});
