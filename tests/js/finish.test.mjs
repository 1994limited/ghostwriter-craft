// "Finish this page" helpers that need no page: run with `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const window = {};
const context = vm.createContext({ window, Garnish: { Base: { extend: (proto) => proto } }, $: () => {}, Craft: { t: (category, message, params) => message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m) }, console, setTimeout, clearTimeout });
context.window = context;
vm.runInContext(readFileSync(new URL('../../src/web/assets/cp/dist/finish.js', import.meta.url), 'utf8'), context);
const H = context.Ghostwriter.FinishHelpers;
const { editorHref, sameTarget } = H;
const t = (message, params) => message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m);
const gap = (kind, dotted, severity = 'blocks', speech = kind) => ({ id: `${kind}|${dotted}`, kind, dotted, severity, speech, label: dotted });

// The seeded page: a fact in the hero, a placeholder, a link field, and five in the Text block (one a suggestion), then a preview.
const page = () => [
    gap('ask', 'pageBuilder.0.subheading', 'blocks', 'Fill this in'),
    gap('image-placeholder', 'pageBuilder.0.image', 'blocks', 'Swap me'),
    gap('link', 'pageBuilder.0.buttonLink', 'blocks', 'Needs a link'),
    gap('ask', 'pageBuilder.1.text', 'blocks', 'Fill this in'),
    gap('placeholder-text', 'pageBuilder.1.text', 'suggestion', 'Fill this in'),
    gap('link', 'pageBuilder.1.text', 'blocks', 'Needs a link'),
    gap('link-broken', 'pageBuilder.1.text', 'blocks', 'Broken link'),
    gap('leftover-token', 'pageBuilder.1.text', 'blocks', 'Oops'),
    gap('stock-preview', 'pageBuilder.2.image', 'blocks', 'License me'),
];

test('counted gaps come first, suggestions after, numbered on their own', () => {
    const steps = H.stepsFrom(page());

    assert.deepEqual(Array.from(steps, (s) => s.gap.kind).slice(-2), ['stock-preview', 'placeholder-text']);

    const numbers = H.counts(steps, 8);
    assert.equal(numbers.suggestion, true);
    assert.equal(numbers.number, 1);
    assert.equal(numbers.suggestions, 1);
    assert.equal(numbers.total, 8);
    assert.equal(numbers.count, 8, 'The menu counts what blocks, not the suggestion.');
    assert.equal(H.counts(steps, 6).number, 7);
});

test('the active field shows the current step and its kind; others their range', () => {
    const steps = H.stepsFrom(page());
    const fields = H.fieldStates(steps, 6, new Set());

    assert.equal(H.tagText(fields.get('pageBuilder.1.text'), steps, t), '7 · Oops', 'On step 7, the Text field says 7, not 4.');
    assert.equal(H.tagText(fields.get('pageBuilder.0.image'), steps, t), '2 · Swap me');

    const away = H.fieldStates(steps, 0, new Set());
    assert.equal(H.tagText(away.get('pageBuilder.1.text'), steps, t), '4–7 · 4 to do');
    assert.equal(H.tagText(away.get('pageBuilder.0.subheading'), steps, t), '1 · Fill this in');
});

test('a field is fixed only when it had gaps and none are left', () => {
    const before = page();
    const touched = new Set(before.map((g) => g.dotted));
    const after = H.stepsFrom(before.filter((g) => g.dotted !== 'pageBuilder.0.buttonLink'));
    const fields = H.fieldStates(after, 0, touched);

    assert.equal(fields.get('pageBuilder.0.buttonLink').state, 'fixed');
    assert.equal(H.tagText(fields.get('pageBuilder.0.buttonLink'), after, t), 'Fixed ✓');
    assert.equal(fields.get('pageBuilder.1.text').state, 'open', 'Still has gaps: not fixed.');
});

test('a fix that makes a new gap leaves its field open, as a new step', () => {
    // The placeholder swapped for a stock preview: same field, new gap.
    const before = page();
    const after = before.map((g) => (g.kind === 'image-placeholder' ? gap('stock-preview', 'pageBuilder.0.image', 'blocks', 'License me') : g));
    const steps = H.stepsFrom(after);
    const fields = H.fieldStates(steps, 1, new Set(before.map((g) => g.dotted)));

    assert.equal(fields.get('pageBuilder.0.image').state, 'current');
    assert.equal(H.tagText(fields.get('pageBuilder.0.image'), steps, t), '2 · License me');
    assert.equal(H.counts(steps, 1).count, 8, 'Nothing was fixed: the count stays.');
});

