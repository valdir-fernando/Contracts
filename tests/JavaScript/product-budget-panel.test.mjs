import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeProductBudgetPanel } from '../../resources/js/product-budget-panel.js';

test('opens budget registration over products and refreshes only after a successful save', () => {
    class Element {
        children = [];
        listeners = {};
        setAttribute() {}
        append(...children) { this.children.push(...children); }
        addEventListener(name, action) { this.listeners[name] = action; }
        emit(name) { this.listeners[name]?.({ preventDefault() {} }); }
        showModal() { this.open = true; }
        close() { this.open = false; this.emit('close'); }
    }
    const doc = { body: new Element(), createElement: () => new Element() };
    const opener = new Element();
    opener.href = '/catalogs/budgets/create?ficha=0001&fund_id=2';
    const warning = new Element();
    warning.open = true;
    let saves = 0;
    let closes = 0;
    initializeProductBudgetPanel(opener, warning, () => saves++, () => closes++, doc);
    opener.emit('click');
    const dialog = doc.body.children[0];
    const [close, frame] = dialog.children;
    assert.equal(dialog.open, true);
    assert.equal(warning.open, false);
    assert.equal(frame.src, opener.href);
    frame.contentDocument = { querySelector: () => null };
    frame.emit('load');
    assert.equal(saves, 0);
    close.emit('click');
    assert.equal(frame.src, 'about:blank');
    assert.equal(closes, 1);
    opener.emit('click');
    assert.equal(doc.body.children.length, 1);
    frame.contentDocument = { querySelector: () => ({}) };
    frame.emit('load');
    assert.equal(saves, 1);
    assert.equal(dialog.open, false);
    assert.equal(closes, 2);
});
