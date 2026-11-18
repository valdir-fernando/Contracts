import test from 'node:test';
import assert from 'node:assert/strict';
import { ataMissingFields, initializeAtaSave, initializeAtaDelete } from '../../resources/js/ata-save.js';

const complete = () => ({ managing_fund_id: '1', Data_ATA: '2026-11-13', admitir_adesao: 'NAO', ata_process: '123', bidding: 'Pregão',
    bidding_number: '12', fornecedor_documento_snapshot: '11.222.333/0001-81', fornecedor_nome_snapshot: 'Fornecedor',
    end_fornec: 'Rua', supplier_email: 'contato@example.test', supplier_mobile: '123', supplier_whatsapp: '',
    socio: 'Representante', cpf_socio: '123', rg_socio: '456' });
const manager = [{ label: 'CNPJ', value: '123' }, { label: 'Endereço', value: 'Rua' }];

test('reports every missing general field and manager field with its tab', () => {
    const missing = ataMissingFields({}, [{ label: 'CPF do Gestor', value: '  ' }]);
    assert.deepEqual(missing.filter(item => item.step === 0).map(item => item.label),
        ['Fundo Gerenciador', 'Data_ATA', 'Processo ATA', 'Licitação', 'Nº Licit']);
    assert.deepEqual(missing.find(item => item.step === 1), { group: 'Dados Gerenciador', step: 1, label: 'CPF do Gestor' });
});

test('PJ requires partner fields but does not require supplier RG or professional documents', () => {
    const values = complete();
    assert.deepEqual(ataMissingFields(values, manager), []);
    for (const [field, label] of Object.entries({ socio: 'Sócio / representante', cpf_socio: 'CPF do sócio', rg_socio: 'RG do sócio',
        fornecedor_nome_snapshot: 'Nome / Razão Social', end_fornec: 'Endereço do fornecedor', supplier_email: 'e-mail' })) {
        assert.ok(ataMissingFields({ ...values, [field]: ' ' }, manager).some(item => item.label === label));
    }
});

test('PF requires its RG and accepts absence of partner fields', () => {
    const values = { ...complete(), fornecedor_documento_snapshot: '529.982.247-25', socio: '', cpf_socio: '', rg_socio: '', rg_fornec: '123' };
    assert.deepEqual(ataMissingFields(values, manager), []);
    assert.equal(ataMissingFields({ ...values, rg_fornec: '' }, manager)[0].label, 'RG do fornecedor');
});

test('either mobile or WhatsApp is enough, but absence of both blocks saving', () => {
    const values = complete();
    assert.deepEqual(ataMissingFields({ ...values, supplier_mobile: '', supplier_whatsapp: '123' }, manager), []);
    assert.match(ataMissingFields({ ...values, supplier_mobile: '', supplier_whatsapp: ' ' }, manager)[0].label, /Celular ou WhatsApp/);
});

test('missing or malformed document cannot bypass supplier validation', () => {
    assert.ok(ataMissingFields({ ...complete(), fornecedor_documento_snapshot: '' }, manager).some(item => item.label === 'CPF/CNPJ do fornecedor'));
    assert.ok(ataMissingFields({ ...complete(), fornecedor_documento_snapshot: '123' }, manager).some(item => item.label.includes('válido')));
});

test('save opens suite warning and selects missing tab without continuing the operation', () => {
    let click;
    const events = [];
    const messages = [];
    const button = { disabled: false, addEventListener: (event, callback) => { click = callback; } };
    const values = complete();
    const root = { ownerDocument: {}, dataset: {},
        querySelector: selector => selector === '[data-ata-save]' ? button : { textContent: 'CPF do Gestor' },
        querySelectorAll: selector => selector.includes('input[name]')
            ? Object.entries(values).map(([name, value]) => ({ name, value })) : [{ id: 'manager', textContent: '' }],
        dispatchEvent: event => events.push(event),
    };
    initializeAtaSave(root, message => messages.push(message));
    click();
    assert.equal(events[0].detail.step, 1);
    assert.equal(events[0].bubbles, true);
    assert.match(messages[0], /Não é possível salvar.*Dados Gerenciador: CPF do Gestor/);
    button.disabled = true;
    click();
    assert.equal(messages.length, 1);
});