test('the guide stays on its gap after a check, or takes the one that moved up', () => {
    const steps = H.stepsFrom(page());
    const id = steps[3].gap.id;

    assert.equal(H.currentAfter(steps, id, 3), 3);

    const fewer = H.stepsFrom(page().filter((g) => g.kind !== 'image-placeholder'));
    assert.equal(H.currentAfter(fewer, id, 3), 2, 'Same gap, one place earlier.');

    const gone = H.stepsFrom(page().filter((g) => g.id !== id));
    assert.equal(gone[H.currentAfter(gone, id, 3)].gap.kind, 'link', 'Its place is taken by the next.');
});

test('skipped gaps stay skipped, and the next open step comes round to the start', () => {
    const steps = H.stepsFrom(page(), { skipped: new Set([page()[0].id]) });

    assert.equal(steps[0].status, 'skipped');
    assert.equal(H.firstOpen(steps), 1);

    steps.forEach((s, i) => (s.status = i === 2 ? 'open' : 'fixed'));
    assert.equal(H.nextOpen(steps, 5), 2);
    steps[2].status = 'fixed';
    assert.equal(H.nextOpen(steps, 0), steps.length);
});

test('links are written and matched as CKEditor holds them in Craft', () => {
    assert.equal(editorHref('{entry:41@1:url||https://example.test/contact}', 1), 'https://example.test/contact#entry:41@1:url');
    assert.equal(editorHref('{entry:41:url}', 2), '#entry:41@2:url');
    assert.equal(editorHref('https://example.org/', 1), 'https://example.org/');

    assert.ok(sameTarget('https://example.test/gone#entry:999999@1:url', '{entry:999999@1:url||https://example.test/gone}'));
    assert.ok(!sameTarget('https://example.test/x#entry:9999@1:url', '{entry:999999@1:url}'));
    assert.ok(sameTarget('https://example.org/', 'https://example.org/'));
});

// A stand-in for Craft.BaseElementSelectInput: chips, a limit, showModal().
const picker = ({ ids = [], limit = 1, allowAdd = true } = {}) => {
    const chips = (list) => Object.assign([...list], {
        filter: (fn) => chips(list.filter((element, i) => fn(i, element))),
        last: () => chips(list.slice(-1)),
    });
    const elements = ids.map((id) => ({ id, getAttribute: (name) => (name === 'data-id' ? String(id) : null) }));

    return {
        settings: { allowAdd, limit },
        $elements: chips(elements),
        _$replaceElement: null,
        opened: 0,
        canAddMoreElements() { return this.settings.allowAdd && (!limit || this.$elements.length < limit); },
        showModal() { if (this._$replaceElement || this.canAddMoreElements()) this.opened++; },
    };
};

test('choose from Assets opens the picker on a full field, replacing the placeholder', () => {
    const input = picker({ ids: [12], limit: 1 });

    assert.equal(input.canAddMoreElements(), false, 'Craft hides "Add an asset" here.');
    assert.ok(Ghostwriter().openPicker(input, 12));
    assert.equal(input.opened, 1, 'The picker opened.');
    assert.equal(input._$replaceElement[0].id, 12, 'The placeholder is what gets replaced.');
});

test('a full field with something else in it replaces its last element', () => {
    const input = picker({ ids: [5, 7], limit: 2 });

    assert.ok(Ghostwriter().openPicker(input, 99));
    assert.equal(input._$replaceElement[0].id, 7);
    assert.equal(input.opened, 1);
});

test('a field with room adds, and a read-only one is not offered', () => {
    const empty = picker({ ids: [], limit: 1 });

    assert.ok(Ghostwriter().openPicker(empty));
    assert.equal(empty._$replaceElement, null);
    assert.equal(empty.opened, 1);

    const locked = picker({ ids: [12], allowAdd: false });

    assert.equal(Ghostwriter().canPick(locked), false);
    assert.equal(Ghostwriter().openPicker(locked, 12), false);
    assert.equal(locked.opened, 0);
    assert.equal(Ghostwriter().canPick(null), false);
});

function Ghostwriter() {
    return H;
}

test('a gap keeps its identity when Craft gives the draft\'s blocks new IDs', () => {
    const before = { id: 'ask|pageBuilder/#186/text|starting price|0', kind: 'ask', path: 'pageBuilder/#186/text', dotted: 'pageBuilder.1.text', hint: 'starting price', occurrence: 0 };
    const after = { ...before, id: 'ask|pageBuilder/#214/text|starting price|0', path: 'pageBuilder/#214/text' };

    assert.equal(Ghostwriter().key(before), Ghostwriter().key(after));
    assert.notEqual(Ghostwriter().key(before), Ghostwriter().key({ ...after, occurrence: 1 }));
});


