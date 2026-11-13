import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/atas/create.blade.php', import.meta.url), 'utf8');
const funds = [{ id: 1, name: 'Educação' }, { id: 2, name: 'Saúde' }, { id: 3, name: 'Município' }];

test('products opens a modal with the current supplier and closes without changing the ata', () => {
    const open = view.match(/aria-controls="ata-products-dialog" @click="([^"]+)"/)[1];
    const close = view.match(/@click="([^"]+)" aria-label="Fechar cadastro de produtos"/)[1];
    const supplier = { value: 'Fornecedor <Teste>' };
    const dialog = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    const heading = { textContent: '' };
    const context = { $refs: { ataProducts: dialog, ataProductsSupplier: heading },
        $root: { querySelector: () => supplier } };

    runInNewContext(open, context);
    assert.equal(dialog.open, true);
    assert.equal(heading.textContent, supplier.value);
    runInNewContext(close, context);
    assert.equal(dialog.open, false);
    assert.equal(supplier.value, 'Fornecedor <Teste>');

    supplier.value = 'Outro fornecedor';
    runInNewContext(open, context);
    assert.equal(heading.textContent, 'Outro fornecedor');
    runInNewContext(close, context);
    supplier.value = '';
    runInNewContext(open, context);
    assert.equal(heading.textContent, 'Nome / Razão Social');
});

function editor({ fetch, initial } = {}) {
    const expression = view.match(/<div x-data="([\s\S]*?)" data-draft-state=/)[1].replace('@js($funds)', JSON.stringify(funds));
    let draftUrl;
    const state = runInNewContext(`(${expression})`, { fetch, URL, CustomEvent, window: {
        location: { href: 'http://localhost/atas/create' },
        history: { replaceState: (_, __, url) => { draftUrl = url; } },
    } });
    state.$el = { dataset: { draftToken: 'signed-draft', participantsUrl: '/atas/participants', csrfToken: 'csrf-token',
        draftState: JSON.stringify(initial || { version: 0, process: '', managing_fund_id: '', participants: [] }) } };
    state.$refs = { ataParticipantRemoval: { open: false, showModal() { this.open = true; }, close() { this.open = false; } } };
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
            onManagerChange?.(value);
        },
        participantIds: () => Array.from(state.participantFunds, fund => fund.id),
        draftUrl: () => draftUrl,
    };
}

test('a loaded ata cannot add or remove participants from the draft', async () => {
    let calls = 0;
    const form = editor({ fetch: async () => { calls++; } });
    form.state.loaded = true;
    form.state.participants = [{ id: 99, FUNDO: 'Participante salvo' }];
    await form.state.addParticipant();
    await form.state.removeParticipant(form.state.participants[0]);
    assert.equal(calls, 0);
    assert.equal(form.state.participants[0].FUNDO, 'Participante salvo');
});

test('participant selection is disabled until a saved ata is loaded', () => {
    assert.match(view, /id="ata-participant-fund"[^>]*disabled :disabled="busy \|\| !loaded"/);
});

test('loaded ata adds to its own endpoint and version without changing the draft', async () => {
    let request;
    const form = editor({ fetch: async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ version: 5, participants: [{ id: 21, fund_id: 2, FUNDO: 'Saúde' }] }) };
    } });
    const loaded = view.match(/@ata-loaded="([^"]+)"/)[1];
    form.state.$event = { detail: { id: 107, version: 4, process: 'PROC', managing_fund_id: '1', participants: [] } };
    runInNewContext(loaded, form.state);
    assert.equal(form.state.loaded, true);
    assert.equal(form.state.loadedAtaId, 107);
    form.state.version = 3;
    form.state.participantFund = '2';
    await form.state.addParticipant();
    assert.equal(request.url, '/atas/107/participants');
    assert.deepEqual(JSON.parse(request.options.body), { version: 4, participant_fund_id: '2' });
    assert.equal(form.state.loadedVersion, 5);
    assert.equal(form.state.version, 3);
    assert.equal(form.state.participants[0].FUNDO, 'Saúde');
    assert.equal(form.state.participantFund, '');
    assert.equal(form.state.busy, false);
    form.state.clearLoaded();
    assert.equal(form.state.loadedAtaId, 0);
    assert.equal(form.state.loadedVersion, 0);
});

function loadedEditor(fetcher) {
    const { state } = editor({ fetch: fetcher });
    state.loaded = true;
    state.loadedAtaId = 107;
    state.loadedVersion = 4;
    state.version = 2;
    const dialog = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    state.$refs.ataManagerReplacement = dialog;
    const events = [];
    state.$el.dispatchEvent = event => events.push(event);
    return { state, dialog, events };
}

test('saved participant delete targets the row with saved version and never alters draft version', async () => {
    let request;
    const { state } = loadedEditor(async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ version: 5, manager_changed: false, participants: [{ id: 1, Tipo_Fundo: 'Gerenciador' }] }) };
    });
    state.participants = [{ id: 1 }, { id: 2 }];
    await state.removeParticipant({ id: 2, Tipo_Fundo: 'Participante' });
    assert.equal(request, undefined);
    assert.equal(state.$refs.ataParticipantRemoval.open, true);
    await state.confirmParticipantRemoval();
    assert.equal(request.url, '/atas/107/participants/2');
    assert.equal(request.options.method, 'DELETE');
    assert.deepEqual(JSON.parse(request.options.body), { version: 4 });
    assert.equal(state.loadedVersion, 5);
    assert.equal(state.version, 2);
    assert.equal(state.participants.length, 1);
    assert.equal(state.busy, false);
});

