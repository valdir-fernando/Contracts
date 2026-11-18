import test from 'node:test';
import assert from 'node:assert/strict';
import { ataPublicationDates } from '../../resources/js/ata-publication-dates.js';

test('publication starts strictly on the next weekday and ends on the anniversary', () => {
    for (const [publication, start, end] of [
        ['2026-11-18', '2026-11-19', '2027-11-19'],
        ['2026-11-19', '2026-11-20', '2027-11-20'],
        ['2026-11-20', '2026-11-23', '2027-11-23'],
        ['2026-11-21', '2026-11-23', '2027-11-23'],
        ['2026-11-22', '2026-11-23', '2027-11-23'],
        ['31/12/2026', '2027-01-01', '2028-01-01'],
        ['2024-02-28', '2024-02-29', '2025-02-28'],
        ['2023-02-28', '2023-03-01', '2024-03-01'],
    ]) {
        assert.deepEqual(ataPublicationDates(publication), { start_date: start, end_date: end }, publication);
    }
});

test('invalid, incomplete and out-of-range publication dates cannot generate dates', () => {
    for (const value of ['', '18/11', '31/02/2026', '2026-02-29', '0000-01-01', '9999-12-31', '9999-01-04']) {
        assert.equal(ataPublicationDates(value), null, value);
    }
});
