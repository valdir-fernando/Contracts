import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAtaProducts, initializeAtaProductPicker, ataProductTotal, fitAtaDescription, limitAtaQuantity } from '../../resources/js/ata-products.js';

const response = (product = {}) => ({ ok: true, json: async () => ({ process: 'PROC', product: {
    id: 1, item: '001', code: '00042', brand: 'Marca', unit: 'CX', remaining: '1234.5678', unit_price: '12.3400', description: '<Produto seguro>', ...product,
} }) });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

test('empty-item picker lists safe process data, selects an item and ignores stale responses', async () => {
    const node = () => ({ listeners: {}, children: [], textContent: '',
        addEventListener(event, handler) { this.listeners[event] = handler; },
        append(child) { this.children.push(child); }, replaceChildren() { this.children = []; } });
    const controls = Object.fromEntries(['rows', 'status', 'previous', 'next', 'cancel', 'page', 'context'].map(name => [`[data-product-picker-${name}]`, node()]));
    const dialog = { ...node(), dataset: { ataProductListUrl: '/atas/0/products/catalog' },
        querySelector: selector => controls[selector], showModal() { this.open = true; },
        close() { this.open = false; this.listeners.close(); } };
    const root = { dataset: { ataLoaded: '81', editorBusy: 'false' }, listeners: {},
        ownerDocument: { baseURI: 'http://contracts.test/', createElement: node },
        querySelector: () => ({ value: 'PROC' }), addEventListener(event, handler) { this.listeners[event] = handler; } };
    const item = { value: '' };
    let selections = 0;
    const pending = [];
    const open = initializeAtaProductPicker(root, { querySelector: () => dialog }, item, () => selections++, (url) => {
        const request = deferred(); pending.push({ ...request, url }); return request.promise;
    });
    const loading = open();
    assert.equal(dialog.open, true);
    assert.equal(pending[0].url.pathname, '/atas/81/products/catalog');
    pending[0].resolve({ ok: true, json: async () => ({ process: 'PROC', page: 1, last_page: 1,
        products: [{ item: '2', description: '<texto>', remaining: '1', unit_price: '2' }] }) });
    await loading;
    const row = controls['[data-product-picker-rows]'].children[0];
    assert.equal(row.children[2].textContent, '<texto>');
    row.children[7].children[0].listeners.click();
    assert.equal(item.value, '2');
    assert.equal(selections, 1);
    assert.equal(dialog.open, false);
    const late = open();
    root.listeners['ata-cleared']();
    pending[1].resolve({ ok: true, json: async () => ({ process: 'PROC', products: [{ item: 'old' }] }) });
    await late;
    assert.equal(controls['[data-product-picker-rows]'].children.length, 0);
});

test('description shrinks to fit, stops at 10px with scroll and restores size for short text', () => {
    let length = 1000;
    const input = { style: {}, clientWidth: 200, clientHeight: 76,
        get scrollHeight() { return length * parseFloat(this.style.fontSize || '13') / 100; } };
    const style = () => ({ fontSize: '13px' });
    fitAtaDescription(input, style);
    assert.equal(input.style.fontSize, '10px');
    assert.equal(input.style.overflowY, 'auto');
    length = 700;
    fitAtaDescription(input, style);
    assert.ok(parseFloat(input.style.fontSize) > 10);
    assert.equal(input.style.overflowY, 'hidden');
    length = 100;
    fitAtaDescription(input, style);
    assert.equal(input.style.fontSize, '');
    assert.equal(input.style.overflowY, 'hidden');
});

