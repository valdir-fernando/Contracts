import test from 'node:test';
import assert from 'node:assert/strict';
import { serviceOrderPayload, advanceServiceOrderVersion, updateServiceOrderSummary } from '../../resources/js/service-orders.js';

test('closing after saving updates the parent OS without reloading its unsaved fields', () => {
    const summary = { hidden: true };
    const number = {};
    const date = {};
    const deadline = { value: 'Prazo anterior' };
    const source = { querySelector: selector => selector === '.contract-service-order-summary' ? summary
        : selector === '#prazo_obra' ? deadline : selector === '[data-derived-field="n_os"]' ? number : date };
    updateServiceOrderSummary(source, { number: 'OS-2', issued_on: '20/10/2026', deadline: '30 dias' });
    assert.equal(number.textContent, 'OS-2');
    assert.equal(date.textContent, '20/10/2026');
    assert.equal(summary.hidden, false);
    assert.equal(deadline.value, '30 dias');
    updateServiceOrderSummary(source, null);
    assert.equal(number.textContent, 'OS-2');
});

test('order payload converts Brazilian dates and sends only order fields with the form version', () => {
    const values = { number: 'OS-01', issued_on: '15/10/2026', deadline: '90 dias', amendment_id: '4', notes: 'Observação', created_by: '999' };
    const form = { elements: { namedItem: (name) => ({ value: values[name] }) } };
    assert.deepEqual(serviceOrderPayload(form, '3'), {
        number: 'OS-01', issued_on: '2026-10-15', deadline: '90 dias', amendment_id: '4', notes: 'Observação', contract_version: 3,
        starts_on: null, ends_on: null,
    });
    values.amendment_id = '';
    values.notes = '';
    assert.equal(serviceOrderPayload(form, '3').amendment_id, null);
    assert.equal(serviceOrderPayload(form, '3').notes, null);
});

test('only the version used for the successful order can advance the unsaved parent form', () => {
    const field = { value: '3' };
    advanceServiceOrderVersion(field, '3', 4);
    assert.equal(field.value, '4');
    advanceServiceOrderVersion(field, '3', 4);
    assert.equal(field.value, '4');
    advanceServiceOrderVersion(field, '4', 9);
    assert.equal(field.value, '4');
});
