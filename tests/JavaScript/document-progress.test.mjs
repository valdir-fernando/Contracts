import test from 'node:test';
import assert from 'node:assert/strict';
import { startDocumentProgress } from '../../resources/js/document-progress.js';
import { initializeGeneratedPdfLinks } from '../../resources/js/document-delivery.js';

function progressFixture() {
    const calls = [];
    const dialog = { showModal() { calls.push('open'); }, close() { calls.push('close'); } };
    return { calls, dialog, ownerDocument: { querySelector: () => dialog } };
}

test('the shared generation dialog stays open until all active emissions finish', () => {
    const { calls, dialog, ownerDocument } = progressFixture();
    const first = startDocumentProgress(ownerDocument);
    const second = startDocumentProgress(ownerDocument);
    let prevented = false;
    dialog.oncancel({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    first();
    first();
    assert.deepEqual(calls, ['open']);
    second();
    assert.deepEqual(calls, ['open', 'close']);
});

test('reports and other PDF links wait for the file and close progress before reporting a failure', async () => {
    const { calls, ownerDocument } = progressFixture();
    let click, reject, count = 0;
    const link = { href: 'https://example.test/report.pdf', target: '' };
    ownerDocument.addEventListener = (event, handler) => { if (event === 'click') click = handler; };
    initializeGeneratedPdfLinks(ownerDocument, (url, options) => {
        count++;
        assert.equal(url, link.href);
        assert.equal(options.sameTab, true);
        return new Promise((resolve, fail) => { reject = fail; });
    }, message => calls.push(message));
    const event = (extra = {}) => ({ target: { closest: () => link }, button: 0, preventDefault() {}, ...extra });
    await click(event({ ctrlKey: true }));
    const pending = click(event());
    await click(event());
    assert.equal(count, 1);
    assert.deepEqual(calls, ['open']);
    reject(new Error('Falha simulada'));
    await pending;
    assert.deepEqual(calls, ['open', 'close', 'Falha simulada']);
});