function fixture(fetcher) {
    const control = value => ({ value, listeners: {}, style: {}, scrollHeight: 70,
        closest() { return this.ownerDialog ?? null; },
        focus() { this.focused = true; },
        select() { this.selected = true; },
        setAttribute(name, value) { this[name] = value; },
        addEventListener(name, callback) { this.listeners[name] = callback; } });
    const fields = Object.fromEntries(['item', 'code', 'brand', 'unit', 'disp', 'unit_price'].map(name => [name, control('')]));
    const feedback = { textContent: '', dataset: { ataItemLookupUrl: '/atas/0/products/item-lookup' } };
    const description = control('');
    const opener = control('');
    const manual = control('');
    const quantity = control('0,0000');
    const specialQuantity = control('0,0000');
    const total = control('0,00');
    const more = control('');
    const less = control('');
    const dialog = control('');
    const newProduct = control('');
    const nestedDialogControl = control('');
    nestedDialogControl.ownerDialog = { nested: true };
    Object.values(fields).forEach(control => { control.ownerDialog = { nested: false }; });
    const panel = { contains(dialog) { return dialog.nested; }, querySelectorAll() { return [...Object.values(fields), description, opener, manual, quantity, specialQuantity, total, more, less, newProduct, nestedDialogControl]; }, querySelector(selector) {
        if (selector.startsWith('[data-product-field=')) return fields[selector.slice(21, -2)];
        return { '[data-ata-total-more]': more, '[data-ata-total-less]': less,
            '[data-products-new]': newProduct,
            '[data-product-special-quantity]': specialQuantity,
            '[data-product-line-total]': total, '[data-ata-products-manual]': manual, '[data-product-quantity]': quantity,
            '[data-ata-item-lookup-url]': feedback, '[data-product-description]': description, '[data-product-item-search]': opener }[selector];
    } };
    const root = { dataset: { ataLoaded: '107', editorBusy: 'false' }, listeners: {},
        ownerDocument: { baseURI: 'http://contracts.test/', defaultView: { getComputedStyle: () => ({ lineHeight: '19.2px' }) } },
        querySelector(selector) { return selector === '.contract-products-preview' ? panel : selector === '#ata-products-dialog' ? dialog : { value: 'PROC' }; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
    };
    const warnings = [];
    initializeAtaProducts(root, fetcher, message => warnings.push(message));
    return { root, fields, feedback, description, opener, warnings, manual, quantity, specialQuantity, total, more, less, dialog, newProduct, nestedDialogControl,
        input(value) { fields.item.value = value; fields.item.listeners.input?.(); },
        lookup: () => fields.item.listeners.blur(),
    };
}

const selectedProduct = (extra = {}) => ({ detail: { ataId: '107', process: 'PROC', product: {
    item: '2', code: '0042', brand: 'Marca salva', unit: 'KM', quantity: '20000.0000',
    unit_price: '8.5000', total: '169999.9999', description: '<Descrição salva>', ...extra,
} } });

test('special quantity participates in Tab only for configured ATAs and selection restores its value', async () => {
    const ui = fixture(() => { assert.fail('Manual selection must not consult the catalog'); });
    assert.equal(ui.specialQuantity.tabIndex, -1);
    ui.root.dataset.ataSpecialQuantityName = 'veículos';
    ui.manual.checked = true;
    ui.manual.listeners.change();
    assert.equal(ui.specialQuantity.tabIndex, 0);
    const event = { key: 'Tab', preventDefault() {} };
    await ui.quantity.listeners.keydown(event);
    assert.equal(ui.specialQuantity.focused, true);
    await ui.specialQuantity.listeners.keydown(event);
    assert.equal(ui.fields.unit_price.focused, true);
    await ui.root.listeners['ata-product-selected'](selectedProduct({ special_quantity: '1234.5678' }));
    assert.equal(ui.specialQuantity.value, '1.234,5678');
    assert.equal(ui.total.value, '169.999,9999');
});

test('opening products focuses and selects Item with the current Tab order on each opening', () => {
    const ui = fixture(() => { assert.fail('Opening the panel must not query products'); });
    ui.dialog.open = true;
    ui.root.listeners['ata-products-opened']();
    assert.equal(ui.fields.item.focused, true);
    assert.equal(ui.fields.item.selected, true);
    assert.equal(ui.fields.item.tabIndex, 0);
    assert.equal(ui.fields.code.tabIndex, -1);
    assert.equal(ui.newProduct.tabIndex, -1);
    ui.manual.checked = true;
    ui.fields.item.focused = false;
    ui.fields.item.selected = false;
    ui.root.listeners['ata-products-opened']();
    assert.equal(ui.fields.item.focused, true);
    assert.equal(ui.fields.item.selected, true);
    assert.equal(ui.fields.code.tabIndex, 0);
    assert.equal(ui.description.tabIndex, 0);
    assert.equal(ui.fields.disp.tabIndex, -1);
    ui.root.dataset.editorBusy = 'true';
    ui.fields.item.focused = false;
    ui.root.listeners['ata-products-opened']();
    assert.equal(ui.fields.item.focused, false);
    ui.root.dataset.editorBusy = 'false';
    ui.dialog.open = false;
    ui.root.listeners['ata-products-opened']();
    assert.equal(ui.fields.item.focused, false);
});

test('Tab from Item waits for lookup and focuses QTD, while manual mode starts at code without lookup', async () => {
    const request = deferred();
    let calls = 0;
    const ui = fixture(() => { calls++; return request.promise; });
    ui.input('2');
    ui.root.ownerDocument.activeElement = ui.fields.item;
    let prevented = false;
    const pending = ui.fields.item.listeners.keydown({ key: 'Tab', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(ui.quantity.focused, undefined);
    request.resolve(response());
    await pending;
    assert.equal(ui.quantity.focused, true);
    assert.equal(calls, 1);
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.quantity.focused = false;
    await ui.fields.item.listeners.keydown({ key: 'Tab', preventDefault() {} });
    assert.equal(ui.quantity.focused, false);
    assert.equal(ui.fields.code.focused, true);
    assert.equal(calls, 1);
});

test('manual Tab cycles through entry fields including description and excludes other controls', async () => {
    const ui = fixture(() => { assert.fail('Manual keyboard navigation must not query the catalog'); });
    ui.manual.checked = true;
    ui.manual.listeners.change();
    const controls = [ui.fields.item, ui.fields.code, ui.fields.brand, ui.fields.unit, ui.quantity, ui.fields.unit_price, ui.description];
    for (let index = 0; index < controls.length - 1; index++) {
        let prevented = false;
        await controls[index].listeners.keydown({ key: 'Tab', preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(controls[index + 1].focused, true);
    }
    for (let index = controls.length - 1; index > 0; index--) {
        controls[index - 1].focused = false;
        await controls[index].listeners.keydown({ key: 'Tab', shiftKey: true, preventDefault() {} });
        assert.equal(controls[index - 1].focused, true);
    }
    ui.fields.item.focused = false;
    await controls.at(-1).listeners.keydown({ key: 'Tab', preventDefault() {} });
    assert.equal(ui.fields.item.focused, true);
    ui.description.focused = false;
    await controls[0].listeners.keydown({ key: 'Tab', shiftKey: true, preventDefault() {} });
    assert.equal(ui.description.focused, true);
    for (const control of controls) assert.equal(control.tabIndex, 0);
    for (const control of [ui.opener, ui.manual, ui.fields.disp, ui.total, ui.more, ui.less, ui.newProduct]) assert.equal(control.tabIndex, -1);
    assert.equal(ui.nestedDialogControl.tabIndex, undefined);
    ui.manual.checked = false;
    ui.manual.listeners.change();
    assert.equal(ui.fields.code.tabIndex, -1);
    assert.equal(ui.description.tabIndex, -1);
    await ui.quantity.listeners.keydown({ key: 'Tab', preventDefault() {} });
    assert.equal(ui.fields.item.focused, true);
});

test('Shift+Tab keeps native navigation and a pending lookup never steals focus', async () => {
    const request = deferred();
    const ui = fixture(() => request.promise);
    ui.input('2');
    ui.root.ownerDocument.activeElement = ui.fields.item;
    await ui.fields.item.listeners.keydown({ key: 'Tab', shiftKey: true, preventDefault() { assert.fail('Native reverse navigation must remain available'); } });
    const pending = ui.fields.item.listeners.keydown({ key: 'Tab', preventDefault() {} });
    ui.root.ownerDocument.activeElement = ui.manual;
    request.resolve(response());
    await pending;
    assert.equal(ui.quantity.focused, undefined);
});

test('selecting a saved product fills exact saved fields and refreshes only availability from the catalog', async () => {
    const ui = fixture(async () => response({ remaining: '30000', description: 'Catálogo diferente', unit_price: '999' }));
    await ui.root.listeners['ata-product-selected'](selectedProduct());
    assert.equal(ui.fields.item.value, '2');
    assert.equal(ui.fields.code.value, '0042');
    assert.equal(ui.fields.brand.value, 'Marca salva');
    assert.equal(ui.fields.unit.value, 'KM');
    assert.equal(ui.fields.unit_price.value, '8,5000');
    assert.equal(ui.quantity.value, '20.000,0000');
    assert.equal(ui.quantity.disabled, false);
    assert.equal(ui.total.value, '169.999,9999');
    assert.equal(ui.description.value, '<Descrição salva>');
    assert.equal(ui.fields.disp.value, '30.000,0000');
    await ui.lookup();
    assert.equal(ui.quantity.value, '20.000,0000');
    assert.equal(ui.total.value, '169.999,9999');
    assert.deepEqual(ui.warnings, []);
});

test('manual selection fills saved data without consulting or changing the manual option', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return response(); });
    ui.manual.checked = true;
    ui.manual.listeners.change();
    await ui.root.listeners['ata-product-selected'](selectedProduct({ code: null, brand: null }));
    assert.equal(calls, 0);
    assert.equal(ui.manual.checked, true);
    assert.equal(ui.fields.code.value, '');
    assert.equal(ui.fields.brand.value, '');
    assert.equal(ui.fields.disp.value, '');
    assert.equal(ui.quantity.value, '20.000,0000');
});

test('selection cancels prior lookups and late availability cannot overwrite a newer selection', async () => {
    const pending = [];
    const ui = fixture((url, options) => { const request = deferred(); pending.push({ ...request, options }); return request.promise; });
    ui.input('001');
    const lookup = ui.lookup();
    const first = ui.root.listeners['ata-product-selected'](selectedProduct());
    const second = ui.root.listeners['ata-product-selected'](selectedProduct({ item: '3', quantity: '12' }));
    pending[2].resolve(response({ remaining: '5' }));
    await second;
    pending[1].resolve(response({ remaining: '999' }));
    await first;
    pending[0].resolve(response({ code: 'OBSOLETO' }));
    await lookup;
    assert.equal(ui.fields.item.value, '3');
    assert.equal(ui.quantity.value, '12,0000');
    assert.equal(ui.fields.disp.value, '5,0000');
    assert.equal(ui.fields.code.value, '0042');
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(pending[1].options.signal.aborted, true);
});

test('catalog failure preserves the selected saved item and busy or foreign contexts cannot select it', async () => {
    const ui = fixture(async () => ({ ok: false, status: 404 }));
    await ui.root.listeners['ata-product-selected'](selectedProduct());
    assert.equal(ui.quantity.value, '20.000,0000');
    assert.equal(ui.total.value, '169.999,9999');
    assert.equal(ui.fields.disp.value, '');
    assert.match(ui.warnings[0], /dados salvos do item foram mantidos/);
    ui.root.dataset.editorBusy = 'true';
    await ui.root.listeners['ata-product-selected'](selectedProduct({ item: '3' }));
    assert.equal(ui.fields.item.value, '2');
    ui.root.dataset.editorBusy = 'false';
    ui.root.dataset.ataLoaded = '108';
    await ui.root.listeners['ata-product-selected'](selectedProduct({ item: '3' }));
    assert.equal(ui.fields.item.value, '2');
});

test('new product clears entry and cancels pending lookup without ficha controls', async () => {
    const request = deferred();
    const ui = fixture(() => request.promise);
    ui.input('001');
    const pending = ui.lookup();
    ui.more.listeners.click();
    ui.newProduct.listeners.click();
    request.resolve(response());
    await pending;
    assert.equal(ui.fields.item.value, '');
    assert.equal(ui.fields.item.focused, true);
    assert.equal(ui.fields.code.value, '');
    assert.equal(ui.description.value, '');
    assert.equal(ui.quantity.value, '0,0000');
    assert.equal(ui.total.value, '0,00');
    assert.equal(ui.less.disabled, true);
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.fields.item.value = 'manual';
    ui.quantity.value = '99';
    ui.root.dataset.editorBusy = 'true';
    ui.newProduct.listeners.click();
    assert.equal(ui.quantity.value, '99');
    ui.root.dataset.editorBusy = 'false';
    ui.newProduct.listeners.click();
    assert.equal(ui.manual.checked, false);
    assert.equal(ui.quantity.value, '0,0000');
});

test('closing products clears entry, restores mode and precision, and rejects late lookup responses', async () => {
    const pending = deferred();
    let signal;
    const ui = fixture((url, options) => { signal = options.signal; return pending.promise; });
    ui.input('001');
    const lookup = ui.lookup();
    ui.more.listeners.click();
    ui.dialog.listeners.close({ target: ui.dialog });
    assert.equal(signal.aborted, true);
    pending.resolve(response());
    await lookup;
    for (const name of ['item', 'code', 'brand', 'unit']) assert.equal(ui.fields[name].value, '');
    for (const name of ['disp', 'unit_price']) assert.equal(ui.fields[name].value, '0,0000');
    assert.equal(ui.description.value, '');
    assert.equal(ui.quantity.value, '0,0000');
    assert.equal(ui.quantity.disabled, true);
    assert.equal(ui.total.value, '0,00');
    assert.equal(ui.less.disabled, true);
    assert.equal(ui.more.disabled, false);
    assert.equal(ui.feedback.textContent, '');
    assert.equal(ui.root.dataset.ataLoaded, '107');
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.fields.item.value = 'manual';
    ui.quantity.value = '9';
    ui.dialog.listeners.close({ target: {} });
    assert.equal(ui.fields.item.value, 'manual');
    ui.dialog.listeners.close({ target: ui.dialog });
    assert.equal(ui.manual.checked, false);
    assert.equal(ui.fields.item.value, '');
    assert.equal(ui.quantity.value, '0,0000');
    assert.equal(ui.fields.unit_price.readOnly, true);
    assert.deepEqual(ui.warnings, []);
});

test('total truncates exact multiplication to two decimal places and groups thousands', () => {
    assert.equal(ataProductTotal('1,2345', '6,7890'), '8,38');
    assert.equal(ataProductTotal('0,0001', '0,9999'), '0,00');
    assert.equal(ataProductTotal('1.000,0000', '2,5000'), '2.500,00');
    assert.equal(ataProductTotal('99999999999999.9999', '1.0000'), '99.999.999.999.999,99');
    assert.equal(ataProductTotal('', '2'), '0,00');
    assert.equal(ataProductTotal('inválido', '2'), '0,00');
});

test('typing item leaves fields untouched and only blur requests and fills the product', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return response(); });
    ui.fields.code.value = 'ANTERIOR';
    ui.input('001');
    await new Promise(resolve => setTimeout(resolve, 400));
    assert.equal(calls, 0);
    assert.equal(ui.fields.code.value, 'ANTERIOR');
    await ui.lookup();
    assert.equal(calls, 1);
    assert.equal(ui.fields.code.value, '00042');
    ui.input('');
    assert.equal(ui.fields.code.value, '00042');
    await ui.lookup();
    assert.equal(calls, 1);
    assert.equal(ui.fields.code.value, '');
});

