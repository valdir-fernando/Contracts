import test from 'node:test';
import assert from 'node:assert/strict';
import { productLineTotal, productBudgetTotals, advanceProductVersion, formatProductPrice, formatFichaValue, exceedsProductBalance } from '../../resources/js/contract-products.js';


test('ficha values display thousands and four decimal places without losing precision', () => {
    assert.equal(formatFichaValue('2530.9200'), '2.530,9200');
    assert.equal(formatFichaValue('99999999999999.9999'), '99.999.999.999.999,9999');
    assert.equal(formatFichaValue('-2530.9200'), '-2.530,9200');
    assert.equal(formatFichaValue('0'), '0,0000');
    assert.equal(formatFichaValue('12.3'), '12,3000');
    assert.equal(formatFichaValue(null), '—');
});

test('quantity cannot exceed the available balance, including four decimal places', () => {
    assert.equal(exceedsProductBalance('100,0001', '100.0000'), true);
    assert.equal(exceedsProductBalance('100', '100.0000'), false);
    assert.equal(exceedsProductBalance('99,9999', '100.0000'), false);
    assert.equal(exceedsProductBalance('0,0001', '0.0000'), true);
    assert.equal(exceedsProductBalance('99999999999999,9999', '99999999999999.9998'), true);
    assert.equal(exceedsProductBalance('', '100.0000'), false);
    assert.equal(exceedsProductBalance('1', undefined), false);
});

test('editable unit prices format four decimal places without floating point loss', () => {
    assert.equal(formatProductPrice('0'), '0,0000');
    assert.equal(formatProductPrice('12,3'), '12,3000');
    assert.equal(formatProductPrice('1.234,5678'), '1234,5678');
    assert.equal(formatProductPrice('99999999999999.9999'), '99999999999999,9999');
    for (const value of ['', '-1', '1,12345']) assert.equal(formatProductPrice(value), null);
    assert.equal(productLineTotal('2', '11,0001'), '22.0002');
});

test('line preview uses exact decimals, Brazilian quantities and server HalfUp scale', () => {
    assert.equal(productLineTotal('40', '12.3456'), '493.8240');
    assert.equal(productLineTotal('0,0001', '12.3456'), '0.0012');
    assert.equal(productLineTotal('1.234,5', '0.1'), '123.4500');
    assert.equal(productLineTotal('0.0001', '0.5'), '0.0001');
    for (const value of ['-1', '0.00001', 'NaN', '1e3', '']) assert.equal(productLineTotal(value, '1'), null);
});

test('ficha totals sum stored line totals without floating point loss', () => {
    assert.deepEqual(productBudgetTotals([{ Ficha: '123', total: '0.1001' }, { Ficha: '123', total: '0.2002' }, { Ficha: null, total: '10.0000' }]),
        [{ label: '123', total: '0.3003' }, { label: 'Sem ficha', total: '10.0000' }]);
});

test('an asynchronous response cannot replace a changed form revision', () => {
    const version = { value: '3' };
    assert.equal(advanceProductVersion(version, '2', 3), false);
    assert.equal(advanceProductVersion(version, '3', 5), false);
    assert.equal(version.value, '3');
    assert.equal(advanceProductVersion(version, '3', 4), true);
    assert.equal(version.value, '4');
});
