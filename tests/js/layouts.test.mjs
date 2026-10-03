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
