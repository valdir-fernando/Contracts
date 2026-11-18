import { isoDate } from './input-masks.js';

export function ataPublicationDates(value) {
    const iso = isoDate(value);
    if (!iso || iso < '0001-01-01') return null;
    const start = new Date(`${iso}T12:00:00Z`);
    do { start.setUTCDate(start.getUTCDate() + 1); } while ([0, 6].includes(start.getUTCDay()));
    const end = new Date(start.valueOf());
    end.setUTCFullYear(start.getUTCFullYear() + 1);
    if (end.getUTCMonth() !== start.getUTCMonth()) end.setUTCDate(0);
    if (end.getUTCFullYear() > 9999) return null;
    return { start_date: start.toISOString().slice(0, 10), end_date: end.toISOString().slice(0, 10) };
}
