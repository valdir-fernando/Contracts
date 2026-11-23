import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaAmendmentDocument, initializeAtaExtract } from '../../resources/js/ata-extract.js';

test('Aditivo downloads the saved amendment Word and discards responses for a different selection', async () => {
    const button = { dataset: { amendmentNumber: '2', ataAmendmentDocumentUrl: '/atas/0/amendments/0/document' },
        addEventListener(name, callback) { this.click = callback; } };
    const root = { dataset: { ataLoaded: '81', ataVersion: '3' }, ownerDocument: { baseURI: 'http://contracts.test/' },
        querySelector: () => button, dispatchEvent() {} };
    const downloads = [];
    let respond;
    let layout = null;
    let format = null;
    let layoutChoices = 0;
    initializeAtaAmendmentDocument(root, url => {
        assert.equal(url.pathname, '/atas/81/amendments/2/document');
        assert.equal(url.searchParams.get('version'), '3');
        assert.equal(url.searchParams.get('products_layout'), 'parallel');
        assert.equal(url.searchParams.get('format'), format);
        return new Promise(resolve => { respond = resolve; });
    }, message => assert.fail(message), (blob, ownerDocument, browser, filename) => downloads.push([blob, filename]),
    async () => { layoutChoices++; return layout; }, async () => format);
    const word = { ok: true, headers: new Headers({
        'Content-Type': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'Content-Disposition': "attachment; filename=ADT_002_Comercio_Sul_ATA_107-2025.docx; filename*=utf-8''ADT_002_Com%C3%A9rcio_Sul_ATA_107-2025.docx",
    }),
        blob: async () => 'WORD-BLOB' };
    await button.click();
    assert.equal(layoutChoices, 0);
    format = 'docx';
    await button.click();
    assert.equal(respond, undefined);
    layout = 'parallel';
    const first = button.click();
    await Promise.resolve();
    await Promise.resolve();
    respond(word);
    await first;
    const second = button.click();
    await Promise.resolve();
    await Promise.resolve();
    button.dataset.amendmentNumber = '4';
    respond(word);
    await second;
    assert.deepEqual(downloads, [['WORD-BLOB', 'ADT_002_Comércio_Sul_ATA_107-2025.docx']]);
    button.dataset.amendmentNumber = '2';
    format = 'pdf';
    const pdf = button.click();
    await Promise.resolve();
    await Promise.resolve();
    respond({ ok: true, headers: new Headers({ 'Content-Type': 'application/pdf',
        'Content-Disposition': 'attachment; filename=ADT_002_Comercio_Sul_ATA_107-2025.pdf' }), blob: async () => 'PDF-BLOB' });
    await pdf;
    assert.deepEqual(downloads[1], ['PDF-BLOB', 'ADT_002_Comercio_Sul_ATA_107-2025.pdf']);
});

function fixture(fetcher) {
    const buttons = [0, 1].map(() => ({ dataset: { ataExtractUrl: '/atas/0/extract' }, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; } }));
    const warnings = [], opened = [], events = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', editorBusy: 'false' },
        ownerDocument: { baseURI: 'http://contracts.test/' }, dispatchEvent: event => events.push(event) };
    initializeAtaExtract(root, buttons, fetcher, message => warnings.push(message), blob => opened.push(blob));
    return { root, buttons, warnings, opened, events, click: (index = 0) => buttons[index].listeners.click() };
}

const success = () => ({ ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => 'PDF-BLOB' });

test('both extract buttons fetch the saved ATA and version before opening the PDF', async () => {
    const requests = [];
    const ui = fixture(async (url, options) => { requests.push({ url, options }); return success(); });
    await ui.click(0);
    await ui.click(1);
    assert.equal(requests.length, 2);
    assert.equal(requests[0].url.pathname, '/atas/81/extract');
    assert.equal(requests[0].url.searchParams.get('version'), '3');
    assert.equal(requests[0].options.cache, 'no-store');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.deepEqual(ui.opened, ['PDF-BLOB', 'PDF-BLOB']);
    assert.deepEqual(ui.warnings, []);
    assert.deepEqual(ui.events.map(event => event.type), ['ata-product-saving', 'ata-product-finished', 'ata-product-saving', 'ata-product-finished']);
});

test('no saved ATA or a busy editor explains why issuing is unavailable', async () => {
    const ui = fixture(() => assert.fail('Should not request an unavailable ATA'));
    ui.root.dataset.ataLoaded = '';
    await ui.click();
    assert.match(ui.warnings[0], /Salve ou consulte/);
    ui.root.dataset.ataLoaded = '81';
    ui.root.dataset.editorBusy = 'true';
    await ui.click();
    assert.match(ui.warnings[1], /Aguarde/);
});

test('changed validity must be saved before issuing the extract', async () => {
    const ui = fixture(() => assert.fail('Cannot issue using unsaved validity'));
    ui.root.dataset.ataValidityDirty = 'true';
    await ui.click();
    assert.match(ui.warnings[0], /Salve a vigência/);
});

test('parallel clicks on the two buttons share the same issuance lock', async () => {
    let complete;
    let requests = 0;
    const ui = fixture(() => { requests++; return new Promise(resolve => { complete = resolve; }); });
    const pending = ui.click();
    await ui.click(1);
    assert.equal(requests, 1);
    complete(success());
    await pending;
    assert.equal(ui.buttons[0].disabled, false);
});

test('permission, session, conflicts and server errors appear as plain messages without server internals', async () => {
    for (const [status, reason] of [[401, /sessão terminou/], [403, /permissão/], [409, /mudaram/], [500, /problema ao gerar/]]) {
        const ui = fixture(async () => ({ ok: false, status, json: async () => ({ message: 'SQLSTATE SECRET' }) }));
        await ui.click();
        assert.match(ui.warnings[0], reason);
        assert.doesNotMatch(ui.warnings[0], /SQLSTATE|SECRET/);
        assert.deepEqual(ui.opened, []);
        assert.equal(ui.buttons[0].disabled, false);
    }
});

test('network errors and non-PDF responses do not open an invalid extract', async () => {
    for (const fetcher of [async () => { throw new TypeError(); }, async () => ({ ok: true, headers: new Headers({ 'Content-Type': 'text/html' }) })]) {
        const ui = fixture(fetcher);
        await ui.click();
        assert.match(ui.warnings[0], /Não foi possível abrir/);
        assert.deepEqual(ui.opened, []);
    }
});

test('missing manager identification displays the correction message and does not open a PDF', async () => {
    const warning = 'Informe um documento nos dados do gestor.';
    const ui = fixture(async () => ({
        ok: false, status: 422, json: async () => ({ errors: { manager_document: [warning] } }),
    }));
    await ui.click();
    assert.deepEqual(ui.warnings, [warning]);
    assert.deepEqual(ui.opened, []);
});

test('responses from a previously loaded ATA are ignored', async () => {
    let complete;
    const ui = fixture(() => new Promise(resolve => { complete = resolve; }));
    const pending = ui.click();
    ui.root.dataset.ataLoaded = '82';
    complete(success());
    await pending;
    assert.deepEqual(ui.opened, []);
    assert.deepEqual(ui.warnings, []);
});
