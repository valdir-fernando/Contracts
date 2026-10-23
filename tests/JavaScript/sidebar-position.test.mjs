import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const layout = readFileSync(new URL('../../resources/views/components/layouts/app.blade.php', import.meta.url), 'utf8');
const script = layout.match(/<script data-sidebar-position>([\s\S]*?)<\/script>/)[1];

function load(saved, initiallyOpen = false) {
    const events = {};
    const catalogs = { open: initiallyOpen, addEventListener: (name, callback) => { events[name] = callback; } };
    const menu = { scrollTop: 0, querySelector: () => catalogs, addEventListener: (name, callback) => { events[name] = callback; } };
    const storage = new Map(saved === undefined ? [] : [['contracts.menu-position', saved]]);
    runInNewContext(script, {
        document: { getElementById: () => menu },
        sessionStorage: { getItem: (key) => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
    });
    return { menu, catalogs, events, storage };
}

test('navigation preserves menu scroll and expanded catalogs across pages', () => {
    const first = load();
    first.catalogs.open = true;
    first.menu.scrollTop = 185;
    first.events.click();
    const next = load(first.storage.get('contracts.menu-position'));
    assert.equal(next.menu.scrollTop, 185);
    assert.equal(next.catalogs.open, true);
});

test('saved closed catalogs stays closed even on a catalog route', () => {
    const next = load(JSON.stringify({ top: 90, catalogsOpen: false }), true);
    assert.equal(next.catalogs.open, false);
    assert.equal(next.menu.scrollTop, 90);
    next.menu.scrollTop = 120;
    next.events.scroll();
    assert.equal(JSON.parse(next.storage.get('contracts.menu-position')).top, 120);
});

test('missing or invalid saved positions preserve initial route defaults', () => {
    for (const saved of [undefined, 'invalid', '{}', '{"top":null,"catalogsOpen":false}']) {
        const next = load(saved, true);
        assert.equal(next.catalogs.open, true);
        assert.equal(next.menu.scrollTop, 0);
    }
});
