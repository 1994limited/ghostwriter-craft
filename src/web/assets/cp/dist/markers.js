/**
 * Ghostwriter's gap markers, shown as what they mean instead of as raw text.
 *
 * Framework-free and dependency-free, like the locator: each addon copies
 * this file as it is (a checksum test in the addon's CI keeps the copies
 * equal); the source of truth is ghostwriter-core's
 * resources/js/preview/markers.js. See ghostwriter-core's docs/gaps.md.
 *
 * Three markers (Gaps\Markers) are visible on purpose, so an editor who never
 * opens the guide still sees them. Printed as they are, they read as code:
 *
 * - `[[ask: adult ticket price]]` becomes an amber chip reading "adult ticket
 *   price" ("Only you know this: add it before publishing");
 * - `[[check: 3 areas | from: …]]` becomes "3 areas" with a dotted amber
 *   underline ("Counted from '…'. Check it before publishing");
 * - a `#gw-link:` link gets a dashed amber underline ("Link to choose").
 *
 * Everything here is display only. In a document (the preview frame), the
 * chips replace the text nodes the site printed; the stored value is never
 * touched, and nothing here is ever read back into a field. Elsewhere (the
 * Extras list, a row under a plain text input) `segments()`, `toHtml()`,
 * `toPlainText()` and `gapsIn()` format a string without a DOM.
 *
 * The patterns are core's (resources/gaps/patterns.json, from
 * Gaps\Markers::patterns()); a test keeps the copies below equal to it.
 */

/** Core's patterns: `ask`, `check` and `sentinel` from patterns.json. */
export const PATTERNS = {
    ask: { source: '\\[\\[\\s*ask\\s*:\\s*([^\\[\\]\\s][^\\[\\]\\n]{0,199}?)\\s*\\]\\]', flags: 'giu' },
    check: { source: '\\[\\[\\s*check\\s*:\\s*([^\\[\\]|\\n]{1,120}?)\\s*\\|\\s*from\\s*:\\s*([^\\[\\]\\n]{1,300}?)\\s*\\]\\]', flags: 'giu' },
    link: { source: '\\[([^\\[\\]\\n]*)\\]\\(\\s*<?(?:https?://example\\.com/?)?#gw-link:([^\\s)>]*)>?(?:\\s+"[^"\\n]*")?\\s*\\)', flags: 'giu' },
    sentinel: { source: '#gw-link:([A-Za-z0-9._~%-]*)', flags: 'gu' },
};

export const LINK_PREFIX = '#gw-link:';

/** The English labels; an addon passes its own translations as `labels`. `:list` is the counted list. */
export const LABELS = {
    ask: 'Only you know this: add it before publishing',
    check: 'Counted from \':list\'. Check it before publishing',
    link: 'Link to choose',
    askSpoken: 'Fact to add:',
    checkSpoken: 'Count to check:',
    linkSpoken: '(link to choose)',
    askRow: 'Add: :hint',
    checkRow: 'Check: :hint',
    linkRow: 'Choose a link: :hint',
};

/** The class every chip carries, and the attribute naming its kind. */
export const CHIP = 'gw-gap';
export const KIND = 'data-gw-gap-kind';

/** The styles: injected into the frame (or the CP page) once, never into the site's CSS. */
export const STYLES = `
.gw-gap-ask { background: #fef3c7 !important; color: #78350f !important; border: 1px dashed #d97706 !important; border-radius: 4px !important; padding: 0 .3em !important; font-style: normal !important; text-decoration: none !important; -webkit-box-decoration-break: clone; box-decoration-break: clone; cursor: help; }
.gw-gap-check { text-decoration-line: underline !important; text-decoration-style: dotted !important; text-decoration-color: #d97706 !important; text-decoration-thickness: 2px !important; text-underline-offset: .2em !important; cursor: help; }
a.gw-gap-link, .gw-gap-link { text-decoration-line: underline !important; text-decoration-style: dashed !important; text-decoration-color: #d97706 !important; text-decoration-thickness: 2px !important; text-underline-offset: .2em !important; cursor: help; }
.gw-gap[tabindex] { cursor: pointer; }
.gw-gap[tabindex]:focus-visible { outline: 2px solid #d97706 !important; outline-offset: 2px !important; }
.gw-gap-sr { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0, 0, 0, 0) !important; white-space: nowrap !important; border: 0 !important; }
.gw-gap-row { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; font-size: 12px; line-height: 1.5; }
.gw-gap-row .gw-gap-ask { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
:where(.dark) .gw-gap-ask { background: rgba(245, 158, 11, .18) !important; color: #fcd34d !important; border-color: #f59e0b !important; }
@media (forced-colors: active) { .gw-gap-ask { border-color: Highlight !important; } .gw-gap-check, .gw-gap-link { text-decoration-color: Highlight !important; } }
`;

