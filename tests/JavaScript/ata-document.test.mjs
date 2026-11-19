import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaExtract } from '../../resources/js/ata-extract.js';

function fixture(chooseFormat, fetcher = async () => ({ ok: true,
    headers: new Headers({ 'Content-Type': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }), blob: async () => 'word' }), chooseLayout = async () => 'continuous') {
    const root = { dataset: { ataLoaded: '12', ataVersion: '3' }, ownerDocument: { baseURI: 'https://suite.test/' }, dispatchEvent() {} };
    const buttons = [0, 1].map(() => ({ dataset: { ataDocumentUrl: '/atas/0/document' }, addEventListener(type, listener) { this[type] = listener; } }));
    const requests = [], opened = [], warnings = [];
    initializeAtaExtract(root, buttons, async url => { requests.push(url); return fetcher(url); },
        message => warnings.push(message), (...args) => opened.push(args), 'ataDocumentUrl', undefined, chooseFormat, chooseLayout);
    return { root, buttons, requests, opened, warnings, click: (index = 0) => buttons[index].click() };
}

test('Word selection requests docx and delivers it to the download handler', async () => {
    const ui = fixture(async () => 'docx');
    await ui.click();
    assert.equal(ui.requests[0].pathname, '/atas/12/document');
    assert.equal(ui.requests[0].searchParams.get('version'), '3');
    assert.equal(ui.requests[0].searchParams.get('format'), 'docx');
    assert.equal(ui.requests[0].searchParams.get('products_layout'), 'continuous');
    assert.equal(ui.opened[0][2], 'docx');
    assert.deepEqual(ui.warnings, []);
});

test('format is followed by layout selection and the chosen layout is requested', async () => {
    const steps = [];
    const ui = fixture(async () => { steps.push('format'); return 'docx'; }, undefined,
        async () => { steps.push('layout'); return 'parallel'; });
    await ui.click();
    assert.deepEqual(steps, ['format', 'layout']);
    assert.equal(ui.requests[0].searchParams.get('products_layout'), 'parallel');
});

test('canceling the second dialog or changing context during it does not issue', async () => {
    const canceled = fixture(async () => 'pdf', undefined, async () => null);
    await canceled.click();
    assert.deepEqual(canceled.requests, []);
    let finish;
    const ui = fixture(async () => 'docx', undefined, () => new Promise(resolve => { finish = resolve; }));
    const pending = ui.click();
    await Promise.resolve();
    await ui.click(1);
    ui.root.dataset.ataLoaded = '99';
    finish('parallel');
    await pending;
    assert.deepEqual(ui.requests, []);
});

test('PDF selection preserves PDF delivery', async () => {
    const ui = fixture(async () => 'pdf', async () => ({ ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => 'pdf' }));
    await ui.click();
    assert.equal(ui.requests[0].searchParams.get('format'), 'pdf');
    assert.equal(ui.opened[0][2], 'pdf');
});

test('canceling the dialog never requests a document', async () => {
    const ui = fixture(async () => null);
    await ui.click();
    assert.deepEqual(ui.requests, []);
});

test('the two ATA buttons share the pending dialog and reject changed context', async () => {
    let finish;
    let choices = 0;
    const ui = fixture(() => { choices++; return new Promise(resolve => { finish = resolve; }); });
    const pending = ui.click();
    await ui.click(1);
    assert.equal(choices, 1);
    ui.root.dataset.ataVersion = '4';
    finish('docx');
    await pending;
    assert.deepEqual(ui.requests, []);
});

test('changes or concurrent operations while choosing require a new attempt', async () => {
    for (const key of ['editorBusy', 'ataValidityDirty']) {
        const ui = fixture(async () => { ui.root.dataset[key] = 'true'; return 'docx'; });
        await ui.click();
        assert.deepEqual(ui.requests, []);
        assert.match(ui.warnings[0], /dados mudaram/);
    }
});

test('HTML and PDF cannot be downloaded as a Word document', async () => {
    for (const type of ['text/html', 'application/pdf']) {
        const ui = fixture(async () => 'docx', async () => ({ ok: true, headers: new Headers({ 'Content-Type': type }) }));
        await ui.click();
        assert.deepEqual(ui.opened, []);
        assert.equal(ui.warnings.length, 1);
        assert.equal(ui.buttons[0].disabled, false);
    }
});
