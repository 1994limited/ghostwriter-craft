/**
 * The preview's frame, as tall as its page. The frame never scrolls: the
 * draft's column scrolls, through the layouts, the hint and the whole page,
 * so the pane has one scroller.
 *
 * The page is laid out as if the window were as tall as the pane
 * (`viewport`, in the frame's CSS pixels), then the frame is made as tall as
 * the document. Before that, what the page sizes from the window's height is
 * pinned in px at its pane-height size, so the taller frame doesn't make it
 * taller too: heights in vh (svh, dvh, lvh, vmin, vmax) from the page's CSS
 * or inline styles, height: 100% chains that start at <html>, and heights a
 * script set to the window's height.
 *
 * The document is always measured with the frame back at the pane's height,
 * so the frame's own height never feeds back into what it measures: a
 * re-measure at the same height is a no-op, and nothing can grow in a loop.
 * The frame's own resize is never a reason to measure again; the page's
 * content changing is (its size, new nodes, images and fonts loading).
 * MAX_HEIGHT is the last stop, and a page that grows by the same amount
 * each time the frame does (a script following the window) stops growing.
 *
 * Shared by the Craft and Statamic addons (Craft keeps a copy beside
 * preview.js).
 */

/** The tallest the frame gets, as a safety stop. */
export const MAX_HEIGHT = 30000;

/** How much taller the frame is laid out, briefly, to see what follows the window's height. */
const PROBE = 240;

/**
 * A page that grows by the same amount each time the frame does is following
 * the frame (a script sizing something to the window): after this many
 * such growths in a row, close together, the frame stops growing.
 */
const GROWTH_LIMIT = 6;
const GROWTH_WINDOW = 4000;

const UNITS = /\d\s*(?:[sdl]?v(?:h|b|min|max))\b/i;
const PIXELS = /^\s*(\d+(?:\.\d+)?)px\s*$/i;

// Each height property, as the property pinned.
const PROPERTIES = { height: 'height', 'min-height': 'min-height', 'max-height': 'max-height', 'block-size': 'height', 'min-block-size': 'min-height', 'max-block-size': 'max-height' };
const COMPUTED = { height: 'height', 'min-height': 'minHeight', 'max-height': 'maxHeight' };

/** Whether a CSS value sizes from the window's height (`100vh`, `calc(100dvh - 4rem)`, `50vmin`). */
export function usesViewport(value) {
    return UNITS.test(String(value ?? ''));
}

/** The frame's height for a document this tall: never under the pane, never over the cap. */
export function frameHeight(documentHeight, viewport, max = MAX_HEIGHT) {
    return Math.min(max, Math.max(Math.ceil(viewport || 0), Math.ceil(documentHeight || 0)));
}

/**
 * Which of an element's height properties followed the window, given its
 * computed values with the frame at the pane's height (`at`) and taller
 * (`moved`): min- and max-height first, since height follows them; height
 * on its own only when neither moved, and never on a scrolling box (its
 * content scrolls inside it however tall it is).
 */
export function followed(at, moved, { scrolls = false } = {}) {
    const changed = (property) => at[property] !== moved[property];
    const limits = ['min-height', 'max-height'].filter(changed);

    if (limits.length) return limits;

    return changed('height') && !scrolls ? ['height'] : [];
}

/** A pixel value within a pixel of the pane's height: a script that set a height to the window's. */
export function isWindowHeight(value, viewport) {
    const match = PIXELS.exec(String(value ?? ''));

    return Boolean(match) && Math.abs(parseFloat(match[1]) - viewport) <= 1;
}

/**
 * The run of growths so far, with this one: kept while each is by the same
 * amount (to a pixel) and soon after the last; otherwise a new run.
 */
export function following(run, growth, span = GROWTH_WINDOW) {
    const last = run[run.length - 1];

    return last && Math.abs(last.by - growth.by) <= 1 && growth.at - last.at < span ? [...run, growth] : [growth];
}

