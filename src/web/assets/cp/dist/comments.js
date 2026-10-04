// Comments on the page preview: the logic the panel and the overlay share,
// with no DOM of its own beyond what is passed in (so Node can test it).
//
// Pins are of two kinds: the editor's own, not sent yet (`pending`, kept in
// their browser), and comments sent in the conversation (their state from
// the server: sending, changed, replied, refused, skipped, failed,
// resolved, detached). Both have {number, status, kind, units, quote,
// label, path}.
//
// A comment points at the draft's words (units), never at a block's place,
// so it follows them into any layout: its pin goes on the block of the
// rendered page that holds them now (the map the preview was rendered with
// says which units each block shows). A comment on some words also carries
// the words, and its pin sits at them when they can still be found.

/** Every unit a block shows: its own and its children's, in map order. */
export function coverOf(key, map) {
    const out = [];
    const walk = (at, depth) => {
        if (depth > 20) return;

        const block = map.find((candidate) => candidate.key === at);

        for (const unit of block?.units ?? []) {
            if (!out.includes(unit)) out.push(unit);
        }

        for (const child of map.filter((candidate) => candidate.parent === at)) walk(child.key, depth + 1);
    };

    walk(key, 0);

    return out;
}

/**
 * Each block's place in its layout's plan, as core names it
 * ("page_builder/2", "page_builder/2/cards/0", "body" for a field): by
 * order in the map, which follows the data. For a block another layout
 * fills from pieces or extras, the server finds what that plan puts there.
 */
export function planPaths(map) {
    const byKey = Object.fromEntries(map.map((block) => [block.key, block]));
    const counts = {};
    const out = {};

    for (const block of map) {
        const parent = block.parent ? byKey[block.parent] : null;

        if (block.kind === 'field' && !parent) {
            out[block.key] = block.path;
        } else if (block.kind === 'block' && !parent) {
            const handle = String(block.path ?? '').split('/')[0];
            counts[handle] = (counts[handle] ?? -1) + 1;
            out[block.key] = `${handle}/${counts[handle]}`;
        } else if (block.kind === 'block' && parent?.kind === 'block' && out[parent.key] && String(block.path ?? '').startsWith(`${parent.path}/`)) {
            const field = block.path.slice(parent.path.length + 1).split('/')[0];
            const at = `${parent.key}/${field}`;
            counts[at] = (counts[at] ?? -1) + 1;
            out[block.key] = `${out[parent.key]}/${field}/${counts[at]}`;
        }
    }

    return out;
}

function depth(block, byKey) {
    let count = 0;
    let at = block;

    while (at?.parent && byKey[at.parent] && count < 20) {
        count += 1;
        at = byKey[at.parent];
    }

    return count;
}

/**
 * Which located block a thread's pin goes on, or null (a comment on the
 * page, one whose words this layout leaves out, or whose block isn't on the
 * page). The deepest block holding all its units; failing that, the
 * deepest holding its first.
 */
export function pinKey(thread, map, located = null) {
    if (!thread || thread.kind === 'page' || thread.in_layout === false || thread.status === 'detached') return null;

    const units = thread.units ?? [];

    if (!units.length) return null;

    const byKey = Object.fromEntries(map.map((block) => [block.key, block]));
    const candidates = map.filter((block) => located === null || located.includes(block.key));
    const deepest = (blocks) => blocks.reduce((best, block) => (!best || depth(block, byKey) > depth(best, byKey) ? block : best), null);
    const covers = candidates.map((block) => ({ block, cover: coverOf(block.key, map) }));

    // The block it was made on, when it is this layout's and still holds the words.
    const made = thread.path ? covers.find(({ block, cover }) => block.path === thread.path && units.every((unit) => cover.includes(unit))) : null;

    if (made) return made.block.key;

    const all = deepest(covers.filter(({ cover }) => units.every((unit) => cover.includes(unit))).map(({ block }) => block));

    if (all) return all.key;

    return deepest(covers.filter(({ cover }) => cover.includes(units[0])).map(({ block }) => block))?.key ?? null;
}

/**
 * The pins for a render: one per thread not resolved whose words this
 * layout shows, numbered as the threads are. Each says where it goes.
 */