const STYLE_ID = 'gw-gap-styles';

/** Elements whose text is never the page's, or is already a chip. */
const SKIP = new Set(['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION', 'TITLE']);

const ELEMENT = 1;
const TEXT = 3;

const regex = ({ source, flags }) => new RegExp(source, flags.includes('g') ? flags : `${flags}g`);

/** Invisible characters a value may carry (the preview's tag-character markers). */
const clean = (text) => String(text ?? '').replace(/[\u{E0000}-\u{E007F}]/gu, '').trim();

const fill = (label, params) => String(label).replace(/:(hint|list|value)\b/g, (whole, name) => (params[name] ?? whole));

/**
 * The asks and counts to check in a string, in order:
 * `{kind: 'ask'|'check', index, length, match, hint, value?, list?}`.
 * For a check, `hint` and `value` are the value ("3 areas").
 */
export function find(text) {
    const value = String(text ?? '');
    const found = [];

    if (!value.includes('[[')) return found;

    for (const m of value.matchAll(regex(PATTERNS.ask))) {
        found.push({ kind: 'ask', index: m.index, length: m[0].length, match: m[0], hint: clean(m[1]) });
    }

    for (const m of value.matchAll(regex(PATTERNS.check))) {
        const hint = clean(m[1]);
        found.push({ kind: 'check', index: m.index, length: m[0].length, match: m[0], hint, value: hint, list: clean(m[2]) });
    }

    return found.sort((a, b) => a.index - b.index);
}

/** Whether a link's href is a link still to choose. */
export function isLinkToChoose(href) {
    return typeof href === 'string' && href.includes(LINK_PREFIX);
}

/** A `#gw-link:` href's hint ("contact page"), or null. */
export function linkHint(href) {
    if (!isLinkToChoose(href)) return null;

    const raw = href.slice(href.indexOf(LINK_PREFIX) + LINK_PREFIX.length);
    let hint = raw;

    try {
        hint = decodeURIComponent(raw);
    } catch {
        // Keep it as written.
    }

    return hint.replace(/[-_]+/g, ' ').trim();
}

/**
 * A string as pieces: `{kind: 'text', text}`, `{kind: 'ask', text, hint}`,
 * `{kind: 'check', text, hint, value, list}`, and, for markdown links to
 * choose (`[words](#gw-link:hint)`) when `links` is true, `{kind: 'link',
 * text, hint}`. `text` is what to show.
 */
export function segments(text, { links = true } = {}) {
    const value = String(text ?? '');
    const marks = find(value);

    if (links && value.includes(LINK_PREFIX)) {
        for (const m of value.matchAll(regex(PATTERNS.link))) {
            if (!marks.some((mark) => m.index < mark.index + mark.length && mark.index < m.index + m[0].length)) {
                marks.push({ kind: 'link', index: m.index, length: m[0].length, match: m[0], words: m[1], hint: linkHint(LINK_PREFIX + m[2]) });
            }
        }

        marks.sort((a, b) => a.index - b.index);
    }

    const pieces = [];
    let at = 0;

    for (const mark of marks) {
        if (mark.index < at) continue;
        if (mark.index > at) pieces.push({ kind: 'text', text: value.slice(at, mark.index) });

        if (mark.kind === 'ask') pieces.push({ kind: 'ask', text: mark.hint, hint: mark.hint });
        else if (mark.kind === 'check') pieces.push({ kind: 'check', text: mark.value, hint: mark.hint, value: mark.value, list: mark.list });
        else pieces.push({ kind: 'link', text: mark.words, hint: mark.hint });

        at = mark.index + mark.length;
    }

    if (at < value.length) pieces.push({ kind: 'text', text: value.slice(at) });

    return pieces;
}

/** A string with each marker as the words it stands for: the hint, the value, the link's words. */
export function toPlainText(text) {
    return segments(text).map((piece) => piece.text).join('');
}

/** Whether a string holds an ask, a count to check or a markdown link to choose. */
export function has(text) {
    return segments(text).some((piece) => piece.kind !== 'text');
}

const escape = (text) => String(text).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);

/**
 * A string as HTML, escaped, with each marker as its chip: for places that
 * print text as HTML (the Extras list). Never store what this returns.
 */
