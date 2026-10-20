import test from 'node:test';
import assert from 'node:assert/strict';
import { attachContractTypeCorrection } from '../../resources/js/contract-type-correction.js';

test('help follows the selected historical option, including types without a correction', () => {
    let change;
    let closed = 0;
    const help = { hidden: false };
    const popup = { matches: () => true, hidePopover: () => closed++ };
    const select = {
        selectedOptions: [{ hasAttribute: () => false }],
        addEventListener: (_, handler) => { change = handler; },
    };
    const nodes = { '#tipo': select, '[data-field-help="contract-type-help"]': help, '#contract-type-help': popup };
    attachContractTypeCorrection({ querySelector: (selector) => nodes[selector] });
    assert.equal(help.hidden, true);
    select.selectedOptions = [{ hasAttribute: (name) => name === 'data-historical' }];
    change();
    assert.equal(help.hidden, false);
    select.selectedOptions = [];
    change();
    assert.equal(help.hidden, true);
    assert.equal(closed, 2);
});

test('accept changes only the field; keep restores the historical value and both notify dependents', () => {
    const handlers = {};
    const changes = [];
    const select = { value: 'Servicos Diversos', options: [{ value: 'Servicos Diversos' }, { value: 'Serviços Diversos' }], dispatchEvent: (event) => changes.push(event.type) };
    const accept = { dataset: { contractTypeCorrection: 'Serviços Diversos', originalType: 'Servicos Diversos' }, addEventListener: (_, handler) => { handlers.accept = handler; } };
    const keep = { addEventListener: (_, handler) => { handlers.keep = handler; } };
    const status = { textContent: '' };
    const nodes = { '#tipo': select, '[data-contract-type-correction]': accept, '[data-contract-type-keep]': keep, '[data-contract-type-status]': status };
    attachContractTypeCorrection({ querySelector: (selector) => nodes[selector] });
    assert.equal(select.value, 'Servicos Diversos');
    handlers.accept();
    assert.equal(select.value, 'Serviços Diversos');
    assert.match(status.textContent, /Salve o contrato/);
    handlers.keep();
    assert.equal(select.value, 'Servicos Diversos');
    assert.deepEqual(changes, ['change', 'change']);
});
