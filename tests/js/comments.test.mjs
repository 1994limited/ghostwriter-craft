// Comments on the preview, in Node with no DOM: node --test tests/js/*.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { changedKeys, coverOf, findQuote, pendingPins, pinKey, placePins, planPaths, plain, quoteOf, runOutcome, toSend } from '../../src/web/assets/cp/dist/comments.js';

// A hero, a text block whose rich text has two sections, and a card grid with two cards.
const map = [
    { key: 'f1', kind: 'field', path: 'title', units: ['u1'], parent: null },
    { key: 'b1', kind: 'block', path: 'page_builder/#a', units: ['u2', 'u3'], parent: null },
    { key: 'b2', kind: 'block', path: 'page_builder/#b', units: [], parent: null },
    { key: 's1', kind: 'section', path: 'page_builder/#b/text', units: ['u4'], parent: 'b2' },
    { key: 's2', kind: 'section', path: 'page_builder/#b/text', units: ['u5'], parent: 'b2' },
    { key: 'b3', kind: 'block', path: 'page_builder/#c', units: [], parent: null },
    { key: 'b4', kind: 'block', path: 'page_builder/#c/cards/#d', units: ['u6'], parent: 'b3' },
    { key: 'b5', kind: 'block', path: 'page_builder/#c/cards/#e', units: ['u7'], parent: 'b3' },
];

test('a block covers its own units and its children\'s', () => {
    assert.deepEqual(coverOf('b2', map), ['u4', 'u5']);
    assert.deepEqual(coverOf('b1', map), ['u2', 'u3']);
    assert.deepEqual(coverOf('zz', map), []);
});

test('a pin goes on the deepest block holding all its words', () => {
    assert.equal(pinKey({ kind: 'block', units: ['u2', 'u3'] }, map), 'b1');
    assert.equal(pinKey({ kind: 'block', units: ['u4', 'u5'] }, map), 'b2', 'a comment on the whole text block');
    assert.equal(pinKey({ kind: 'text', units: ['u5'] }, map), 's2', 'some words: their section');
    assert.equal(pinKey({ kind: 'block', units: ['u6', 'u7'] }, map), 'b3');
    assert.equal(pinKey({ kind: 'block', units: ['u6'] }, map), 'b4');
});

test('it follows the words into another layout, where they are split or joined', () => {
    // "A block a section" splits the text block in two.
    const split = [
        { key: 'b1', path: 'page_builder/#x@0:text', units: ['u4'], parent: null },
        { key: 'b2', path: 'page_builder/#y@1:text', units: ['u5'], parent: null },
    ];

    assert.equal(pinKey({ kind: 'block', units: ['u4', 'u5'] }, split), 'b1', 'spans two blocks: on the first');
    assert.equal(pinKey({ kind: 'text', units: ['u5'] }, split), 'b2');
    assert.equal(pinKey({ kind: 'block', units: ['u2'] }, split), null, 'its words are not in this layout');
});

test('no pin for the page, a detached thread, a resolved one, or a block not on the page', () => {
    assert.equal(pinKey({ kind: 'page', units: [] }, map), null);
    assert.equal(pinKey({ kind: 'block', units: ['u2'], status: 'detached' }, map), null);
    assert.equal(pinKey({ kind: 'block', units: ['u2'], in_layout: false }, map), null);
    assert.equal(pinKey({ kind: 'block', units: ['u2', 'u3'] }, map, ['b2', 's1']), null, 'the hero is not on the page');

    const threads = [
        { number: 1, status: 'open', state: 'Not sent', kind: 'block', units: ['u2', 'u3'], label: 'Hero' },
        { number: 2, status: 'resolved', state: 'Resolved', kind: 'block', units: ['u6'] },
        { number: 3, status: 'changed', state: 'Changed', kind: 'text', units: ['u5'], quote: 'two sections' },
    ];

    assert.deepEqual(placePins(threads, map), [
        { number: 1, status: 'open', state: 'Not sent', label: 'Hero', quote: null, key: 'b1' },
        { number: 3, status: 'changed', state: 'Changed', label: '', quote: 'two sections', key: 's2' },
    ]);
    assert.deepEqual(changedKeys(threads, map), ['s2']);
});

