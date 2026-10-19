import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractDocument } from '../../resources/js/contract-extract.js';

test('contract PDF uses the saved version and refuses unsaved type or field changes', () => {
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
    click(event);
    assert.equal(opened[0], 'http://contracts.test/contracts/1/document?version=1');
    for (const index of [1, 2]) {
        const before = fields[index].value;
        fields[index].value = 'Alterado';
        click(event);
        assert.equal(opened.length, 1);
        assert.equal(feedback.hidden, false);
        assert.equal(feedback.textContent, 'Salve as alterações do contrato antes de emitir o contrato.');
        fields[index].value = before;
    }
    fields[0].value = '2';
    click(event);
    assert.equal(opened[1], 'http://contracts.test/contracts/1/document?version=2');
    assert.equal(feedback.hidden, true);
});
