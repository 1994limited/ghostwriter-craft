// Suggest edits' helpers that need no page: core's QuoteFinder, ported and
// run against core's own cases (quote-cases.json, kept identical to core's
// by a PHP test), the steps and the markdown. Run with `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const window = {};
const context = vm.createContext({ window, Craft: { t: (category, message, params) => message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m) }, console, setTimeout, clearTimeout });
context.window = context;
vm.runInContext(readFileSync(new URL('../../src/web/assets/cp/dist/suggest.js', import.meta.url), 'utf8'), context);
const S = context.Ghostwriter.SuggestHelpers;
const plainArray = (value) => JSON.parse(JSON.stringify(value));

const { cases } = JSON.parse(readFileSync(new URL('./quote-cases.json', import.meta.url), 'utf8'));

cases.forEach((c) => {
    test(`quote: ${c.name}`, () => {
        const found = S.findQuote(c.quote, c.text, c.occurrence ?? null, c.markdown ?? false);

        if (c.expect === null) {
            assert.equal(found, null);

            return;
        }

        assert.ok(found, 'found');
        assert.equal(found.offset, c.expect.offset);
        assert.equal(found.length, c.expect.length);
        assert.equal(found.fuzzy, c.expect.fuzzy);
        if (c.expect.occurrence !== undefined) assert.equal(found.occurrence, c.expect.occurrence);
        assert.equal(Array.from(c.text).slice(found.offset, found.offset + found.length).join(''), c.expect.text);
    });
});

test('a range in UTF-16 indices', () => {
    const text = '🌱 New for 2024: winter visits';
    const [start, end] = S.rangeIn(text, S.findQuote({ exact: 'New for 2024' }, text));

    assert.equal(text.slice(start, end), 'New for 2024');
});

const s = (id, category, dotted, extra = {}) => ({ id, category, label: category[0].toUpperCase() + category.slice(1), dotted, state: 'open', scope: 'range', replacement: 'new', alternatives: [], ...extra });

const review = [
    s('a', 'out-of-date', 'pageBuilder.0.eyebrow'),
    s('b', 'voice', 'pageBuilder.0.heading', { alternatives: ['one', 'two'] }),
    s('c', 'fact-to-check', 'body', { replacement: null, fact: { template: 'team of {answer}', answer: 'number' } }),
    s('d', 'clarity', 'body'),
    s('e', 'link', 'body'),
    s('f', 'accessibility', 'image', { scope: 'asset' }),
    s('g', 'seo', 'metaDescription'),
    s('h', 'seo', 'seo.metaGlobalVars.seoDescription', { seo: { writable: false } }),
];

test('steps keep what this page knows, with stale ones last', () => {
    const steps = S.stepsFrom(review, new Map([['b', { state: 'accepted', text: 'We design gardens' }], ['a', { stale: true }]]));

    assert.deepEqual(plainArray(steps.map((step) => step.id)), ['b', 'c', 'd', 'e', 'f', 'g', 'h', 'a']);
    assert.equal(steps[0].state, 'accepted');
    assert.deepEqual(plainArray(S.counts(steps)), { open: 6, accepted: 1, dismissed: 0, confirmed: 0, stale: 1, total: 8 });
});

test('filters count what is open and hide empty categories unless chosen', () => {
    const steps = S.stepsFrom(review, new Map([['b', { state: 'dismissed' }]]));

    assert.deepEqual(plainArray(S.filters(steps).map((f) => [f.key, f.count])), [['all', 7], ['out-of-date', 1], ['fact-to-check', 1], ['clarity', 1], ['link', 1], ['accessibility', 1], ['seo', 2]]);
    assert.ok(S.filters(steps, 'voice').some((f) => f.key === 'voice' && f.count === 0));
});

test('next goes to the next open one in the filter, coming round', () => {
    const steps = S.stepsFrom(review, new Map([['c', { state: 'accepted' }]]));

    assert.equal(S.nextOpen(steps, 1), 3);
    assert.equal(S.nextOpen(steps, 7), 0);
    assert.equal(S.nextOpen(steps, 0, 'clarity'), 3);
    assert.equal(S.nextOpen(S.stepsFrom(review.map((x) => ({ ...x, state: 'dismissed' }))), 0), 8);
});

test('fields are current, open with their numbers, or done', () => {
    const steps = S.stepsFrom(review, new Map([['a', { state: 'accepted' }]]));
    const fields = S.fieldStates(steps, 3);

    assert.equal(fields.get('pageBuilder.0.eyebrow').state, 'done');
    assert.equal(S.tagText(fields.get('pageBuilder.0.eyebrow'), 3, steps), '✓');
    assert.equal(fields.get('body').state, 'current');
    assert.equal(S.tagText(fields.get('body'), 3, steps), '4 · Clarity');
    assert.equal(S.tagText(S.fieldStates(steps, 0).get('body'), 0, steps), '3–5');
});

test('accept all takes open wording fixes in the filter, and not an SEO value set in SEOmatic', () => {
    const steps = S.stepsFrom(review);

    assert.deepEqual(plainArray(S.wordingFixes(steps).map((i) => steps[i].id)), ['b', 'd', 'g']);
    assert.deepEqual(plainArray(S.wordingFixes(steps, 'seo').map((i) => steps[i].id)), ['g']);
});

test('another version cycles through the replacement, the alternatives and any written since', () => {
    assert.deepEqual(plainArray(S.versionsOf({ replacement: 'a', alternatives: ['b', 'a'], versions: ['c'] })), ['a', 'b', 'c']);
});

test('a fact is filled as core fills it', () => {
    assert.equal(S.fillFact({ template: 'our team of {answer} designers', answer: 'number' }, ' 8 '), 'our team of 8 designers');
    assert.throws(() => S.fillFact({ template: 'team of {answer}', answer: 'number' }, '8 or 9?'), /number-only/);
    assert.equal(S.fillFact({ template: 'from £{answer}', answer: 'money' }, '£480'), 'from £480');
});

test('inline markdown becomes words, or pieces with CKEditor attributes and Craft links', () => {
    assert.equal(S.plain('We **design** gardens'), 'We design gardens');
    assert.deepEqual(plainArray(S.pieces('Every winter', { bold: true })), [{ text: 'Every winter', attributes: { bold: true } }]);
    assert.deepEqual(plainArray(S.pieces('see [a walled garden]({entry:12@1:url||https://northfold.test/walled})', {}, 1)), [
        { text: 'see ', attributes: {} },
        { text: 'a walled garden', attributes: { linkHref: 'https://northfold.test/walled#entry:12@1:url' } },
    ]);
    assert.equal(S.editorHref('{entry:41@2:url}'), '#entry:41@2:url');
});
