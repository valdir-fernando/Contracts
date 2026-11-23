import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaValidity } from '../../resources/js/ata-validity.js';

function fixture(loaded = true, confirm = async () => true) {
    const fields = Object.fromEntries(['start_date', 'end_date', 'validity_period', 'one_year_inpc', 'publication_date'].map(name => [name, {
        value: '', checked: false, type: name.includes('date') ? 'date' : 'text', dataset: {}, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
    }]));
    const publicationField = { hidden: false };
    const notice = { hidden: true };
    const panel = { dataset: {}, querySelector: selector => selector === '[data-ata-publication-field]' ? publicationField
        : selector === '[data-ata-inpc-notice]' ? notice : fields[selector.slice(7, -2)] };
    const root = { dataset: { ataLoaded: loaded ? '81' : '', ataVersion: '3', editorBusy: 'false' }, ownerDocument: {}, listeners: {},
        querySelector: () => panel, addEventListener(name, callback) { this.listeners[name] = callback; } };
    const confirmations = [];
    initializeAtaValidity(root, (...args) => { confirmations.push(args); return confirm(...args); });
    return { root, fields, panel, publicationField, notice, confirmations };
}

test('the saved publication date is restored and published validity dates are locked', () => {
    const ui = fixture();
    ui.root.dataset.ataPublicationLocked = 'true';
    ui.root.listeners['ata-loaded']({ detail: { publication_date: '2026-11-20' } });
    assert.equal(ui.fields.publication_date.value, '2026-11-20');
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.fields.end_date.disabled, true);
    assert.equal(ui.root.dataset.ataValidityDirty, 'false');
});

test('the projected original end is displayed and its derived marker is cleared when switching ATAs', () => {
    const ui = fixture();
    ui.fields.end_date.type = 'text';
    ui.root.listeners['ata-loaded']({ detail: { end_date: '2026-06-02', end_date_derived: true } });
    assert.equal(ui.fields.end_date.value, '02/06/2026');
    assert.equal(ui.root.dataset.ataDerivedEndDate, '2026-06-02');
    ui.root.listeners['ata-loaded']({ detail: { end_date: '2027-01-01' } });
    assert.equal(ui.fields.end_date.value, '01/01/2027');
    assert.equal(ui.root.dataset.ataDerivedEndDate, undefined);
});

test('INPC clears and locks draft dates and their calendars after confirmation while publication stays visible', async () => {
    const ui = fixture(false);
    const calendars = [{ disabled: false }, { disabled: false }];
    for (const name of ['start_date', 'end_date']) {
        ui.fields[name].value = '2026-11-18';
        ui.fields[name].parentElement = { querySelectorAll: () => calendars };
    }
    ui.fields.one_year_inpc.checked = true;
    await ui.fields.one_year_inpc.listeners.change();
    for (const name of ['start_date', 'end_date']) {
        assert.equal(ui.fields[name].value, '');
        assert.equal(ui.fields[name].disabled, true);
        assert.equal(ui.fields[name].dataset.validityDisabled, 'true');
    }
    assert.equal(calendars.every(control => control.disabled), true);
    assert.equal(ui.publicationField.hidden, false);
    ui.fields.one_year_inpc.checked = false;
    ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.fields.start_date.disabled, false);
    assert.equal(ui.fields.end_date.disabled, false);
    assert.equal(ui.fields.start_date.value, '');
    assert.equal(ui.fields.end_date.value, '');
    assert.equal(calendars.every(control => !control.disabled), true);
    ui.root.dataset.ataLoaded = '81';
    ui.root.listeners['ata-loaded']({ detail: { one_year_inpc: true } });
    assert.equal(ui.publicationField.hidden, false);
    ui.root.dataset.ataLoaded = '';
    ui.root.listeners['ata-cleared']();
    ui.fields.one_year_inpc.checked = true;
    ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.publicationField.hidden, false);
});

test('INPC clears existing saved dates after confirmation while locking editing', async () => {
    const ui = fixture();
    ui.root.listeners['ata-loaded']({ detail: { start_date: '2025-01-01', end_date: '2026-01-01', one_year_inpc: false } });
    ui.fields.one_year_inpc.checked = true;
    await ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.fields.start_date.value, '');
    assert.equal(ui.fields.end_date.value, '');
    assert.equal(ui.confirmations.length, 1);
    assert.equal(ui.notice.hidden, false);
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.fields.end_date.disabled, true);
    assert.equal(ui.publicationField.hidden, false);
});

