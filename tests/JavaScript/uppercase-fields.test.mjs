import test from 'node:test';
import assert from 'node:assert/strict';
import { uppercaseField } from '../../resources/js/uppercase-fields.js';

test('uppercase fields preserve accents, punctuation and selection', () => {
    for (const [value, expected] of [['João da Conceição', 'JOÃO DA CONCEIÇÃO'], ['joao.silva-1', 'JOAO.SILVA-1'], ['buscar usuário', 'BUSCAR USUÁRIO']]) {
        const field = { value, selectionStart: 2, selectionEnd: 4,
            setSelectionRange(start, end) { assert.equal(start, 2); assert.equal(end, 4); } };
        uppercaseField(field);
        assert.equal(field.value, expected);
    }
});