test('manual item blur and search never consult or clear entered values, even with empty item', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return response(); });
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.fields.code.value = 'CODIGO MANUAL';
    ui.description.value = 'Descricao manual';
    ui.quantity.value = '7,0000';
    for (const item of ['123', '']) {
        ui.input(item);
        await ui.lookup();
        await ui.opener.listeners.click();
        assert.equal(calls, 0);
        assert.equal(ui.fields.code.value, 'CODIGO MANUAL');
        assert.equal(ui.description.value, 'Descricao manual');
        assert.equal(ui.quantity.value, '7,0000');
    }
    assert.deepEqual(ui.warnings, []);
    ui.manual.checked = false;
    ui.manual.listeners.change();
    ui.input('123');
    await ui.lookup();
    assert.equal(calls, 1);
    assert.equal(ui.fields.code.value, '00042');
});

test('quantity limit respects exact decimals, zero, blank availability and large values', () => {
    assert.equal(limitAtaQuantity('1.234,5679', '1.234,5678'), '1.234,5678');
    assert.equal(limitAtaQuantity('0,0001', '0,0000'), '0,0000');
    assert.equal(limitAtaQuantity('25', ''), '25');
    assert.equal(limitAtaQuantity('2,0000', '3,0000'), '2,0000');
    assert.equal(limitAtaQuantity('99999999999999.9999', '99999999999999.9998'), '99.999.999.999.999,9998');
});

