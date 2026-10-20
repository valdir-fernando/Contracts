import test from 'node:test';
import assert from 'node:assert/strict';
import { attachContractTypeCorrection } from '../../resources/js/contract-type-correction.js';

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
