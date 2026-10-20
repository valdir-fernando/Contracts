import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeServiceOrderLink, serviceOrderTypeAllowed } from '../../resources/js/service-order-link.js';

test('service order link is available only for service and construction types', () => {
    for (const type of ['Serviço', 'Serviços', 'Serviços Diversos', 'Obras']) assert.equal(serviceOrderTypeAllowed(type), true);
    for (const type of ['', 'Fornecimento', 'Locação de Imóvel', 'Show', 'Credenciamento']) assert.equal(serviceOrderTypeAllowed(type), false);
    const link = { hidden: true };
    const type = { value: 'Fornecimento', addEventListener(name, listener) { this[name] = listener; } };
    initializeServiceOrderLink({ querySelector: selector => selector === '[name="tipo"]' ? type : link });
    assert.equal(link.hidden, true);
    type.value = 'Obras';
    type.change();
    assert.equal(link.hidden, false);
    type.value = 'Fornecimento';
    type.change();
    assert.equal(link.hidden, true);
});
