/**
 * Ghostwriter's preview locator: maps a rendered preview page back to the
 * blocks, fields and sections it shows.
 *
 * Framework-free and dependency-free. It runs in the CP page and reaches
 * into the preview frame's document, which must be same-origin. Each addon
 * copies this file as it is (a checksum test in the addon's CI keeps the
 * copies equal); the source of truth is ghostwriter-core's
 * resources/js/preview/locator.js. See ghostwriter-core's docs/preview.md.
 *
 * The page's text carries invisible markers (Unicode tag characters, see
 * core's Preview\PreviewMarkers). The locator:
 *
 * 1. finds them in text nodes and in `alt`, `title`, `aria-label` and
 *    `content` attributes, records each one's element, and removes them all
 *    straight away, so nothing can be copied, selected or measured with one;
 * 2. groups the page into one region per block, level by level (top-level
 *    blocks, then each block's children inside its region): a block's root
 *    is the outermost ancestor of its marks that holds no other block's
 *    marks, and a block printed straight into a shared container (an
 *    unwrapped rich-text block, `<h2><p><p>` in `<main>`) is a run of that
 *    container's children. Core puts each marker at the END of its value
 *    or section (a leading one breaks filters that capitalise the first
 *    letter), so a bare run reaches back over the unmarked elements before
 *    its marks (a section's heading) to the block before, then forward over
 *    what is left, stopping at the page's header, footer and nav;
 * 3. falls back, per block, in order: markers, then asset file names
 *    (`img`, `srcset`, `background-image`), then the text anchors (a unique
 *    match only), then the elements between two located siblings. A block
 *    found by none of them is "not on the page"; `content` is the page's
 *    content area, for a field-level outline.
 *
 * Nothing here draws anything: the overlay is the caller's.
 */

/** A marker. Group 1 is the payload in tag characters. Never after the black flag (subdivision flags). */
export const MARKER = /(?<!\u{1F3F4})\u{E0067}\u{E0077}([\u{E0020}-\u{E007E}]{1,16})\u{E007F}/gu;

/** A payload Ghostwriter writes: `b7`, `f2`, `s3`, each with an optional field index (`b7.2`). */
export const PAYLOAD = /^([bfs]\d+)(?:\.(\d+))?$/;

/** Attributes whose text may carry a marker. */
export const MARKED_ATTRIBUTES = ['alt', 'title', 'aria-label', 'content'];

