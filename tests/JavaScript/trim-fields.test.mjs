import test from 'node:test';
import assert from 'node:assert/strict';
import { trimField, initializeFieldTrimming } from '../../resources/js/trim-fields.js';

const field = (value, options = {}) => ({ value, name: 'q', matches: () => true,
    dispatchEvent(event) { this.event = event; }, ...options });

test('trimming preserves internal spaces and notifies reactive fields only when changed', () => {
    const input = field('  Nome  completo \n');
    trimField(input);
    assert.equal(input.value, 'Nome  completo');
    assert.equal(input.event.type, 'input');
    assert.equal(input.event.bubbles, true);
    input.event = null;
    trimField(input);
    assert.equal(input.event, null);
    for (const options of [{ disabled: true }, { readOnly: true }, { matches: () => false }]) {
        const excluded = field(' dado ', options);
        trimField(excluded);
        assert.equal(excluded.value, ' dado ');
    }
});

test('delegated blur, submit and FormData cover dynamic fields and repeated values', () => {
    const handlers = {};
    initializeFieldTrimming({ addEventListener(name, listener, capture) {
        assert.equal(capture, true);
        handlers[name] = listener;
    } });
    const input = field(' busca ');
    handlers.blur({ target: input });
    assert.equal(input.value, 'busca');
    input.value = ' salvar ';
    const form = { querySelectorAll: () => [input] };
    handlers.submit({ target: form });
    assert.equal(input.value, 'salvar');
    input.value = ' ajax ';
    const data = new FormData();
    data.append('q', ' ajax ');
    data.append('q', ' segundo  valor ');
    data.append('version', '1');
    handlers.formdata({ target: form, formData: data });
    assert.deepEqual(data.getAll('q'), ['ajax', 'segundo  valor']);
    assert.equal(data.get('version'), '1');
});
