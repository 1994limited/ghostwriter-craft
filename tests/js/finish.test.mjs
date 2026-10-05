// "Finish this page" helpers that need no page: run with `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const window = {};
const context = vm.createContext({ window, Garnish: { Base: { extend: (proto) => proto } }, $: () => {}, Craft: { t: (category, message, params) => message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m) }, console, setTimeout, clearTimeout, URL });
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

test('a fix label cuts only the name in it short', () => {
    const name = 'Winter structure: plants that earn their keep in January';
    const parts = H.labelParts(`Link to ${name}`, name);

    assert.equal(parts.lead, 'Link to ');
    assert.equal(parts.tail, '');
    assert.equal(parts.name.length, 40);
    assert.ok(parts.name.endsWith('…'));
    assert.deepEqual({ ...H.labelParts('Link to Short', 'Short') }, { lead: 'Link to ', name: 'Short', tail: '' });
    assert.deepEqual({ ...H.labelParts(`${name} verlinken`, name, 80) }, { lead: '', name, tail: ' verlinken' }, 'A translation with the name first keeps its words after it.');
    assert.deepEqual({ ...H.labelParts('Choose an entry', null) }, { lead: '', name: 'Choose an entry', tail: '' });
});

test('a link to choose matches its hint, encoded or not', () => {
    assert.equal(H.isLinkFor('#gw-link:Winter%20structure', 'Winter structure'), true);
    assert.equal(H.isLinkFor('#gw-link:Winter structure', 'Winter structure'), true);
    assert.equal(H.isLinkFor('#gw-link:services-page', 'services-page'), true);
    assert.equal(H.isLinkFor('#gw-link:services-page', 'about'), false);
    assert.equal(H.isLinkFor('https://example.com/', 'about'), false);
    assert.equal(H.isLinkFor('#gw-link:%E0%A4%A', '%E0%A4%A'), true, 'A malformed escape is compared as it is.');
});

test('the mark sits above the words, below them under the chrome, and waits when they are hidden', () => {
    const view = { top: 57, width: 1280, height: 800 };
    const line = (top) => ({ left: 600, right: 720, top, bottom: top + 22 });

    assert.deepEqual({ ...H.inlineSpot(line(400), view) }, { x: 578, y: 354 });
    assert.deepEqual({ ...H.inlineSpot(line(80), view) }, { x: 578, y: 106 }, 'No room under the header: below the line.');
    assert.equal(H.inlineSpot(line(30), view), null, 'Under the header.');
    assert.equal(H.inlineSpot(line(820), view), null, 'Below the window.');
    assert.equal(H.inlineSpot({ left: 2, right: 40, top: 400, bottom: 422 }, view).x, 4, 'Kept inside the window.');
    assert.equal(H.inlineSpot(line(400), { ...view, mirror: true }).x, 698);
});

test('the mark\'s words go where they fit', () => {
    assert.equal(H.saySide(500, 80, 1280), 'right');
    assert.equal(H.saySide(1200, 80, 1280), 'left', 'Near the right edge: on its left.');
    assert.equal(H.saySide(100, 80, 1280, { mirror: true }), 'left');
    assert.equal(H.saySide(20, 80, 1280, { mirror: true }), 'right');
    assert.equal(H.saySide(150, 300, 375), 'below-right');
    assert.equal(H.saySide(250, 300, 375), 'below-left');
});

// Entry 168, "A roof garden for a cafe in Newcastle": its one gap was the
// required Hero image left empty (severity `required`, nothing that blocks),
// so Finish this page stayed in (`shown` false) and the menu said nothing,
// but Suggest edits' end said "1 thing still to finish", and its button
// opened a guide that was never out.
const heroImage = () => ({
    id: 'image-empty|heroImage||0', kind: 'image-empty', severity: 'required', path: 'heroImage', dotted: 'heroImage', field: 'heroImage', label: 'Hero image',
    hint: null, excerpt: null, occurrence: 0, message: 'Hero image is empty. Pages like this usually have an image here.', speech: 'Empty!', blocks: false, meta: [],
    fixes: [{ label: 'Find a photo', action: 'find-photo', cost: 'free', primary: true }, { label: 'Choose from Assets', action: 'choose-asset', cost: 'free', primary: false }],
    location: { elementId: 172, handle: 'heroImage', blocks: [], field: 'heroImage' },
});

test('what is left to finish is one number everywhere: nothing until the guide is out', () => {
    const steps = H.stepsFrom([heroImage()]);

    assert.equal(H.counts(steps, 0).count, 1, 'The guide itself counts the empty hero image.');
    assert.equal(H.published(steps, 0, false), 0, 'Not out yet: the menu, its row and Suggest edits say nothing.');
    assert.equal(H.published(steps, 0, true), 1, 'Once out, every count says 1.');
});

