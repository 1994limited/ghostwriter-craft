// The image dialog's helpers that need no page: run with `node --test tests/js/*.test.mjs`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Enough of jQuery, Garnish and the page for ghostwriter.js to load.
const chain = new Proxy(function () {}, { get: (target, name) => (name === Symbol.toPrimitive ? () => '' : chain), apply: () => chain });
const context = vm.createContext({
    $: (arg) => (typeof arg === 'function' && arg !== chain ? undefined : chain),
    Garnish: { Base: { extend: (proto) => proto } },
    Craft: { t: (category, message) => message },
    document: chain,
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    MutationObserver: function () { return { observe() {} }; },
    console,
});
context.window = context;
vm.runInContext(readFileSync(new URL('../../src/web/assets/cp/dist/ghostwriter.js', import.meta.url), 'utf8'), context);
const { editorialFor } = context.Ghostwriter;

const sources = [
    { value: 'free', label: 'Free libraries', editorial: false },
    { value: 'demo', label: 'Demo stock', editorial: true },
    { value: 'creative', label: 'Creative only', editorial: false },
    { value: 'everything', label: 'Everything', editorial: true },
];

test('"Include editorial images" shows only for a source that can return them', () => {
    assert.equal(editorialFor(sources, 'free'), false, 'Never the free libraries.');
    assert.equal(editorialFor(sources, 'creative'), false);
    assert.equal(editorialFor(sources, 'demo'), true);
    assert.equal(editorialFor(sources, 'everything'), true);
});

test('with no choice made, or nothing known, it stays hidden', () => {
    assert.equal(editorialFor(sources, null), false, 'No choice is the free libraries.');
    assert.equal(editorialFor(sources, 'gone'), false);
    assert.equal(editorialFor(undefined, 'demo'), false);
    assert.equal(editorialFor([{ value: 'free' }], 'free'), false);
});