export function toHtml(text, { labels = {} } = {}) {
    const l = { ...LABELS, ...labels };

    return segments(text).map((piece) => {
        if (piece.kind === 'text') return escape(piece.text);

        const { title, spoken } = describe(piece, l);

        return `<span class="${CHIP} gw-gap-${piece.kind}" ${KIND}="${piece.kind}" title="${escape(title)}"><span class="gw-gap-sr">${escape(spoken)} </span>${escape(piece.text)}</span>`;
    }).join('');
}

/**
 * The gaps in a value, one line each, for a chip row under a plain text
 * input: `[{kind, hint, text, title}]`, text being "Add: adult ticket price".
 */
export function gapsIn(text, { labels = {} } = {}) {
    const l = { ...LABELS, ...labels };

    return segments(text).filter((piece) => piece.kind !== 'text').map((piece) => ({
        kind: piece.kind,
        hint: piece.hint,
        text: fill(l[`${piece.kind}Row`], { hint: piece.hint }),
        title: describe(piece, l).title,
    }));
}

function describe(piece, l) {
    if (piece.kind === 'ask') return { title: l.ask, spoken: l.askSpoken };
    if (piece.kind === 'check') return { title: fill(l.check, { list: piece.list }), spoken: l.checkSpoken };

    return { title: l.link, spoken: l.linkSpoken };
}

/** Puts the styles into a document once. */
export function injectStyles(doc) {
    if (!doc || findById(doc, STYLE_ID)) return;

    const style = doc.createElement('style');
    style.setAttribute('id', STYLE_ID);
    style.textContent = STYLES;
    (doc.head ?? doc.documentElement).appendChild(style);
}

/**
 * A chip row for a plain text input's value: a `<div class="gw-gap-row">`
 * of chips ("Add: adult ticket price"), or null when it has no gaps.
 */
export function chipRow(doc, text, { labels = {} } = {}) {
    const gaps = gapsIn(text, { labels });

    if (!gaps.length) return null;

    const row = doc.createElement('div');
    row.setAttribute('class', 'gw-gap-row');
    row.setAttribute('data-gw-gap-row', '');

    for (const gap of gaps) {
        const chip = doc.createElement('span');
        chip.setAttribute('class', `${CHIP} gw-gap-ask`);
        chip.setAttribute(KIND, gap.kind);
        chip.setAttribute('title', gap.title);
        chip.appendChild(doc.createTextNode(gap.text));
        row.appendChild(chip);
    }

    return row;
}

/**
 * Shows every marker under a root (a document or an element) as its chip,
 * and marks links to choose. Run it after the locator has stripped the
 * preview's markers, so each chip sits inside its block's region.
 *
 * - Text nodes holding `[[ask: …]]` or `[[check: …]]` are split, each marker
 *   replaced by a `<span class="gw-gap …">` with a visually hidden label and
 *   a `title`; text in scripts, styles, form controls and existing chips is
 *   left alone, so running it again changes nothing.
 * - `a[href*="#gw-link:"]` gets `gw-gap gw-gap-link`, a `title` and a hidden
 *   "(link to choose)".
 * - With `onActivate(chip)`, chips are focusable buttons: a click, Enter or
 *   Space calls it with `{kind, hint, value?, list?, element}`.
 *
 * @returns {Array<{kind: string, hint: string, value?: string, list?: string, element: object}>} every chip under the root, in document order
 */
export function markGaps(root, { labels = {}, onActivate = null, styles = true } = {}) {
    const doc = root?.ownerDocument && root.nodeType !== 9 ? root.ownerDocument : root;
    const start = root?.documentElement ?? root;
    const l = { ...LABELS, ...labels };

    if (!doc || !start) return [];

    if (styles) injectStyles(doc);

    const texts = [];
    const links = [];

    walk(start, (node) => {
        if (node.nodeType === TEXT) {
            if ((node.nodeValue ?? '').includes('[[')) texts.push(node);

            return true;
        }

        if (node.nodeType !== ELEMENT || SKIP.has(tagOf(node)) || isChip(node)) return false;

        if (tagOf(node) === 'A' && isLinkToChoose(node.getAttribute?.('href'))) links.push(node);

        return true;
    });

    for (const node of texts) {
        const pieces = segments(node.nodeValue, { links: false });

        if (!pieces.some((piece) => piece.kind !== 'text')) continue;

        const parent = node.parentNode;

        for (const piece of pieces) {
            const replacement = piece.kind === 'text' ? doc.createTextNode(piece.text) : chip(doc, piece, l);
            parent.insertBefore(replacement, node);
        }

        parent.removeChild(node);
    }

    for (const link of links) {
        const hint = linkHint(link.getAttribute('href'));
        const classes = String(link.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);

        for (const name of [CHIP, 'gw-gap-link']) {
            if (!classes.includes(name)) classes.push(name);
        }

        link.setAttribute('class', classes.join(' '));
        link.setAttribute(KIND, 'link');
        link.setAttribute('data-gw-gap-hint', hint ?? '');
        link.setAttribute('title', l.link);

        if (!childOf(link, (child) => hasClass(child, 'gw-gap-sr'))) {
            const spoken = doc.createElement('span');
            spoken.setAttribute('class', 'gw-gap-sr');
            spoken.appendChild(doc.createTextNode(` ${l.linkSpoken}`));
            link.appendChild(spoken);
        }
    }

    const chips = [];

    walk(start, (node) => {
        if (node.nodeType !== ELEMENT || SKIP.has(tagOf(node))) return false;

        if (hasClass(node, CHIP)) {
            chips.push(describeChip(node));

            return tagOf(node) === 'A';
        }

        return true;
    });

    if (typeof onActivate === 'function') {
        for (const found of chips) activate(found, onActivate);
    }

    return chips;
}