test('canceling INPC confirmation preserves dates, period and editing with either date filled', async () => {
    for (const date of ['start_date', 'end_date']) {
        const ui = fixture(true, async () => false);
        ui.fields[date].value = '2026-11-18';
        ui.fields.validity_period.value = '24 meses';
        ui.fields.one_year_inpc.checked = true;
        await ui.fields.one_year_inpc.listeners.change();
        assert.equal(ui.fields.one_year_inpc.checked, false);
        assert.equal(ui.fields[date].value, '2026-11-18');
        assert.equal(ui.fields.validity_period.value, '24 meses');
        assert.equal(ui.fields.start_date.disabled, false);
        assert.equal(ui.fields.end_date.disabled, false);
        assert.equal(ui.notice.hidden, true);
        assert.equal(ui.confirmations[0][0], 'Ao marcar a opção "1 Ano INPC" a "Data_Inicial" e a "Data_Final" serão preenchidas automaticamente após a inclusão da "Data de Publicação no INPC"');
        assert.equal(ui.confirmations[0][1], ui.root.ownerDocument);
        assert.deepEqual(ui.confirmations[0][2], { emphasize: ['1 Ano INPC', 'Data_Inicial', 'Data_Final', 'Data de Publicação no INPC'] });
    }
});

test('INPC without dates shows the notice immediately without asking for confirmation', async () => {
    const ui = fixture(false);
    ui.fields.one_year_inpc.checked = true;
    await ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.confirmations.length, 0);
    assert.equal(ui.notice.hidden, false);
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.fields.end_date.disabled, true);
    ui.fields.one_year_inpc.checked = false;
    await ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.notice.hidden, true);
});

test('confirmation from a previous ATA cannot clear dates of another loaded ATA', async () => {
    let resolve;
    const ui = fixture(true, () => new Promise(done => { resolve = done; }));
    ui.fields.start_date.value = '2026-11-18';
    ui.fields.one_year_inpc.checked = true;
    const pending = ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.fields.one_year_inpc.checked, false);
    ui.root.listeners['ata-loaded']({ detail: { start_date: '2025-01-01', end_date: '2026-01-01', validity_period: '12 meses', one_year_inpc: false } });
    resolve(true);
    await pending;
    assert.equal(ui.fields.start_date.value, '2025-01-01');
    assert.equal(ui.fields.end_date.value, '2026-01-01');
    assert.equal(ui.fields.validity_period.value, '12 meses');
    assert.equal(ui.fields.one_year_inpc.checked, false);
    assert.equal(ui.notice.hidden, true);
});

test('publication stays visible in both modes and is cleared when changing ATA', () => {
    const ui = fixture();
    assert.equal(ui.publicationField.hidden, false);
    ui.fields.one_year_inpc.checked = true;
    ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.publicationField.hidden, false);
    ui.fields.publication_date.value = '2026-11-18';
    ui.fields.one_year_inpc.checked = false;
    ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.publicationField.hidden, false);
    ui.root.listeners['ata-loaded']({ detail: { one_year_inpc: true } });
    assert.equal(ui.publicationField.hidden, false);
    assert.equal(ui.fields.publication_date.value, '');
    ui.fields.validity_period.value = '24 meses';
    ui.fields.validity_period.listeners.input();
    assert.equal(ui.publicationField.hidden, false);
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.publicationField.hidden, false);
});

test('publication preserves manual dates and recalculates only after INPC is enabled', async () => {
    const ui = fixture(false);
    ui.fields.start_date.value = '2025-01-01';
    ui.fields.end_date.value = '2026-01-01';
    ui.fields.publication_date.value = '2026-11-20';
    ui.fields.publication_date.listeners.change();
    assert.equal(ui.fields.start_date.value, '2025-01-01');
    assert.equal(ui.fields.end_date.value, '2026-01-01');
    ui.fields.one_year_inpc.checked = true;
    await ui.fields.one_year_inpc.listeners.change();
    assert.equal(ui.fields.start_date.value, '2026-11-23');
    assert.equal(ui.fields.end_date.value, '2027-11-23');
    assert.equal(ui.fields.start_date.dataset.autoCalculated, 'true');
    assert.equal(ui.fields.end_date.dataset.autoCalculated, 'true');
    assert.equal(ui.root.dataset.ataValidityDirty, 'true');
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.fields.end_date.disabled, true);
    ui.fields.publication_date.value = '2026-11-23';
    ui.fields.publication_date.listeners.input();
    assert.equal(ui.fields.start_date.value, '2026-11-24');
    assert.equal(ui.fields.end_date.value, '2027-11-24');
    assert.equal(ui.fields.start_date.disabled, true);
    assert.equal(ui.fields.end_date.disabled, true);
    ui.fields.one_year_inpc.checked = false;
    await ui.fields.one_year_inpc.listeners.change();
    for (const value of ['2026-12-01', '', '31/02/2026']) {
        ui.fields.publication_date.value = value;
        ui.fields.publication_date.listeners.input();
        ui.fields.publication_date.listeners.change();
        assert.equal(ui.fields.start_date.value, '2026-11-24');
        assert.equal(ui.fields.end_date.value, '2027-11-24');
    }
});