// The guide's own methods, run against stand-ins for the page.
const guide = () => context.Ghostwriter.Finish;
const emitter = () => {
    const handlers = {};

    return {
        on(name, fn) { (handlers[name] ??= []).push(fn); },
        off(name, fn) { handlers[name] = (handlers[name] ?? []).filter((h) => h !== fn); },
        emit(name) { (handlers[name] ?? []).slice().forEach((fn) => fn()); },
    };
};
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test('a cancelled picker leaves the field as it was: no replace pending, its type put back', async () => {
    const modal = emitter();
    const input = { ...emitter(), modal, _$replaceElement: ['placeholder chip'] };
    const calls = [];
    const self = { fixed: () => calls.push('fixed'), paint: () => calls.push('paint'), clearPlaceholderLabel: () => calls.push('label') };

    guide().watchPicker.call(self, input, { id: 'g' }, {}, () => calls.push('restored'));
    modal.emit('hide');
    await wait(450);

    assert.equal(input._$replaceElement, null);
    assert.deepEqual(calls, ['restored', 'paint']);
});

test('a choice made in the picker is checked like any fix', async () => {
    const modal = emitter();
    const input = { ...emitter(), modal, _$replaceElement: null };
    const calls = [];
    const self = { fixed: () => calls.push('fixed'), paint() {}, clearPlaceholderLabel: () => calls.push('label') };

    guide().watchPicker.call(self, input, { id: 'g' }, {}, () => calls.push('restored'));
    input.emit('selectElements');
    modal.emit('hide');
    await wait(450);

    assert.deepEqual(calls, ['label', 'fixed']);
});

test('a fix that left the field as it was does not count, and says so', () => {
    const gap = { id: 'g', kind: 'ask' };
    const said = [];
    const self = {
        before: { id: 'g', value: 'same' },
        steps: [{ gap, status: 'open' }],
        index: 0,
        locate: () => ({}),
        readField: () => 'same',
        announce: (text) => said.push(text),
        paint() {},
        advance: () => said.push('advanced'),
    };

    guide().fixed.call(self, gap);

    assert.equal(self.steps[0].status, 'open');
    assert.equal(self.unchangedId, 'g');
    assert.equal(said.length, 1);
    assert.match(said[0], /didn’t change the field/);
});

test('a chip row goes after the wrappers holding only the box, inside its field or cell', () => {
    const node = (name, children = [], attrs = {}) => {
        const element = { name, children, parentElement: null, hasAttribute: (key) => key in attrs, matches: (selector) => selector.split(',').map((part) => part.trim()).includes(name) };
        children.forEach((child) => (child.parentElement = element));

        return element;
    };
    const input = node('input');
    const wrap = node('div', [input]);
    node('.input', [wrap, node('p')]);
    assert.equal(H.rowAnchor(input), wrap);

    const cell = node('textarea');
    node('td', [cell]);
    assert.equal(H.rowAnchor(cell), cell, 'never the cell itself');

    const withRow = node('input');
    const box = node('div', [withRow, node('div', [], { 'data-gw-gap-row': '' })]);
    node('.input', [box, node('p')]);
    assert.equal(H.rowAnchor(withRow), box, 'its own row isn’t a sibling that counts');
    assert.match(H.CHIP_CONTROLS, /table\.editable textarea/);
});

test('the menu beside Edit with Ghostwriter: one count, amber while anything is left to finish, read out in full', () => {
    const plain = (value) => JSON.parse(JSON.stringify(value));

    assert.deepEqual(plain(H.menuBadge(3, 7)), { total: 10, tone: 'finish', label: '10 items: 3 to finish, 7 suggestions' });
    assert.deepEqual(plain(H.menuBadge(2, 1)), { total: 3, tone: 'finish', label: '3 items: 2 to finish, 1 suggestion' });
    assert.deepEqual(plain(H.menuBadge(3, 0)), { total: 3, tone: 'finish', label: '3 to finish' });
    assert.deepEqual(plain(H.menuBadge(0, 7)), { total: 7, tone: 'suggest', label: '7 suggestions' }, 'Only suggestions: grey, never amber.');
    assert.deepEqual(plain(H.menuBadge(0, 0)), { total: 0, tone: 'suggest', label: '' }, 'Nothing: no count.');
});
