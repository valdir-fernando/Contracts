import test from 'node:test';
import assert from 'node:assert/strict';
import { uppercaseSupplierName } from '../../resources/js/supplier-registration.js';

test('supplier names preserve accents and cursor in uppercase', () => {
    for (const name of ['name', 'Nome_Fantasia', 'Nome_Socio', 'Nome_Contato']) {
        const field = { name, value: 'João conceição', selectionStart: 3, selectionEnd: 3,
            setSelectionRange(start, end) { assert.equal(start, 3); assert.equal(end, 3); } };
        uppercaseSupplierName(field);
        assert.equal(field.value, 'JOÃO CONCEIÇÃO');
    }
    const email = { name: 'Email_Fornecedor', value: 'contato@example.com' };
    uppercaseSupplierName(email);
    assert.equal(email.value, 'contato@example.com');
});
