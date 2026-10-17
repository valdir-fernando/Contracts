import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractExtract } from '../../resources/js/contract-extract.js';

test('extract opens saved PDF in a new tab, blocks unsaved fields and follows persisted item revision', () => {
    const fields = [{ name: 'version', value: '1' }, { name: 'objeto', value: 'Materiais' }, { name: '_token', value: 'csrf' }];
    fields.namedItem = (name) => fields.find((field) => field.name === name);
    const form = { elements: fields, ownerDocument: { baseURI: 'http://contracts.test/' } };
    const feedback = { hidden: true };
    let click;
    const button = { dataset: { extractUrl: '/contracts/1/extract?version=1' },
        closest: (selector) => selector === 'form' ? form : { querySelector: () => feedback },
        addEventListener: (name, callback) => { click = callback; } };
    const opened = [];
    initializeContractExtract(button, (url) => opened.push(url));
    const event = { preventDefault() {} };
    click(event);
    assert.equal(opened[0], 'http://contracts.test/contracts/1/extract?version=1');
    fields[1].value = 'Objeto alterado';
    click(event);
    assert.equal(opened.length, 1);
    assert.equal(feedback.hidden, false);
    assert.match(feedback.textContent, /Salve as alterações/);
    fields[1].value = 'Materiais';
    fields[0].value = '2';
    click(event);
    assert.equal(opened[1], 'http://contracts.test/contracts/1/extract?version=2');
    assert.equal(feedback.hidden, true);
});