test('publication accepts Brazilian display dates and clears generated dates when removed or invalid', () => {
    const ui = fixture(false);
    ui.fields.one_year_inpc.checked = true;
    ui.fields.one_year_inpc.listeners.change();
    ui.fields.start_date.type = ui.fields.end_date.type = 'text';
    ui.fields.publication_date.value = '28/02/2024';
    ui.fields.publication_date.listeners.input();
    assert.equal(ui.fields.start_date.value, '29/02/2024');
    assert.equal(ui.fields.end_date.value, '28/02/2025');
    ui.fields.publication_date.value = '';
    ui.fields.publication_date.listeners.input();
    assert.equal(ui.fields.start_date.value, '');
    assert.equal(ui.fields.end_date.value, '');
    assert.equal(ui.fields.start_date.dataset.autoCalculated, 'false');
    assert.equal(ui.fields.end_date.dataset.autoCalculated, 'false');
    ui.fields.publication_date.value = '20/11/2026';
    ui.fields.publication_date.listeners.change();
    ui.fields.publication_date.value = '31/02/2026';
    ui.fields.publication_date.listeners.change();
    assert.equal(ui.fields.start_date.value, '');
    assert.equal(ui.fields.end_date.value, '');
});

test('invalid publication does not erase historical or manually edited dates', async () => {
    const ui = fixture();
    ui.root.listeners['ata-loaded']({ detail: { start_date: '2025-01-01', end_date: '2026-01-01' } });
    ui.fields.publication_date.value = '18/11';
    ui.fields.publication_date.listeners.input();
    assert.equal(ui.fields.start_date.value, '2025-01-01');
    assert.equal(ui.fields.end_date.value, '2026-01-01');
    ui.fields.one_year_inpc.checked = true;
    await ui.fields.one_year_inpc.listeners.change();
    ui.fields.publication_date.value = '2026-11-20';
    ui.fields.publication_date.listeners.input();
    ui.fields.one_year_inpc.checked = false;
    await ui.fields.one_year_inpc.listeners.change();
    ui.fields.start_date.value = '2027-01-01';
    ui.fields.start_date.listeners.input();
    assert.equal(ui.fields.start_date.dataset.autoCalculated, 'false');
    assert.equal(ui.fields.end_date.dataset.autoCalculated, 'true');
    ui.fields.publication_date.value = '';
    ui.fields.publication_date.listeners.input();
    assert.equal(ui.fields.start_date.value, '2027-01-01');
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.fields.publication_date.value, '');
    assert.equal(ui.fields.start_date.value, '');
    assert.equal(ui.fields.end_date.value, '');
    assert.equal(ui.fields.start_date.dataset.autoCalculated, 'false');
    assert.equal(ui.fields.end_date.dataset.autoCalculated, 'false');
});


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

test('unchecking restores the saved period instead of unsaved edits across repeated toggles', () => {
    const ui = fixture();
    ui.root.listeners['ata-loaded']({ detail: { validity_period: '24 meses', one_year_inpc: false } });
    ui.fields.validity_period.value = 'Texto não salvo';
    ui.fields.validity_period.listeners.input();
    for (let i = 0; i < 2; i++) {
        ui.fields.one_year_inpc.checked = true;
        ui.fields.one_year_inpc.listeners.change();
        assert.equal(ui.fields.validity_period.value, '1 Ano INPC');
        ui.fields.one_year_inpc.checked = false;
        ui.fields.one_year_inpc.listeners.change();
        assert.equal(ui.fields.validity_period.value, '24 meses');
        assert.equal(ui.fields.start_date.disabled, false);
        assert.equal(ui.fields.end_date.disabled, false);
        assert.equal(ui.root.dataset.ataValidityDirty, 'false');
    }
});

test('unchecking uses the current ATA saved value even when loaded with INPC enabled', () => {
    const ui = fixture();
    for (const validity_period of ['12 meses', '1 Ano INPC', null, '']) {
        ui.root.listeners['ata-loaded']({ detail: { validity_period, one_year_inpc: true } });
        ui.fields.one_year_inpc.checked = false;
        ui.fields.one_year_inpc.listeners.change();
        assert.equal(ui.fields.validity_period.value, validity_period ?? '');
    }
});

test('unchecking leaves the period empty for a new ATA or after clearing the loaded record', () => {
    const ui = fixture();
    const toggle = () => {
        ui.fields.one_year_inpc.checked = true;
        ui.fields.one_year_inpc.listeners.change();
        ui.fields.one_year_inpc.checked = false;
        ui.fields.one_year_inpc.listeners.change();
        assert.equal(ui.fields.validity_period.value, '');
    };
    toggle();
    ui.root.listeners['ata-loaded']({ detail: { validity_period: '24 meses' } });
    ui.root.listeners['ata-cleared']();
    toggle();
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
