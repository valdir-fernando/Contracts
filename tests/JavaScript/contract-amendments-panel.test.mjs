import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAmendmentsPanel } from '../../resources/js/contract-amendments-panel.js';

class Element {
    listeners = {};
    addEventListener(name, action) { (this.listeners[name] ??= []).push(action); }
    emit(name, event = {}) { for (const action of this.listeners[name] ?? []) action({ preventDefault() {}, ...event }); }
    focus() { this.focused = true; }
    showModal() { this.open = true; }
    close() { this.open = false; this.emit('close'); }
}

function setup() {
    const openers = [new Element(), new Element()];
    openers.forEach((opener) => { opener.href = 'https://contracts.test/contracts/42/amendments'; });
    const dialog = new Element();
    dialog.dataset = { summaryUrl: 'https://contracts.test/contracts/42' };
    const frame = new Element();
    const close = new Element();
    const loading = { hidden: true };
    dialog.querySelector = (selector) => ({ '[data-contract-amendments-frame]': frame, '[data-contract-amendments-close]': close, '[data-contract-amendments-loading]': loading })[selector];
    initializeAmendmentsPanel(openers, dialog);
    return { openers, dialog, frame, close, loading };
}

test('both links open the panel and closing clears it and restores the active opener', () => {
    const { openers, dialog, frame, close, loading } = setup();
    openers[0].emit('click', { ctrlKey: true });
    assert.ok(!dialog.open);
    openers[1].emit('click');
    assert.equal(dialog.open, true);
    assert.equal(frame.src, openers[1].href);
    assert.equal(loading.hidden, false);
    close.emit('click');
    assert.equal(dialog.open, false);
    assert.equal(frame.src, 'about:blank');
    assert.equal(openers[1].focused, true);
    assert.ok(!openers[0].focused);
});

test('details and editing stay in the panel, Escape respects nested dialogs, and summary link closes it', () => {
    const { openers, dialog, frame, loading } = setup();
    openers[0].emit('click');
    const doc = new Element();
    doc.URL = `${openers[0].href}/18/edit`;
    let nested = true;
    doc.querySelector = () => nested ? {} : null;
    frame.contentDocument = doc;
    frame.emit('load');
    assert.equal(loading.hidden, true);
    assert.equal(dialog.open, true);
    doc.emit('keydown', { key: 'Escape' });
    assert.equal(dialog.open, true);
    nested = false;
    doc.emit('keydown', { key: 'Escape' });
    assert.equal(dialog.open, false);
    openers[0].emit('click');
    doc.URL = dialog.dataset.summaryUrl;
    frame.emit('load');
    assert.equal(dialog.open, false);
    assert.equal(frame.src, 'about:blank');
});