test('cancel or Escape clears pending participant and cannot submit a deletion', async () => {
    let calls = 0;
    const { state } = loadedEditor(async () => { calls++; });
    const participant = { id: 2, fund_id: 2, FUNDO: 'Saúde', Tipo_Fundo: 'Participante' };
    state.participants = [participant];
    await state.removeParticipant(participant);
    assert.equal(calls, 0);
    state.$refs.ataParticipantRemoval.close();
    const close = view.match(/x-ref="ataParticipantRemoval"[^>]*@close="([^"]+)"/)[1];
    runInNewContext(close, state);
    await state.confirmParticipantRemoval();
    assert.equal(calls, 0);
    assert.equal(state.participants[0], participant);
    assert.equal(state.loadedVersion, 4);
});

test('deleting manager opens replacement dialog without any request and excludes that fund', async () => {
    let calls = 0;
    const { state, dialog } = loadedEditor(async () => { calls++; });
    const manager = { id: 7, fund_id: 1, Tipo_Fundo: 'Gerenciador' };
    await state.removeParticipant(manager);
    assert.equal(calls, 0);
    assert.equal(dialog.open, true);
    assert.equal(state.pendingRemoval, manager);
    assert.deepEqual(Array.from(state.replacementFunds, fund => fund.id), [2, 3]);
});

test('confirmed replacement sends selected fund and refreshes authorized ata after success', async () => {
    let payload;
    const { state, dialog, events } = loadedEditor(async (_, options) => {
        payload = JSON.parse(options.body);
        return { ok: true, json: async () => ({ version: 5, number: '107/25', manager_changed: true,
            participants: [{ id: 9, fund_id: 2, Tipo_Fundo: 'Gerenciador' }] }) };
    });
    const manager = { id: 7, fund_id: 1, Tipo_Fundo: 'Gerenciador' };
    await state.removeParticipant(manager);
    await state.removeSavedParticipant(state.pendingRemoval, '2');
    assert.deepEqual(payload, { version: 4, replacement_fund_id: '2' });
    assert.equal(dialog.open, false);
    assert.equal(state.pendingRemoval, null);
    assert.equal(state.loadedVersion, 5);
    assert.equal(events[0].type, 'ata-saved');
    assert.equal(events[0].bubbles, true);
    assert.equal(events[0].detail.number, '107/25');
});

test('server manager requirement opens dialog for historical row and failure preserves the row', async () => {
    const { state, dialog } = loadedEditor(async () => ({ ok: false, json: async () => ({
        errors: { replacement_fund_id: ['Uma ata salva não pode ficar sem Fundo Gerenciador.'] },
    }) }));
    const row = { id: 7, fund_id: 1, Tipo_Fundo: 'Participante' };
    state.participants = [row];
    await state.removeParticipant(row);
    await state.confirmParticipantRemoval();
    assert.equal(dialog.open, true);
    assert.equal(state.participants[0], row);
    assert.match(state.replacementMessage, /não pode ficar sem/);
    assert.equal(state.loadedVersion, 4);
    assert.equal(state.busy, false);
});

test('clearing a saved ata restores the current draft including participants added after page load', () => {
    const { state } = editor();
    state.process = 'RASCUNHO NOVO';
    state.managingFund = '2';
    state.version = 3;
    state.participants = [{ id: 5, FUNDO: 'Adicionado agora' }];
    state.clearLoaded();
    state.loaded = true;
    state.process = 'ATA SALVA';
    state.managingFund = '3';
    state.participants = [{ id: 100, FUNDO: 'Participante da ata' }];
    state.clearLoaded();
    assert.equal(state.process, 'RASCUNHO NOVO');
    assert.equal(state.managingFund, '2');
    assert.equal(state.participants[0].FUNDO, 'Adicionado agora');
    assert.equal(state.version, 3);
});

test('participants list all funds initially and include the current manager after each change', () => {
    const form = editor();
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    form.selectManager('2');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    form.selectManager('1');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
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
    assert.deepEqual(form.participantIds(), [1, 3]);
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
    assert.deepEqual(form.participantIds(), [1, 3]);
    const request = form.state.addParticipant();
    await form.state.addParticipant();
    assert.equal(calls, 1);
    finish({ ok: false, json: async () => ({ message: 'Conflito. Recarregue.' }) });
    await request;
    assert.equal(form.state.participants.length, 1);
    assert.equal(form.state.busy, false);
});

test('changing manager preserves the selection including the same fund', () => {
    const form = editor();
    form.state.participantFund = '2';
    form.selectManager('1');
    assert.equal(form.state.participantFund, '2');
    form.selectManager('2');
    assert.equal(form.state.participantFund, '2');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
});

test('delete sends the saved version and only removes rows after server confirmation', async () => {
    let sent;
    let ok = false;
    const participant = { id: 10, fund_id: 2 };
    const form = editor({ initial: { version: 1, process: '01/2026', managing_fund_id: '1', participants: [participant] },
        fetch: async (url, options) => {
            sent = { url, ...options };
            return { ok, json: async () => ok ? { version: 2, participants: [] } : { message: 'Conflito' } };
        } });
    await form.state.removeParticipant(participant);
    await form.state.confirmParticipantRemoval();
    assert.equal(form.state.participants.length, 1);
    assert.equal(form.state.version, 1);
    ok = true;
    await form.state.removeParticipant(participant);
    await form.state.confirmParticipantRemoval();
    assert.equal(sent.method, 'DELETE');
    assert.equal(sent.url, '/atas/participants/10');
    assert.equal(sent.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(JSON.parse(sent.body), { draft_token: 'signed-draft', version: 1 });
    assert.equal(form.state.participants.length, 0);
    assert.equal(form.state.version, 2);
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    assert.equal(form.state.busy, false);
});