function savePanel(fetcher, confirm = async () => true) {
    let click;
    const events = [];
    const messages = [];
    const confirmations = [];
    const values = complete();
    values.Data_ATA = '13/11/2026';
    const button = { disabled: false, dataset: { version: '3', saveUrl: '/atas' },
        addEventListener: (event, callback) => { click = callback; } };
    const root = { ownerDocument: {}, dataset: { draftToken: 'encrypted-draft', csrfToken: 'token' },
        querySelector: selector => selector === '[data-ata-save]' ? button : { textContent: 'CPF do Gestor' },
        querySelectorAll: selector => selector.includes('input[name]')
            ? Object.entries(values).map(([name, value]) => ({ name, value })) : [{ id: 'manager', textContent: '52998224725' }],
        dispatchEvent: event => events.push(event),
    };
    initializeAtaSave(root, message => messages.push(message), fetcher, (...args) => { confirmations.push(args); return confirm(...args); });
    return { root, events, messages, confirmations, click: () => click() };
}

test('complete fields POST only references and process data, then load the issued number', async () => {
    let request;
    const panel = savePanel(async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ id: 7, number: '059/26', version: 1 }) };
    });
    await panel.click();
    assert.equal(request.url, '/atas');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'token');
    assert.deepEqual(JSON.parse(request.options.body), { draft_token: 'encrypted-draft', version: 3,
        managing_fund_id: '1', Data_ATA: '2026-11-13', ata_process: '123', bidding: 'Pregão', bidding_number: '12',
        admitir_adesao: 'NAO', start_date: null, end_date: null, validity_period: null, one_year_inpc: false,
        fornecedor_documento_snapshot: '11.222.333/0001-81' });
    assert.deepEqual(panel.events.map(event => event.type), ['ata-saving', 'ata-save-finished', 'ata-saved']);
    assert.equal(panel.events.at(-1).detail.number, '059/26');
    assert.equal(panel.messages[0], 'Ata 059/26 salva com sucesso.');
    assert.deepEqual(panel.confirmations, []);
});

test('double click cannot dispatch two pending saves and failures restore the button state', async () => {
    let release;
    let calls = 0;
    const pending = new Promise(resolve => { release = resolve; });
    const panel = savePanel(async () => { calls++; return pending; });
    const first = panel.click();
    await panel.click();
    assert.equal(calls, 1);
    release({ ok: false, json: async () => ({ errors: { supplier: ['Dados do Fornecedor: e-mail ausente.'] } }) });
    await first;
    assert.match(panel.messages[0], /e-mail ausente/);
    assert.equal(panel.events.at(-1).type, 'ata-save-finished');
    assert.equal(panel.events.some(event => event.type === 'ata-saved'), false);
    await panel.click();
    assert.equal(calls, 2);
});

test('network failure reports uncertainty and allows retry of the same draft', async () => {
    const panel = savePanel(async () => { throw new TypeError('Network'); });
    await panel.click();
    assert.match(panel.messages[0], /Não foi possível confirmar/);
    assert.equal(panel.events.at(-1).type, 'ata-save-finished');
});

test('loaded ATA uses PATCH and current ATA version without submitting masked snapshots or a draft token', async () => {
    let request;
    const panel = savePanel(async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 9 }) };
    });
    panel.root.dataset.ataLoaded = '7';
    panel.root.dataset.ataVersion = '8';
    panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
    await panel.click();
    assert.equal(request.url, '/atas/7');
    assert.equal(request.options.method, 'PATCH');
    const payload = JSON.parse(request.options.body);
    assert.equal(payload.version, 8);
    assert.equal(payload.bidding_number, '12');
    assert.equal(payload.Data_ATA, '2026-11-13');
    for (const key of ['draft_token', 'managing_fund_id', 'fornecedor_documento_snapshot']) assert.equal(key in payload, false);
    assert.equal(panel.messages[0], 'Ata 107/2025 alterada com sucesso.');
    assert.equal(panel.confirmations[0][0], 'Confirma a alteração dos dados desta ATA?');
});

test('pending operations and an unverified typed number cannot start a new registration', async () => {
    let calls = 0;
    const panel = savePanel(async () => { calls++; });
    panel.root.dataset.editorBusy = 'true';
    await panel.click();
    assert.equal(calls, 0);
    panel.root.dataset.editorBusy = 'false';
    panel.root.ownerDocument.querySelector = () => ({ value: '107/2025' });
    await panel.click();
    assert.equal(calls, 0);
    assert.match(panel.messages[0], /Consulte a ATA pelo número/);
});