test('an image the page needs brings the guide out and counts; a suggestion alone does not', () => {
    const hero = [gap('image-empty', 'heroImage', 'prompt', 'Empty!')];

    assert.equal(H.bringsOut(hero), true);
    assert.equal(H.published(H.stepsFrom(hero), 0, H.bringsOut(hero)), 1, 'Entry 168: the header and the menu row say 1.');
    assert.equal(H.bringsOut([gap('expected', 'summary', 'suggestion')]), false);
    assert.equal(H.bringsOut([gap('link-empty', 'related', 'required')]), false, 'Counted once out, but it doesn\'t bring the guide out.');
    assert.equal(H.bringsOut(page()), true);
});

test('a guide opened from a count lands on what it counted, skipped or not', () => {
    const open = H.stepsFrom([heroImage()]);
    const skipped = H.stepsFrom([heroImage()], { skipped: new Set(['image-empty|heroImage||0']) });

    assert.equal(H.firstToDo(open), 0);
    assert.equal(H.firstOpen(skipped), 1, 'Nothing open: the end.');
    assert.equal(H.firstToDo(skipped), 0, 'But the skipped one still counts, so the guide opens on it.');
    assert.equal(H.firstToDo([]), 0);
});

test('the mark’s words go where they cover none of the page’s text', () => {
    // Above the guide, near the right edge: the sidebar's "Updated at" value is on its left.
    const covered = new Set(['left', 'below-left', 'below-right']);

    assert.equal(H.saySide(1330, 60, 1400, { covers: (side) => covered.has(side), y: 700 }), 'above-left');
    assert.equal(H.saySide(500, 60, 1400, { covers: () => false, y: 700 }), 'right', 'Nothing in the way: the reading side.');
    assert.equal(H.saySide(500, 60, 1400, { covers: (side) => side === 'right', y: 700 }), 'left');
    assert.equal(H.saySide(1330, 60, 1400, { covers: () => true, y: 700 }), 'none', 'Text everywhere (the mark in an editor): no words, the mark alone.');
    assert.equal(H.saySide(1330, 60, 1400, { covers: (side) => side !== 'below-left', y: 10 }), 'below-left', 'No room above.');

    const rect = H.sayRect('above-left', 1330, 700, 60, 20);
    assert.deepEqual({ ...rect }, { left: 1314, top: 676, right: 1374, bottom: 696 });
    assert.deepEqual({ ...H.sayRect('left', 1330, 700, 60, 20) }, { left: 1268, top: 702, right: 1328, bottom: 722 });
});

test('every line of an editor’s words counts; hidden words, the mark’s own and empty fields don’t', () => {
    const line = (left, top, right, bottom) => ({ left, top, right, bottom });
    const node = (text, boxes, { hidden = false, skip = false } = {}) => ({ nodeType: 3, textContent: text, boxes, parentElement: { closest: () => (skip ? {} : null), checkVisibility: () => !hidden } });
    const field = (value) => ({ value, placeholder: '', closest: () => null, checkVisibility: () => true, getBoundingClientRect: () => line(100, 600, 400, 630) });
    const nodes = [
        node('A long paragraph in CKEditor', [line(300, 400, 900, 420), line(300, 422, 900, 442), line(300, 444, 640, 464)]),
        node('Hidden', [line(100, 100, 200, 120)], { hidden: true }),
        node('Over here', [line(100, 100, 200, 120)], { skip: true }),
        node('Off screen', [line(100, 1000, 200, 1020)]),
    ];
    const doc = {
        body: {},
        createTreeWalker: () => ({ i: 0, nextNode() { return nodes[this.i++] ?? null; } }),
        createRange: () => ({ node: null, selectNodeContents(n) { this.node = n; }, getClientRects() { return this.node.boxes; } }),
        querySelectorAll: () => [field('Spring open days'), field('')],
    };
    const boxes = H.textBoxes({ skip: '.gw-finish-flyer', doc, win: { innerWidth: 1400, innerHeight: 900 } });

    assert.equal(boxes.length, 4, 'Three lines and the field with words in it.');
    assert.equal(H.meetsText({ left: 600, top: 446, right: 680, bottom: 466 }, boxes), true, 'On the third line, between where nine sample points would look.');
    assert.equal(H.meetsText({ left: 650, top: 470, right: 730, bottom: 490 }, boxes), false);
    assert.equal(H.meetsText({ left: 120, top: 100, right: 180, bottom: 120 }, boxes), false);
});

