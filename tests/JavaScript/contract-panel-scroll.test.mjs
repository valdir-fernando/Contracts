import test from 'node:test';
import assert from 'node:assert/strict';
import { scrollContractSection } from '../../resources/js/contract-panel-scroll.js';

test('section navigation scrolls only the panels and focuses without moving the page', () => {
    const panels = { scrollTop: 40, clientTop: 1, getBoundingClientRect: () => ({ top: 200 }) };
    let focusOptions;
    const section = {
        closest: () => panels,
        getBoundingClientRect: () => ({ top: 500 }),
        scrollIntoView() { assert.fail('The page must not scroll'); },
        querySelector: () => ({ focus: (options) => { focusOptions = options; } }),
    };
    scrollContractSection(section);
    assert.equal(panels.scrollTop, 339);
    assert.deepEqual(focusOptions, { preventScroll: true });
    section.getBoundingClientRect = () => ({ top: 150 });
    scrollContractSection(section);
    assert.equal(panels.scrollTop, 288);
});
