import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeContractSummary } from '../../resources/js/contract-summary.js';

test('supplier summary follows edits and clearing without interpreting HTML or changing protected values', () => {
    const controls = { fornecedor_nome_snapshot: { value: 'Empresa inicial' }, end_fornec: { value: 'Rua inicial' } };
    const outputs = Object.keys(controls).map((name) => ({ dataset: { contractSummaryField: name }, textContent: '' }));
    const protectedOutput = { dataset: { contractSummaryField: 'fornecedor_documento_snapshot' }, textContent: '•••8901' };
    outputs.push(protectedOutput);
    const listeners = {};
    const workspace = { querySelector: (selector) => { assert.equal(selector, 'form[data-contract-form]'); return form; }, querySelectorAll: () => outputs };
    const form = { elements: { namedItem: (name) => controls[name] }, closest: () => workspace,
        addEventListener: (name, handler) => { listeners[name] = handler; } };
    initializeContractSummary(workspace);
    assert.equal(outputs[0].textContent, 'Empresa inicial');
    controls.fornecedor_nome_snapshot.value = '<script>alert(1)</script>';
    listeners.input();
    assert.equal(outputs[0].textContent, '<script>alert(1)</script>');
    controls.fornecedor_nome_snapshot.value = '';
    listeners.input();
    assert.equal(outputs[0].textContent, 'Nome / Razão Social');
    controls.end_fornec.value = '   ';
    listeners.change();
    assert.equal(outputs[1].textContent, 'Não informado');
    assert.equal(protectedOutput.textContent, '•••8901');
});
