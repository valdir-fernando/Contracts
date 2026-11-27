import test from 'node:test';
import assert from 'node:assert/strict';
import { ataMissingFields, initializeAtaSave, initializeAtaDelete, initializeAtaAmendmentDelete, initializeAtaAmendmentSave } from '../../resources/js/ata-save.js';

const complete = () => ({ managing_fund_id: '1', Data_ATA: '2026-11-13', admitir_adesao: 'NAO', ata_process: '123', bidding: 'Pregão',
    bidding_number: '12', Data_Orçamento: '20/10/2026', fornecedor_documento_snapshot: '11.222.333/0001-81', fornecedor_nome_snapshot: 'Fornecedor',
    end_fornec: 'Rua', supplier_email: 'contato@example.test', supplier_mobile: '123', supplier_whatsapp: '',
    socio: 'Representante', cpf_socio: '123', rg_socio: '456' });
const manager = [{ label: 'CNPJ', value: '123' }, { label: 'Endereço', value: 'Rua' }];

test('reports every missing general field and manager field with its tab', () => {
    const missing = ataMissingFields({}, [{ label: 'CPF do Gestor', value: '  ' }]);
    assert.deepEqual(missing.filter(item => item.step === 0).map(item => item.label),
        ['Fundo Gerenciador', 'Data_ATA', 'Processo ATA', 'Licitação', 'Nº Licit', 'Data_Orçamento Estimado']);
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
    return { root, values, events, messages, confirmations, click: () => click() };
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
        Data_Orçamento: '2026-10-20',
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
    assert.equal(payload.Data_Orçamento, '2026-10-20');
    for (const key of ['draft_token', 'managing_fund_id', 'fornecedor_documento_snapshot']) assert.equal(key in payload, false);
    assert.equal(panel.messages[0], 'Ata 107/2025 alterada com sucesso.');
    assert.equal(panel.confirmations[0][0], 'Confirma a alteração dos dados desta ATA?');
});

test('saving another field preserves the amended legacy end unless the displayed original end was edited', async () => {
    const payloads = [];
    const panel = savePanel(async (url, options) => {
        payloads.push(JSON.parse(options.body));
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 9 }) };
    });
    Object.assign(panel.root.dataset, { ataLoaded: '7', ataVersion: '8', ataDerivedEndDate: '2026-06-02' });
    panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
    panel.values.end_date = '02/06/2026';
    await panel.click();
    assert.equal('end_date' in payloads[0], false);
    panel.values.end_date = '03/06/2026';
    await panel.click();
    assert.equal(payloads[1].end_date, '2026-06-03');
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

test('a changed CPF or CNPJ on a saved ATA sends only the new supplier document', async () => {
    let payload;
    const panel = savePanel(async (_url, options) => {
        payload = JSON.parse(options.body);
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 9 }) };
    });
    Object.assign(panel.root.dataset, { ataLoaded: '7', ataVersion: '8', ataSupplierDocument: '•••0000' });
    panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
    panel.values.fornecedor_documento_snapshot = '529.982.247-25';
    await panel.click();
    assert.equal(payload.fornecedor_documento_snapshot, '529.982.247-25');
    assert.equal('fornecedor_nome_snapshot' in payload, false);
    assert.equal('supplier_email' in payload, false);
});

test('the main save sends the publication date and a published ATA cannot be saved again', async () => {
    const requests = [];
    const panel = savePanel(async (_url, options) => {
        requests.push(JSON.parse(options.body));
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 9 }) };
    });
    Object.assign(panel.root.dataset, { ataLoaded: '7', ataVersion: '8' });
    panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
    panel.values.publication_date = '20/11/2026';
    await panel.click();
    assert.equal(requests[0].publication_date, '2026-11-20');
    panel.root.dataset.ataPublicationLocked = 'true';
    await panel.click();
    assert.equal(requests.length, 1);
});

