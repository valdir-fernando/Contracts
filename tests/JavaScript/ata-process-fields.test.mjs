import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/atas/create.blade.php', import.meta.url), 'utf8');
const funds = [{ id: 1, name: 'Educação' }, { id: 2, name: 'Saúde' }, { id: 3, name: 'Município' }];
const equalQuantityType = 'Prorrogação/Quantitativo - Igual Período e Valor';
const balanceExtensionType = 'Prorrogação - Saldo Positivo';
const variablePeriodType = 'Prorrogação/Quantitativo - Período e Valor Variável';
const annualIndexType = 'Prorrogação/Quantitativo/Reajuste Anual INDICE';
const apostilleType = 'Apostilamento/Reajuste Anual INDICE';
const annualIndexTypes = [annualIndexType, apostilleType];

test('historical type can be restored only for the selected saved amendment', () => {
    const { state } = editor();
    const historical = 'Prorrogação de vigência';
    state.resetAmendment({ amendments: { 1: { type: historical }, 2: { type: balanceExtensionType } } });
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.historicalAmendmentType, historical);
    assert.equal(state.amendmentFields.type, historical);
    assert.equal(state.requiresAmendmentAdvantage, false);
    state.amendmentFields.type = equalQuantityType;
    assert.equal(state.historicalAmendmentType, historical);
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.historicalAmendmentType, '');
    state.clearAmendmentFields();
    assert.equal(state.historicalAmendmentType, '');
});

test('the historical extension option displays the variable period and value label while retaining its saved value', () => {
    const { state } = editor();
    const [, label] = view.match(/<option :value="historicalAmendmentType"\s+x-text="([^"]+)"/);
    for (const [type, expectedLabel] of [
        ['Prorrogação de vigência', variablePeriodType],
        ['Prorrogação de Vigência', variablePeriodType],
        ['Aditivo histórico', 'Aditivo histórico'],
    ]) {
        state.resetAmendment({ amendments: { 1: { type } } });
        state.amendmentNumber = '001';
        state.lookupAmendment();
        assert.equal(runInNewContext(label, state), expectedLabel);
        assert.equal(state.amendmentFields.type, type);
        assert.equal(state.usesHistoricalVariablePeriodLabel, expectedLabel === variablePeriodType);
        assert.equal(state.editAmendmentBalanceQuantities, false);
    }
});

test('variable period permits edited quantities above the original and shows their calculated value', () => {
    const { state } = editor();
    assert.equal(state.editAmendmentBalanceQuantities, false);
    state.amendmentOpen = true;
    state.amendmentFields.type = variablePeriodType;
    assert.equal(state.editAmendmentBalanceQuantities, true);
    assert.equal(state.limitAmendmentQuantitiesToOriginal, false);
    assert.equal(state.requiresAmendmentAdvantage, true);
    state.amendmentBalanceTotal = '12,3000';
    assert.equal(state.amendmentDisplayedValue, '12,3000');

    state.resetAmendment({ amendments: { 1: { type: variablePeriodType } } });
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.amendmentFields.type, variablePeriodType);
    assert.equal(state.printableAmendment, '');
});

test('copying an amendment type writes its visible label and skips an empty selection', async () => {
    const clipboard = [];
    const { state } = editor({ navigator: { clipboard: { async writeText(text) { clipboard.push(text); } } } });
    const select = { value: '', selectedOptions: [{ label: '' }] };
    state.$refs.ataAmendType = select;
    await state.copyAmendmentType();
    assert.deepEqual(clipboard, []);

    select.value = 'Prorrogação de vigência';
    select.selectedOptions = [{ label: 'Prorrogação/Quantitativo - Período e Valor Variável' }];
    await state.copyAmendmentType();
    assert.deepEqual(clipboard, ['Prorrogação/Quantitativo - Período e Valor Variável']);
    assert.equal(state.amendmentTypeCopyStatus, 'copied');
    assert.equal(select.value, 'Prorrogação de vigência');
});

test('annual index allows saving with the index filled and keeps historical editing available', async () => {
    const clipboard = [];
    const { state } = editor({ navigator: { clipboard: { async writeText(text) { clipboard.push(text); } } } });
    const [label] = view.match(/Prorrogação\/Quantitativo\/Reajuste ANUAL INDICE/);
    const [, expression] = view.match(/data-ata-amendment-save\b[\s\S]*?\s:disabled="([^"]+)"/);
    const saveDisabled = () => runInNewContext(expression, state);
    state.loaded = true;
    state.amendmentFields.type = annualIndexType;
    state.$refs.ataAmendType = { value: annualIndexType, selectedOptions: [{ label }] };
    assert.equal(saveDisabled(), false);
    assert.equal(state.requiresAnnualIndex, true);
    await state.copyAmendmentType();
    assert.deepEqual(clipboard, ['Prorrogação/Quantitativo/Reajuste ANUAL INDICE']);

    state.resetAmendment({ amendments: { 1: { type: annualIndexType }, 2: { type: equalQuantityType } } });
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.amendmentFields.type, annualIndexType);
    assert.equal(saveDisabled(), false);
    assert.equal(state.requiresAnnualIndex, false);
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(saveDisabled(), false);
    state.amendmentFields.type = annualIndexType;
    assert.equal(state.requiresAnnualIndex, true);
});