test('a link to a page is known however it is written (links Ghostwriter added)', () => {
    assert.equal(H.linkKey('{entry:12@1:url||/contact}'), 'entry:12');
    assert.equal(H.linkKey('{entry:12@1:url}'), 'entry:12');
    assert.equal(H.linkKey('%7Bentry:12@1:url%7C%7C/contact%7D'), 'entry:12', 'As the panel shows it.');
    assert.equal(H.linkKey('https://northfold.test/contact#entry:12@1:url'), 'entry:12', 'As CKEditor holds it.');
    assert.equal(H.linkKey('statamic://entry::abc-1'), 'entry::abc-1');
    assert.equal(H.linkKey('https://northfold.test/contact/'), 'path:/contact');
    assert.equal(H.linkKey('#gw-link:contact-page'), null, 'A link to choose is no page.');
    assert.equal(H.linkKey('mailto:hello@northfold.test'), null);
    assert.equal(H.linkKey(''), null);
});

test('the links to a page in plain text, with their words', () => {
    const text = 'Do [tell us]({entry:12@1:url||/contact}), see [plans]({entry:14@1:url||/plans}) and ![a photo](/a.jpg).';

    assert.deepEqual(Array.from(H.linksTo(text, 'https://northfold.test/contact#entry:12@1:url'), (m) => [m.words, m.match, m.index]), [['tell us', '[tell us]({entry:12@1:url||/contact})', 3]]);
    assert.deepEqual(Array.from(H.linksTo(text, '{entry:99@1:url}')), []);
});

test('an SEOmatic value made the page’s own: its override switch on and its source custom (seo-missing, Use this)', () => {
    context.Event = class { constructor(type, options) { this.type = type; this.bubbles = options?.bubbles; } };
    const fired = [];
    const element = (props) => ({ ...props, dispatchEvent(event) { fired.push([props.name ?? props.className, event.type]); }, closest: () => null });
    const classes = new Set(['inheritable-field', 'inherited-settings']);
    const wrapper = { classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c) } };
    const lightswitch = { ...element({ className: 'lightswitch' }), classList: { add: (c) => classes.add(`switch:${c}`) }, setAttribute: (name, value) => classes.add(`${name}=${value}`) };
    const toggle = { ...element({ name: 'fields[seoSettings][metaGlobalVars][override-seoDescription]', value: '' }), closest: (selector) => (selector === '.lightswitch' ? lightswitch : wrapper) };
    const source = element({ name: 'fields[seoSettings][metaBundleSettings][seoDescriptionSource]', value: 'fromField', options: [{ value: 'fromField' }, { value: 'fromCustom' }] });
    const box = element({ name: 'fields[seoSettings][metaGlobalVars][seoDescription]', value: '', id: 'fields-seoSettings-metaGlobalVars-seoDescription' });
    // The greyed copy of the section's value SEOmatic shows while the switch is off: never written to.
    const inherited = element({ name: 'fields[seoSettings][metaGlobalVars][seoDescription]', value: '{{ seomatic.helper.extractTextFromField(entry.excerpt) }}', id: 'fields-seoSettings-metaGlobalVars-seoDescription-inherited', disabled: true });
    const field = {
        querySelectorAll(selector) {
            return selector.includes('[metaGlobalVars][seoDescription]') ? [inherited, box] : [];
        },
        querySelector(selector) {
            if (selector.includes('override-seoDescription')) return toggle;
            if (selector.includes('seoDescriptionSource')) return source;
            if (selector.includes('[metaGlobalVars][seoDescription]')) return box;

            return null;
        },
    };

    assert.equal(H.seomaticInput(field, 'seoDescription'), box);
    assert.equal(H.seomaticOwn(field, 'seoDescription'), true);
    assert.equal(toggle.value, '1', 'Switched on: SEOmatic keeps the value.');
    assert.equal(source.value, 'fromCustom');
    assert.ok(classes.has('defined-settings') && !classes.has('inherited-settings'), 'The value shows, as SEOmatic shows it when switched on.');
    assert.deepEqual(fired.map(([, type]) => type), ['change', 'change']);

    // Already on, and custom: nothing changes.
    fired.length = 0;
    assert.equal(H.seomaticOwn(field, 'seoDescription'), true);
    assert.deepEqual(fired, []);
    assert.equal(H.seomaticOwn({ querySelector: () => null }, 'seoDescription'), false, 'A plain field has no switch.');
});

test('a link Suggest links found is found outside links, as core counts its repeats', () => {
    const text = 'Tell us about your garden, or tell us about your garden here, then tell us about your garden.';
    const linked = [...text].map((_, i) => i >= 30 && i < 55);
    const found = H.unlinkedRuns(text, linked, 'tell us about your garden');

    assert.equal(found.length, 1, 'The linked one and the capitalised one are not counted.');
    assert.equal(text.slice(found[0].index, found[0].index + found[0].length), 'tell us about your garden');
    assert.ok(found[0].index > 60);
    assert.deepEqual([...H.unlinkedRuns(text, [], '')], []);
});