test('quantity above availability remains available for removal when leaving the field', async () => {
    const ui = fixture(async () => response({ remaining: '2.0001' }));
    ui.input('001');
    await ui.lookup();
    assert.equal(ui.quantity.disabled, false);
    ui.quantity.value = '3';
    assert.equal(ui.quantity.listeners.input, undefined);
    assert.equal(ui.quantity.value, '3');
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '3');
    assert.deepEqual(ui.warnings, []);
    assert.equal(ui.total.value, '37,02');
    ui.quantity.value = '2,0001';
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '2,0001');
    assert.equal(ui.warnings.length, 0);
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.quantity.value = '99';
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '99');
    assert.equal(ui.warnings.length, 0);
});

test('quantity and unit price blur both refresh the total', () => {
    const ui = fixture(() => response());
    ui.manual.checked = true;
    ui.quantity.value = '1,2345';
    ui.fields.unit_price.value = '6,7890';
    ui.quantity.listeners.blur();
    assert.equal(ui.total.value, '8,38');
    ui.fields.unit_price.value = '10,0000';
    ui.fields.unit_price.listeners.blur();
    assert.equal(ui.total.value, '12,34');
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.total.value, '0,00');
});

test('decimal buttons recalculate from operands and respect two to four places', () => {
    const ui = fixture(() => response());
    ui.quantity.value = '1,2345';
    ui.fields.unit_price.value = '6,7890';
    ui.more.listeners.click();
    assert.equal(ui.total.value, '8,381');
    ui.more.listeners.click();
    assert.equal(ui.total.value, '8,3810');
    assert.equal(ui.more.disabled, true);
    for (let index = 0; index < 5; index++) ui.less.listeners.click();
    assert.equal(ui.total.value, '8,38');
    assert.equal(ui.less.disabled, true);
    ui.more.listeners.click();
    assert.equal(ui.total.value, '8,381');
});