test('an unavailable clipboard selects the visible amendment type for manual copying', async () => {
    const { state } = editor({ navigator: {} });
    const actions = [];
    const text = { value: '', focus() { actions.push('focus'); }, select() { actions.push('select'); } };
    state.$refs.ataAmendType = { value: equalQuantityType, selectedOptions: [{ label: equalQuantityType }] };
    state.$refs.ataAmendTypeCopyText = text;
    state.$nextTick = callback => callback();

    await state.copyAmendmentType();
    assert.equal(state.amendmentTypeCopyStatus, 'manual');
    assert.equal(text.value, equalQuantityType);
    assert.deepEqual(actions, ['focus', 'select']);
});

test('amendment title follows saving, selecting and clearing a saved amendment', () => {
    const { state } = editor();
    const expression = view.match(/id="ata-amend-title"[^>]*><span x-text="([^"]+)"/)[1];
    const title = () => runInNewContext(expression, state);
    assert.equal(title(), 'Aditivar ATA');
    state.amendmentNumber = '002';
    assert.equal(title(), 'Aditivar ATA');
    state.applySavedAmendment({ amendment_number: 2, version: 'next', amendment: { type: balanceExtensionType } });
    assert.equal(title(), 'Aditivo Nº 2');
    state.clearAmendmentFields();
    assert.equal(title(), 'Aditivar ATA');
    state.resetAmendment({ amendments: { 3: { type: balanceExtensionType } } });
    state.amendmentNumber = '003';
    state.lookupAmendment();
    assert.equal(title(), 'Aditivo Nº 3');
    state.amendmentNumber = '004';
    state.lookupAmendment();
    assert.equal(title(), 'Aditivar ATA');
});

test('positive balance displays zero as the amendment amount and keeps the official ATA total', () => {
    const { state } = editor();
    state.amendmentOpen = true;
    state.amendmentBaseTotal = '125,0000';
    state.amendmentUpdatedTotal = '150,0000';
    state.amendmentFields.type = balanceExtensionType;
    state.amendmentBalanceLineTotals = { 35: '15,0000' };
    assert.equal(state.amendmentDisplayedValue, '0,0000');
    assert.equal(state.amendmentUpdatedTotal, '150,0000');
    state.amendmentFields.type = equalQuantityType;
    assert.equal(state.amendmentDisplayedValue, '125,0000');
});

test('new positive-balance rows are selectable even when the historical flag is absent', () => {
    const { state } = editor();
    const original = { id: 35 };
    state.loaded = true;
    assert.equal(state.canEditAmendmentProduct(original), true);
    assert.equal(state.canEditAmendmentProduct({ ...original, balanceHistoryOnly: true }), false);
    state.publicationLocked = true;
    assert.equal(state.canEditAmendmentProduct(original), false);
    assert.match(view, /aria-describedby="ata-amend-exclusion-notice"\s+:disabled="!canEditAmendmentProduct\(product\)"/);
});

test('Vantajosidade, Indice_Anual and editable quantities follow the selected amendment type', () => {
    const { state } = editor();
    const advantageTypes = [equalQuantityType, balanceExtensionType, variablePeriodType];
    for (const type of [...advantageTypes, ...annualIndexTypes, 'Apostilamento', '']) {
        state.amendmentFields.type = type;
        assert.equal(state.requiresAmendmentAdvantage, [...advantageTypes, ...annualIndexTypes].includes(type));
        assert.equal(state.showsAmendmentAdvantage, [...advantageTypes, ...annualIndexTypes].includes(type));
        assert.equal(state.isAnnualIndexAmendment, annualIndexTypes.includes(type));
        assert.equal(state.showAmendmentQuantities, type === equalQuantityType);
        assert.equal(state.editAmendmentBalanceQuantities, [balanceExtensionType, variablePeriodType, ...annualIndexTypes].includes(type));
        assert.equal(state.limitAmendmentQuantitiesToOriginal, type === balanceExtensionType);
    }
});

test('apostilamento shares the number field and consults its own records, products and signed PDFs', () => {
    const { state } = editor();
    const amended = { type: annualIndexType, process: 'PROC-ADT', annual_index: '5' };
    const apostilled = { type: apostilleType, process: 'PROC-APOST', annual_index: '7',
        balance_products: [{ id: 2, balance_quantity: '3.0000', adjusted_unit_price: '17.2500' }] };
    state.resetAmendment({ amendments: { 1: amended }, amendment_numbers: [1], amendment_signed_documents: { 1: { id: 3 } },
        apostilles: { 1: apostilled }, apostille_numbers: [1], apostille_signed_documents: { 1: { id: 5 } } });
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.amendmentFields.process, 'PROC-ADT');
    assert.equal(state.selectedAmendmentDocument.id, 3);
    state.changeAmendmentType(apostilleType);
    assert.equal(state.amendmentFields.process, 'PROC-APOST');
    assert.equal(state.amendmentFields.annualIndex, '7%');
    assert.equal(state.amendmentManualPrices[2], '17,2500');
    assert.equal(state.selectedAmendmentDocument.id, 5);
    assert.equal(state.removableAmendment, '1');
    for (const [id, label] of [['number', 'Nº Apostilamento'], ['date', 'Data_Apost.'], ['process', 'Proc_Apost.']]) {
        const expression = view.match(new RegExp(`for="ata-amend-${id}" x-text="([^"]+)"`))[1];
        assert.equal(runInNewContext(expression, state), label);
    }
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.amendmentFields.type, apostilleType);
    assert.equal(state.amendmentFields.process, undefined);
    assert.equal(state.savedAmendmentNumber, '');
    assert.equal(state.requiresAnnualIndex, true);
    state.applySavedAmendment({ amendment_number: 2, version: 4, amendment: apostilled, signed_amendment_id: 6 });
    assert.equal(state.apostilles[2].process, 'PROC-APOST');
    assert.equal(state.selectedAmendmentDocument.id, 6);
    assert.equal(state.removableAmendment, '2');
    assert.equal(state.amendments[2], undefined);
});