test('the main save sends the annual index type and can clear a saved choice', async () => {
    const requests = [];
    const panel = savePanel(async (_url, options) => {
        requests.push({ method: options.method, payload: JSON.parse(options.body) });
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 1 }) };
    });
    panel.values.annual_index_type = 'IGP-M';
    await panel.click();
    assert.equal(requests[0].method, 'POST');
    assert.equal(requests[0].payload.annual_index_type, 'IGP-M');

    Object.assign(panel.root.dataset, { ataLoaded: '7', ataVersion: '1' });
    panel.root.querySelector('[data-ata-save]').dataset.updateUrl = '/atas/0';
    panel.values.annual_index_type = '';
    await panel.click();
    assert.equal(requests[1].method, 'PATCH');
    assert.equal(requests[1].payload.annual_index_type, null);
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

test('amendment deletion requires confirmation and refreshes the same ATA after success', async () => {
    let click, request, accepted = false;
    const events = [];
    const button = { dataset: { deleteUrl: '/atas/0/amendments/0', amendmentNumber: '2' },
        addEventListener: (event, handler) => { click = handler; } };
    const root = { ownerDocument: {}, dataset: { ataLoaded: '7', ataVersion: '4', csrfToken: 'token' },
        querySelector: () => button, dispatchEvent: event => events.push(event) };
    const saved = { id: 7, number: '107/2025', version: 5 };
    initializeAtaAmendmentDelete(root, async message => {
        assert.match(message, /2/);
        return accepted;
    }, assert.fail, async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => saved };
    });
    await click();
    assert.equal(request, undefined);
    assert.deepEqual(events, []);

    accepted = true;
    await click();
    assert.equal(request.url, '/atas/7/amendments/2');
    assert.equal(request.options.method, 'DELETE');
    assert.deepEqual(JSON.parse(request.options.body), { version: 4 });
    assert.deepEqual(events.map(event => event.type), ['ata-saving', 'ata-saved', 'ata-save-finished']);
    assert.deepEqual(events[1].detail, saved);
});