test('manual mode unlocks entry, preserves typed values and cancels stale lookups', async () => {
    const pending = deferred();
    let calls = 0;
    const ui = fixture(() => { calls++; return pending.promise; });
    ui.input('001');
    const lookup = ui.lookup();
    ui.manual.checked = true;
    ui.manual.listeners.change();
    for (const name of ['code', 'brand', 'unit', 'unit_price']) assert.equal(ui.fields[name].readOnly, false);
    assert.equal(ui.fields.disp.readOnly, true);
    assert.equal(ui.quantity.disabled, false);
    assert.equal(ui.description.readOnly, false);
    ui.fields.brand.value = 'Manual';
    ui.description.value = 'Descrição manual';
    ui.input('002');
    await ui.lookup();
    pending.resolve(response());
    await lookup;
    assert.equal(calls, 1);
    assert.equal(ui.fields.brand.value, 'Manual');
    assert.equal(ui.description.value, 'Descrição manual');
    ui.manual.checked = false;
    ui.manual.listeners.change();
    assert.equal(ui.fields.brand.readOnly, true);
    assert.equal(ui.quantity.disabled, true);
    assert.equal(ui.description.readOnly, true);
    ui.manual.checked = true;
    ui.manual.listeners.change();
    ui.root.listeners['ata-cleared']();
    assert.equal(ui.manual.checked, false);
    assert.equal(ui.fields.unit_price.readOnly, true);
});