export function placePins(threads, map, located = null) {
    return (threads ?? [])
        .filter((thread) => thread.status !== 'resolved')
        .map((thread) => ({ number: thread.number, status: thread.status, state: thread.state, label: thread.label ?? '', quote: thread.kind === 'text' ? thread.quote : null, key: pinKey(thread, map, located) }))
        .filter((pin) => pin.key !== null);
}

/** The blocks to mark Changed: those holding a comment Ghostwriter changed, not put back or resolved. */
export function changedKeys(threads, map, located = null) {
    return [...new Set((threads ?? []).filter((thread) => thread.status === 'changed' && !thread.put_back_by).map((thread) => pinKey(thread, map, located)).filter(Boolean))];
}

/** Whitespace squeezed, for matching rendered text against a quote. */
export function squeeze(text) {
    return String(text ?? '').replace(/\s+/g, ' ');
}

/**
 * Some words picked on the page as a quote: the exact words (at most 300
 * characters, trimmed) and up to 32 characters either side, as core's
 * TextQuote keeps them. Null for nothing but whitespace.
 */
export function quoteOf(exact, before = '', after = '') {
    const words = squeeze(exact).trim();

    if (!words) return null;

    return {
        exact: words.slice(0, 300),
        prefix: squeeze(before).slice(-32),
        suffix: squeeze(after).slice(0, 32),
    };
}

/**
 * Where a quote is in some text, by the same loose rules the server uses
 * for whitespace: {start, end} offsets into `text`, or null. With context,
 * the occurrence whose prefix fits best wins.
 */
export function findQuote(text, quote) {
    const exact = squeeze(quote?.exact ?? quote ?? '').trim();

    if (!exact) return null;

    // Map each character of the squeezed text back to the original.
    const index = [];
    let squeezed = '';
    let space = false;

    for (let i = 0; i < text.length; i += 1) {
        const char = text[i];

        if (/\s/.test(char)) {
            if (space) continue;
            space = true;
            squeezed += ' ';
        } else {
            space = false;
            squeezed += char;
        }

        index.push(i);
    }

    const hits = [];
    let from = squeezed.indexOf(exact);

    while (from !== -1 && hits.length < 50) {
        hits.push(from);
        from = squeezed.indexOf(exact, from + 1);
    }

    if (!hits.length) return null;

    const prefix = squeeze(quote?.prefix ?? '').trim();
    const best = prefix ? hits.find((hit) => squeezed.slice(Math.max(0, hit - prefix.length - 2), hit).includes(prefix.slice(-12))) ?? hits[0] : hits[0];

    return { start: index[best], end: index[best + exact.length - 1] + 1 };
}

/**
 * What a run did, comparing the sent pins before and after it: which went
 * Changed or Replied, and which weren't applied (refused, skipped because
 * someone changed their block meanwhile, or failed), each with the reason
 * Ghostwriter gave.
 */
export function runOutcome(before, after) {
    const was = Object.fromEntries((before ?? []).map((pin) => [pin.number, pin.status]));
    const done = (after ?? []).filter((pin) => was[pin.number] === 'sending' && pin.status !== 'sending');

    return {
        changed: done.filter((pin) => pin.status === 'changed').map((pin) => pin.number),
        replied: done.filter((pin) => pin.status === 'replied').map((pin) => pin.number),
        back: done.filter((pin) => ['refused', 'skipped', 'failed', 'detached'].includes(pin.status)).map((pin) => ({
            number: pin.number,
            reason: plain(pin.reply ?? ''),
            skipped: pin.status === 'skipped',
        })),
    };
}

/**
 * The editor's pins not sent yet, numbered after the comments already sent
 * (`next`), as pins: {number, status: 'pending', …}.
 */
export function pendingPins(pending, next = 1) {
    return (pending ?? []).map((pin, index) => ({
        ...pin,
        number: next + index,
        status: 'pending',
        state: 'Not sent',
        quote: pin.quote?.exact ?? null,
        in_layout: true,
    }));
}

/** What Apply sends for each pin not sent yet. */
export function toSend(pending) {
    return (pending ?? []).map((pin) => ({
        kind: pin.kind,
        units: pin.units ?? [],
        label: pin.label ?? null,
        path: pin.path ?? null,
        plan_path: pin.planPath ?? null,
        quote: pin.quote ?? null,
        body: pin.body,
    }));
}

/** Text from a note's HTML (escaped markdown), for announcements. */
export function plain(html) {
    return String(html ?? '')
        .replace(/<[^>]*>/g, '')
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&')
        .trim();
}
