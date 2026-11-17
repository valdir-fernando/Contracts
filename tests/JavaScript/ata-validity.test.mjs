import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaValidity } from '../../resources/js/ata-validity.js';

function fixture(fetcher = async () => ({ ok: true, json: async () => ({ id: 81, number: '107/2025', version: 4 }) })) {
    const fields = Object.fromEntries(['start_date', 'end_date', 'validity_period', 'one_year_inpc'].map(name => [name, {
        value: '', checked: false, type: name.includes('date') ? 'date' : 'text', dataset: {}, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
    }]));
    const button = { dataset: { ataValiditySaveUrl: '/atas/0/validity' }, listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; } };
    const panel = { querySelector: selector => selector === '[data-ata-validity-save-url]' ? button : fields[selector.slice(7, -2)] };
    const events = [], warnings = [], requests = [];
    const root = { dataset: { ataLoaded: '81', ataVersion: '3', editorBusy: 'false' }, ownerDocument: {}, listeners: {},
        querySelector: () => panel, addEventListener(name, callback) { this.listeners[name] = callback; }, dispatchEvent: event => events.push(event) };
    initializeAtaValidity(root, async (...args) => { requests.push(args); return fetcher(...args); }, message => warnings.push(message));
    return { root, fields, button, events, requests, warnings, save: () => button.listeners.click() };
}

test('one-year option fills the editable period, disables both dates and restores editing when cleared', () => {
    const ui = fixture();
    ui.fields.one_year_inpc.checked = true;
    ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.fields.validity_period.value, '1 Ano INPC');
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.fields.end_date.disabled, true);
    assert.equal(ui.root.dataset.ataValidityDirty, 'true');
    ui.fields.validity_period.value = '24 meses';
    ui.fields.validity_period.listeners.input();
    assert.equal(ui.fields.one_year_inpc.checked, false);
    assert.equal(ui.fields.start_date.disabled, false);
    assert.equal(ui.fields.end_date.disabled, false);
});

test('loading an ATA restores the validity fields and clearing resets the checkbox and dates', () => {
    const ui = fixture();
    ui.root.listeners['ata-loaded']({ detail: { start_date: '2025-01-01', end_date: '2026-01-01', validity_period: '1 Ano INPC', one_year_inpc: true } });
    assert.equal(ui.fields.start_date.value, '2025-01-01');
    assert.equal(ui.fields.one_year_inpc.checked, true);
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.root.dataset.ataValidityDirty, 'false');
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.fields.start_date.value, '');
    assert.equal(ui.fields.validity_period.value, '');
    assert.equal(ui.fields.one_year_inpc.checked, false);
});

test('save validity sends the loaded version and all explicit values then refreshes the ATA', async () => {
    const ui = fixture();
    ui.fields.start_date.value = '2025-01-01';
    ui.fields.end_date.value = '2027-01-01';
    ui.fields.validity_period.value = '24 meses';
    await ui.save();
    assert.equal(ui.requests[0][0], '/atas/81/validity');
    assert.deepEqual(JSON.parse(ui.requests[0][1].body), { version: 3, start_date: '2025-01-01', end_date: '2027-01-01', validity_period: '24 meses', one_year_inpc: false });
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), true);
    assert.match(ui.warnings[0], /Vigência salva/);
});

test('failed saving preserves typed fields and explains the error using the standard warning', async () => {
    const ui = fixture(async () => ({ ok: false, status: 409 }));
    ui.fields.validity_period.value = '24 meses';
    await ui.save();
    assert.equal(ui.fields.validity_period.value, '24 meses');
    assert.match(ui.warnings[0], /ata foi alterada/);
    assert.equal(ui.events.some(event => event.type === 'ata-saved'), false);
});