test('item lookup fills code, brand, unit, availability, price and description with exact formatting', async () => {
    let request;
    const ui = fixture(async (url, options) => { request = { url, options }; return response(); });
    ui.input('001');
    await ui.lookup();
    assert.equal(request.url.pathname, '/atas/107/products/item-lookup');
    assert.equal(request.url.searchParams.get('item'), '001');
    assert.equal(request.url.searchParams.has('process'), false);
    assert.equal(request.options.cache, 'no-store');
    assert.equal(ui.fields.code.value, '00042');
    assert.equal(ui.fields.brand.value, 'Marca');
    assert.equal(ui.fields.unit.value, 'CX');
    assert.equal(ui.fields.disp.value, '1.234,5678');
    assert.equal(ui.fields.unit_price.value, '12,3400');
    assert.equal(ui.description.value, '<Produto seguro>');
    assert.deepEqual(ui.warnings, []);
});

test('editing item clears previous fields and a late response cannot overwrite the new item', async () => {
    const pending = [];
    const ui = fixture((url, options) => { const request = deferred(); pending.push({ ...request, options }); return request.promise; });
    ui.input('001');
    const first = ui.lookup();
    ui.input('002');
    const second = ui.lookup();
    pending[1].resolve(response({ item: '002', code: 'NOVO' }));
    await second;
    pending[0].resolve(response({ code: 'OBSOLETO' }));
    await first;
    assert.equal(pending[0].options.signal.aborted, true);
    assert.equal(ui.fields.code.value, 'NOVO');
    ui.input('');
    await ui.lookup();
    assert.equal(ui.fields.code.value, '');
    assert.equal(ui.fields.disp.value, '0,0000');
    assert.equal(ui.description.value, '');
});