/**
 * Sizes `frame` (loaded, same-origin) to its page.
 *
 * @param {HTMLIFrameElement} frame
 * @param {object} options
 * @param {number} options.viewport The pane's height, in the frame's CSS px.
 * @param {(height: number) => void} [options.onHeight] The frame's new height.
 * @param {number} [options.max]
 * @returns {{height: number, measure: () => void, setViewport: (viewport: number) => void, stop: () => void} | null}
 */
export function fitFrame(frame, { viewport, onHeight = () => {}, max = MAX_HEIGHT } = {}) {
    let doc;
    let win;

    try {
        doc = frame.contentDocument;
        win = doc?.defaultView;
    } catch {
        return null;
    }

    if (!doc?.documentElement || !win) return null;

    let pane = Math.max(1, Math.round(viewport || 0));
    let height = 0;
    let stopped = false;
    let queued = false;
    let style = null;
    let pinned = [];
    let growths = [];
    let frozen = false;
    const cleanups = [];

    frame.setAttribute('scrolling', 'no');
    frame.style.overflow = 'hidden';

    const setFrame = (value) => {
        frame.style.height = `${value}px`;
    };

    // Height properties in viewport units, in the page's CSS (where it can be read) and inline.
    const fromSheets = () => {
        const found = new Set();
        const walk = (rules) => {
            for (const rule of rules ?? []) {
                if (rule.selectorText !== undefined && rule.style) {
                    if (Object.keys(PROPERTIES).some((property) => usesViewport(rule.style.getPropertyValue(property)))) {
                        try {
                            doc.querySelectorAll(rule.selectorText).forEach((element) => found.add(element));
                        } catch {}
                    }
                } else if (rule.cssRules) {
                    walk(rule.cssRules);
                }
            }
        };

        for (const sheet of doc.styleSheets ?? []) {
            let rules = null;

            try {
                rules = sheet.cssRules;
            } catch {}

            walk(rules);
        }

        doc.querySelectorAll('[style]').forEach((element) => {
            if (Object.keys(PROPERTIES).some((property) => usesViewport(element.style?.getPropertyValue(property)))) found.add(element);
        });

        return found;
    };

    const read = (elements) => elements.map((element) => {
        const computed = win.getComputedStyle(element);

        return Object.fromEntries(Object.entries(COMPUTED).map(([property, key]) => [property, computed[key]]));
    });

    const ours = (element) => element.closest?.('[data-ghostwriter-overlay], ghostwriter-preview-overlay') || element.tagName === 'GHOSTWRITER-PREVIEW-OVERLAY';

    // Each element's pinned properties, as one style sheet the page's own can't outrank.
    const pin = () => {
        setFrame(pane);

        const pins = new Map();
        const add = (element, property, value) => {
            if (!value || value === 'auto' || value === 'none') return;
            pins.set(element, { ...(pins.get(element) ?? {}), [property]: value });
        };

        // A script's height: an inline px value equal to the window's height.
        doc.querySelectorAll('[style]').forEach((element) => {
            if (ours(element)) return;

            ['height', 'min-height'].forEach((property) => {
                const value = element.style?.getPropertyValue(property);

                if (isWindowHeight(value, pane)) add(element, property, `${pane}px`);
            });
        });

        // The page's vh, then whatever is exactly the window's height (height: 100% from <html>).
        const sources = [...fromSheets()].filter((element) => !ours(element));
        const full = [...doc.querySelectorAll('*')].filter((element) => element.offsetHeight !== undefined && !ours(element) && !sources.includes(element) && Math.abs(element.offsetHeight - pane) <= 1);

        [sources, full].forEach((elements) => {
            if (!elements.length) return;

            const at = read(elements);
            setFrame(pane + PROBE);
            const moved = read(elements);
            setFrame(pane);

            elements.forEach((element, index) => {
                const overflow = win.getComputedStyle(element).overflowY;
                const scrolls = element !== doc.documentElement && element !== doc.body && !['visible', 'clip'].includes(overflow);

                followed(at[index], moved[index], { scrolls }).forEach((property) => add(element, property, at[index][property]));
            });

            // Pinned now, so the next group is read with these in place.
            write(pins);
        });

        write(pins);
    };

    const write = (pins) => {
        pinned.forEach((element) => element.removeAttribute('data-gw-fit'));
        pinned = [...pins.keys()];

        const rules = pinned.map((element, index) => {
            element.setAttribute('data-gw-fit', String(index));

            return `[data-gw-fit="${index}"]{${Object.entries(pins.get(element)).map(([property, value]) => `${property}:${value} !important`).join(';')}}`;
        });

        if (!style) {
            style = doc.createElement('style');
            style.setAttribute('data-ghostwriter-fit', '');
            (doc.head ?? doc.documentElement).appendChild(style);
        }

        style.textContent = rules.join('\n');
    };

    const unpin = () => {
        pinned.forEach((element) => element.removeAttribute('data-gw-fit'));
        pinned = [];
        style?.remove();
        style = null;
    };

    // The document's height, laid out at the pane's height.
    const documentHeight = () => {
        const was = frame.style.height;
        setFrame(pane);
        const value = Math.max(doc.documentElement.scrollHeight || 0, doc.body?.scrollHeight || 0);
        frame.style.height = was;

        return value;
    };

    // A hidden frame (another tab of the draft) has no layout to measure.
    const hidden = () => typeof frame.getClientRects === 'function' && frame.getClientRects().length === 0;

    const measure = () => {
        if (stopped || hidden()) return;

        const next = frameHeight(documentHeight(), pane, max);

        if (next === height) return;

        if (next > height && height > 0) {
            if (frozen) return;

            growths = following(growths, { at: Date.now(), by: next - height });

            if (growths.length >= GROWTH_LIMIT) {
                console.warn('Ghostwriter: the preview kept growing with its frame; it stops here.');
                frozen = true;

                return;
            }
        } else {
            growths = [];
        }

        height = next;
        setFrame(height);
        onHeight(height);
    };

    // Once per frame; a timer too, for a tab in the background (no frames there).
    const later = () => {
        if (queued || stopped) return;

        queued = true;
        const run = () => {
            if (!queued) return;

            queued = false;
            measure();
        };

        win.requestAnimationFrame?.(run);
        setTimeout(run, 120);
    };

    const listen = (target, type, handler, options) => {
        if (!target?.addEventListener) return;

        target.addEventListener(type, handler, options);
        cleanups.push(() => target.removeEventListener(type, handler, options));
    };

    pin();
    measure();

    // The page's content changing: its size, its nodes, images and fonts loading.
    if (win.ResizeObserver) {
        const observer = new win.ResizeObserver(later);
        if (doc.body) observer.observe(doc.body);
        cleanups.push(() => observer.disconnect());
    }

    if (win.MutationObserver && doc.body) {
        const mutations = new win.MutationObserver(later);
        mutations.observe(doc.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'open', 'src', 'srcset'] });
        cleanups.push(() => mutations.disconnect());
    }

    listen(doc, 'load', later, true);
    listen(doc, 'transitionend', later, true);
    listen(doc.fonts, 'loadingdone', later);
    doc.fonts?.ready?.then(later).catch(() => {});

    return {
        get height() {
            return height;
        },
        // Measure now (the frame shown again).
        measure,
        // The pane's height changed: pin again at the new one, and measure.
        setViewport(value) {
            const next = Math.max(1, Math.round(value || 0));

            if (stopped || Math.abs(next - pane) < 1) return;

            pane = next;
            unpin();
            frozen = false;
            growths = [];
            pin();
            height = 0;
            measure();
        },
        stop() {
            stopped = true;
            cleanups.forEach((cleanup) => cleanup());
        },
    };
}