test('stale edit response cannot reload a different ATA', async () => {
    let release;
    const panel = savePanel(() => new Promise(resolve => { release = resolve; }));
    panel.root.dataset.ataLoaded = '7';
    panel.root.dataset.ataVersion = '1';
    panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
    const pending = panel.click();
    await Promise.resolve();
    panel.root.dataset.ataLoaded = '8';
    release({ ok: true, json: async () => ({ id: 7, number: '107/2025', version: 2 }) });
    await pending;
    assert.equal(panel.events.some(event => event.type === 'ata-saved'), false);
});

test('cancel or a changed version during confirmation preserves the edit without sending PATCH', async () => {
    let calls = 0;
    const cancelled = savePanel(async () => { calls++; }, async () => false);
    cancelled.root.dataset.ataLoaded = '7';
    cancelled.root.dataset.ataVersion = '1';
    await cancelled.click();
    assert.equal(calls, 0);
    assert.equal(cancelled.events.at(-1).type, 'ata-save-finished');
    assert.deepEqual(cancelled.messages, []);
    let accept;
    const panel = savePanel(async () => { calls++; }, () => new Promise(resolve => { accept = resolve; }));
    panel.root.dataset.ataLoaded = '7';
    panel.root.dataset.ataVersion = '1';
    const pending = panel.click();
    await panel.click();
    assert.equal(panel.confirmations.length, 1);
    panel.root.dataset.ataVersion = '2';
    accept(true);
    await pending;
    assert.equal(calls, 0);
    assert.equal(panel.events.at(-1).type, 'ata-save-finished');
});

test('edit failures report amendment restrictions or the access and version reason without a success event', async () => {
    for (const [status, body, expected] of [
        [422, { errors: { ata_process: ['O Processo só pode mudar se não houver aditivos vinculados. Esta ATA possui aditivos vinculados.'] } }, /possui aditivos vinculados/],
        [403, { message: 'Forbidden' }, /não tem permissão/],
        [409, { message: 'Conflict' }, /alterada por outra operação/],
        [500, { message: 'Internal SQL error' }, /falha no sistema/],
    ]) {
        const panel = savePanel(async () => ({ ok: false, status, json: async () => body }));
        panel.root.dataset.ataLoaded = '7';
        panel.root.dataset.ataVersion = '1';
        panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
        await panel.click();
        assert.match(panel.messages[0], expected);
        assert.equal(panel.events.some(event => event.type === 'ata-saved'), false);
        assert.equal(panel.events.at(-1).type, 'ata-save-finished');
    }
});

function deletePanel(confirm, fetcher) {
    let click;
    const events = [], messages = [];
    const button = { dataset: { deleteUrl: '/atas/0' }, addEventListener: (event, handler) => { click = handler; } };
    const root = { ownerDocument: {}, dataset: { ataLoaded: '7', ataVersion: '4', csrfToken: 'token' },
        querySelector: () => button, dispatchEvent: event => events.push(event) };
    initializeAtaDelete(root, confirm, message => messages.push(message), fetcher);
    return { root, events, messages, click: () => click() };
}

test('confirmed delete uses saved identity and version and clears the panel only after success', async () => {
    let request;
    const panel = deletePanel(async () => true, async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 5 }) };
    });
    await panel.click();
    assert.equal(request.url, '/atas/7');
    assert.equal(request.options.method, 'DELETE');
    assert.deepEqual(JSON.parse(request.options.body), { version: 4 });
    assert.deepEqual(panel.events.map(event => event.type), ['ata-saving', 'ata-deleted', 'ata-save-finished']);
    assert.deepEqual(panel.messages, []);
});

test('cancelled delete, missing ATA, changed context and duplicate clicks do not send deletion', async () => {
    let calls = 0;
    const cancelled = deletePanel(async () => false, async () => { calls++; });
    await cancelled.click();
    cancelled.root.dataset.ataLoaded = '';
    await cancelled.click();
    let accept;
    const panel = deletePanel(() => new Promise(resolve => { accept = resolve; }), async () => { calls++; });
    const pending = panel.click();
    await panel.click();
    panel.root.dataset.ataVersion = '5';
    accept(true);
    await pending;
    assert.equal(calls, 0);
    assert.deepEqual(panel.events, []);
});

test('delete conflict keeps the current panel and reports the failure', async () => {
    const panel = deletePanel(async () => true, async () => ({ ok: false, json: async () => ({ message: 'Consulte novamente.' }) }));
    await panel.click();
    assert.equal(panel.events.some(event => event.type === 'ata-deleted'), false);
    assert.equal(panel.messages[0], 'Consulte novamente.');
    assert.equal(panel.events.at(-1).type, 'ata-save-finished');
});