test('annual adjustment shows the estimated budget date and index type of the loaded ATA', () => {
    const { state } = editor();
    const [, dateBinding] = view.match(/id="ata-amend-budget-date" type="text" readonly :value="([^"]+)"/);
    const [, indexTypeBinding] = view.match(/id="ata-amend-annual-index-type" type="text" readonly :value="([^"]+)"/);
    const visibleDate = () => runInNewContext(dateBinding, state);
    const visibleIndexType = () => runInNewContext(indexTypeBinding, state);
    state.resetAmendment({ budget_date: '2026-10-20', annual_index_type: 'IPCA' });
    assert.equal(visibleDate(), '20/10/2026');
    assert.equal(visibleIndexType(), 'IPCA');
    state.clearAmendmentFields();
    assert.equal(visibleDate(), '20/10/2026');
    assert.equal(visibleIndexType(), 'IPCA');
    state.resetAmendment({ budget_date: '2025-03-07', annual_index_type: 'IGP-M' });
    assert.equal(visibleDate(), '07/03/2025');
    assert.equal(visibleIndexType(), 'IGP-M');
    state.resetAmendment({ budget_date: null, annual_index_type: null });
    assert.equal(visibleDate(), 'Não informado');
    assert.equal(visibleIndexType(), 'Não informado');
    state.resetAmendment();
    assert.equal(visibleDate(), 'Não informado');
    assert.equal(visibleIndexType(), 'Não informado');
});

test('Indice_Anual accepts only decimal numbers and appends a percent sign without rounding', () => {
    const { state } = editor();
    const input = { value: '', selectionStart: 0, selectionEnd: 0, setSelectionRange(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    } };
    const edit = value => {
        input.value = value;
        input.selectionStart = input.selectionEnd = value.length;
        state.editAnnualIndex(input);
        return input.value;
    };
    for (const [entered, displayed] of [
        ['2,23', '2,23%'], ['12,256', '12,256%'], ['12,256700', '12,256700%'], ['4.05%', '4,05%'],
        ['0', '0%'], [',5', '0,5%'], ['2,', '2%'], [',', ''], ['', ''],
    ]) {
        edit(entered);
        state.formatAnnualIndex();
        assert.equal(state.amendmentFields.annualIndex, displayed);
        state.formatAnnualIndex();
        assert.equal(state.amendmentFields.annualIndex, displayed);
        assert.equal(edit(displayed), displayed.replace(/%$/, ''));
    }

    edit('2,23');
    for (const invalid of ['2,2,3', '1.234,56', '-2,23', 'IPCA 4,33', '2,23x']) {
        assert.equal(edit(invalid), '2,23');
        assert.equal(state.amendmentFields.annualIndex, '2,23');
    }
    state.formatAnnualIndex();
    input.value = state.amendmentFields.annualIndex;
    input.selectionStart = 0;
    input.selectionEnd = input.value.length;
    state.editAnnualIndex(input);
    assert.equal(input.value.slice(input.selectionStart, input.selectionEnd), '2,23');
    state.clearAmendmentFields();
    assert.equal(state.amendmentFields.annualIndex, undefined);
});

test('annual amendment restores the manual unit price and submits null to restore the suggestion', () => {
    const { state } = editor();
    state.resetAmendment({ amendments: { 1: { type: annualIndexType, annual_index: '12.256',
        balance_products: [{ id: 31, balance_quantity: '2.0001', adjusted_unit_price: '3.4567' }],
    } } });
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.amendmentManualPrices[31], '3,4567');
    assert.equal(state.amendmentBalanceSubmission[0].adjusted_unit_price, '3,4567');
    delete state.amendmentManualPrices[31];
    assert.equal(state.amendmentBalanceSubmission[0].adjusted_unit_price, null);
    state.amendmentExcludedProducts = ['31'];
    assert.equal(state.amendmentBalanceSubmission.length, 0);
    state.clearAmendmentFields();
    assert.equal(Object.keys(state.amendmentManualPrices).length, 0);
});