/**
 * How many chips are in each located block's region (the locator's
 * `result.regions`): `{b1: 2, s3: 1}`. A chip in a child's region counts
 * for its parent too, as the parent's region holds it.
 */
export function countByRegion(regions, chips) {
    const counts = {};

    for (const region of regions ?? []) {
        const n = chips.filter((found) => (region.elements ?? []).some((element) => element === found.element || within(element, found.element))).length;

        if (n) counts[region.key] = n;
    }

    return counts;
}

// ---------------------------------------------------------------------------

function chip(doc, piece, l) {
    const { title, spoken } = describe(piece, l);
    const span = doc.createElement('span');
    span.setAttribute('class', `${CHIP} gw-gap-${piece.kind}`);
    span.setAttribute(KIND, piece.kind);
    span.setAttribute('data-gw-gap-hint', piece.hint);

    if (piece.kind === 'check') span.setAttribute('data-gw-gap-list', piece.list);

    span.setAttribute('title', title);

    const hidden = doc.createElement('span');
    hidden.setAttribute('class', 'gw-gap-sr');
    hidden.appendChild(doc.createTextNode(`${spoken} `));
    span.appendChild(hidden);
    span.appendChild(doc.createTextNode(piece.text));

    return span;
}

function describeChip(element) {
    const kind = element.getAttribute(KIND) ?? 'ask';
    const hint = element.getAttribute('data-gw-gap-hint') ?? '';
    const found = { kind, hint, element };

    if (kind === 'check') {
        found.value = hint;
        found.list = element.getAttribute('data-gw-gap-list') ?? '';
    }

    return found;
}

function activate(found, onActivate) {
    const element = found.element;

    if (element.getAttribute('data-gw-gap-active') !== null) return;

    element.setAttribute('data-gw-gap-active', '');

    if (tagOf(element) !== 'A') {
        element.setAttribute('tabindex', '0');
        element.setAttribute('role', 'button');
    }

    element.addEventListener?.('click', (event) => {
        event.preventDefault?.();
        event.stopPropagation?.();
        onActivate(found);
    });
    element.addEventListener?.('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault?.();
        onActivate(found);
    });
}

/** Visits nodes depth first; `visit` returns false to skip a node's children. */
function walk(node, visit) {
    if (!node || visit(node) === false) return;

    for (const child of Array.from(node.childNodes ?? [])) walk(child, visit);
}

function findById(doc, id) {
    if (typeof doc.getElementById === 'function') return doc.getElementById(id);

    let found = null;
    walk(doc.documentElement, (node) => {
        if (found || node.nodeType !== ELEMENT) return false;
        if (node.getAttribute?.('id') === id) found = node;

        return !found;
    });

    return found;
}

function childOf(element, test) {
    return Array.from(element.childNodes ?? []).find((child) => child.nodeType === ELEMENT && test(child)) ?? null;
}

function hasClass(element, name) {
    return String(element.getAttribute?.('class') ?? '').split(/\s+/).includes(name);
}

function isChip(element) {
    return hasClass(element, CHIP) && tagOf(element) !== 'A';
}

function within(ancestor, node) {
    for (let at = node?.parentNode; at; at = at.parentNode) {
        if (at === ancestor) return true;
    }

    return false;
}

function tagOf(element) {
    return String(element?.tagName ?? element?.nodeName ?? '').toUpperCase();
}