test('exhausted item loads silently and keeps quantity editable for removal', async () => {
    const ui = fixture(async () => response({ remaining: '0.0000' }));
    ui.quantity.value = '9,0000';
    ui.input('001');
    await ui.lookup();
    assert.equal(ui.fields.disp.value, '0,0000');
    assert.equal(ui.quantity.disabled, false);
    assert.equal(ui.quantity.value, '0,0000');
    assert.equal(ui.total.value, '0,00');
    assert.equal(ui.fields.code.value, '00042');
    assert.deepEqual(ui.warnings, []);
    await ui.lookup();
    assert.equal(ui.warnings.length, 0);
});

test('partial balance is displayed without clearing a quantity intended for removal', async () => {
    const ui = fixture(async () => response({ remaining: '3.0001' }));
    ui.input('001');
    await ui.lookup();
    assert.equal(ui.fields.disp.value, '3,0001');
    assert.equal(ui.quantity.disabled, false);
    ui.quantity.value = '3,0002';
    ui.quantity.listeners.blur();
    assert.equal(ui.quantity.value, '3,0002');
    assert.deepEqual(ui.warnings, []);
});

test('clearing or switching ata cancels lookup and never applies a product from the previous context', async () => {
    const request = deferred();
    const ui = fixture(() => request.promise);
    ui.input('001');
    const pending = ui.lookup();
    ui.root.listeners['ata-cleared']();
    ui.root.dataset.ataLoaded = '108';
    request.resolve(response());
    await pending;
    assert.equal(ui.fields.item.value, '');
    assert.equal(ui.fields.code.value, '');
    assert.deepEqual(ui.warnings, []);
});

test('not found, duplicate and network failure clear stale values and show the suite warning', async () => {
    for (const fetcher of [async () => ({ ok: false, json: async () => ({ message: 'Item não encontrado.' }) }),
        async () => ({ ok: false, json: async () => ({ message: 'Item duplicado.' }) }),
        async () => { throw new TypeError('Network'); }]) {
        const ui = fixture(fetcher);
        ui.fields.code.value = 'ANTIGO';
        ui.input('001');
        await ui.lookup();
        assert.equal(ui.fields.code.value, '');
        assert.equal(ui.fields.unit_price.value, '0,0000');
        assert.equal(ui.warnings.length, 1);
        assert.equal(ui.feedback.textContent, '');
    }
});

test('missing item displays the exact requested message in the suite warning', async () => {
    const message = 'Nenhum produto encontrado para este Item no Processo da Ata.';
    const ui = fixture(async () => ({ ok: false, json: async () => ({ message }) }));
    ui.input('999');
    await ui.lookup();
    assert.deepEqual(ui.warnings, [message]);
    assert.equal(ui.feedback.textContent, '');
});

test('lookup is unavailable before loading ata and repeated blur does not duplicate a successful request', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; return response(); });
    ui.root.dataset.ataLoaded = '';
    ui.input('001');
    await ui.lookup();
    assert.equal(calls, 0);
    ui.root.dataset.ataLoaded = '107';
    await ui.lookup();
    await ui.lookup();
    assert.equal(calls, 1);
});

test('a server response for a changed process cannot populate fields under the old process', async () => {
    const ui = fixture(async () => ({ ok: true, json: async () => ({ process: 'OUTRO', product: {} }) }));
    ui.input('001');
    await ui.lookup();
    assert.equal(ui.fields.code.value, '');
    assert.match(ui.warnings[0], /Processo da ata foi alterado/);
});