test('typing a balance leaves reference quantities intact and New clears the draft', () => {
    const { state } = editor();
    state.resetAmendment({ amendment_base_products: [{ id: 35, item: '2', quantity: '10,0000' }] });
    state.amendmentFields.type = balanceExtensionType;
    state.amendmentBalanceQuantities[35] = '2,5000';
    assert.match(view, /x-model="amendmentBalanceQuantities\[product\.id\]"/);
    assert.equal(state.amendmentBaseProducts[0].quantity, '10,0000');
    assert.equal(state.amendmentBalanceQuantityWidth, 86);
    state.amendmentBalanceQuantities[35] = '123456789,50';
    state.amendmentBalanceQuantities[36] = '10';
    const wide = state.amendmentBalanceQuantityWidth;
    assert.ok(wide > 86);
    state.amendmentBalanceQuantities[35] = '123.456.789,5000';
    assert.equal(state.amendmentBalanceQuantityWidth, wide);
    state.amendmentFields.type = equalQuantityType;
    assert.equal(state.amendmentBalanceQuantityWidth, 86);
    state.amendmentFields.type = balanceExtensionType;
    state.amendmentBalanceQuantities[35] = '1';
    assert.equal(state.amendmentBalanceQuantityWidth, 86);
    state.clearAmendmentFields();
    assert.equal(state.amendmentBalanceQuantities[35], undefined);
});

test('consulting a positive-balance amendment shows the saved exclusions and New clears the selection', () => {
    const { state } = editor();
    state.resetAmendment({ amendments: { 2: { type: balanceExtensionType, excluded_product_ids: [35] } } });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.deepEqual(Array.from(state.amendmentExcludedProducts), ['35']);
    assert.match(view, /x-model="amendmentExcludedProducts"/);
    state.clearAmendmentFields();
    assert.equal(state.amendmentExcludedProducts.length, 0);
});

test('consulting the amendment restores its saved balances and historical product details', () => {
    const { state } = editor();
    state.resetAmendment({
        amendment_base_products: [{ id: 35, description: 'Descrição atual' }, { id: 36, description: 'Outro item' }],
        amendments: { 2: { type: balanceExtensionType, balance_products: [
            { id: 35, description: 'Descrição gravada', balance_quantity: '2.5000' },
            { id: 37, description: 'Produto histórico', balance_quantity: '0.7500' },
        ] } },
    });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.amendmentBalanceQuantities[35], '2,5000');
    assert.equal(state.amendmentProducts[0].description, 'Descrição gravada');
    assert.deepEqual(Array.from(state.amendmentProducts, product => product.id), [35, 37]);
    assert.equal(state.amendmentProducts[1].balanceHistoryOnly, true);
    state.showAllAmendmentProducts = true;
    assert.deepEqual(Array.from(state.amendmentProducts, product => product.id), [35, 36, 37]);
    state.amendmentExcludedProducts = ['35'];
    assert.equal(state.amendmentBalanceSubmission.length, 1);
    assert.equal(state.amendmentBalanceSubmission[0].product_id, '37');
});

test('a saved amendment with exclusions and no recorded quantities hides its excluded products by default', () => {
    const { state } = editor();
    state.resetAmendment({
        amendment_base_products: [2, 3, 4, 5, 6].map(id => ({ id })),
        amendments: { 2: { type: balanceExtensionType, excluded_product_ids: [5, 6] } },
    });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.deepEqual(Array.from(state.amendmentProducts, product => product.id), [2, 3, 4]);
    assert.equal(Object.keys(state.amendmentBalanceQuantities).length, 0);
    state.showAllAmendmentProducts = true;
    assert.deepEqual(Array.from(state.amendmentProducts, product => product.id), [2, 3, 4, 5, 6]);
});

test('amendment list shows four products and reserves the fifth row height above the controls', () => {
    const { state } = editor();
    const row = bottom => ({ getBoundingClientRect: () => ({ bottom }) });
    const rows = [row(98), row(134), row(175), row(211.5), row(254)];
    const properties = new Map();
    const list = {
        scrollTop: 20,
        getBoundingClientRect: () => ({ top: 50 }),
        querySelectorAll: () => rows,
        style: { setProperty: (key, value) => properties.set(key, value), removeProperty: key => properties.delete(key) },
    };
    state.$refs.ataAmend = { open: true };
    state.$refs.ataAmendProductList = list;

    state.sizeAmendmentProductList();
    assert.equal(list.style.maxHeight, '182px');
    assert.equal(properties.get('--ata-amend-list-spacer'), '42px');
    rows.pop();
    state.sizeAmendmentProductList();
    assert.equal(list.style.maxHeight, '');
    assert.equal(properties.size, 0);
});

test('equal-quantity emission requires a saved amendment with unchanged fields', () => {
    const { state } = editor();
    const saved = { type: equalQuantityType, date: '2026-11-20', start: '2026-12-01', end: '2027-11-30',
        process: 'PROC-ADT', period: '12 meses', advantage: 'Pesquisa de Preços' };
    state.resetAmendment({ amendment_numbers: [2], amendments: { 2: saved, 1: { ...saved, type: 'Prorrogação - Saldo Positivo' } } });
    state.amendmentNumber = '002';
    assert.equal(state.printableAmendment, '');
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '2');
    state.amendmentFields.advantage = 'Declaração do Gestor';
    assert.equal(state.printableAmendment, '');
    state.amendmentFields.advantage = saved.advantage;
    state.amendmentFields.period = '24 meses';
    assert.equal(state.printableAmendment, '');
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '');
});

