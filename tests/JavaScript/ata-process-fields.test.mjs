import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/atas/create.blade.php', import.meta.url), 'utf8');
const funds = [{ id: 1, name: 'Educação' }, { id: 2, name: 'Saúde' }, { id: 3, name: 'Município' }];

function editor({ fetch, initial } = {}) {
    const expression = view.match(/<div x-data="([\s\S]*?)" data-draft-state=/)[1].replace('@js($funds)', JSON.stringify(funds));
    let draftUrl;
    const state = runInNewContext(`(${expression})`, { fetch, URL, window: {
        location: { href: 'http://localhost/atas/create' },
        history: { replaceState: (_, __, url) => { draftUrl = url; } },
    } });
    state.$el = { dataset: { draftToken: 'signed-draft', participantsUrl: '/atas/participants', csrfToken: 'csrf-token',
        draftState: JSON.stringify(initial || { version: 0, process: '', managing_fund_id: '', participants: [] }) } };
    let onManagerChange;
    state.$watch = (property, callback) => {
        assert.equal(property, 'managingFund');
        onManagerChange = callback;
    };
    state.init();
    return {
        state,
        selectManager(value) {
            state.managingFund = value;
            onManagerChange(value);
        },
        participantIds: () => Array.from(state.participantFunds, fund => fund.id),
        draftUrl: () => draftUrl,
    };
}

test('participants list all funds initially and exclude the current manager after each change', () => {
    const form = editor();
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    form.selectManager('2');
    assert.deepEqual(form.participantIds(), [1, 3]);
    form.selectManager('1');
    assert.deepEqual(form.participantIds(), [2, 3]);
    form.selectManager('');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
});

test('add sends only identifiers and process with CSRF and renders confirmed server rows', async () => {
    let sent;
    const participant = { id: 10, fund_id: 2, ATA: null, Processo_ATA: '01/2026', CNPJ_Fundo: '11222333000181', FUNDO: 'Saúde', Tipo_Fundo: 'Participante' };
    const form = editor({ fetch: async (url, options) => {
        sent = { url, ...options };
        return { ok: true, json: async () => ({ version: 1, process: '01/2026', managing_fund_id: '1', participants: [participant] }) };
    } });
    assert.equal(form.draftUrl().searchParams.get('draft'), 'signed-draft');
    form.selectManager('1');
    form.state.process = '01/2026';
    form.state.participantFund = '2';
    // Alpine's $el during a click is the button, rather than the root component.
    form.state.$el = { dataset: {} };
    await form.state.addParticipant();
    assert.deepEqual(JSON.parse(sent.body), { draft_token: 'signed-draft', version: 0,
        ata_process: '01/2026', managing_fund_id: '1', participant_fund_id: '2' });
    assert.equal(sent.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(sent.method, 'POST');
    assert.equal(sent.url, '/atas/participants');
    assert.equal(form.state.version, 1);
    assert.equal(form.state.participants[0].ATA, null);
    assert.deepEqual(form.participantIds(), [3]);
    assert.equal(form.state.participantFund, '');
    assert.equal(form.state.busy, false);
});

test('validation and network failures preserve the selection and never invent a saved row', async () => {
    let failNetwork = false;
    const form = editor({ fetch: async () => {
        if (failNetwork) throw new Error('network');
        return { ok: false, json: async () => ({ errors: { participant_fund_id: ['Fundo duplicado.'] } }) };
    } });
    form.state.participantFund = '2';
    await form.state.addParticipant();
    assert.equal(form.state.message, 'Fundo duplicado.');
    assert.equal(form.state.participants.length, 0);
    assert.equal(form.state.participantFund, '2');
    failNetwork = true;
    await form.state.addParticipant();
    assert.match(form.state.message, /Recarregue/);
    assert.equal(form.state.participants.length, 0);
    assert.equal(form.state.busy, false);
});

test('pending add blocks repeated clicks and loaded drafts retain their participants', async () => {
    let calls = 0;
    let finish;
    const initial = { version: 1, process: '01/2026', managing_fund_id: '1', participants: [{ id: 10, fund_id: 2 }] };
    const form = editor({ initial, fetch: () => {
        calls++;
        return new Promise(resolve => { finish = resolve; });
    } });
    assert.equal(form.state.version, 1);
    assert.deepEqual(form.participantIds(), [3]);
    const request = form.state.addParticipant();
    await form.state.addParticipant();
    assert.equal(calls, 1);
    finish({ ok: false, json: async () => ({ message: 'Conflito. Recarregue.' }) });
    await request;
    assert.equal(form.state.participants.length, 1);
    assert.equal(form.state.busy, false);
});

test('changing manager clears a conflicting participant and preserves a different participant', () => {
    const form = editor();
    form.state.participantFund = '2';
    form.selectManager('1');
    assert.equal(form.state.participantFund, '2');
    form.selectManager('2');
    assert.equal(form.state.participantFund, '');
    assert.deepEqual(form.participantIds(), [1, 3]);
});