test('the block it was made on wins while it still holds the words', () => {
    assert.equal(pinKey({ kind: 'block', units: ['u4'], path: 'page_builder/#b/text' }, map), 's1');
    assert.equal(pinKey({ kind: 'block', units: ['u6'], path: 'page_builder/#c' }, map), 'b3');
});

test('picked words become a quote as core keeps one', () => {
    assert.deepEqual(quoteOf('  Cut back,\n mulch ', 'We visit four times between November and February. What we do ', ' and protect'), {
        exact: 'Cut back, mulch',
        prefix: 'vember and February. What we do ',
        suffix: ' and protect',
    });
    assert.equal(quoteOf('   \n '), null);
    assert.equal(quoteOf('x'.repeat(400)).exact.length, 300);
});

test('a quote is found again in the rendered text, whitespace aside, by its context', () => {
    const text = 'Cut back.  Mulch\nthe beds. Then cut back again.';

    assert.deepEqual(findQuote(text, { exact: 'Mulch the beds.' }), { start: 11, end: 26 });
    assert.equal(text.slice(...Object.values(findQuote(text, { exact: 'cut back', prefix: 'Then ' }))), 'cut back');
    assert.equal(findQuote(text, { exact: 'cut back', prefix: 'Then ' }).start, 32);
    assert.equal(findQuote(text, { exact: 'Prune' }), null);
});

test('after a run: what changed, what was answered, and what was not applied, with why', () => {
    const before = [1, 2, 3, 4].map((number) => ({ number, status: 'sending' })).concat([{ number: 5, status: 'changed' }]);
    const after = [
        { number: 1, status: 'changed' },
        { number: 2, status: 'replied' },
        { number: 3, status: 'refused', reply: '<p>That change needed something I don’t have (£75). Say it in a new comment and apply again.</p>' },
        { number: 4, status: 'skipped', reply: '<p>Someone changed this block while I was working, so I changed nothing.</p>' },
        { number: 5, status: 'changed' },
    ];

    assert.deepEqual(runOutcome(before, after), {
        changed: [1],
        replied: [2],
        back: [
            { number: 3, reason: 'That change needed something I don’t have (£75). Say it in a new comment and apply again.', skipped: false },
            { number: 4, reason: 'Someone changed this block while I was working, so I changed nothing.', skipped: true },
        ],
    });
});

test('pins not sent yet are numbered after the comments sent, and sent as Apply wants them', () => {
    const pending = [
        { id: 'a', kind: 'text', units: ['u5'], label: 'Text', path: 'page_builder/#b/text', planPath: 'page_builder/1', quote: { exact: 'Cut back', prefix: '', suffix: '' }, body: 'When?' },
        { id: 'b', kind: 'page', units: [], label: null, body: 'Shorter.' },
    ];

    assert.deepEqual(pendingPins(pending, 4).map((pin) => [pin.number, pin.status, pin.quote]), [[4, 'pending', 'Cut back'], [5, 'pending', null]]);
    assert.equal(pinKey(pendingPins(pending, 4)[0], map), 's2');
    assert.deepEqual(toSend(pending), [
        { kind: 'text', units: ['u5'], label: 'Text', path: 'page_builder/#b/text', plan_path: 'page_builder/1', quote: { exact: 'Cut back', prefix: '', suffix: '' }, body: 'When?' },
        { kind: 'page', units: [], label: null, path: null, plan_path: null, quote: null, body: 'Shorter.' },
    ]);
});

test('a note\'s HTML read as plain words', () => {
    assert.equal(plain('<p>Use &quot;£60&quot; &amp; &lt;b&gt;</p>'), 'Use "£60" & <b>');
});

test('each block is named by its place in the plan, as core names it', () => {
    assert.deepEqual(planPaths(map), { f1: 'title', b1: 'page_builder/0', b2: 'page_builder/1', b3: 'page_builder/2', b4: 'page_builder/2/cards/0', b5: 'page_builder/2/cards/1' });
});
