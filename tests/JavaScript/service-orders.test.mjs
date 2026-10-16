import test from 'node:test';
import assert from 'node:assert/strict';
import { serviceOrderPayload, advanceServiceOrderVersion } from '../../resources/js/service-orders.js';

test('order payload converts Brazilian dates and sends only order fields with the form version', () => {
    const values = { number: 'OS-01', issued_on: '15/10/2026', deadline: '90 dias', amendment_id: '4', notes: 'Observação', created_by: '999' };
    const form = { elements: { namedItem: (name) => ({ value: values[name] }) } };
    assert.deepEqual(serviceOrderPayload(form, '3'), {
        number: 'OS-01', issued_on: '2026-10-15', deadline: '90 dias', amendment_id: '4', notes: 'Observação', contract_version: 3,
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
