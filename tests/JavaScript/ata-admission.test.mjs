import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaAdmission } from '../../resources/js/ata-admission.js';

function fixture(fetcher) {
    const select = { value: '', dataset: { ataAdmissionUrl: '/atas/0/admission' }, listeners: {},
        addEventListener(name, handler) { this.listeners[name] = handler; } };
    const events = [], warnings = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', csrfToken: 'csrf' }, ownerDocument: {}, listeners: {},
        querySelector: () => select, dispatchEvent(event) { events.push(event); },
        addEventListener(name, handler) { this.listeners[name] = handler; } };
    initializeAtaAdmission(root, fetcher, message => warnings.push(message));
    root.listeners['ata-loaded']({ detail: { admitir_adesao: 'NAO' } });
    select.value = 'SIM';
    return { root, select, events, warnings, change: () => select.listeners.change() };
}

test('loaded ata automatically saves a new admission value and refreshes its projection', async () => {
    let request;
    const ui = fixture(async (url, options) => { request = { url, options }; return { ok: true,
        json: async () => ({ id: 81, number: '107/2025', version: 4, admitir_adesao: 'SIM' }) }; });
    await ui.change();
    assert.equal(request.url, '/atas/81/admission');
    assert.equal(request.options.method, 'PATCH');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.deepEqual(JSON.parse(request.options.body), { version: 3, admitir_adesao: 'SIM' });
    assert.deepEqual(ui.events.map(event => event.type), ['ata-saving', 'ata-save-finished', 'ata-saved']);
    assert.equal(ui.select.disabled, false);
    assert.match(ui.warnings[0], /salva com sucesso/);
});

test('draft changes do not send requests and duplicate pending changes cannot update twice', async () => {
    let calls = 0, release;
    const ui = fixture(() => { calls++; return new Promise(resolve => { release = resolve; }); });
    ui.root.dataset.ataLoaded = '';
    await ui.change();
    assert.equal(calls, 0);
    ui.root.dataset.ataLoaded = '81';
    const pending = ui.change();
    await ui.change();
    assert.equal(calls, 1);
    release({ ok: true, json: async () => ({ id: 81, number: '107/2025', version: 4, admitir_adesao: 'SIM' }) });
    await pending;
});

test('failed updates restore previous value and report plain errors without technical details', async () => {
    for (const [status, message] of [[403, /permissão/], [409, /ata foi alterada/], [422, /SIM ou NÃO/], [500, /problema ao salvar/]]) {
        const ui = fixture(async () => ({ ok: false, status, json: async () => ({ message: 'SQLSTATE tb_ATA' }) }));
        await ui.change();
        assert.equal(ui.select.value, 'NAO');
        assert.equal(ui.select.disabled, false);
        assert.match(ui.warnings[0], message);
        assert.doesNotMatch(ui.warnings[0], /SQLSTATE|tb_ATA/);
        assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
    }
    const ui = fixture(async () => { throw new TypeError('Network'); });
    await ui.change();
    assert.equal(ui.select.value, 'NAO');
    assert.match(ui.warnings[0], /Não foi possível confirmar/);
});

test('old responses cannot overwrite another ata selection', async () => {
    let release;
    const ui = fixture(() => new Promise(resolve => { release = resolve; }));
    const pending = ui.change();
    ui.root.dataset.ataLoaded = '82';
    ui.select.value = 'NAO';
    release({ ok: true, json: async () => ({ admitir_adesao: 'SIM' }) });
    await pending;
    assert.equal(ui.select.value, 'NAO');
    assert.deepEqual(ui.warnings, []);
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
});