test('positive-balance emission uses only saved balances and exclusions', () => {
    const { state } = editor();
    const saved = { type: balanceExtensionType, date: '2026-11-20', start: '2026-12-01', end: '2027-11-30',
        process: 'PROC-ADT', period: '12 meses', advantage: 'Pesquisa de Preços',
        balance_products: [{ id: 35, balance_quantity: '2.5000' }], excluded_product_ids: [36] };
    state.resetAmendment({ amendments: { 2: saved } });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '2');
    state.amendmentBalanceQuantities[35] = '3';
    assert.equal(state.printableAmendment, '');
    state.amendmentBalanceQuantities[35] = '2,5';
    assert.equal(state.printableAmendment, '2');
    state.amendmentExcludedProducts = [];
    assert.equal(state.printableAmendment, '');
});

test('variable-period emission requires saved products and rejects unsaved changes', () => {
    const { state } = editor();
    const saved = { type: variablePeriodType, date: '2026-11-20', start: '2026-12-01', end: '2027-08-31',
        process: 'PROC-ADT', period: '9 meses', advantage: 'Declaração do Gestor', has_registered_products: true,
        balance_products: [{ id: 35, balance_quantity: '3.1250' }], excluded_product_ids: [36] };
    state.resetAmendment({ amendments: { 2: saved } });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '2');
    state.amendmentBalanceQuantities[35] = '5';
    assert.equal(state.printableAmendment, '');
    state.applySavedAmendment({ amendment_number: 2, version: 3,
        amendment: { ...saved, balance_products: [{ id: 35, balance_quantity: '5.0000' }] } });
    assert.equal(state.printableAmendment, '2');
    state.amendmentExcludedProducts = [];
    assert.equal(state.printableAmendment, '');

    const { balance_products, ...imported } = saved;
    state.resetAmendment({ amendments: { 2: imported, 1: { ...imported, has_registered_products: false } } });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '2');
    state.amendmentBalanceQuantities[35] = '1';
    assert.equal(state.printableAmendment, '');
    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '');
});

test('annual index emission requires saved products, percentage and unit prices', () => {
    const { state } = editor();
    const saved = { type: annualIndexType, date: '2026-11-20', start: '2026-12-01', end: '2027-08-31', process: 'PROC-ADT',
        period: '9 meses', advantage: 'Declaração do Gestor', annual_index: '12.256', has_registered_products: true,
        balance_products: [{ id: 35, balance_quantity: '2.0001', adjusted_unit_price: '3.4567' }], excluded_product_ids: [36] };
    state.resetAmendment({ amendments: { 2: saved, 1: { ...saved, has_registered_products: false } } });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '2');
    state.amendmentManualPrices[35] = '3,4577';
    assert.equal(state.printableAmendment, '');
    state.amendmentManualPrices[35] = '3,4567';
    state.amendmentFields.annualIndex = '2,23%';
    assert.equal(state.printableAmendment, '');
    state.amendmentFields.annualIndex = '12,256%';
    assert.equal(state.printableAmendment, '2');
    delete state.amendmentManualPrices[35];
    assert.equal(state.printableAmendment, '');
    state.applySavedAmendment({ amendment_number: 2, version: 3,
        amendment: { ...saved, balance_products: [{ id: 35, balance_quantity: '2.0001' }] } });
    assert.equal(state.printableAmendment, '2');
    state.amendmentManualPrices[35] = '1,2,3';
    assert.equal(state.printableAmendment, '');
    state.amendmentExcludedProducts = [];
    assert.equal(state.printableAmendment, '');

    state.amendmentNumber = '001';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '');
    state.resetAmendment({ amendments: { 2: { ...saved, annual_index: null } } });
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.printableAmendment, '');
});

test('amendment reference products are always shown and remain the same after looking up an amendment', () => {
    const state = editor().state;
    state.loaded = true;
    state.resetAmendment({ amendment_numbers: [2], amendments: { 2: { type: 'Prorrogação - Saldo Positivo' } }, amendment_base_products: [{ item: '9' }] });
    const list = view.match(/<div class="product-list-scroll product-contract-list ata-amend-product-list"[^>]*>/)[0];
    assert.doesNotMatch(list, /x-show|x-cloak|hidden/);
    assert.equal(state.amendmentBaseProducts[0].item, '9');
    state.amendmentNumber = '002';
    state.lookupAmendment();
    assert.equal(state.amendmentBaseProducts[0].item, '9');
    state.clearLoaded();
    assert.equal(state.amendmentBaseProducts.length, 0);
});

test('toolbar keeps New available and locks Save and Delete after publication', () => {
    const toolbar = view.slice(view.indexOf('aria-label="Ações da ata"'), view.indexOf('<dialog class="catalog-modal"'));
    const save = toolbar.split('\n').find(line => line.includes('data-ata-save'));
    const remove = toolbar.split('\n').find(line => line.includes('data-ata-delete'));
    const fresh = toolbar.match(/<a[^>]+>/)[0];
    assert.doesNotMatch(fresh, /disabled|pointer-events-none/);
    const saveDisabled = save.match(/:disabled="([^"]+)"/)[1];
    const removeDisabled = remove.match(/:disabled="([^"]+)"/)[1];
    for (const publicationLocked of [false, true]) {
        assert.equal(runInNewContext(saveDisabled, { publicationLocked }), publicationLocked);
        for (const loaded of [false, true]) {
            assert.equal(runInNewContext(removeDisabled, { loaded, publicationLocked }), !loaded || publicationLocked);
        }
    }
});

