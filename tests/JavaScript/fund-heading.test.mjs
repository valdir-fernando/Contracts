import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeFundHeading } from '../../resources/js/fund-heading.js';

test('fund name shrinks to its available column and regains normal size when space grows', async () => {
    let resized;
    let fontsLoaded;
    let mutated;
    let textWidth = 300;
    const context = { font: '', measureText: () => ({ width: textWidth }) };
    globalThis.document = {
        createElement: () => ({ getContext: () => context }),
        fonts: { ready: new Promise((resolve) => { fontsLoaded = resolve; }) },
    };
    globalThis.getComputedStyle = () => ({ fontSize: '30px', fontWeight: '650', fontFamily: 'Inter' });
    globalThis.ResizeObserver = class {
        constructor(callback) { resized = callback; }
        observe() {}
    };
    globalThis.MutationObserver = class {
        constructor(callback) { mutated = callback; }
        observe() {}
    };
    const heading = { style: {}, textContent: 'MUNICIPIO DE NIQUELÂNDIA', parentElement: { clientWidth: 200 } };
    initializeFundHeading(heading);
    assert.equal(heading.style.fontSize, '20px');
    heading.parentElement.clientWidth = 400;
    resized();
    assert.equal(heading.style.fontSize, '');
    heading.parentElement.clientWidth = 150;
    fontsLoaded();
    await document.fonts.ready;
    assert.equal(heading.style.fontSize, '15px');
    heading.textContent = 'FUNDO';
    textWidth = 100;
    mutated();
    assert.equal(heading.style.fontSize, '');
});
