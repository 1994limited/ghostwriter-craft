// The layout cards' helpers: run with `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../src/web/assets/cp/dist/layouts.js', import.meta.url), 'utf8');
const window = { Ghostwriter: { escape: (text) => String(text) } };
vm.runInNewContext(source, { window, Ghostwriter: window.Ghostwriter, ResizeObserver: class {}, document: {}, CSS: { escape: (text) => text } });
const helpers = window.Ghostwriter.LayoutHelpers;
// Arrays from the script's own context, as plain ones.
const cards = (layouts) => JSON.parse(JSON.stringify(helpers.cards(layouts)));
const count = (plan) => helpers.count(plan);

const plans = [
    { id: 'w', name: 'As written', blocks: 3, writer: true },
    { id: 'p1', name: 'Sections apart', blocks: 5, suggested: true },
    { id: 'p2', name: 'Numbers first', blocks: 4 },
];

test('the row is hidden with one layout or none', () => {
    assert.deepEqual(cards({ plans: [] }), []);
    assert.deepEqual(cards({ plans: plans.slice(0, 1) }), []);
    assert.deepEqual(cards(undefined), []);
});

test('every layout is a card, the writer’s first', () => {
    assert.deepEqual(cards({ plans }).map((card) => card.id), ['w', 'p1', 'p2']);
});

test('while the planner works: the writer’s card and two placeholders', () => {
    const shown = cards({ planning: true, plans: [] });

    assert.deepEqual(shown.map((card) => [card.id, Boolean(card.skeleton)]), [['w', false], ['finding-1', true], ['finding-2', true]]);
    assert.equal(shown[0].name, 'As written');
    assert.deepEqual(cards({ planning: true, plans }).slice(0, 1).map((card) => card.name), ['As written']);
});

test('block counts', () => {
    assert.equal(count({ blocks: 1 }), '1 block');
    assert.equal(count({ blocks: 6 }), '6 blocks');
    assert.equal(count({ blocks: null }), '');
});

test('a layout says what it changes, on one line', () => {
    assert.equal(helpers.changes({ changes: ['Call to action added', 'Text split into 3 blocks'] }), 'Call to action added · Text split into 3 blocks');
    assert.equal(helpers.changes(plans[0]), '');
});

test('the places a layout changed are found in the render’s block map', () => {
    const map = [
        { key: 'f1', kind: 'field', type: 'title', path: 'title', parent: null },
        { key: 'b1', kind: 'block', type: 'hero', path: 'pageBuilder/0', parent: null },
        { key: 'b2', kind: 'block', type: 'cta', path: 'pageBuilder/1', parent: null },
        { key: 'b3', kind: 'block', type: 'text', path: 'pageBuilder/2', parent: null },
        { key: 's1', kind: 'section', path: 'pageBuilder/2/text', parent: 'b3' },
        { key: 's2', kind: 'section', path: 'pageBuilder/2/text', parent: 'b3' },
        { key: 'f2', kind: 'field', type: 'body', path: 'body', parent: null },
        { key: 's3', kind: 'section', path: 'body', parent: 'f2' },
        { key: 's4', kind: 'section', path: 'body', parent: 'f2' },
    ];
    const keys = (places) => JSON.parse(JSON.stringify(helpers.keysForPlaces(map, places)));

    assert.deepEqual(keys([{ field: 'pageBuilder', block: 2, section: 1 }, { field: 'pageBuilder', block: 1, section: null }]), ['b2', 's2'], 'in page order');
    assert.deepEqual(keys([{ field: 'body', block: null, section: 1 }]), ['s4']);
    assert.deepEqual(keys([{ field: 'pageBuilder', block: 0, section: 3 }]), ['b1'], 'no sections there: the block');
    assert.deepEqual(keys([{ field: 'pageBuilder', block: 9, section: null }, { field: 'gone', block: null, section: null }]), []);
    assert.deepEqual(JSON.parse(JSON.stringify(helpers.keysForPlaces(null, []))), []);
});

test('in Blocks and Text, a layout’s places are its blocks, or whole fields', () => {
    assert.deepEqual(JSON.parse(JSON.stringify(helpers.markSelectors([{ field: 'pageBuilder', block: 2, section: 1 }, { field: 'pageBuilder', block: 2, section: 0 }, { field: 'body', block: null, section: 4 }]))), ['[data-gw-field="pageBuilder"][data-gw-block="2"]', '[data-gw-field="body"]:not([data-gw-block])']);
});