test('products opens a modal with the current supplier and closes without changing the ata', () => {
    const open = view.match(/aria-controls="ata-products-dialog" @click="([^"]+)"/)[1];
    const close = view.match(/@click="([^"]+)" aria-label="Fechar cadastro de produtos"/)[1];
    const supplier = { value: 'Fornecedor <Teste>' };
    const dialog = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    const heading = { textContent: '' };
    const ticks = [], dispatched = [];
    const context = { $refs: { ataProducts: dialog, ataProductsSupplier: heading },
        $root: { querySelector: () => supplier }, $nextTick: callback => ticks.push(callback), $dispatch: name => dispatched.push(name) };

    runInNewContext(open, context);
    assert.equal(dialog.open, true);
    assert.equal(heading.textContent, supplier.value);
    assert.deepEqual(dispatched, []);
    ticks.shift()();
    assert.deepEqual(dispatched, ['ata-products-opened']);
    runInNewContext(close, context);
    assert.equal(dialog.open, false);
    assert.equal(supplier.value, 'Fornecedor <Teste>');

    supplier.value = 'Outro fornecedor';
    runInNewContext(open, context);
    assert.equal(heading.textContent, 'Outro fornecedor');
    runInNewContext(close, context);
    supplier.value = '';
    runInNewContext(open, context);
    assert.equal(heading.textContent, 'Nome / Razão Social');
});

function editor({ fetch, initial, navigator } = {}) {
    const expression = view.match(/<div x-data="([\s\S]*?)" data-draft-state=/)[1]
        .replace('@js($funds)', JSON.stringify(funds))
        .replace('@js($amendmentTypes)', JSON.stringify([balanceExtensionType, equalQuantityType, variablePeriodType, ...annualIndexTypes]))
        .replace('@js($variablePeriodType)', JSON.stringify(variablePeriodType))
        .replaceAll('@js($apostilleAnnualIndexType)', JSON.stringify(apostilleType))
        .replace('@js(\\App\\Infrastructure\\Atas\\SaveAtaAmendment::ANNUAL_INDEX_TYPES)', JSON.stringify(annualIndexTypes))
        .replace('@js(\\App\\Infrastructure\\Atas\\SaveAtaAmendment::EQUAL_QUANTITY)', JSON.stringify(equalQuantityType))
        .replace('@js(\\App\\Infrastructure\\Atas\\SaveAtaAmendment::EXTENSION)', JSON.stringify(balanceExtensionType))
        .replace('@js(\\App\\Infrastructure\\Atas\\SaveAtaAmendment::ADVANTAGE_TYPES)', JSON.stringify([balanceExtensionType, equalQuantityType, variablePeriodType, ...annualIndexTypes]))
        .replace('@js(\\App\\Infrastructure\\Atas\\SaveAtaAmendment::SUPPORTED_TYPES)', JSON.stringify([balanceExtensionType, equalQuantityType, variablePeriodType, ...annualIndexTypes]));
    let draftUrl;
    const state = runInNewContext(`(${expression})`, { fetch, URL, CustomEvent, navigator, window: {
        location: { href: 'http://localhost/atas/create' },
        history: { replaceState: (_, __, url) => { draftUrl = url; } },
    } });
    state.$el = { dataset: { draftToken: 'signed-draft', participantsUrl: '/atas/participants', csrfToken: 'csrf-token',
        draftState: JSON.stringify(initial || { version: 0, process: '', managing_fund_id: '', participants: [] }) } };
    state.$refs = { ataParticipantRemoval: { open: false, showModal() { this.open = true; }, close() { this.open = false; } } };
    let onManagerChange;
    state.$watch = (property, callback) => {
        assert.equal(property, 'managingFund');
        onManagerChange = callback;
    };
    state.init();
    return {
        state,
        selectManager(value) {
            state.managingFund = value;
            onManagerChange?.(value);
        },
        participantIds: () => Array.from(state.participantFunds, fund => fund.id),
        draftUrl: () => draftUrl,
    };
}

test('a loaded ata cannot add or remove participants from the draft', async () => {
    let calls = 0;
    const form = editor({ fetch: async () => { calls++; } });
    form.state.loaded = true;
    form.state.participants = [{ id: 99, FUNDO: 'Participante salvo' }];
    await form.state.addParticipant();
    await form.state.removeParticipant(form.state.participants[0]);
    assert.equal(calls, 0);
    assert.equal(form.state.participants[0].FUNDO, 'Participante salvo');
});

test('participant selection requires a saved, unpublished ATA', () => {
    assert.match(view, /id="ata-participant-fund"[^>]*disabled :disabled="busy \|\| publicationLocked \|\| !loaded"/);
});