test('amendment save warns for missing fields, retains them after success and edits on the next save', async () => {
    let click, request, blurEnd;
    const events = [], messages = [];
    const values = { number: '', date: '', process: '', type: '', start: '', end: '', period: '' };
    const advantage = { required: false, disabled: true, value: '' };
    const annualIndex = { required: false, disabled: true, value: '' };
    const finalField = { dataset: { amendmentEndLimit: '2027-11-07' }, get value() { return values.end; },
        addEventListener: (event, handler) => { if (event === 'blur') blurEnd = handler; } };
    const button = { dataset: { saveUrl: '/atas/0/amendments' },
        addEventListener: (event, handler) => { click = handler; } };
    const root = { ownerDocument: {}, dataset: { ataLoaded: '7', ataVersion: '4', csrfToken: 'token' },
        querySelector: selector => {
            if (selector === '[data-ata-amendment-save]') return button;
            if (selector === '#ata-amend-advantage') return advantage;
            if (selector === '#ata-amend-annual-index') return annualIndex;
            if (selector === '#ata-amend-end') return finalField;
            return { value: values[selector.slice('#ata-amend-'.length)] };
        },
        dispatchEvent: event => {
            events.push(event);
            if (event.type === 'ata-amendment-saved') {
                button.dataset.savedAmendmentNumber = String(event.detail.amendment_number);
                root.dataset.ataVersion = String(event.detail.version);
            }
        } };
    initializeAtaAmendmentSave(root, message => messages.push(message), async (url, options) => {
        request = { url, options };
        const payload = JSON.parse(options.body);
        return { ok: true, json: async () => ({ id: 7, number: '107/2025', version: 5,
            amendment_number: 2, amendment_updated_total: '1234.5678', original_end_date: '2026-11-06',
            ...(payload.excluded_product_ids ? { amendment: { excluded_product_ids: payload.excluded_product_ids,
                balance_products: payload.balance_quantities } } : {}) }) };
    });
    await click();
    assert.equal(request, undefined);
    assert.match(messages[0], /Nº Aditivo, Data_ADT, Proc_ADT, Tipo_ADT, INICIO, FINAL, Período/);

    Object.assign(values, { number: '002', date: '05/11/2026', process: 'P-2', type: 'Prorrogação - Saldo Positivo',
        start: '07/11/2026', end: '06/11/2027', period: '12 meses' });
    Object.assign(advantage, { required: true, disabled: false });
    await click();
    assert.equal(request, undefined);
    assert.match(messages.at(-1), /Vantajosidade/);
    advantage.value = 'Declaração do Gestor';
    values.end = '08/11/2027';
    blurEnd();
    assert.match(messages.at(-1), /até 07\/11\/2027/);
    await click();
    assert.equal(request, undefined);
    values.end = '06/11/2027';
    button.dataset.excludedProducts = '["8"]';
    button.dataset.balanceQuantities = '[{"product_id":"31","quantity":"2,"}]';
    await click();
    assert.equal(request.url, '/atas/7/amendments');
    assert.equal(request.options.method, 'POST');
    assert.deepEqual(JSON.parse(request.options.body), { ...values, number: '2', date: '2026-11-05',
        start: '2026-11-07', end: '2027-11-06', version: 4, advantage: 'Declaração do Gestor', excluded_product_ids: ['8'],
        balance_quantities: [{ product_id: '31', quantity: '2,0' }] });
    assert.deepEqual(events.map(event => event.type), ['ata-saving', 'ata-amendment-saved', 'ata-save-finished']);
    assert.equal(events[1].detail.amendment_updated_total, '1.234,5678');
    assert.equal(root.dataset.ataDerivedEndDate, '2026-11-06');
    assert.equal(values.number, '002');
    assert.equal(values.process, 'P-2');
    assert.equal(messages.at(-1), 'Aditivo salvo com sucesso. Saldos gravados (1). Itens excluídos da prorrogação (1).');

    values.process = 'P-2-EDITADO';
    await click();
    assert.equal(request.url, '/atas/7/amendments/2');
    assert.equal(request.options.method, 'PATCH');
    assert.equal(JSON.parse(request.options.body).version, 5);
    assert.equal(JSON.parse(request.options.body).process, 'P-2-EDITADO');
    assert.equal(messages.at(-1), 'Aditivo atualizado com sucesso. Saldos gravados (1). Itens excluídos da prorrogação (1).');

    values.type = 'Prorrogação/Quantitativo - Igual Período e Valor';
    delete button.dataset.excludedProducts;
    delete button.dataset.balanceQuantities;
    advantage.value = '';
    request = undefined;
    await click();
    assert.equal(request, undefined);
    assert.match(messages.at(-1), /Vantajosidade/);
    advantage.value = 'Pesquisa de Preços';
    await click();
    assert.equal(JSON.parse(request.options.body).advantage, 'Pesquisa de Preços');

    values.type = 'Prorrogação/Quantitativo/Reajuste Anual INDICE';
    Object.assign(annualIndex, { required: true, disabled: false });
    request = undefined;
    await click();
    assert.equal(request, undefined);
    assert.match(messages.at(-1), /Indice_Anual/);
    annualIndex.value = '12,256%';
    button.dataset.excludedProducts = '["8"]';
    button.dataset.balanceQuantities = '[{"product_id":"31","quantity":"2,0001","adjusted_unit_price":"3,"}]';
    await click();
    assert.equal(JSON.parse(request.options.body).annual_index, '12,256');
    assert.deepEqual(JSON.parse(request.options.body).balance_quantities, [{ product_id: '31', quantity: '2,0001', adjusted_unit_price: '3,0' }]);
    assert.match(messages.at(-1), /Quantidades gravadas \(1\)/);
});
