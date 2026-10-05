/**
 * Suggest edits on an existing entry's edit screen (the suggest-edits
 * design, decisions 1–17): a review of the page, asked for from the menu
 * beside "Edit with Ghostwriter", stepped through in Finish this page's
 * guide, in indigo.
 *
 *   the menu item   "Suggest edits", then a confirm with what it costs
 *   the count       on the menu beside Edit with Ghostwriter (finish.js's
 *                   HeaderMenu paints it from `ghostwriter:counts`)
 *   the guide       Finish's (Ghostwriter.Finish): the dock, the flying
 *                   mark, the highlights and the keyboard, with a review's
 *                   suggestions as its steps
 *
 * Every change goes into the form, never a save: CKEditor through its
 * model (links and bold kept), plain inputs by their value with `input`
 * and `change`, so Craft autosaves the provisional draft as it does for
 * any typing. The one exception is alt text, saved to the asset after its
 * own confirm. Decisions are shared through the server and kept as the
 * page's history.
 *
 * The parts that need no page (core's QuoteFinder, ported; the steps; the
 * markdown) are kept apart so they can be tested (tests/js).
 */
(function () {
    window.Ghostwriter = window.Ghostwriter || {};

    const t = (message, params) => Craft.t('ghostwriter', message, params);
    const esc = (text) => (Ghostwriter.escape ? Ghostwriter.escape(text) : String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
    const ENTRY = 'craft\\elements\\Entry';
    const POLL = 3000;

    /* ----------------------------------------------------------------------
     * Core's QuoteFinder, ported: finds a suggestion's quote in a field's
     * current text the way the server found it, so a range still finds its
     * words after the text around it moves. Tested against core's own
     * cases (tests/js/quote-cases.json, kept identical to core's by a PHP
     * test). Offsets and lengths are in characters (code points), as core
     * counts them.
     * -------------------------------------------------------------------- */

    const QUOTES = { '‘': "'", '’': "'", '‚': "'", '‛': "'", '′': "'", '“': '"', '”': '"', '„': '"', '‟': '"', '″': '"' };
    const DASHES = new Set(['‐', '‑', '‒', '–', '—', '―', '−']);
    const SPACE = new RegExp('^[\\s' + String.fromCodePoint(0xa0, 0x1680) + String.fromCodePoint(0x2000) + '-' + String.fromCodePoint(0x200a, 0x2028, 0x2029, 0x202f, 0x205f, 0x3000) + ']$', 'u');
    const INVISIBLE = /^[\u{E0000}-\u{E007F}\u{00AD}\u{200B}-\u{200D}\u{2060}\u{FEFF}]$/u;
    const FUZZY = 0.9;
    const FUZZY_MIN = 16;

    function skipLineStart(chars, start, skip) {
        const count = chars.length;
        let i = start;

        for (;;) {
            let j = i;

            while (j < count && (chars[j] === ' ' || chars[j] === '\t')) j++;

            let k = j;

            if (k < count && chars[k] === '#') {
                while (k < count && chars[k] === '#') k++;
            } else if (k < count && chars[k] === '>') {
                k++;
            } else if (k < count && ['-', '*', '+'].includes(chars[k]) && (chars[k + 1] ?? '') === ' ') {
                k++;
            } else {
                while (k < count && /^[0-9]$/.test(chars[k])) k++;

                if (!(k > j && k < count && (chars[k] === '.' || chars[k] === ')') && (chars[k + 1] ?? '') === ' ')) return i;

                k++;
            }

            if (k < count && chars[k] !== ' ' && chars[k] !== '\t' && chars[k] !== '\n' && chars[k - 1] !== '>') return i;

            for (let m = i; m < k; m++) skip.add(m);

            while (k < count && (chars[k] === ' ' || chars[k] === '\t')) {
                skip.add(k);
                k++;
            }

            i = k;
        }
    }

    function markdownSyntax(chars) {
        const skip = new Set();
        const count = chars.length;
        let lineStart = true;

        for (let i = 0; i < count; i++) {
            let char = chars[i];

            if (lineStart) {
                i = skipLineStart(chars, i, skip);
                lineStart = false;

                if (i >= count) break;

                char = chars[i];
            }

            if (char === '\n') {
                lineStart = true;
                continue;
            }

            if (char === '\\' && i + 1 < count && /^[\\`*_{}[\]()#+\-.!>]$/.test(chars[i + 1])) {
                skip.add(i);
                i++;
                continue;
            }

            if (char === '*' || char === '_' || char === '`' || char === '[') {
                skip.add(i);
                continue;
            }

            if (char === ']' && (chars[i + 1] ?? '') === '(') {
                let close = null;

                for (let j = i + 2; j < count && chars[j] !== '\n'; j++) {
                    if (chars[j] === ')') {
                        close = j;
                        break;
                    }
                }

                if (close !== null) {
                    for (let j = i; j <= close; j++) skip.add(j);
                    i = close;
                    continue;
                }
            }

            if (char === ']') skip.add(i);
        }

        return skip;
    }

    function fold(char) {
        if (QUOTES[char]) return [QUOTES[char]];
        if (DASHES.has(char)) return ['-'];

        return char === '…' ? ['.', '.', '.'] : [char];
    }

    /** Text as QuoteFinder compares it: { chars, map, length }; map[i] is where chars[i] came from. */
    function normalise(text, markdown = false) {
        const chars = Array.from(String(text ?? ''));
        const skip = markdown ? markdownSyntax(chars) : new Set();
        const out = [];
        const map = [];
        let space = false;

        chars.forEach((char, i) => {
            if (skip.has(i) || INVISIBLE.test(char)) return;

            if (SPACE.test(char)) {
                space = out.length > 0;

                return;
            }

            if (space) {
                out.push(' ');
                map.push(i - 1);
                space = false;
            }

            fold(char).forEach((folded) => {
                out.push(folded);
                map.push(i);
            });
        });

        return { chars: out, text: out.join(''), map, length: chars.length };
    }

    function original(norm, offset, length) {
        if (norm.map.length === 0 || length <= 0) return [norm.map[offset] ?? norm.length, 0];

        const start = norm.map[offset] ?? norm.length;
        const end = (norm.map[Math.min(offset + length, norm.map.length) - 1] ?? norm.length - 1) + 1;

        return [start, Math.max(0, end - start)];
    }

    function exact(hay, needle) {
        const found = [];

        outer: for (let i = 0; i + needle.length <= hay.length; i++) {
            for (let j = 0; j < needle.length; j++) {
                if (hay[i + j] !== needle[j]) continue outer;
            }

            found.push(i);
        }

        return found;
    }

    const commonSuffix = (a, b) => {
        const x = Array.from(a);
        const y = Array.from(b);
        let n = 0;

        while (n < x.length && n < y.length && x[x.length - 1 - n] === y[y.length - 1 - n]) n++;

        return n;
    };

    const commonPrefix = (a, b) => {
        const x = Array.from(a);
        const y = Array.from(b);
        let n = 0;

        while (n < x.length && n < y.length && x[n] === y[n]) n++;

        return n;
    };

    function pick(found, hay, length, quote, markdown, occurrence) {
        if (found.length === 1) return 0;

        const prefix = normalise(quote.prefix ?? '', markdown).chars;
        const suffix = normalise(quote.suffix ?? '', markdown).chars;

        if (prefix.length || suffix.length) {
            const scores = found.map((at) => {
                const before = hay.slice(Math.max(0, at - prefix.length - 1), at).join('');
                const after = hay.slice(at + length, at + length + suffix.length + 1).join('');

                return commonSuffix(before.trim(), prefix.join('').trim()) + commonPrefix(after.trim(), suffix.join('').trim());
            });
            const order = scores.map((score, i) => [score, i]).sort((a, b) => b[0] - a[0] || a[1] - b[1]);

            if (order[0][0] > 0 && (order[1]?.[0] ?? -1) < order[0][0]) return order[0][1];
        }

        return occurrence !== null && occurrence !== undefined && found[occurrence] !== undefined ? occurrence : null;
    }

    function trigrams(text) {
        const chars = Array.from(` ${text.toLowerCase()} `);
        const grams = new Map();

        for (let i = 0; i + 2 < chars.length; i++) {
            const gram = chars[i] + chars[i + 1] + chars[i + 2];
            grams.set(gram, (grams.get(gram) ?? 0) + 1);
        }

        return grams;
    }

    function dice(a, b) {
        let shared = 0;
        let total = 0;

        a.forEach((count, gram) => {
            shared += Math.min(count, b.get(gram) ?? 0);
            total += count;
        });
        b.forEach((count) => (total += count));

        return total === 0 ? 0 : (2 * shared) / total;
    }

    function fuzzy(norm, needle) {
        const length = needle.length;

        if (length < FUZZY_MIN) return null;

        const target = trigrams(needle.join(''));
        const text = norm.chars;
        const spans = [];
        let i = 0;

        while (i < text.length) {
            if (text[i] === ' ') {
                i++;
                continue;
            }

            let j = i;
            while (j < text.length && text[j] !== ' ') j++;

            const word = text.slice(i, j).join('');
            const bare = Array.from(word.replace(/[\p{P}\p{S}]+$/u, '')).length;
            spans.push([i, [...new Set([j, i + Math.max(1, bare)])]]);
            i = j;
        }

        const candidates = [];

        for (let s = 0; s < spans.length; s++) {
            const start = spans[s][0];

            for (let e = s; e < spans.length; e++) {
                spans[e][1].forEach((end) => {
                    const size = end - start;

                    if (size < length * 0.75 || size > length * 1.25) return;

                    const score = dice(target, trigrams(text.slice(start, start + size).join('')));

                    if (score >= FUZZY) candidates.push([start, size, score]);
                });

                if (spans[e][1][0] - start > length * 1.25) break;
            }
        }

        if (!candidates.length) return null;

        candidates.sort((a, b) => a[0] - b[0]);
        const places = [];

        candidates.forEach((candidate) => {
            const last = places[places.length - 1];

            if (last && candidate[0] < last[0] + last[1]) {
                if (candidate[2] > last[2]) places[places.length - 1] = candidate;

                return;
            }

            places.push(candidate);
        });

        if (places.length !== 1) return null;

        const [offset, size] = original(norm, places[0][0], places[0][1]);

        return { offset, length: size, occurrence: 0, fuzzy: true };
    }

    /**
     * Where a quote ({exact, prefix, suffix}) is in some text: { offset,
     * length, occurrence, fuzzy } in characters, or null when it isn't there
     * or can't be told apart.
     */
    function findQuote(quote, text, occurrence = null, markdown = false) {
        const hay = normalise(text, markdown);
        const needle = normalise(quote?.exact ?? '', markdown).chars;

        if (!needle.length || !hay.chars.length) return null;

        const found = exact(hay.chars, needle);

        if (found.length) {
            const index = pick(found, hay.chars, needle.length, quote, markdown, occurrence);

            if (index === null) return null;

            const [offset, length] = original(hay, found[index], needle.length);

            return { offset, length, occurrence: index, fuzzy: false };
        }

        return fuzzy(hay, needle);
    }

    /** A character offset (code points) as a JavaScript string index. */
    function utf16(text, offset) {
        return Array.from(String(text)).slice(0, offset).join('').length;
    }

    /** The same match in UTF-16 indices: [start, end). */
    function rangeIn(text, match) {
        if (!match) return null;

        const start = utf16(text, match.offset);

        return [start, start + Array.from(String(text)).slice(match.offset, match.offset + match.length).join('').length];
    }

    /* ----------------------------------------------------------------------
     * A replacement's inline markdown (text, **bold**, *italic*,
     * [links](to)), as the words alone or as pieces with attributes for
     * CKEditor, where a link to an entry is Craft's reference tag.
     * -------------------------------------------------------------------- */

    function tokens(markdown) {
        const source = String(markdown ?? '');
        const out = [];
        const pattern = /\*\*([^*]+)\*\*|__([^_]+)__|\*([^*]+)\*|_([^_]+)_|\[([^\]]+)\]\(([^)\s]+)\)/g;
        let last = 0;
        let match;

        while ((match = pattern.exec(source)) !== null) {
            if (match.index > last) out.push({ text: source.slice(last, match.index), bold: false, italic: false, href: null });

            if (match[1] ?? match[2]) out.push({ text: match[1] ?? match[2], bold: true, italic: false, href: null });
            else if (match[3] ?? match[4]) out.push({ text: match[3] ?? match[4], bold: false, italic: true, href: null });
            else out.push({ text: match[5], bold: false, italic: false, href: match[6] });

            last = match.index + match[0].length;
        }

        if (last < source.length) out.push({ text: source.slice(last), bold: false, italic: false, href: null });

        return out.filter((token) => token.text !== '');
    }

    /** The words alone, for a plain field. */
    function plain(markdown) {
        return tokens(markdown).map((token) => token.text).join('');
    }

    /**
     * A link as CKEditor holds it in Craft: a reference tag
     * (`{entry:41@1:url||https://…}`) becomes `https://…#entry:41@1:url`.
     */
    function editorHref(value, siteId = 1) {
        const match = /^\{(entry|asset|category):(\d+)(?:@(\d+))?:url(?:\|\|(.*))?\}$/.exec(String(value ?? ''));

        return match ? `${match[4] ?? ''}#${match[1]}:${match[2]}@${match[3] ?? siteId}:url` : String(value ?? '');
    }

    /**
     * Pieces of text with CKEditor's attributes: each token's own, and
     * those the replaced words all had (a quote wholly in bold stays
     * bold; a link stays unless the token has its own).
     */
    function pieces(markdown, inherit = {}, siteId = 1) {
        return tokens(markdown).map((token) => {
            const attributes = { ...inherit };

            if (token.href) attributes.linkHref = editorHref(token.href, siteId);
            if (token.bold) attributes.bold = true;
            if (token.italic) attributes.italic = true;

            return { text: token.text, attributes };
        });
    }

    /* ----------------------------------------------------------------------
     * The guide's state, kept apart from the page so it can be tested: the
     * steps (one per suggestion, in form order, stale ones last), the
     * category filters, the counts every part of the guide shows, the field
     * tags, and what Accept all wording fixes takes.
     * -------------------------------------------------------------------- */

    const WORDING = new Set(['voice', 'clarity', 'seo']);

    const isOpen = (step) => step.state === 'open' && !step.stale;

    /**
     * The steps for a new answer from the server, keeping what this page
     * view knows that the server doesn't yet (accepted here, stale in the
     * form).
     */
    function stepsFrom(suggestions, local = new Map()) {
        const steps = suggestions.map((s) => {
            const mine = local.get(s.id);

            return { ...s, state: mine?.state ?? s.state, stale: mine?.stale ?? s.state === 'stale', text: mine?.text ?? null };
        });

        return [...steps.filter((step) => !step.stale), ...steps.filter((step) => step.stale)];
    }

    function visible(steps, filter = 'all') {
        return steps.map((step, i) => i).filter((i) => filter === 'all' || steps[i].category === filter);
    }

    /** The next open step after `from` in the filter, coming round to the start; steps.length when none is open. */
    function nextOpen(steps, from, filter = 'all') {
        const shown = visible(steps, filter);
        const after = shown.find((i) => i > from && isOpen(steps[i]));

        if (after !== undefined) return after;

        const before = shown.find((i) => isOpen(steps[i]));

        return before !== undefined ? before : steps.length;
    }

    function previous(steps, from, filter = 'all') {
        const shown = visible(steps, filter).filter((i) => i < from);

        return shown.length ? shown[shown.length - 1] : from;
    }

    function following(steps, from, filter = 'all') {
        const next = visible(steps, filter).find((i) => i > from);

        return next === undefined ? steps.length : next;
    }

    /** All, then each category with its open count, in the order the steps first name them. */
    function filters(steps, chosen = 'all') {
        const open = steps.filter(isOpen);
        const list = [{ key: 'all', label: null, count: open.length }];
        const seen = new Set();

        steps.forEach((step) => {
            if (seen.has(step.category)) return;

            seen.add(step.category);
            const count = open.filter((other) => other.category === step.category).length;

            if (count || chosen === step.category) list.push({ key: step.category, label: step.label, count });
        });

        return list;
    }

    function counts(steps) {
        return {
            open: steps.filter(isOpen).length,
            accepted: steps.filter((step) => step.state === 'accepted' || step.state === 'done').length,
            dismissed: steps.filter((step) => step.state === 'dismissed').length,
            confirmed: steps.filter((step) => step.state === 'confirmed').length,
            stale: steps.filter((step) => step.stale).length,
            total: steps.length,
        };
    }

    /** Each field's highlight and tag, by its place: current, open with its steps, or done. */
    function fieldStates(steps, index) {
        const fields = new Map();

        steps.forEach((step, i) => {
            if (step.stale) return;

            const field = fields.get(step.dotted) ?? { state: 'done', steps: [], step: null, first: i };

            if (isOpen(step)) {
                field.steps.push(i);
                if (field.state === 'done') field.state = 'open';
            }

            if (i === index && isOpen(step)) {
                field.state = 'current';
                field.step = step;
            }

            field.step ??= step;
            fields.set(step.dotted, field);
        });

        return fields;
    }

    /** A field's tag: "2 · Voice" when current, "3–4" or "3" when open, "✓" when done. */
    function tagText(field, index, steps) {
        if (field.state === 'done') return '✓';
        if (field.state === 'current') return `${index + 1} · ${steps[index].label}`;

        const numbers = field.steps.map((i) => i + 1);

        return numbers.length > 1 ? `${numbers[0]}–${numbers[numbers.length - 1]}` : String(numbers[0]);
    }

    /** Open Voice, Clarity and SEO suggestions with words, in the filter. Never facts, links, alt text, dates or duplicates. */
    function wordingFixes(steps, filter = 'all') {
        return visible(steps, filter).filter((i) => isOpen(steps[i]) && WORDING.has(steps[i].category) && typeof steps[i].replacement === 'string' && steps[i].scope !== 'asset' && steps[i].seo?.writable !== false);
    }

    /** The versions "Another version" cycles through: the replacement, its alternatives, then any written since. */
    function versionsOf(step) {
        return [step.replacement, ...(step.alternatives ?? []), ...(step.versions ?? [])].filter((v, i, all) => typeof v === 'string' && v !== '' && all.indexOf(v) === i);
    }

    /**
     * A fact's answer put into its template, as core's FactCheck::fill():
     * a number or an amount must be one, and money keeps the template's
     * currency symbol. Throws Error('number-only') or Error('date-only').
     */
    function fillFact(fact, answer) {
        const value = String(answer ?? '').trim();
        const kind = fact?.answer ?? 'text';
        const ok = {
            number: /^\d{1,3}(?:[,.  ]\d{3})*(?:[.,]\d+)?$|^\d+(?:[.,]\d+)?$/u.test(value),
            money: /^[£$€]?\s?\d[\d,. ]*(?:k|m)?\s?€?$/iu.test(value),
            date: /\d/.test(value),
            text: value !== '',
        }[kind] ?? value !== '';

        if (!ok || value === '') throw new Error(kind === 'date' ? 'date-only' : 'number-only');

        const filled = kind === 'money' && /[£$€]/u.test(fact.template) ? value.replace(/[£$€]\s?/gu, '').trim() : value;

        return String(fact.template).replace('{answer}', filled);
    }

    /**
     * A CKEditor nested entry's element in the editor's model, by the
     * entry ID it holds, from a walk of the model
     * (`model.createRangeIn(root)`); null when it isn't there.
     */
    function entryModel(walk, id) {
        for (const value of walk ?? []) {
            const item = value?.item ?? value;

            if (item?.name === 'craftEntryModel' && String(item.getAttribute?.('entryId')) === String(id)) return item;
        }

        return null;
    }

    Ghostwriter.SuggestHelpers = {
        normalise, findQuote, utf16, rangeIn, FUZZY, FUZZY_MIN,
        tokens, plain, editorHref, pieces,
        WORDING, isOpen, stepsFrom, visible, nextOpen, previous, following, filters, counts, fieldStates, tagText, wordingFixes, versionsOf, fillFact,
        entryModel,
    };

    if (!window.Garnish || !Ghostwriter.Finish) return;

    const H = Ghostwriter.SuggestHelpers;

    /* ----------------------------------------------------------------------
     * The guide
     * -------------------------------------------------------------------- */

    Ghostwriter.Suggest = Ghostwriter.Finish.extend({
        // The current suggestion's words, as CKEditor's marker draws them: where the mark points.
        inlineSelector: '.gw-suggest-mark--current',

        init(config) {
            this.config = config;
            this.strings = {};
            Ghostwriter.suggest = this;
            Ghostwriter.guides = Ghostwriter.guides ?? new Set();
            Ghostwriter.guides.add(this);

            this.steps = [];
            this.index = 0;
            this.local = new Map();
            this.filter = 'all';
            this.data = { suggestions: [], review: null, running: false };
            this.versionAt = new Map();
            this.undoAll = null;
            this.last = null;
            this.status = 'idle';
            this.pending = [];
            this.shown = false;
            this.minimised = true;
            this.done = false;
            this.editors = new Map();
            this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.phone = window.matchMedia('(max-width: 639px)').matches;

            $(() => this.start());
        },

        start() {
            this.build();

            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
            const phone = window.matchMedia('(max-width: 639px)');
            const onReduce = () => {
                this.reduced = reduce.matches;
                this.$root.toggleClass('gw-finish--still', this.reduced);
                this.placeFlyer();
            };
            const onPhone = () => {
                this.phone = phone.matches;
                this.$root.toggleClass('gw-finish--phone', this.phone);
                this.placeFlyer();
            };

            reduce.addEventListener?.('change', onReduce);
            phone.addEventListener?.('change', onPhone);
            onReduce();
            onPhone();

            // "Suggest edits" in the menu beside Edit with Ghostwriter: its confirm.
            document.addEventListener('click', (event) => {
                if (event.target.closest?.('[data-ghostwriter-suggest]')) {
                    event.preventDefault();
                    this.ask();
                }
            });
            this.addListener(Garnish.$doc, 'keydown', 'keys');
            this.addListener(Garnish.$win, 'resize', () => this.placeFlyer());
            document.addEventListener('scroll', () => this.placeFlyer(), true);
            document.addEventListener('ghostwriter:suggest-open', () => this.ask());
            // The header's menu: show the review (or the confirm, before there is one).
            document.addEventListener('ghostwriter:suggest-show', () => (this.steps.length || this.status === 'running' ? this.openAt(this.steps.length ? this.nextOpen(-1) : 0) : this.ask()));

            // The form changed: each suggestion's words are looked for again.
            const form = Craft.cp?.$primaryForm?.[0];

            if (form) {
                form.addEventListener('input', () => this.later(), true);
                form.addEventListener('change', () => this.later(), true);
                new ResizeObserver(() => this.placeFlyer()).observe(form);
            }

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && this.polling) this.load();
            });

            this.load({ initial: true });
        },

        /** Look again at each suggestion's words, a moment after typing. */
        later(wait = 400) {
            clearTimeout(this.typing);
            this.typing = setTimeout(() => this.recheck(), wait);
        },

        /* ------------------------------------------------------------------
         * The server
         * ------------------------------------------------------------------ */

        params() {
            return { elementId: this.elementId(), siteId: this.config.siteId };
        },

        async post(action, data = {}) {
            try {
                const response = await Craft.sendActionRequest('POST', `ghostwriter/suggest/${action}`, { data: { ...this.params(), ...data } });

                return response.data;
            } catch (error) {
                throw new Error(error?.response?.data?.message ?? t('Something went wrong.'));
            }
        },

        async load({ initial = false, open = false } = {}) {
            clearTimeout(this.timer);

            let data;

            try {
                data = await this.post('guide');
            } catch (error) {
                if (!initial) this.flash(error.message);

                return;
            }

            const review = data.review;
            const wasRunning = this.data.running;

            if (initial) {
                const asked = this.config.asked;

                // `?ghostwriter=suggest` is for this visit only.
                if (asked) this.forget();
                const usable = review && review.status === 'ready' && review.fresh && data.suggestions.some((s) => s.state === 'open');

                if (asked) {
                    if (usable || data.running) this.receive(data, { open: true });
                    else {
                        this.receive(data);
                        this.ask(data);
                    }
                } else if (data.running || (review && review.status === 'ready' && data.suggestions.some((s) => s.state === 'open'))) {
                    // A stored review: its count on the menu, the guide minimised (it wasn't just asked for).
                    this.receive(data, { show: true });
                } else {
                    this.receive(data);
                }
            } else {
                this.receive(data, { open: open || (wasRunning && !data.running && !this.minimised) });
            }

            // Decisions made while it ran go in now it's finished.
            if (!data.running && review?.status === 'ready' && this.pending.length) {
                this.decide(this.pending.splice(0));
            }

            this.polling = data.running;

            if (data.running) {
                this.timer = setTimeout(() => (document.visibilityState === 'visible' ? this.load() : null), POLL);
            }
        },

        forget() {
            try {
                const url = new URL(window.location.href);

                url.searchParams.delete('ghostwriter');
                window.history.replaceState(window.history.state, '', url);
            } catch (error) {
                // The address just keeps it.
            }
        },

        /**
         * A new answer from the server. `open` brings the guide out (it was
         * just asked for); a stored review shows only its count on the menu.
         */
        receive(data, { open = false, show = false } = {}) {
            const before = this.steps[this.index]?.id;
            const wasRunning = this.data?.running;

            this.data = data;
            this.status = data.running ? 'running' : data.review?.status === 'failed' ? 'failed' : data.review ? 'ready' : 'idle';

            data.suggestions.forEach((s) => {
                const mine = this.local.get(s.id);

                if (mine && !mine.pending && s.state === mine.state) mine.synced = true;
            });

            this.steps = H.stepsFrom(data.suggestions, this.local);
            this.recheck(false);

            const same = this.steps.findIndex((step) => step.id === before);
            this.index = same >= 0 ? same : this.nextOpen(-1);

            if (show || open || data.running) this.shown = true;

            if (wasRunning && !data.running) {
                const left = H.counts(this.steps).open;

                this.announce(data.review?.status === 'failed'
                    ? t('The review stopped before it finished.')
                    : left ? t('{count} suggestions ready.', { count: left }) : t('Nothing to suggest. The page reads well against your guide.'));
            }

            if (open) {
                this.index = this.nextOpen(-1);
                this.restore();

                return;
            }

            this.paint();
        },

        /**
         * The form changed: each open suggestion's words are looked for
         * again. Found, it stays; the new words there instead, it's
         * accepted; neither, it's stale.
         */
        recheck(repaint = true) {
            this.steps.forEach((step) => {
                if (step.scope !== 'range' || !step.quote || ['dismissed', 'confirmed', 'done'].includes(step.state)) return;

                const mine = this.local.get(step.id);

                if (mine?.state === 'accepted' && mine.change) return;

                const here = this.present(step);

                // Accepted in an earlier visit, but never saved: the words
                // aren't in the page, so it's open again.
                if (step.state === 'accepted' && !mine && here && !H.versionsOf(step).some((words) => this.applied(step, words))) {
                    step.state = 'open';
                    step.unsaved = true;

                    return;
                }

                if (here) {
                    step.stale = step.state === 'stale';
                } else if (H.versionsOf(step).some((words) => this.applied(step, words))) {
                    step.state = 'accepted';
                    step.stale = false;
                } else {
                    step.stale = true;
                }
            });

            if (repaint) this.paint();
        },

        decide(list) {
            const review = this.data.review;

            if (!review || review.status !== 'ready') {
                if (review && this.data.running) this.pending.push(...list);

                return;
            }

            this.post('decide', { reviewId: review.id, decisions: list }).then(() => list.forEach((d) => {
                const mine = this.local.get(d.suggestion);

                if (mine) mine.pending = false;
            })).catch((error) => this.flash(error.message));
        },

        flash(text) {
            if (!text) return;

            this.announce(text);
            Craft.cp.displayError(text);
        },

        /* ------------------------------------------------------------------
         * The confirm: what a review reads and what it costs, before it runs
         * ------------------------------------------------------------------ */

        async ask(data = null) {
            let info = data;

            if (!info) {
                try {
                    info = await this.post('guide');
                } catch (error) {
                    this.flash(error.message);

                    return;
                }
            }

            const calls = info.calls ?? 1;
            const cost = calls > 1
                ? t('This page is long, so it’s read in {calls} parts, each reviewed and then double-checked: {total} calls to your AI provider.', { calls, total: calls * 2 })
                : t('Uses Ghostwriter twice: one call reviews the page, a second double-checks every suggestion before you see it.');
            const id = `gw-s-confirm-${Date.now()}`;
            const $modal = $(`
                <div class="modal fitted gw-s-modal" role="dialog" aria-modal="true" aria-labelledby="${id}" data-ghostwriter-suggest-confirm>
                    <div class="body">
                        <h2 id="${id}">${esc(t('Suggest edits'))}</h2>
                        <p>${esc(t('Ghostwriter reads this page against your voice guide and the rest of the site, and suggests small changes for you to accept or not. Each suggestion is checked in its paragraph before you see it.'))}</p>
                        <p>${esc(t('Nothing changes until you accept a suggestion, and nothing is saved until you save. Alt text is the one exception: it’s saved to the image, after you confirm.'))}</p>
                        ${info.configured ? `<p class="gw-s-cost">${esc(cost)}</p>` : `<p class="warning">${esc(t('Add an API key first: Suggest edits reads the page with your AI provider.'))}</p>`}
                        <p class="error hidden" role="alert"></p>
                    </div>
                    <div class="footer">
                        <div class="buttons right">
                            <button type="button" class="btn" data-cancel>${esc(t('Cancel'))}</button>
                            <button type="button" class="btn submit" data-start ${info.configured ? '' : 'disabled'}><span class="gw-mark" aria-hidden="true"></span>${esc(t('Suggest edits'))}</button>
                        </div>
                    </div>
                </div>`);

            const modal = new Garnish.Modal($modal, {
                onHide: () => setTimeout(() => modal.destroy?.(), 300),
            });
            const $start = $modal.find('[data-start]');

            $modal.find('[data-cancel]').on('click', () => modal.hide());
            $start.on('click', async () => {
                $start.addClass('loading').prop('disabled', true);

                try {
                    await this.begin();
                    modal.hide();
                } catch (error) {
                    $modal.find('.error').removeClass('hidden').text(error.message);
                    $start.removeClass('loading').prop('disabled', false);
                }
            });

            setTimeout(() => $start.trigger('focus'), 50);
        },

        async begin() {
            // The review reads the form as Craft has saved it: the
            // provisional draft, after any typing still to autosave.
            try {
                await this.editor()?.checkForm?.();
            } catch (error) {
                // Saved or not, the review reads what's there.
            }

            const data = await this.post('start');

            Craft.cp?.runQueue?.();
            this.local.clear();
            this.receive(data, { open: true });
            this.polling = true;
            this.timer = setTimeout(() => this.load(), POLL);
        },

        /* ------------------------------------------------------------------
         * Drawing
         * ------------------------------------------------------------------ */

        build() {
            const icon = this.config.icon ?? '';

            this.$root = $('<div class="gw-finish gw-finish--suggest gw-finish--minimised hidden" data-gw-suggest-guide/>').appendTo(Garnish.$bod);
            this.$live = $('<div class="visually-hidden" role="status" aria-live="polite"/>').appendTo(this.$root);

            this.$guide = $(`
                <section class="gw-finish-guide" role="region" aria-label="${esc(t('Suggested edits'))}" aria-describedby="gw-suggest-keys">
                    <div class="gw-finish-guide__handle" aria-hidden="true"></div>
                    <header class="gw-finish-guide__head">
                        <span class="gw-finish-guide__mark" aria-hidden="true">${icon}</span>
                        <h2 class="gw-finish-guide__title">${esc(t('Suggested edits'))}</h2>
                        <span class="gw-finish-guide__step light"></span>
                        <button type="button" class="gw-finish-guide__min" aria-label="${esc(t('Minimise'))}" title="${esc(t('Minimise'))}">&#8212;</button>
                    </header>
                    <div class="gw-s-status" hidden></div>
                    <div class="gw-s-filters" role="group" aria-label="${esc(t('Show suggestions'))}" hidden></div>
                    <div class="gw-finish-guide__bar" aria-hidden="true"></div>
                    <div class="gw-finish-guide__body"></div>
                    <footer class="gw-finish-guide__foot">
                        <button type="button" class="gw-finish-guide__nav" data-go="prev">${esc(t('← Back'))}</button>
                        <button type="button" class="gw-finish-guide__nav gw-s-all" hidden></button>
                        <button type="button" class="gw-finish-guide__nav" data-go="next">${esc(t('Next →'))}</button>
                    </footer>
                    <button type="button" class="gw-finish-guide__compact" aria-label="${esc(t('Show the step'))}"></button>
                    <p id="gw-suggest-keys" class="visually-hidden">${esc(t('Alt+Shift+N for the next step, Alt+Shift+P for the one before, Alt+Shift+G to open or minimise.'))}</p>
                </section>`).appendTo(this.$root);

            this.$dock = $(`
                <button type="button" class="gw-finish-dock" aria-keyshortcuts="Alt+Shift+G">
                    <span class="gw-finish-dock__mark" aria-hidden="true">${icon}</span>
                    <span class="gw-finish-dock__count" aria-hidden="true"></span>
                    <span class="gw-finish-dock__tip" aria-hidden="true"></span>
                </button>`).appendTo(this.$root);

            this.$flyer = $(`
                <div class="gw-finish-flyer gw-finish-flyer--hidden" aria-hidden="true">
                    <span class="gw-finish-flyer__trail"></span>
                    <span class="gw-finish-flyer__tilt"><span class="gw-finish-flyer__bob">${icon}</span></span>
                    <span class="gw-finish-flyer__say"></span>
                </div>`).appendTo(this.$root);

            this.$body = this.$guide.find('.gw-finish-guide__body');
            this.$status = this.$guide.find('.gw-s-status');
            this.$filters = this.$guide.find('.gw-s-filters');
            this.$all = this.$guide.find('.gw-s-all');

            this.addListener(this.$guide.find('.gw-finish-guide__min'), 'click', () => this.minimise(true));
            this.addListener(this.$dock, 'click', () => this.openAt(this.index < this.steps.length ? this.index : this.nextOpen(-1)));
            this.addListener(this.$guide.find('[data-go]'), 'click', (event) => (event.currentTarget.dataset.go === 'prev' ? this.prev() : this.next()));
            this.addListener(this.$all, 'click', () => (this.undoAll ? this.undoAllNow() : this.acceptAll()));
            this.addListener(this.$guide.find('.gw-finish-guide__compact'), 'click', () => this.$guide.removeClass('gw-finish-guide--compact'));
            this.addListener(this.$guide.find('.gw-finish-guide__handle'), 'click', () => this.$guide.toggleClass('gw-finish-guide--compact'));

            // Esc minimises, only from inside the guide, so it never takes
            // Esc from Craft's slideouts and modals.
            this.addListener(this.$guide, 'keydown', (event) => {
                if (event.key === 'Escape' && !$(event.target).is('input, textarea') && !$(event.target).closest('.gw-s-confirm').length) {
                    event.stopPropagation();
                    this.minimise(true);
                }
            });
        },

        pillLabel(numbers) {
            if (this.status === 'running') return t('Reviewing…');
            if (this.status === 'failed' && !numbers.open) return t('Review failed');
            if (this.status === 'ready' && !this.steps.length) return t('Nothing to suggest');
            if (!numbers.open) return t('All reviewed');

            const label = numbers.open === 1 ? t('1 suggestion') : t('{count} suggestions', { count: numbers.open });
            const review = this.data.review;

            if (review && !review.mine && review.by && review.ago) return `${label} · ${t('by {name}, {ago}', { name: review.by, ago: review.ago })}`;

            return label;
        },

        paint({ focus = false, fly = false } = {}) {
            const numbers = H.counts(this.steps);
            const visible = this.shown && (this.steps.length > 0 || this.status !== 'idle');
            const label = this.pillLabel(numbers);

            this.$dock.find('.gw-finish-dock__count').text(numbers.open ? String(numbers.open) : '✓').toggleClass('gw-finish-dock__count--ready', !numbers.open);
            this.$dock.find('.gw-finish-dock__tip').text(label);
            this.$dock.attr('aria-label', `${t('Suggested edits')}: ${label}`);

            this.$root.toggleClass('hidden', !visible).toggleClass('gw-finish--minimised', this.minimised);
            this.$guide.toggleClass('gw-finish-guide--done', this.index >= this.steps.length);

            // The count for anything else that shows it (the header's
            // Ghostwriter menu): open suggestions, and whether a review runs.
            if (this.lastSaid !== `${numbers.open}|${this.status}|${visible}`) {
                this.lastSaid = `${numbers.open}|${this.status}|${visible}`;
                document.dispatchEvent(new CustomEvent('ghostwriter:counts', { detail: { suggestions: visible ? numbers.open : 0, reviewing: this.status === 'running' } }));
                document.dispatchEvent(new CustomEvent('ghostwriter:suggest-count', { detail: { count: numbers.open, status: this.status, shown: visible, label } }));
            }

            if (visible) this.highlight();
            else this.unhighlight();

            this.paintEditors(visible);

            if (!visible || this.minimised) {
                this.$flyer.addClass('gw-finish-flyer--hidden');

                return;
            }

            this.drawStatus();
            this.drawFilters();
            this.drawBar();
            this.drawFoot();

            if (this.index >= this.steps.length) {
                this.drawEnd(numbers);
                this.flyHome(this.status === 'running' ? t('Reading…') : t('All done!'));

                if (focus) this.$message?.trigger('focus');

                return;
            }

            const step = this.steps[this.index];
            const shown = this.steps.filter((s) => this.filter === 'all' || s.category === this.filter);
            const where = t('{n} of {total}', { n: shown.indexOf(step) + 1, total: shown.length });

            this.$guide.find('.gw-finish-guide__step').text(where);
            this.drawStep(step);
            this.$guide.find('.gw-finish-guide__compact').text([where, step.label, t('Next →')].join(' · '));

            if (focus) {
                this.$message?.trigger('focus');
                this.announce(`${t('Suggestion {n} of {total}.', { n: shown.indexOf(step) + 1, total: shown.length })} ${step.label}. ${step.reason}`);
            }

            if (fly) {
                this.show(step);
            } else {
                this.follow(step);
            }
        },

        /** The mark beside the current step's field. */
        follow(step) {
            const field = this.locate(step) ?? this.cardFor(step)?.card ?? null;

            if (field !== this.flyTarget || this.flySpeech !== step.label) {
                field ? this.flyTo(field, step.label) : this.flyHome(step.label);
            } else {
                this.placeFlyer();
            }
        },

        /** Reveal the step's field (its tab, its blocks), scroll to it and select its words. */
        async show(step) {
            const field = (await this.reveal(step)) ?? this.cardFor(step)?.card ?? null;

            if (field) {
                this.scrollTo(field);
                this.highlight();
                this.flyTo(field, step.label);
            } else {
                this.flyHome(step.label);
            }
        },

        drawStatus() {
            const review = this.data.review;
            let text = '';

            if (this.status === 'running') text = review?.status === 'queued' ? t('Waiting to start…') : t('Reviewing… then double-checking. Ghostwriter reads each thing it found in its paragraph, then checks every suggestion again before you see it.');
            else if (this.status === 'failed') text = t('I couldn’t finish reading the page: {reason}', { reason: review?.error ?? t('the AI provider didn’t answer') });
            else if (review?.truncated) text = t('I ran out of room; these are the first {count}.', { count: this.steps.length });
            else if (review && !review.fresh && review.ago) text = t('From a review {ago}. The page has changed since; suggestions that no longer fit are marked.', { ago: review.ago });

            this.$status.prop('hidden', !text).empty();

            if (!text) return;

            // The spinner sits before the words, on their first line.
            const $line = $('<span class="gw-s-status__line"/>').appendTo(this.$status);

            if (this.status === 'running') $line.append('<span class="gw-s-spinner" aria-hidden="true"></span>');

            $line.append($('<span class="gw-s-status__text"/>').text(text));

            if (this.status === 'running') {
                this.$status.append(`<ol class="gw-s-phases" aria-label="${esc(t('Progress'))}">
                    <li class="${review?.status === 'queued' ? 'is-now' : 'is-done'}">${esc(t('Started'))}</li>
                    <li class="${review?.status === 'running' ? 'is-now' : ''}">${esc(t('Reviewing, then double-checking'))}</li>
                    <li>${esc(t('Ready'))}</li>
                </ol>`);
            }

            if (this.status === 'failed') {
                $(`<button type="button" class="btn small">${esc(t('Try again'))}</button>`).on('click', () => this.ask()).appendTo(this.$status);
            }
        },

        drawFilters() {
            const list = H.filters(this.steps, this.filter);

            this.$filters.prop('hidden', this.steps.length < 2).empty();

            list.forEach((filter) => {
                const name = filter.key === 'all' ? t('All') : filter.label;
                const spoken = filter.key === 'all'
                    ? t('All, {count} open', { count: filter.count })
                    : filter.count === 1 ? t('{label}, 1 suggestion', { label: name }) : t('{label}, {count} suggestions', { label: name, count: filter.count });

                $(`<button type="button" class="gw-s-filter" aria-pressed="${this.filter === filter.key ? 'true' : 'false'}" aria-label="${esc(spoken)}"><span>${esc(name)}</span><span class="gw-s-filter-n">${filter.count}</span></button>`)
                    .on('click', () => this.setFilter(filter.key))
                    .appendTo(this.$filters);
            });
        },

        drawBar() {
            this.$guide.find('.gw-finish-guide__bar').html(this.steps.map((step, i) => {
                const state = i === this.index && H.isOpen(step) ? 'current' : step.state === 'accepted' || step.state === 'done' ? 'fixed' : step.state === 'dismissed' || step.state === 'confirmed' || step.stale ? 'skipped' : 'open';

                return `<i class="gw-finish-guide__seg gw-finish-guide__seg--${state}"></i>`;
            }).join(''));
        },

        drawFoot() {
            const fixes = H.wordingFixes(this.steps, this.filter);

            this.$guide.find('.gw-finish-guide__foot').toggle(this.steps.length > 0);
            this.$guide.find('[data-go="prev"]').prop('disabled', this.index === 0 || !this.steps.length);
            this.$all.prop('hidden', !this.undoAll && !fixes.length).text(this.undoAll
                ? t('Undo all ({count})', { count: this.undoAll.length })
                : t('Accept all wording fixes ({count})', { count: fixes.length }));
        },

        drawEnd(numbers) {
            this.$guide.find('.gw-finish-guide__step').text('');

            const finish = Ghostwriter.finish;
            // The same number as the menu's: nothing while Finish this page isn't out.
            const left = finish && finish !== this ? finish.left?.() ?? 0 : 0;
            let text;

            if (this.status === 'running') text = t('Suggestions show here once Ghostwriter has checked each one in its paragraph.');
            else if (this.status === 'failed') text = t('Nothing to show: the review didn’t finish.');
            else if (this.status === 'ready' && !this.steps.length) text = t('Nothing to suggest. The page reads well against your guide.');
            else if (!numbers.open) text = t('Done. {accepted} accepted, {dismissed} dismissed. The changes are in the form: check them and save when you’re happy.', { accepted: numbers.accepted, dismissed: numbers.dismissed + numbers.confirmed });
            else text = numbers.open === 1 ? t('1 suggestion is still open in another filter.') : t('{count} suggestions are still open in other filters.', { count: numbers.open });

            this.$body.empty();

            if (this.last) this.$body.append(this.lastLine());

            this.$message = $('<p class="gw-finish-guide__message" tabindex="-1"/>').text(text).appendTo(this.$body);

            const $fixes = $('<div class="gw-finish-guide__fixes"/>').appendTo(this.$body);

            if (this.steps.length) this.button(t('Go through again'), () => this.again()).appendTo($fixes);

            if (left) {
                this.button(left === 1 ? t('1 thing still to finish') : t('{count} things still to finish', { count: left }), () => {
                    this.minimise(true);
                    finish.open();
                }).appendTo($fixes);
            }

            if (this.status !== 'running' && this.data.configured) this.button(t('Review again'), () => this.ask(), { model: true }).appendTo($fixes);

            this.$guide.find('.gw-finish-guide__compact').text(t('All done'));
        },

        lastLine() {
            const $line = $('<p class="gw-s-last"/>').append($('<span/>').text(this.last.text));
            const step = this.last.step;

            $('<button type="button" class="gw-s-linkbtn"/>').text(t('Undo')).on('click', () => this.undo(step)).appendTo($line);

            return $line;
        },

        drawStep(step) {
            this.$body.empty();

            if (this.last && this.last.step !== step) this.$body.append(this.lastLine());

            this.$body.append(`<div class="gw-s-chips"><span class="gw-s-cat">${esc(step.label)}</span><span class="gw-s-place">${esc(step.place ?? '')}</span></div>`);
            this.$message = $('<p class="gw-finish-guide__message" tabindex="-1"/>').text(step.reason).appendTo(this.$body);

            if (step.unsaved) this.note(t('Accepted earlier, but the page wasn’t saved, so it isn’t in the page.'));

            if (step.stale) {
                this.note(t('This text has changed since the review.'));
                this.fixes([this.button(t('Dismiss'), () => this.dismiss(step))]);
            } else if (!H.isOpen(step)) {
                this.decided(step);
            } else if (step.fact) {
                this.factStep(step);
            } else if (step.scope === 'asset') {
                this.altStep(step);
            } else if (step.link && step.category === 'link') {
                this.linkStep(step);
            } else {
                this.wordingStep(step);
            }

            $('<p class="gw-s-src"/>').text(step.source).appendTo(this.$body);
            Ghostwriter.prepareButtons?.(this.$body);
        },

        note(text) {
            $('<p class="gw-s-reason"/>').text(text).appendTo(this.$body);
        },

        fixes(buttons) {
            const $fixes = $('<div class="gw-finish-guide__fixes"/>').appendTo(this.$body);

            buttons.filter(Boolean).forEach(($button) => $fixes.append($button));

            return $fixes;
        },

        button(label, onClick, { primary = false, model = false, disabled = false } = {}) {
            const $button = this.fixButton(label, { primary, model });

            $button.prop('disabled', disabled || this.busy === true).on('click', onClick);

            return $button;
        },

        decided(step) {
            const by = step.decided?.by;
            const said = {
                accepted: by ? t('Accepted by {name}.', { name: by }) : t('Accepted. It’s in the form until you save.'),
                done: t('Done: it’s in the saved page.'),
                dismissed: by ? t('Dismissed by {name}.', { name: by }) : t('Dismissed.'),
                confirmed: by ? t('{name} said it’s still right.', { name: by }) : t('Kept: it’s still right.'),
            }[step.state] ?? '';
            const text = this.local.get(step.id)?.text ?? null;

            $('<p class="gw-s-reason"/>').text(said).appendTo(this.$body);

            if (text) this.$body.append(this.diff(step, null, text));

            if (!(step.state === 'done' && this.local.get(step.id)?.alt === undefined)) {
                this.fixes([this.button(t('Undo'), () => this.undo(step))]);
            }
        },

        /**
         * The before and after, each as its whole sentence with the change
         * marked, so the editor can judge the fit: <del> and <ins>, each with
         * words a screen reader says. A dated phrase is marked in the old
         * words.
         */
        diff(step, before, after) {
            const ctx = step.scope === 'range' ? step.context : null;
            const lead = ctx?.before ?? '';
            const tail = ctx?.after ?? '';
            const marked = (text) => {
                const at = step.phrase ? text.indexOf(step.phrase) : -1;

                return at < 0 ? esc(text) : `${esc(text.slice(0, at))}<mark class="gw-s-phrase">${esc(step.phrase)}</mark>${esc(text.slice(at + step.phrase.length))}`;
            };
            const line = (kind, words) => `<p class="gw-s-line">${lead ? `<span class="gw-s-ctx">${esc(lead)}</span>` : ''}<${kind === 'old' ? 'del' : 'ins'}><span class="visually-hidden">${esc(kind === 'old' ? t('Remove:') : t('Add:'))} </span>${kind === 'old' ? marked(words) : esc(words)}</${kind === 'old' ? 'del' : 'ins'}>${tail ? `<span class="gw-s-ctx">${esc(tail)}</span>` : ''}</p>`;

            return $(`<div class="gw-s-diff" role="group" aria-label="${esc(t('Suggested change, in its sentence'))}">${before !== null ? line('old', before) : ''}${after !== null ? line('new', after) : ''}</div>`);
        },

        before(step) {
            if (step.scope !== 'range' && step.seo) return step.seo.text ?? '';

            return step.quote?.exact ?? '';
        },

        version(step) {
            const versions = H.versionsOf(step);

            return versions[Math.min(this.versionAt.get(step.id) ?? 0, versions.length - 1)] ?? null;
        },

        wordingStep(step) {
            const words = this.version(step);
            const inherited = step.seo?.inheritsFrom;

            if (inherited) {
                this.note(step.seo.writable
                    ? t('This comes from {field}. Accepting gives the page its own {label}.', { field: inherited, label: step.place })
                    : t('This comes from {field}. Set it in SEOmatic’s settings for this field, or copy the new text.', { field: inherited }));
            }

            if (words === null) {
                // A finding the review wrote no words for.
                this.$body.append(this.diff(step, this.before(step), null));
                this.note(step.free_label ?? t('Rewrite it yourself'));
                this.$body.append(this.editBox(step, H.plain(this.before(step)), true));
                this.fixes([
                    step.category === 'out-of-date' ? this.button(t('It’s still right'), () => this.confirm(step)) : null,
                    this.button(t('Dismiss'), () => this.dismiss(step)),
                ]);

                return;
            }

            const versions = H.versionsOf(step);
            const at = this.versionAt.get(step.id) ?? 0;
            const more = at + 1 < versions.length;
            const writable = step.seo?.writable !== false;
            const $box = this.editBox(step, H.plain(words), false);

            this.$body.append(this.diff(step, this.before(step), H.plain(words)));
            this.$body.append($box);
            this.fixes([
                writable
                    ? this.button(t('Accept'), () => this.accept(step, words), { primary: true })
                    : this.button(t('Copy the new text'), () => navigator.clipboard?.writeText(H.plain(words)).then(() => this.announce(t('Copied.'))), { primary: true }),
                writable ? this.button(t('Edit'), () => this.openEdit($box)) : null,
                step.category === 'out-of-date' ? this.button(t('It’s still right'), () => this.confirm(step)) : null,
                more
                    ? this.button(t('Another version'), () => this.another(step))
                    : this.data.review?.status === 'ready' && ['out-of-date', 'voice', 'clarity', 'seo', 'duplicate'].includes(step.category) ? this.button(t('Write another'), () => this.writeAnother(step), { model: true }) : null,
                this.button(t('Dismiss'), () => this.dismiss(step)),
            ]);

            if (step.seo?.limit) {
                $('<p class="gw-s-count"/>').text(t('{length} / {limit} characters', { length: Array.from(H.plain(words)).length, limit: step.seo.limit })).appendTo(this.$body);
            }
        },

        /**
         * "Edit": the new words in a box in the guide. Enter or leaving it
         * puts them in (accepted, with the editor's words); Esc puts it back.
         */
        editBox(step, value, open) {
            const id = `gw-s-edit-${Math.random().toString(36).slice(2, 8)}`;
            const $box = $(`<div class="gw-s-edit"${open ? '' : ' hidden'}>
                <label for="${id}" class="visually-hidden">${esc(t('Your words for {place}', { place: step.place ?? '' }))}</label>
                <textarea id="${id}" class="text fullwidth gw-s-textarea" rows="3"></textarea>
                <p class="gw-s-hint">${esc(t('Enter puts it in. Esc puts it back.'))}</p>
            </div>`);
            const $input = $box.find('textarea').val(value);
            let done = false;

            const put = () => {
                const words = String($input.val()).trim();

                if (done || !words || words === value.trim()) return;

                done = true;
                this.accept(step, words, { edited: true });
            };

            $input.on('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    put();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    $input.val(value);
                    done = true;
                    $box.prop('hidden', true);
                    this.paint();
                }
            });
            $input.on('blur', () => setTimeout(put, 150));

            return $box;
        },

        openEdit($box) {
            $box.prop('hidden', false);
            $box.find('textarea').trigger('focus');
        },

        factStep(step) {
            const id = `gw-s-fact-${Date.now()}`;
            const $ask = $(`<div class="gw-s-ask">
                <label for="${id}" class="visually-hidden">${esc(step.fact.ask)}</label>
                <input id="${id}" type="text" class="text gw-finish-answer" placeholder="${esc(step.fact.ask)}" autocomplete="off"${step.fact.answer === 'number' ? ' inputmode="decimal"' : ''}>
            </div>`);
            const $input = $ask.find('input');
            const $error = $('<p class="gw-s-error" role="alert" hidden></p>');
            const use = () => {
                try {
                    const filled = H.fillFact(step.fact, $input.val());

                    this.accept(step, filled, { answer: String($input.val()).trim() });
                } catch (failure) {
                    $error.prop('hidden', false).text(failure.message === 'date-only' ? t('Type a date.') : t('Type the number only.'));
                    $input.attr('aria-invalid', 'true').attr('aria-describedby', 'gw-s-fact-error').trigger('focus');
                    $error.attr('id', 'gw-s-fact-error');
                }
            };

            $input.on('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    use();
                } else if (event.key === 'Escape') {
                    event.stopPropagation();
                    $input.val('');
                }
            });

            $ask.append(this.button(t('Use it'), use, { primary: true }));
            this.$body.append(this.diff(step, this.before(step), null), $ask, $error);
            this.fixes([
                this.button(t('It’s still right'), () => this.confirm(step)),
                step.fact.without !== null && step.fact.without !== undefined ? this.button(t('Remove the number'), () => this.accept(step, step.fact.without)) : null,
                this.button(t('Dismiss'), () => this.dismiss(step)),
            ]);
        },

        linkStep(step) {
            const words = typeof step.replacement === 'string' ? step.replacement : null;

            this.$body.append(`<div class="gw-s-diff gw-s-link-change" role="group" aria-label="${esc(t('Suggested change'))}">
                <span>${esc(words ? H.plain(words) : this.before(step) || step.place || '')}</span>
                <span class="gw-s-arrow" aria-hidden="true">→</span>
                <span class="gw-s-target">${esc(step.link.title ?? '')}</span>
            </div>`);
            this.fixes([
                step.link.value ? this.button(t('Link to it'), () => this.linkTo(step, words), { primary: true }) : null,
                this.button(t('Choose an entry'), () => this.chooseEntry(step)),
                this.button(t('Remove the link'), () => this.removeLink(step)),
                this.button(t('Dismiss'), () => this.dismiss(step)),
            ]);
        },

        altStep(step) {
            const asset = step.asset ?? {};
            const proposed = this.version(step);
            const id = `gw-s-alt-${Date.now()}`;
            const $asset = $(`<div class="gw-s-asset">${asset.url ? `<img src="${esc(asset.url)}" alt="" class="gw-s-thumb">` : ''}<div><strong>${esc(asset.filename ?? '')}</strong><p>${esc(t('Alt text:'))} ${esc(asset.alt ? asset.alt : t('none'))}</p></div></div>`);
            const $box = $(`<div class="gw-s-edit"><label for="${id}" class="visually-hidden">${esc(t('Alt text for {file}', { file: asset.filename ?? '' }))}</label><textarea id="${id}" class="text fullwidth gw-s-textarea" rows="2" maxlength="300"></textarea></div>`);
            const $input = $box.find('textarea').val(proposed ? H.plain(proposed) : '');

            this.$body.append($asset);

            if (!proposed) this.note(step.free_label ?? t('Describe the image yourself'));

            this.$body.append($box);

            if (!asset.canEdit) {
                this.note(t('Ask someone who can edit assets to add it.'));
                this.fixes([
                    this.button(t('Copy'), () => navigator.clipboard?.writeText(String($input.val())).then(() => this.announce(t('Copied.')))),
                    this.button(t('Dismiss'), () => this.dismiss(step)),
                ]);

                return;
            }

            const $save = this.button(t('Save to the image'), () => this.confirmAlt(step, String($input.val()), $save), { primary: true });

            this.fixes([$save, this.button(t('Dismiss'), () => this.dismiss(step))]);
        },

        /**
         * The confirm for alt text: a non-modal popover by the button, focus
         * moved in, back to the button on Cancel or Esc.
         */
        confirmAlt(step, alt, $button) {
            const text = alt.trim();

            if (!text) {
                this.announce(t('Write the alt text first.'));

                return;
            }

            this.$body.find('.gw-s-confirm').remove();

            const uses = step.asset?.uses ?? 0;
            const where = uses > 1 ? t('It shows wherever the image is used ({count} pages).', { count: uses }) : uses === 1 ? t('It shows wherever the image is used (1 page).') : t('It shows wherever the image is used.');
            const $popover = $(`<div class="gw-s-confirm" role="dialog" aria-label="${esc(t('Save to the image'))}"><p>${esc(t('This saves the alt text on {file} now, not when you save the page.', { file: step.asset?.filename ?? '' }))} ${esc(where)}</p></div>`);
            const close = () => {
                $popover.remove();
                $button.trigger('focus');
            };

            $popover.on('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    close();
                }
            });

            const $fixes = $('<div class="gw-finish-guide__fixes"/>').appendTo($popover);

            this.button(t('Save to the image'), () => this.saveAlt(step, text), { primary: true }).appendTo($fixes);
            this.button(t('Cancel'), close).appendTo($fixes);
            $button.closest('.gw-finish-guide__fixes').after($popover);
            $popover.find('button').first().trigger('focus');
        },

        /* ------------------------------------------------------------------
         * Moving
         * ------------------------------------------------------------------ */

        nextOpen(from) {
            return H.nextOpen(this.steps, from, this.filter);
        },

        firstOpen() {
            return this.nextOpen(-1);
        },

        openAt(index) {
            this.index = Math.max(0, Math.min(index, this.steps.length));
            this.shown = true;

            if (this.minimised) {
                this.restore();
            } else {
                this.paint({ focus: true, fly: true });
            }
        },

        go(index) {
            this.index = Math.max(0, Math.min(index, this.steps.length));
            this.paint({ focus: true, fly: true });
        },

        next() {
            this.clearLast();
            this.go(H.following(this.steps, this.index, this.filter));
        },

        prev() {
            this.clearLast();
            this.go(H.previous(this.steps, this.index, this.filter));
        },

        again() {
            this.local.forEach((mine, id) => mine.state === 'dismissed' && !mine.synced && this.local.delete(id));
            this.go(this.nextOpen(-1) < this.steps.length ? this.nextOpen(-1) : 0);
        },

        advance() {
            this.go(this.nextOpen(this.index));
        },

        setFilter(key) {
            this.filter = key;
            this.clearLast();

            const shown = H.visible(this.steps, key);
            const open = shown.find((i) => H.isOpen(this.steps[i]));

            this.index = open ?? shown[0] ?? this.steps.length;
            this.announce(key === 'all'
                ? t('Showing all: {count} open.', { count: H.counts(this.steps).open })
                : t('Showing {label}: {n} of {total}.', { label: this.steps[shown[0]]?.label ?? key, n: shown.filter((i) => H.isOpen(this.steps[i])).length, total: this.steps.length }));
            this.paint({ fly: true });
        },

        clearLast() {
            this.last = null;
            this.undoAll = null;
        },

        /** Alt+Shift+N, P and G, unless another guide was opened last. */
        keys(event) {
            if (!event.altKey || !event.shiftKey || event.metaKey || event.ctrlKey) return;

            const typing = $(event.target).is('input, textarea, select, [contenteditable="true"], [contenteditable=""]');
            const active = Ghostwriter.activeGuide;

            if (typing || !['KeyN', 'KeyP', 'KeyG'].includes(event.code) || this.$root.hasClass('hidden')) return;
            if (active && active !== this && !active.$root?.hasClass('hidden')) return;

            event.preventDefault();

            if (event.code === 'KeyG') {
                this.minimised ? this.restore() : this.minimise(true);
            } else if (this.minimised) {
                this.restore();
            } else {
                event.code === 'KeyN' ? this.next() : this.prev();
            }
        },

        /* ------------------------------------------------------------------
         * Minimise and restore (not remembered: a review opens minimised
         * unless it was just asked for)
         * ------------------------------------------------------------------ */

        restore() {
            Ghostwriter.activeGuide = this;
            Ghostwriter.guides?.forEach((other) => other !== this && other.stepAside?.());
            this.minimised = false;
            this.shown = true;
            this.announce(t('Suggested edits opened.'));

            if (this.reduced) {
                this.paint({ focus: true, fly: true });

                return;
            }

            this.$dock.addClass('gw-finish-dock--leaping');
            clearTimeout(this.minimiseTimer);
            this.minimiseTimer = setTimeout(() => {
                this.$dock.removeClass('gw-finish-dock--leaping');
                this.$guide.addClass('gw-finish-guide--unfurling');
                this.$flyer.removeClass('gw-finish-flyer--hidden');
                this.paint({ focus: true, fly: true });
                setTimeout(() => this.$guide.removeClass('gw-finish-guide--unfurling'), 600);
            }, this.$root.hasClass('hidden') ? 0 : 300);
        },

        minimise(on) {
            if (!on) {
                this.restore();

                return;
            }

            if (this.minimised) return;

            this.minimised = true;
            this.announce(t('Suggested edits minimised.'));
            clearTimeout(this.minimiseTimer);

            if (this.reduced) {
                this.paint();
                this.$dock.trigger('focus');

                return;
            }

            this.$guide.addClass('gw-finish-guide--sucking');
            this.flyToDock();
            this.minimiseTimer = setTimeout(() => {
                this.$guide.removeClass('gw-finish-guide--sucking');
                this.paint();
                this.$dock.addClass('gw-finish-dock--popping').trigger('focus');
                this.puff();
                setTimeout(() => this.$dock.removeClass('gw-finish-dock--popping'), 450);
            }, 480);
        },

        /** Another guide opened: this one goes to its dock, quietly. */
        stepAside() {
            if (this.minimised) return;

            this.minimised = true;
            this.paint();
        },

        /* ------------------------------------------------------------------
         * Actions
         * ------------------------------------------------------------------ */

        async accept(step, words, { edited = false, answer = null } = {}) {
            await this.reachCard(step);

            const change = this.replace(step, words);

            if (!change) {
                step.stale = true;
                this.announce(t('Those words aren’t in the field any more.'));
                this.paint({ focus: true });

                return;
            }

            const text = H.plain(words);

            this.local.set(step.id, { state: 'accepted', text, change, pending: true });
            step.state = 'accepted';
            this.last = { step, text: t('Accepted.') };
            this.undoAll = null;
            this.announce(edited ? t('Your words are in the form.') : t('Accepted. It’s in the form until you save.'));
            this.decide([{ suggestion: step.id, state: 'accepted', text, answer }]);
            this.advance();
        },

        dismiss(step) {
            this.local.set(step.id, { state: 'dismissed', pending: true });
            step.state = 'dismissed';
            step.stale = false;
            this.last = { step, text: t('Dismissed.') };
            this.undoAll = null;
            this.announce(t('Dismissed. It won’t be suggested again.'));
            this.decide([{ suggestion: step.id, state: 'dismissed' }]);
            this.advance();
        },

        confirm(step) {
            this.local.set(step.id, { state: 'confirmed', pending: true });
            step.state = 'confirmed';
            this.last = { step, text: t('Kept: it’s still right.') };
            this.announce(t('Kept. It won’t be asked about for 12 months, or until it’s edited.'));
            this.decide([{ suggestion: step.id, state: 'confirmed' }]);
            this.advance();
        },

        async undo(step) {
            const mine = this.local.get(step.id);

            if (mine?.alt !== undefined) {
                try {
                    const result = await this.post('unalt', { reviewId: this.data.review?.id, suggestion: step.id, before: mine.alt });

                    this.announce(result?.message ?? t('Undone.'));
                    if (step.asset) step.asset.alt = mine.alt;
                } catch (error) {
                    this.flash(error.message);

                    return;
                }
            } else if (mine?.change) {
                await this.reachCard(step);
                this.restoreChange(step, mine.change);
            }

            this.local.delete(step.id);
            step.state = 'open';
            step.stale = false;
            this.last = null;
            this.undoAll = null;

            if (mine?.alt === undefined) this.decide([{ suggestion: step.id, state: 'open' }]);

            this.index = this.steps.indexOf(step);
            this.announce(t('Undone.'));
            this.paint({ focus: true, fly: true });
        },

        another(step) {
            const versions = H.versionsOf(step);
            const at = Math.min((this.versionAt.get(step.id) ?? 0) + 1, versions.length - 1);

            this.versionAt.set(step.id, at);
            this.announce(`${t('Another version:')} ${H.plain(versions[at])}`);
            this.paint({ focus: true });
        },

        async writeAnother(step) {
            this.busy = true;
            this.paint();

            try {
                const found = (await this.post('another', { reviewId: this.data.review?.id, suggestion: step.id })).versions ?? [];

                step.versions = [...(step.versions ?? []), ...found];
                this.versionAt.set(step.id, H.versionsOf(step).indexOf(found[0]));
                this.announce(`${t('Another version:')} ${H.plain(found[0] ?? '')}`);
            } catch (error) {
                this.flash(error.message);
            } finally {
                this.busy = false;
            }

            this.paint({ focus: true });
        },

        async linkTo(step, words) {
            const change = await this.link(step, words);

            if (!change) {
                this.flash(t('That didn’t change the field. Try again, or change it by hand.'));

                return;
            }

            this.local.set(step.id, { state: 'accepted', text: words ? H.plain(words) : step.link.title, change, pending: true });
            step.state = 'accepted';
            this.last = { step, text: t('Linked.') };
            this.announce(t('Linked to {title}.', { title: step.link.title }));
            this.decide([{ suggestion: step.id, state: 'accepted', text: words ? H.plain(words) : null }]);
            this.advance();
        },

        chooseEntry(step) {
            this.choose(step, () => {
                this.local.set(step.id, { state: 'accepted', pending: true });
                step.state = 'accepted';
                this.decide([{ suggestion: step.id, state: 'accepted' }]);
                this.advance();
            });
        },

        removeLink(step) {
            const change = this.unlink(step);

            if (!change) return;

            this.local.set(step.id, { state: 'accepted', change, pending: true });
            step.state = 'accepted';
            this.last = { step, text: t('Link removed; the words stay.') };
            this.decide([{ suggestion: step.id, state: 'accepted', text: this.before(step) }]);
            this.advance();
        },

        async saveAlt(step, alt) {
            try {
                const result = await this.post('alt', { reviewId: this.data.review?.id, suggestion: step.id, alt });

                this.local.set(step.id, { state: 'accepted', text: alt, alt: result?.before ?? '', synced: true });
                step.state = 'accepted';
                if (step.asset) step.asset.alt = alt;
                this.last = { step, text: result?.message ?? t('Saved to the image.') };
                this.announce(result?.message ?? t('Saved to the image.'));
                this.advance();
            } catch (error) {
                this.flash(error.message);
            }
        },

        acceptAll() {
            const targets = H.wordingFixes(this.steps, this.filter);
            const done = [];

            // From the end of the form backwards, each found again just
            // before it goes in; one that isn't there stays open.
            [...targets].reverse().forEach((i) => {
                const step = this.steps[i];
                const words = this.version(step);
                const change = this.replace(step, words);

                if (!change) return;

                this.local.set(step.id, { state: 'accepted', text: H.plain(words), change, pending: true });
                step.state = 'accepted';
                done.push(step);
            });

            if (!done.length) return;

            this.decide(done.map((step) => ({ suggestion: step.id, state: 'accepted', text: H.plain(this.version(step)) })));
            this.undoAll = done;
            this.last = null;
            this.announce(t('{count} accepted. Undo all is available.', { count: done.length }));
            this.index = this.nextOpen(this.index);
            this.paint();
            this.$all.trigger('focus');
        },

        undoAllNow() {
            const list = this.undoAll ?? [];

            list.forEach((step) => {
                const mine = this.local.get(step.id);

                if (mine?.change) this.restoreChange(step, mine.change);

                this.local.delete(step.id);
                step.state = 'open';
            });

            this.decide(list.map((step) => ({ suggestion: step.id, state: 'open' })));
            this.undoAll = null;
            this.announce(t('Undone: {count}.', { count: list.length }));
            this.index = this.nextOpen(-1);
            this.paint({ focus: true });
        },

        /* ------------------------------------------------------------------
         * Highlights: indigo, so a suggestion never looks like a Finish gap
         * ------------------------------------------------------------------ */

        highlight() {
            const current = !this.minimised && this.index < this.steps.length ? this.index : -1;
            const fields = H.fieldStates(this.steps, current);
            const seen = new Set();

            fields.forEach((state) => {
                const field = this.locate(state.step) ?? this.cardFor(state.step)?.card ?? null;

                if (!field || seen.has(field)) return;

                seen.add(field);

                if (field.getAttribute('data-gw-suggest') !== state.state) field.setAttribute('data-gw-suggest', state.state);

                const heading = field.querySelector(':scope > .heading');
                const name = heading?.querySelector(':scope > label, :scope > legend, :scope > .label, :scope > h2, :scope > h3');
                let tag = field.querySelector(':scope > .gw-s-tag, :scope > .heading > .gw-s-tag');

                if (!tag) {
                    tag = document.createElement('button');
                    tag.type = 'button';
                    tag.className = 'gw-gap-tag gw-s-tag';
                    tag.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        if (tag.dataset.step !== '') this.openAt(Number(tag.dataset.step));
                    });

                    if (name) name.after(tag);
                    else if (heading) heading.prepend(tag);
                    else field.prepend(tag);
                }

                const text = H.tagText(state, current, this.steps);
                const target = state.state === 'current' ? current : state.steps[0] ?? state.first;

                if (tag.dataset.step !== String(target) || tag.textContent !== text) {
                    tag.dataset.step = String(target);
                    tag.textContent = text;
                    tag.setAttribute('aria-label', state.state === 'done' ? t('Suggestions here: all reviewed') : t('Suggestion {n}: {label}', { n: Number(target) + 1, label: this.steps[target]?.label ?? '' }));
                }

                if (!field.dataset.gwSuggestNote) field.dataset.gwSuggestNote = `gw-s-note-${Math.random().toString(36).slice(2, 8)}`;

                let note = document.getElementById(field.dataset.gwSuggestNote);

                if (!note) {
                    note = document.createElement('span');
                    note.id = field.dataset.gwSuggestNote;
                    note.className = 'visually-hidden';
                    field.append(note);
                }

                const said = state.state === 'done' ? '' : t('Ghostwriter suggests a change here ({label}).', { label: state.step?.label ?? '' });

                if (note.textContent !== said) note.textContent = said;

                const control = field.querySelector('input:not([type="hidden"]), textarea, [contenteditable="true"]');

                if (control && said && !(control.getAttribute('aria-describedby') ?? '').includes(note.id)) {
                    control.setAttribute('aria-describedby', `${control.getAttribute('aria-describedby') ?? ''} ${note.id}`.trim());
                }
            });

            document.querySelectorAll('[data-gw-suggest]').forEach((field) => {
                if (!seen.has(field)) this.clearField(field);
            });
        },

        unhighlight() {
            document.querySelectorAll('[data-gw-suggest]').forEach((field) => this.clearField(field));
        },

        clearField(field) {
            field.removeAttribute('data-gw-suggest');
            field.querySelectorAll(':scope > .gw-s-tag, :scope > .heading > .gw-s-tag').forEach((tag) => tag.remove());

            if (field.dataset.gwSuggestNote) document.getElementById(field.dataset.gwSuggestNote)?.remove();
        },

        /** The words of each open range underlined in CKEditor, through its markers; the current one filled. */
        paintEditors(visible = true) {
            const byEditor = new Map();
            const current = !this.minimised && this.index < this.steps.length ? this.steps[this.index] : null;

            (visible ? this.steps : []).forEach((step) => {
                if (step.scope !== 'range' || !step.quote || !H.isOpen(step)) return;

                const editor = this.ckeditorIn(this.locate(step));

                if (!editor) return;

                if (!byEditor.has(editor)) byEditor.set(editor, []);
                byEditor.get(editor).push(step);
            });

            this.editors.forEach((state, editor) => {
                if (!byEditor.has(editor)) this.markEditor(editor, [], null);
            });

            byEditor.forEach((steps, editor) => this.markEditor(editor, steps, current));
        },

        markEditor(editor, steps, current) {
            let state = this.editors.get(editor);

            if (!state) {
                state = { classes: {}, steps: [] };
                this.editors.set(editor, state);

                try {
                    editor.conversion.for('editingDowncast').markerToHighlight({
                        model: 'gw-suggest',
                        view: (data) => ({ classes: state.classes[data.markerName] ?? ['gw-suggest-mark'] }),
                    });
                } catch (error) {
                    // Registered already, for an editor made again.
                }

                let timer = null;

                editor.model.document.on('change:data', () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        this.markEditor(editor, state.steps, state.current);
                        this.later(100);
                    }, 300);
                });
            }

            state.steps = steps;
            state.current = current;

            const ranges = steps.map((step) => [step, this.editorRange(editor, step)]).filter(([, range]) => range);

            try {
                editor.model.change((writer) => {
                    for (const marker of Array.from(editor.model.markers.getMarkersGroup('gw-suggest'))) {
                        writer.removeMarker(marker);
                    }

                    ranges.forEach(([step, range], i) => {
                        const name = `gw-suggest:${i}`;
                        state.classes[name] = ['gw-suggest-mark', current && current.id === step.id ? 'gw-suggest-mark--current' : 'gw-suggest-mark--open'];
                        writer.addMarker(name, { range, usingOperation: false, affectsData: false });
                    });
                });
            } catch (error) {
                // An editor being torn down: nothing to mark.
            }
        },

        /* ------------------------------------------------------------------
         * Craft's side: where a suggestion's words are in the form, and how
         * each change goes in. CKEditor through its model; a plain input by
         * its value, as if typed; a Link field through its picker.
         * ------------------------------------------------------------------ */

        /**
         * CKEditor's text as QuoteFinder reads it, a line per text block,
         * with each character's model position (null for the line breaks
         * between blocks). Inline elements take one place.
         */
        editorText(editor) {
            const model = editor.model;
            const chars = [];
            const positions = [];
            const walk = (element) => {
                let started = false;

                for (const child of element.getChildren()) {
                    if (child.is('$text')) {
                        if (!started && chars.length) {
                            chars.push('\n');
                            positions.push(null);
                        }

                        started = true;
                        let at = child.startOffset;

                        for (const char of child.data) {
                            chars.push(char);
                            positions.push(model.createPositionAt(child.parent, at));
                            at += char.length;
                        }
                    } else if (child.is('element') && model.schema.isInline(child)) {
                        if (!started && chars.length) {
                            chars.push('\n');
                            positions.push(null);
                        }

                        started = true;
                        chars.push('￼');
                        positions.push(model.createPositionBefore(child));
                    } else if (child.is('element')) {
                        started = false;
                        walk(child);
                    }
                }
            };

            walk(model.document.getRoot());

            return { text: chars.join(''), chars, positions };
        },

        /** Where a step's quote is in CKEditor's model, or null. */
        editorRange(editor, step, quote = step.quote, occurrence = step.occurrence ?? 0) {
            if (!quote?.exact) return null;

            const { text, chars, positions } = this.editorText(editor);
            const match = H.findQuote(quote, text, occurrence, false);

            if (!match || !match.length) return null;

            const first = positions[match.offset];
            const lastIndex = match.offset + match.length - 1;
            const last = positions[lastIndex];

            if (!first || !last) return null;

            return editor.model.createRange(first, last.getShiftedBy(chars[lastIndex].length === 2 ? 2 : 1));
        },

        /** What every piece of text in a range shares: bold, italic, a link. */
        commonAttributes(range) {
            let common = null;

            for (const item of range.getItems()) {
                if (!item.is('$textProxy')) continue;

                const attributes = Object.fromEntries(item.getAttributes());

                common = common === null ? attributes : Object.fromEntries(Object.entries(common).filter(([key, value]) => attributes[key] === value));
            }

            return common ?? {};
        },

        /** A range's content as pieces of text with their attributes, for Undo. */
        piecesOf(range) {
            const out = [];

            for (const item of range.getItems()) {
                if (item.is('$textProxy')) out.push({ text: item.data, attributes: Object.fromEntries(item.getAttributes()) });
            }

            return out;
        },

        /** Pieces written in at a position, one after the other; the end. */
        writePieces(writer, pieces, position) {
            let at = position;

            pieces.forEach((piece) => {
                writer.insertText(piece.text, piece.attributes, at);
                at = at.getShiftedBy(piece.text.length);
            });

            return at;
        },

        /**
         * Reveal the step's field; in a nested entry shown as a card (an
         * entry nested in a CKEditor field, or a Matrix entry in cards
         * view), open the card's slideout first, as a double-click on it
         * does, and wait for its form. A change made there goes into the
         * person's draft when the slideout is saved, as any edit there
         * does: nothing is saved to the entry.
         */
        async reach(step) {
            const field = await this.reveal(step);

            if (field) return field;

            const found = this.cardFor(step);

            if (!found) return null;

            this.openCard(found);

            const opened = await this.waitFor(() => this.locate(step));

            if (opened) this.showTab(opened);

            return opened;
        },

        /** The card's slideout, before a change goes in there, when its field isn't drawn in the form. */
        async reachCard(step) {
            if (step.scope !== 'asset' && !this.locate(step) && this.cardFor(step)) await this.reach(step);
        },

        /**
         * A card's slideout: CKEditor's own for an entry nested in it (so
         * its save goes into the person's draft as CKEditor puts it there),
         * else Craft's element editor, as Finish opens a Matrix card.
         */
        openCard({ id, card }) {
            const editor = card.closest('.ck-editor__editable')?.ckeditorInstance ?? null;
            const ui = editor?.plugins?.has?.('CraftEntriesUI') ? editor.plugins.get('CraftEntriesUI') : null;
            const model = ui ? H.entryModel(editor.model.createRangeIn(editor.model.document.getRoot()), card.dataset.id ?? id) : null;

            if (model && typeof ui._initEditEntrySlideout === 'function') {
                ui._initEditEntrySlideout(null, model);
            } else {
                Craft.createElementEditor(ENTRY, card, { siteId: this.config.siteId });
            }

            this.watchSlideout(id);
        },

        /**
         * Once the card's slideout is open: saving it puts the nested entry
         * in the person's draft, under an ID of its own there, so the guide
         * asks where each suggestion is again; closing it, each one's words
         * are looked for again.
         */
        async watchSlideout(id) {
            const container = await this.waitFor(() => this.slideoutFor(id));
            const slideout = container ? $(container).data('slideout') ?? $(container).closest('.slideout-container').data('slideout') : null;

            if (!slideout?.on || slideout.gwSuggest) return;

            slideout.gwSuggest = true;
            slideout.on('submit', () => setTimeout(() => this.load(), 300));
            slideout.on('close', () => this.later(100));
        },

        /** What `find` finds, looked for until it's there or a few seconds have passed (then null). */
        waitFor(find, wait = 8000) {
            const started = Date.now();

            return new Promise((resolve) => {
                const look = () => {
                    const found = find();

                    if (found || Date.now() - started > wait) {
                        resolve(found ?? null);
                    } else {
                        setTimeout(look, 150);
                    }
                };

                look();
            });
        },

        /** The input to write to for a plain value: the title, an SEOmatic value, or the field's text box. */
        inputFor(step) {
            const field = this.locate(step);

            if (!field) return null;

            if (step.location?.handle === 'title') return field.querySelector('input#title, input[name="title"]') ?? this.inputIn(field);

            if (step.seo?.seomatic) {
                const key = String(step.path ?? '').split('/').pop();

                return field.querySelector(`[name$="[metaGlobalVars][${CSS.escape(key)}]"]`);
            }

            return this.inputIn(field);
        },

        present(step) {
            if (step.scope === 'asset' || step.scope === 'field') return true;

            const field = this.locate(step);
            const editor = this.ckeditorIn(field);

            if (editor) return !!this.editorRange(editor, step);

            // A field that isn't drawn here (a block in cards view): can't tell, so not stale.
            if (!field || step.fieldType === 'richtext') return true;

            const input = this.inputFor(step);

            return input ? !!H.findQuote(step.quote, input.value, step.occurrence, false) : true;
        },

        /** Whether the words a step would put in are there already (accepted in another tab, or typed). */
        applied(step, words) {
            if (!words || step.scope === 'asset') return false;

            const editor = this.ckeditorIn(this.locate(step));
            const text = editor ? this.editorText(editor).text : this.inputFor(step)?.value ?? '';
            const added = H.plain(words);

            // Words that were in the old ones already count only once the
            // old words have gone (as core's Reconciler has it).
            return text.includes(added) && (!this.present(step) || (step.quote?.exact && added.includes(step.quote.exact)));
        },

        /**
         * Put words in for a step: the quote's range, or the whole value.
         * Returns what Undo needs, or null when the words aren't there.
         */
        replace(step, markdown) {
            const field = this.locate(step);
            const editor = this.ckeditorIn(field);

            if (editor && step.scope === 'range') {
                const range = this.editorRange(editor, step);

                if (!range) return null;

                const before = this.piecesOf(range);
                const inherit = this.commonAttributes(range);
                const pieces = H.pieces(markdown, inherit, this.config.siteId);

                editor.model.change((writer) => {
                    const start = range.start;

                    writer.remove(range);
                    this.writePieces(writer, pieces, start);
                });

                return { kind: 'editor', before, after: pieces.map((piece) => piece.text).join('') };
            }

            const input = this.inputFor(step);

            if (!input) return null;

            const words = H.plain(markdown);
            const old = input.value;
            let next;

            if (step.scope === 'field') {
                next = words;
            } else {
                const range = H.rangeIn(old, H.findQuote(step.quote, old, step.occurrence, false));

                if (!range) return null;

                next = old.slice(0, range[0]) + words + old.slice(range[1]);
            }

            // An SEOmatic value becomes the page's own: its override switch on, so SEOmatic keeps it.
            if (step.seo?.seomatic) Ghostwriter.FinishHelpers?.seomaticOwn?.(this.locate(step), String(step.path ?? '').split('/').pop());

            this.setInput(input, next);

            return { kind: 'value', before: old };
        },

        /** Undo: the new words found by the old words' context, and the old content put back. */
        restoreChange(step, change) {
            if (!change) return false;

            if (change.kind === 'value') {
                const input = this.inputFor(step);

                if (input) this.setInput(input, change.before);

                return !!input;
            }

            if (change.kind === 'link-field') {
                change.restore?.();

                return true;
            }

            const editor = this.ckeditorIn(this.locate(step));

            if (!editor) return false;

            const range = change.after === '' ? null : this.editorRange(editor, step, { exact: change.after, prefix: step.quote?.prefix ?? '', suffix: step.quote?.suffix ?? '' }, 0);

            if (!range) return false;

            editor.model.change((writer) => {
                const start = range.start;

                writer.remove(range);
                this.writePieces(writer, change.before, start);
            });

            return true;
        },

        /**
         * "Link to it": inline in CKEditor, the link on the words (with new
         * words when the review wrote some); a Link field, the entry chosen
         * as its picker would.
         */
        async link(step, words = null) {
            const target = step.link?.value;

            if (!target) return null;

            const field = await this.reach(step);
            const editor = this.ckeditorIn(field);

            if (editor && step.quote) {
                const range = this.editorRange(editor, step);

                if (!range) return null;

                const before = this.piecesOf(range);
                const inherit = this.commonAttributes(range);
                const text = words ? H.plain(words) : before.map((piece) => piece.text).join('');
                const pieces = [{ text, attributes: { ...inherit, linkHref: H.editorHref(target, this.config.siteId) } }];

                editor.model.change((writer) => {
                    const start = range.start;

                    writer.remove(range);
                    this.writePieces(writer, pieces, start);
                });

                return { kind: 'editor', before, after: text };
            }

            const entry = step.link?.entry;

            if (field && entry && (await this.setLinkField(field, entry.id, entry.siteId ?? this.config.siteId, step.link.title))) {
                return { kind: 'link-field' };
            }

            return null;
        },

        unlink(step) {
            const field = this.locate(step);
            const editor = this.ckeditorIn(field);

            if (editor && step.quote) {
                const range = this.editorRange(editor, step);

                if (!range) return null;

                const before = this.piecesOf(range);

                editor.model.change((writer) => writer.removeAttribute('linkHref', range));

                return { kind: 'editor', before, after: before.map((piece) => piece.text).join('') };
            }

            const input = this.selectInput(field, 'entry') ?? this.selectInput(field);

            if (!input) return null;

            input.$elements.each((i, element) => input.removeElement($(element)));

            return { kind: 'link-field' };
        },

        /** "Choose an entry": Craft's entry picker, for an inline link or a Link field. */
        async choose(step, chosen) {
            const field = await this.reveal(step);
            const editor = this.ckeditorIn(field);

            if (editor && step.quote) {
                Craft.createElementSelectorModal(ENTRY, {
                    criteria: { siteId: this.config.siteId, uri: ':notempty:' },
                    multiSelect: false,
                    onSelect: (elements) => {
                        const entry = elements[0];
                        const range = entry ? this.editorRange(editor, step) : null;

                        if (!range) return;

                        editor.model.change((writer) => writer.setAttribute('linkHref', `${entry.url ?? ''}#entry:${entry.id}@${entry.siteId}:url`, range));
                        chosen();
                    },
                });

                return;
            }

            const $select = $(field).find('select.fieldtoggle').first();
            const type = $select.val();

            if ($select.length && $select.find('option[value="entry"]').length) $select.val('entry').trigger('change');

            const input = this.selectInput(field, 'entry') ?? this.selectInput(field);

            if (!Ghostwriter.FinishHelpers.openPicker(input)) {
                if ($select.length) $select.val(type).trigger('change');
                field?.querySelector('button, input, select, textarea')?.focus();

                return;
            }

            let picked = false;
            const selected = () => (picked = true);
            const hidden = () => {
                input.modal?.off('hide', hidden);
                input.off?.('selectElements', selected);

                if (picked) {
                    this.clearPlaceholderLabel(field);
                    chosen();
                } else if ($select.length && $select.val() !== type) {
                    $select.val(type).trigger('change');
                }
            };

            input.on?.('selectElements', selected);
            input.modal?.on('hide', hidden);
        },
    });
})();