test('loaded ata adds to its own endpoint and version without changing the draft', async () => {
    let request;
    const form = editor({ fetch: async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ version: 5, participants: [{ id: 21, fund_id: 2, FUNDO: 'Saúde' }] }) };
    } });
    const loaded = view.match(/@ata-loaded="([^"]+)"/)[1];
    form.state.resetAmendment = form.state.resetAmendment.bind(form.state);
    form.state.$event = { detail: { id: 107, version: 4, process: 'PROC', managing_fund_id: '1', participants: [] } };
    runInNewContext(loaded, form.state);
    assert.equal(form.state.loaded, true);
    assert.equal(form.state.loadedAtaId, 107);
    form.state.version = 3;
    form.state.participantFund = '2';
    await form.state.addParticipant();
    assert.equal(request.url, '/atas/107/participants');
    assert.deepEqual(JSON.parse(request.options.body), { version: 4, participant_fund_id: '2' });
    assert.equal(form.state.loadedVersion, 5);
    assert.equal(form.state.version, 3);
    assert.equal(form.state.participants[0].FUNDO, 'Saúde');
    assert.equal(form.state.participantFund, '');
    assert.equal(form.state.busy, false);
    form.state.clearLoaded();
    assert.equal(form.state.loadedAtaId, 0);
    assert.equal(form.state.loadedVersion, 0);
});

function loadedEditor(fetcher) {
    const { state } = editor({ fetch: fetcher });
    state.loaded = true;
    state.loadedAtaId = 107;
    state.loadedVersion = 4;
    state.version = 2;
    const dialog = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    state.$refs.ataManagerReplacement = dialog;
    const events = [];
    state.$el.dispatchEvent = event => events.push(event);
    return { state, dialog, events };
}

test('saved participant delete targets the row with saved version and never alters draft version', async () => {
    let request;
    const { state } = loadedEditor(async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ version: 5, manager_changed: false, participants: [{ id: 1, Tipo_Fundo: 'Gerenciador' }] }) };
    });
    state.participants = [{ id: 1 }, { id: 2 }];
    await state.removeParticipant({ id: 2, Tipo_Fundo: 'Participante' });
    assert.equal(request, undefined);
    assert.equal(state.$refs.ataParticipantRemoval.open, true);
    await state.confirmParticipantRemoval();
    assert.equal(request.url, '/atas/107/participants/2');
    assert.equal(request.options.method, 'DELETE');
    assert.deepEqual(JSON.parse(request.options.body), { version: 4 });
    assert.equal(state.loadedVersion, 5);
    assert.equal(state.version, 2);
    assert.equal(state.participants.length, 1);
    assert.equal(state.busy, false);
});

test('cancel or Escape clears pending participant and cannot submit a deletion', async () => {
    let calls = 0;
    const { state } = loadedEditor(async () => { calls++; });
    const participant = { id: 2, fund_id: 2, FUNDO: 'Saúde', Tipo_Fundo: 'Participante' };
    state.participants = [participant];
    await state.removeParticipant(participant);
    assert.equal(calls, 0);
    state.$refs.ataParticipantRemoval.close();
    const close = view.match(/x-ref="ataParticipantRemoval"[^>]*@close="([^"]+)"/)[1];
    runInNewContext(close, state);
    await state.confirmParticipantRemoval();
    assert.equal(calls, 0);
    assert.equal(state.participants[0], participant);
    assert.equal(state.loadedVersion, 4);
});

test('deleting manager opens replacement dialog without any request and excludes that fund', async () => {
    let calls = 0;
    const { state, dialog } = loadedEditor(async () => { calls++; });
    const manager = { id: 7, fund_id: 1, Tipo_Fundo: 'Gerenciador' };
    await state.removeParticipant(manager);
    assert.equal(calls, 0);
    assert.equal(dialog.open, true);
    assert.equal(state.pendingRemoval, manager);
    assert.deepEqual(Array.from(state.replacementFunds, fund => fund.id), [2, 3]);
});

test('confirmed replacement sends selected fund and refreshes authorized ata after success', async () => {
    let payload;
    const { state, dialog, events } = loadedEditor(async (_, options) => {
        payload = JSON.parse(options.body);
        return { ok: true, json: async () => ({ version: 5, number: '107/25', manager_changed: true,
            participants: [{ id: 9, fund_id: 2, Tipo_Fundo: 'Gerenciador' }] }) };
    });
    const manager = { id: 7, fund_id: 1, Tipo_Fundo: 'Gerenciador' };
    await state.removeParticipant(manager);
    await state.removeSavedParticipant(state.pendingRemoval, '2');
    assert.deepEqual(payload, { version: 4, replacement_fund_id: '2' });
    assert.equal(dialog.open, false);
    assert.equal(state.pendingRemoval, null);
    assert.equal(state.loadedVersion, 5);
    assert.equal(events[0].type, 'ata-saved');
    assert.equal(events[0].bubbles, true);
    assert.equal(events[0].detail.number, '107/25');
});

test('server manager requirement opens dialog for historical row and failure preserves the row', async () => {
    const { state, dialog } = loadedEditor(async () => ({ ok: false, json: async () => ({
        errors: { replacement_fund_id: ['Uma ata salva não pode ficar sem Fundo Gerenciador.'] },
    }) }));
    const row = { id: 7, fund_id: 1, Tipo_Fundo: 'Participante' };
    state.participants = [row];
    await state.removeParticipant(row);
    await state.confirmParticipantRemoval();
    assert.equal(dialog.open, true);
    assert.equal(state.participants[0], row);
    assert.match(state.replacementMessage, /não pode ficar sem/);
    assert.equal(state.loadedVersion, 4);
    assert.equal(state.busy, false);
});

