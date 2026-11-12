import test from 'node:test';
import assert from 'node:assert/strict';
import { ataDateError } from '../../resources/js/ata-date.js';

test('ata dates reject impossible dates and weekends and accept weekdays', () => {
    for (const value of ['31/02/2026', '29/02/2026', '00/11/2026', 'texto', '14/11/2026', '15/11/2026']) {
        assert.notEqual(ataDateError(value), '', value);
    }
    for (const value of ['', '13/11/2026', '16/11/2026', '29/02/2024']) {
        assert.equal(ataDateError(value), '', value);
    }
});

test('confirmation permits only the accepted date and cannot permit impossible dates', () => {
    assert.equal(ataDateError('14/11/2026', '2026-11-14'), '');
    assert.notEqual(ataDateError('15/11/2026', '2026-11-14'), '');
    assert.notEqual(ataDateError('31/02/2026', '2026-02-31'), '');
});