/** Elements a block prints bare into a container; a run of them belongs to the marked element at its end. */
const BARE = new Set(['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'UL', 'OL', 'DL', 'BLOCKQUOTE', 'FIGURE', 'TABLE', 'PRE', 'HR', 'IMG', 'PICTURE', 'ADDRESS']);

/** Elements that end a run: the page's own furniture. */
const LANDMARKS = new Set(['HEADER', 'FOOTER', 'NAV', 'ASIDE', 'FORM', 'SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT']);
const LANDMARK_ROLES = new Set(['banner', 'contentinfo', 'navigation', 'complementary', 'search', 'form']);

/** Elements whose text is never the page's. */
const SKIP = new Set(['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'TEXTAREA']);

const ELEMENT = 1;
const TEXT = 3;

/** The payload of a marker's tag characters ("b7.2"). */
export function decodePayload(tags) {
    return Array.from(tags, (char) => String.fromCharCode(char.codePointAt(0) - 0xe0000)).join('');
}

/** A payload's key and field index, or null when it isn't Ghostwriter's shape. */
export function parsePayload(payload) {
    const match = PAYLOAD.exec(payload);

    return match ? { key: match[1], field: match[2] === undefined ? null : Number(match[2]) } : null;
}

/** Every marker taken out of a string. */
export function stripText(text) {
    return typeof text === 'string' ? text.replace(MARKER, '') : text;
}

/**
 * The words of some text, lower-cased, everything that isn't a letter or a
 * digit taken as a space: as core's NormalisedText::words().
 */
export function words(text) {
    return String(text ?? '')
        .replace(/[\u{E0000}-\u{E007F}­​-‍⁠﻿]/gu, '')
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter(Boolean);
}

/**
 * Finds every marker under a root (a document or an element), records
 * where each is, and removes them (unless `strip` is false).
 *
 * @returns {{ marks: Array<{payload: string, key: string|null, field: number|null, element: object, attribute: string|null}>, removed: number }}
 */
export function findMarkers(root, { strip = true } = {}) {
    const marks = [];
    let removed = 0;
    const start = root.documentElement ?? root;

    const visit = (node) => {
        if (node.nodeType === TEXT) {
            const value = node.nodeValue ?? '';

            if (value.includes('\u{E0067}\u{E0077}')) {
                for (const match of value.matchAll(MARKER)) {
                    marks.push(mark(match[1], node.parentNode, null));
                }

                if (strip) {
                    const stripped = stripText(value);

                    if (stripped !== value) {
                        removed += 1;
                        node.nodeValue = stripped;
                    }
                }
            }

            return;
        }

        if (node.nodeType !== ELEMENT || SKIP.has(tagOf(node))) {
            return;
        }

        for (const name of MARKED_ATTRIBUTES) {
            const value = node.getAttribute?.(name);

            if (typeof value === 'string' && value.includes('\u{E0067}\u{E0077}')) {
                for (const match of value.matchAll(MARKER)) {
                    marks.push(mark(match[1], node, name));
                }

                if (strip) {
                    removed += 1;
                    node.setAttribute(name, stripText(value));
                }
            }
        }

        for (const child of Array.from(node.childNodes ?? [])) {
            visit(child);
        }
    };

    visit(start);

    return { marks, removed };
}

/** Removes every marker under a root; returns how many nodes changed. */
export function stripMarkers(root) {
    return findMarkers(root).removed;
}

/**
 * Locates every block of a map on a page.
 *
 * @param {object} doc  The frame's document (same-origin).
 * @param {Array<object>} map  BlockMap::toArray(), as JSON.
 * @param {{strip?: boolean, marks?: Array<object>}} options  `marks`: what an earlier findMarkers() found (the DOM has been stripped since).
 * @returns {{ regions: Array<object>, byKey: Object<string, object>, missing: string[], content: object|null, partial: boolean, marks: Array<object> }}
 */
export function locate(doc, map, options = {}) {
    const body = doc.body ?? doc.documentElement;
    const found = options.marks ?? findMarkers(doc, { strip: options.strip !== false }).marks;
    const blocks = (map ?? []).filter((block) => block && typeof block.key === 'string');
    const byKey = Object.fromEntries(blocks.map((block) => [block.key, block]));
    const own = new Map(blocks.map((block) => [block.key, []]));
    const fields = new Map(blocks.map((block) => [block.key, {}]));
    const marks = [];

    for (const found1 of found) {
        if (!found1.key || !own.has(found1.key) || !contains(body, found1.element)) {
            continue;
        }

        marks.push(found1);
        pushUnique(own.get(found1.key), found1.element);

        if (found1.field !== null) {
            const byField = fields.get(found1.key);
            byField[found1.field] = byField[found1.field] ?? [];
            pushUnique(byField[found1.field], found1.element);
        }
    }

    const children = (parent) => blocks.filter((block) => (block.parent ?? null) === parent);
    const subtree = (key) => [...own.get(key), ...children(key).flatMap((child) => subtree(child.key))];
    const allMarked = blocks.flatMap((block) => own.get(block.key));
    const regions = {};
    const cache = new Map();

    const level = (parent, scope) => {
        const siblings = children(parent);
        const evidence = new Map();
        const claimed = new Set();

        for (const block of siblings) {
            const marked = subtree(block.key).filter((element) => inScope(element, scope));

            if (marked.length) {
                evidence.set(block.key, { method: 'marker', elements: marked });
                continue;
            }

            const assets = byAsset(scope, block.assets ?? []);

            if (assets.length) {
                evidence.set(block.key, { method: 'asset', elements: assets });
                continue;
            }

            const anchored = byAnchor(scope, block.anchors ?? [], cache);

            if (anchored.length) {
                evidence.set(block.key, { method: 'anchor', elements: anchored });
            }
        }

        const placed = place(siblings, evidence, scope, allMarked, claimed);
        fillGaps(siblings, placed, claimed, allMarked);

        for (const block of siblings) {
            const region = placed.get(block.key);

            if (region) {
                regions[block.key] = {
                    key: block.key,
                    kind: block.kind ?? 'block',
                    label: block.label ?? block.key,
                    parent: block.parent ?? null,
                    method: region.method,
                    elements: region.elements,
                    fields: Object.fromEntries(Object.entries(fields.get(block.key)).map(([index, elements]) => [index, elements.slice()])),
                };
            }
        }

        for (const block of siblings) {
            level(block.key, regions[block.key] ? regions[block.key].elements : scope);
        }
    };

    level(null, [body]);

    const ordered = blocks.map((block) => regions[block.key]).filter(Boolean);
    const top = blocks.filter((block) => !block.parent);
    const located = top.filter((block) => regions[block.key]).length;

    return {
        regions: ordered,
        byKey: regions,
        missing: blocks.filter((block) => !regions[block.key]).map((block) => block.key),
        content: contentArea(doc),
        partial: top.length > 0 && located < top.length / 2,
        marks,
    };
}

/**
 * A region's box in document coordinates: the union of its elements'
 * client rects plus the window's scroll. Null when nothing has a size.
 */
export function measure(region, win) {
    let box = null;

    for (const element of region?.elements ?? []) {
        const rect = element.getBoundingClientRect?.();

        if (!rect || (rect.width === 0 && rect.height === 0)) {
            continue;
        }

        const left = rect.left + (win?.scrollX ?? 0);
        const top = rect.top + (win?.scrollY ?? 0);

        box = box
            ? union(box, { left, top, right: left + rect.width, bottom: top + rect.height })
            : { left, top, right: left + rect.width, bottom: top + rect.height };
    }

    return box ? { left: box.left, top: box.top, width: box.right - box.left, height: box.bottom - box.top } : null;
}

/**
 * Whether a frame's document can be read: same-origin, and loaded with
 * something in it (a refused frame loads an empty or error document).
 */
export function canRead(frame) {
    try {
        const doc = frame?.contentDocument;

        return Boolean(doc && doc.body && doc.body.childNodes && doc.body.childNodes.length > 0);
    } catch {
        return false;
    }
}

/**
 * Watches a document for markup that scripts add later: each batch of
 * mutations (one per animation frame) is searched and stripped, and
 * `onMarks(marks)` is called when it held markers. Returns `stop()`.
 */
export function watch(doc, onMarks) {
    const win = doc.defaultView;
    const Observer = win?.MutationObserver;

    if (!Observer) {
        return { stop() {} };
    }

    let pending = false;
    const frame = win.requestAnimationFrame?.bind(win) ?? ((callback) => setTimeout(callback, 16));
    const observer = new Observer(() => {
        if (pending) {
            return;
        }

        pending = true;
        frame(() => {
            pending = false;
            observer.disconnect();
            const { marks } = findMarkers(doc);
            observer.observe(doc.documentElement, OPTIONS);

            if (marks.length) {
                onMarks(marks);
            }
        });
    });
    const OPTIONS = { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: MARKED_ATTRIBUTES };

    observer.observe(doc.documentElement, OPTIONS);

    return { stop: () => observer.disconnect() };
}

/** The page's content area: `main`, `article`, `[role=main]`, or the body. */
export function contentArea(doc) {
    const body = doc.body ?? null;

    if (!body) {
        return null;
    }

    return first(body, (element) => tagOf(element) === 'MAIN' || element.getAttribute?.('role') === 'main')
        ?? first(body, (element) => tagOf(element) === 'ARTICLE')
        ?? body;
}

// ---------------------------------------------------------------------------

function mark(tags, element, attribute) {
    const payload = decodePayload(tags);
    const parsed = parsePayload(payload);

    return { payload, key: parsed?.key ?? null, field: parsed?.field ?? null, element, attribute };
}

/**
 * Each sibling's region from its evidence: a root, or a run of a shared
 * container's children; bare roots and runs then take the bare, unmarked
 * elements before them (back to the block before), then after them (up to
 * the next block).
 */
function place(siblings, evidence, scope, allMarked, claimed) {
    const placed = new Map();
    const keys = siblings.map((block) => block.key).filter((key) => evidence.has(key));

    for (const key of keys) {
        const { method, elements } = evidence.get(key);
        const others = keys.filter((other) => other !== key).flatMap((other) => evidence.get(other).elements);
        const lca = commonAncestor(elements);

        if (!lca) {
            continue;
        }

        if (!others.some((element) => element === lca || contains(lca, element))) {
            // Its own wrapper: the outermost ancestor holding no other block's evidence, inside the scope.
            // Within a run (a scope of several elements) that may be one of the run's own elements.
            let root = lca;
            const within = scope.length > 1 ? inScope : insideScope;

            while (root.parentNode && !scope.includes(root) && within(root.parentNode, scope) && !others.some((element) => contains(root.parentNode, element))) {
                root = root.parentNode;
            }

            placed.set(key, { method, elements: [root], bare: BARE.has(tagOf(root)) });
            continue;
        }

        // A shared container: the run of its children (within the scope) from the first holding this block's evidence.
        const kids = elementChildren(lca).filter((child) => inScope(child, scope));
        const holds = (child, list) => list.some((element) => child === element || contains(child, element));
        const firstIndex = kids.findIndex((child) => holds(child, elements) && !holds(child, others));

        if (firstIndex < 0) {
            continue;
        }

        let lastIndex = firstIndex;

        for (let i = firstIndex + 1; i < kids.length && !holds(kids[i], others); i++) {
            if (holds(kids[i], elements)) {
                lastIndex = i;
            }
        }

        placed.set(key, { method, elements: kids.slice(firstIndex, lastIndex + 1), bare: true });
    }

    for (const region of placed.values()) {
        region.elements.forEach((element) => claimed.add(element));
    }

    const free = (element) => element && BARE.has(tagOf(element)) && !isLandmark(element) && !claimed.has(element) && inScope(element, scope)
        && !allMarked.some((marked) => marked === element || contains(element, marked));

    // Markers are at the end of each value and section, so bare roots and runs
    // first take the bare, unmarked elements before them (a section's heading
    // and its first paragraphs), back to the block before; then, for what is
    // left, the ones after them, up to the next block or the page's furniture.
    for (const key of keys) {
        const region = placed.get(key);

        if (region?.bare) {
            for (let previous = previousElement(region.elements[0]); free(previous); previous = previousElement(previous)) {
                region.elements.unshift(previous);
                claimed.add(previous);
            }
        }
    }

    for (const key of keys) {
        const region = placed.get(key);

        if (region?.bare) {
            for (let next = nextElement(region.elements[region.elements.length - 1]); free(next); next = nextElement(next)) {
                region.elements.push(next);
                claimed.add(next);
            }
        }
    }

    return placed;
}

/** A block found by nothing, between two located siblings in one container, takes the elements between them. */
function fillGaps(siblings, placed, claimed, allMarked) {
    for (let i = 0; i < siblings.length; i++) {
        if (placed.has(siblings[i].key)) {
            continue;
        }

        const before = siblings.slice(0, i).reverse().find((block) => placed.has(block.key));
        let j = i;

        while (j < siblings.length && !placed.has(siblings[j].key)) {
            j++;
        }

        const after = siblings[j];

        if (!before || !after) {
            i = j;
            continue;
        }

        const a = placed.get(before.key);
        const b = placed.get(after.key);
        const last = a.elements[a.elements.length - 1];
        const firstOfB = b.elements[0];

        if (last.parentNode !== firstOfB.parentNode) {
            i = j;
            continue;
        }

        const between = [];

        for (let next = nextElement(last); next && next !== firstOfB; next = nextElement(next)) {
            if (!claimed.has(next) && !isLandmark(next) && !allMarked.some((element) => element === next || contains(next, element))) {
                between.push(next);
            }
        }

        const missing = siblings.slice(i, j);

        if (missing.length === 1 && between.length) {
            placed.set(missing[0].key, { method: 'gap', elements: between, bare: false });
            between.forEach((element) => claimed.add(element));
        } else if (missing.length > 1 && between.length === missing.length) {
            missing.forEach((block, n) => {
                placed.set(block.key, { method: 'gap', elements: [between[n]], bare: false });
                claimed.add(between[n]);
            });
        }

        i = j;
    }
}

/** Elements showing one of the block's image files: `img` src/srcset, `picture source`, `background-image`. */
function byAsset(scope, assets) {
    const stems = new Set(assets.map((asset) => stem(asset)).filter(Boolean));

    if (!stems.size) {
        return [];
    }

    const found = [];

    for (const root of scope) {
        walkElements(root, (element) => {
            const tag = tagOf(element);
            const urls = [];

            if (tag === 'IMG' || tag === 'SOURCE') {
                for (const name of ['src', 'srcset', 'data-src', 'data-srcset']) {
                    urls.push(...splitSrcset(element.getAttribute?.(name)));
                }
            }

            const style = element.getAttribute?.('style') ?? '';

            for (const match of style.matchAll(/background(?:-image)?\s*:[^;]*url\(\s*['"]?([^'")]+)['"]?\s*\)/gi)) {
                urls.push(match[1]);
            }

            if (urls.some((url) => segments(url).some((segment) => stems.has(stem(segment))))) {
                const target = tag === 'SOURCE' && tagOf(element.parentNode) === 'PICTURE' ? element.parentNode : element;
                pushUnique(found, target);
            }
        });
    }

    return found;
}

/** The deepest element holding each anchor's words, where exactly one does. */
function byAnchor(scope, anchors, cache) {
    const found = [];

    for (const anchor of anchors) {
        const needle = ` ${words(anchor).join(' ')} `;

        if (needle.trim().split(' ').length < 2) {
            continue;
        }

        const holding = [];

        for (const root of scope) {
            walkElements(root, (element) => {
                if (!SKIP.has(tagOf(element)) && textOf(element, cache).includes(needle)) {
                    holding.push(element);
                }
            });
        }

        const deepest = holding.filter((element) => !holding.some((other) => other !== element && contains(element, other)));

        if (deepest.length === 1) {
            pushUnique(found, deepest[0]);
        }
    }

    return found;
}

function textOf(element, cache) {
    if (!cache.has(element)) {
        cache.set(element, ` ${words(stripText(element.textContent ?? '')).join(' ')} `);
    }

    return cache.get(element);
}

function stem(path) {
    let name = String(path ?? '').split(/[?#]/)[0].split('/').pop() ?? '';

    try {
        name = decodeURIComponent(name);
    } catch {
        // Keep it as written.
    }

    return name.toLowerCase().replace(/\.[a-z0-9]{2,5}$/i, '');
}

function segments(url) {
    return String(url ?? '').split(/[?#]/)[0].split('/').filter(Boolean);
}

function splitSrcset(value) {
    return typeof value === 'string' && value.trim() ? value.split(',').map((part) => part.trim().split(/\s+/)[0]).filter(Boolean) : [];
}

function commonAncestor(elements) {
    if (!elements.length) {
        return null;
    }

    let ancestor = elements[0];

    for (const element of elements.slice(1)) {
        while (ancestor && !(ancestor === element || contains(ancestor, element))) {
            ancestor = ancestor.parentNode;
        }
    }

    return ancestor && ancestor.nodeType === ELEMENT ? ancestor : null;
}

function contains(ancestor, node) {
    if (!ancestor || !node || ancestor === node) {
        return false;
    }

    for (let at = node.parentNode; at; at = at.parentNode) {
        if (at === ancestor) {
            return true;
        }
    }

    return false;
}

function inScope(element, scope) {
    return scope.some((root) => root === element || contains(root, element));
}

function insideScope(element, scope) {
    return scope.some((root) => contains(root, element));
}

function isLandmark(element) {
    return LANDMARKS.has(tagOf(element)) || LANDMARK_ROLES.has(element.getAttribute?.('role') ?? '');
}

function elementChildren(element) {
    return Array.from(element?.childNodes ?? []).filter((node) => node.nodeType === ELEMENT);
}

function previousElement(element) {
    let previous = element?.previousSibling ?? null;

    while (previous && previous.nodeType !== ELEMENT) {
        previous = previous.previousSibling;
    }

    return previous;
}

function nextElement(element) {
    let next = element?.nextSibling ?? null;

    while (next && next.nodeType !== ELEMENT) {
        next = next.nextSibling;
    }

    return next;
}

function walkElements(root, callback) {
    if (!root || root.nodeType !== ELEMENT) {
        return;
    }

    callback(root);

    for (const child of Array.from(root.childNodes ?? [])) {
        walkElements(child, callback);
    }
}

function first(root, test) {
    let found = null;

    walkElements(root, (element) => {
        if (!found && element !== root && test(element)) {
            found = element;
        }
    });

    return found;
}

function tagOf(element) {
    return String(element?.tagName ?? element?.nodeName ?? '').toUpperCase();
}

function pushUnique(list, item) {
    if (item && !list.includes(item)) {
        list.push(item);
    }
}

function union(a, b) {
    return { left: Math.min(a.left, b.left), top: Math.min(a.top, b.top), right: Math.max(a.right, b.right), bottom: Math.max(a.bottom, b.bottom) };
}