test('clearing a saved ata restores the current draft including participants added after page load', () => {
    const { state } = editor();
    state.process = 'RASCUNHO NOVO';
    state.managingFund = '2';
    state.version = 3;
    state.participants = [{ id: 5, FUNDO: 'Adicionado agora' }];
    state.clearLoaded();
    state.loaded = true;
    state.process = 'ATA SALVA';
    state.managingFund = '3';
    state.participants = [{ id: 100, FUNDO: 'Participante da ata' }];
    state.clearLoaded();
    assert.equal(state.process, 'RASCUNHO NOVO');
    assert.equal(state.managingFund, '2');
    assert.equal(state.participants[0].FUNDO, 'Adicionado agora');
    assert.equal(state.version, 3);
});

test('participants list all funds initially and include the current manager after each change', () => {
    const form = editor();
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    form.selectManager('2');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    form.selectManager('1');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    form.selectManager('');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
});

test('add sends only identifiers and process with CSRF and renders confirmed server rows', async () => {
    let sent;
    const participant = { id: 10, fund_id: 2, ATA: null, Processo_ATA: '01/2026', CNPJ_Fundo: '11222333000181', FUNDO: 'Saúde', Tipo_Fundo: 'Participante' };
    const form = editor({ fetch: async (url, options) => {
        sent = { url, ...options };
        return { ok: true, json: async () => ({ version: 1, process: '01/2026', managing_fund_id: '1', participants: [participant] }) };
    } });
    assert.equal(form.draftUrl().searchParams.get('draft'), 'signed-draft');
    form.selectManager('1');
    form.state.process = '01/2026';
    form.state.participantFund = '2';
    // Alpine's $el during a click is the button, rather than the root component.
    form.state.$el = { dataset: {} };
    await form.state.addParticipant();
    assert.deepEqual(JSON.parse(sent.body), { draft_token: 'signed-draft', version: 0,
        ata_process: '01/2026', managing_fund_id: '1', participant_fund_id: '2' });
    assert.equal(sent.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(sent.method, 'POST');
    assert.equal(sent.url, '/atas/participants');
    assert.equal(form.state.version, 1);
    assert.equal(form.state.participants[0].ATA, null);
    assert.deepEqual(form.participantIds(), [1, 3]);
    assert.equal(form.state.participantFund, '');
    assert.equal(form.state.busy, false);
});

test('validation and network failures preserve the selection and never invent a saved row', async () => {
    let failNetwork = false;
    const form = editor({ fetch: async () => {
        if (failNetwork) throw new Error('network');
        return { ok: false, json: async () => ({ errors: { participant_fund_id: ['Fundo duplicado.'] } }) };
    } });
    form.state.participantFund = '2';
    await form.state.addParticipant();
    assert.equal(form.state.message, 'Fundo duplicado.');
    assert.equal(form.state.participants.length, 0);
    assert.equal(form.state.participantFund, '2');
    failNetwork = true;
    await form.state.addParticipant();
    assert.match(form.state.message, /Recarregue/);
    assert.equal(form.state.participants.length, 0);
    assert.equal(form.state.busy, false);
});

test('pending add blocks repeated clicks and loaded drafts retain their participants', async () => {
    let calls = 0;
    let finish;
    const initial = { version: 1, process: '01/2026', managing_fund_id: '1', participants: [{ id: 10, fund_id: 2 }] };
    const form = editor({ initial, fetch: () => {
        calls++;
        return new Promise(resolve => { finish = resolve; });
    } });
    assert.equal(form.state.version, 1);
    assert.deepEqual(form.participantIds(), [1, 3]);
    const request = form.state.addParticipant();
    await form.state.addParticipant();
    assert.equal(calls, 1);
    finish({ ok: false, json: async () => ({ message: 'Conflito. Recarregue.' }) });
    await request;
    assert.equal(form.state.participants.length, 1);
    assert.equal(form.state.busy, false);
});

test('changing manager preserves the selection including the same fund', () => {
    const form = editor();
    form.state.participantFund = '2';
    form.selectManager('1');
    assert.equal(form.state.participantFund, '2');
    form.selectManager('2');
    assert.equal(form.state.participantFund, '2');
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
});

test('delete sends the saved version and only removes rows after server confirmation', async () => {
    let sent;
    let ok = false;
    const participant = { id: 10, fund_id: 2 };
    const form = editor({ initial: { version: 1, process: '01/2026', managing_fund_id: '1', participants: [participant] },
        fetch: async (url, options) => {
            sent = { url, ...options };
            return { ok, json: async () => ok ? { version: 2, participants: [] } : { message: 'Conflito' } };
        } });
    await form.state.removeParticipant(participant);
    await form.state.confirmParticipantRemoval();
    assert.equal(form.state.participants.length, 1);
    assert.equal(form.state.version, 1);
    ok = true;
    await form.state.removeParticipant(participant);
    await form.state.confirmParticipantRemoval();
    assert.equal(sent.method, 'DELETE');
    assert.equal(sent.url, '/atas/participants/10');
    assert.equal(sent.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(JSON.parse(sent.body), { draft_token: 'signed-draft', version: 1 });
    assert.equal(form.state.participants.length, 0);
    assert.equal(form.state.version, 2);
    assert.deepEqual(form.participantIds(), [1, 2, 3]);
    assert.equal(form.state.busy, false);
});
