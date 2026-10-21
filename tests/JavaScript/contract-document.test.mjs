import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractDocument, openContractDocument } from '../../resources/js/contract-extract.js';

test('contract PDF uses the saved version and refuses unsaved type or field changes', async () => {
    const fields = [{ name: 'version', value: '1' }, { name: 'tipo', value: 'Fornecimento' }, { name: 'objeto', value: 'Material' }];
    fields.namedItem = (name) => fields.find((field) => field.name === name);
    const form = { elements: fields, ownerDocument: { baseURI: 'http://contracts.test/' } };
    const feedback = { hidden: true };
    let click;
    const button = {
        dataset: { contractDocumentUrl: '/contracts/1/document?version=1' },
        closest: (selector) => selector === 'form' ? form : { querySelector: () => feedback },
        addEventListener: (name, callback) => { click = callback; },
    };
    const opened = [];
    initializeContractDocument(button, (url) => opened.push(url));
    const event = { preventDefault() {} };
    await click(event);
    assert.equal(opened[0], 'http://contracts.test/contracts/1/document?version=1');
    for (const index of [1, 2]) {
        const before = fields[index].value;
        fields[index].value = 'Alterado';
        await click(event);
        assert.equal(opened.length, 1);
        assert.equal(feedback.hidden, false);
        assert.equal(feedback.textContent, 'Salve as alterações do contrato antes de emitir o contrato.');
        fields[index].value = before;
    }
    fields[0].value = '2';
    await click(event);
    assert.equal(opened[1], 'http://contracts.test/contracts/1/document?version=2');
    assert.equal(feedback.hidden, true);
});

test('failed emission stays on the contract, shows its message and permits retry', async () => {
    const fields = [{ name: 'version', value: '1' }];
    fields.namedItem = () => fields[0];
    const form = { elements: fields, ownerDocument: { baseURI: 'http://contracts.test/' } };
    const feedback = { hidden: true };
    const message = {};
    let close;
    let onClosed;
    let focused = false;
    const dialog = {
        open: false,
        showModal() { this.open = true; },
        close() { this.open = false; onClosed(); },
        querySelector: (selector) => selector.endsWith('message]') ? message : { addEventListener: (name, callback) => { close = callback; } },
        addEventListener: (name, callback) => { onClosed = callback; },
    };
    let click;
    let attempts = 0;
    const button = {
        dataset: { contractDocumentUrl: '/contracts/1/document' },
        closest: (selector) => selector === 'form' ? form : { querySelector: (query) => query === '[data-extract-feedback]' ? feedback : dialog },
        focus: () => { focused = true; },
        addEventListener: (name, callback) => { click = callback; },
    };
    initializeContractDocument(button, async () => {
        if (++attempts === 1) throw new Error('Confira os totais.');
    });
    await click({ preventDefault() {} });
    assert.equal(button.disabled, false);
    assert.equal(dialog.open, true);
    assert.equal(message.textContent, 'Confira os totais.');
    close();
    assert.equal(dialog.open, false);
    assert.equal(focused, true);
    await click({ preventDefault() {} });
    assert.equal(attempts, 2);
    assert.equal(button.disabled, false);
    assert.equal(feedback.hidden, true);
});

test('validation failure never opens another tab and preserves the server message', async () => {
    const browser = { open: () => { throw new Error('Must not open a tab for an error'); } };
    await assert.rejects(openContractDocument('/document', async () => ({
        ok: false, json: async () => ({ message: 'A soma dos produtos não corresponde ao valor inicial.' }),
    }), browser), /A soma dos produtos/);
});
