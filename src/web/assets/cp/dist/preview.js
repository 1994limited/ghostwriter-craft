/**
 * The writing panel's Preview tab: the draft rendered as the page it would
 * make, through the section's own template, with nothing saved (page
 * preview design §3, §7.3, §8, §12, §13).
 *
 * The server prepares a token URL and a map of the page's blocks; the page
 * loads into a sandboxed, same-origin frame; core's locator (locator.js,
 * copied from ghostwriter-core) finds each block by the invisible markers in
 * its text and strips them; an overlay in the frame outlines the block under
 * the pointer with its name, and follows the page as it resizes, scrolls and
 * loads its images and fonts. Links in the frame do nothing: Craft adds the
 * preview token to every one.
 *
 * The frame is as tall as its page and never scrolls itself: the draft's
 * column scrolls, through the layouts, the hint and the whole page
 * (framefit.js, shared with the Statamic addon, pins what the page sizes
 * from the window's height first, so a taller frame can't grow it).
 *
 * Gap markers the templates print as they are (`[[ask: …]]`, `[[check: …]]`,
 * `#gw-link:` links) are shown as chips once the locator has placed the
 * blocks (core's markers.js, copied as it is, beside this file). A chip is
 * a button (`onGap`): the panel opens a small popover at it, to resolve the
 * gap in the draft itself. The frame never changes the draft.
 *
 * Comments (setComments()): in comment mode a click on a block, or words
 * selected in one, is a pick (`onPick`) for the panel's comment box, and
 * nothing on the page reacts to it (links, gap chips). Numbered pins sit on
 * the blocks holding each comment's words in this render, whatever the
 * layout (comments.js, shared with the Statamic addon), at the words for a
 * comment on some; blocks a run changed carry a Changed mark. For the
 * keyboard each block has a target button in reading order (one Tab stop;
 * arrows move; Enter comments; Alt+Shift+M comments on selected words).
 */
(function () {
    window.Ghostwriter = window.Ghostwriter || {};

    // Where locator.js is, beside this file wherever Craft published it.
    const LOCATOR = new URL('locator.js', document.currentScript?.src ?? window.location.href).toString();

    /** A new render waits this long after the last change (§12). */
    const DEBOUNCE = 800;

    /** A render that takes longer is abandoned (§12). */
    const TIMEOUT = 8000;

    /** The phone preview's width, and the width a desktop render is laid out at when the panel is narrower. */
    const PHONE = 390;
    const DESKTOP = 1280;
    const NARROW = 640;

    /** The shortest the page's frame gets, however short the panel. */
    const MIN_STAGE = 320;

    const t = (message, params) => (window.Craft?.t ? Craft.t('ghostwriter', message, params) : message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m));
    const esc = (text) => Ghostwriter.escape(text);

    let locatorModule = null;
    const locator = () => (locatorModule ??= (Ghostwriter.previewLocator?.() ?? import(LOCATOR)));

    // Sizing the frame to its page (framefit.js), beside this file too.
    const FRAMEFIT = new URL('framefit.js', document.currentScript?.src ?? window.location.href).toString();
    let framefitModule = null;
    const framefit = () => (framefitModule ??= (Ghostwriter.previewFrameFit?.() ?? import(FRAMEFIT)));

    // Placing comments' pins (comments.js), beside this file too.
    const COMMENTS = new URL('comments.js', document.currentScript?.src ?? window.location.href).toString();
    let commentsModule = null;
    Ghostwriter.commentHelpers = () => (commentsModule ??= (Ghostwriter.previewComments?.() ?? import(COMMENTS)));

    // Core's gap chips (markers.js), beside this file too. The Text tab's
    // extras and Finish this page use them as well.
    const MARKERS = new URL('markers.js', document.currentScript?.src ?? window.location.href).toString();
    let markersModule = null;
    Ghostwriter.gapMarkers = () => (markersModule ??= (Ghostwriter.previewMarkers?.() ?? import(MARKERS)).then((module) => {
        // For code that formats text as it draws (the extras list).
        Ghostwriter.gapMarkersLoaded = module;

        return module;
    }));

    /** The chips' words, translated. */
    Ghostwriter.gapLabels = () => ({
        ask: t('Only you know this: add it before publishing'),
        check: t('Counted from \':list\'. Check it before publishing'),
        link: t('Link to choose'),
        askSpoken: t('Fact to add:'),
        checkSpoken: t('Count to check:'),
        linkSpoken: t('(link to choose)'),
        askRow: t('Add: :hint'),
        checkRow: t('Check: :hint'),
        linkRow: t('Choose a link: :hint'),
    });

    /**
     * Helpers with no page, for the tests.
     */
    const Helpers = {
        /**
         * Which markers to use. A page-level field (the title) can be
         * printed outside the page's content too: Craft's preview puts the
         * unsaved entry in every query, so a new page's title is in the
         * nav. Inside the content area wins; failing that, anywhere but
         * the page's furniture.
         */
        pickMarks(marks, map, content, isFurniture) {
            const fields = new Set(map.filter((block) => block.kind === 'field').map((block) => block.key));
            const inContent = (mark) => content && (content === mark.element || content.contains?.(mark.element));
            const byKey = new Map();

            marks.forEach((mark) => {
                if (fields.has(mark.key)) {
                    byKey.set(mark.key, [...(byKey.get(mark.key) ?? []), mark]);
                }
            });

            const dropped = new Set();

            byKey.forEach((list) => {
                const inside = list.filter(inContent);
                const keep = inside.length ? inside : list.filter((mark) => !isFurniture(mark.element));
                list.filter((mark) => !keep.includes(mark)).forEach((mark) => dropped.add(mark));
            });

            return marks.filter((mark) => !dropped.has(mark));
        },

        /**
         * What a loaded frame holds: the page, a refusal (§8.6), or the
         * render's own error page, with its reason.
         */
        readFrame(doc, status) {
            if (!doc || !doc.body || !doc.body.childNodes || doc.body.childNodes.length === 0) {
                return { ok: false, kind: 'refused' };
            }

            const error = doc.body.getAttribute?.('data-ghostwriter-preview-error');

            if (error !== null && error !== undefined) {
                return {
                    ok: false,
                    kind: 'error',
                    message: error,
                    detail: [doc.body.getAttribute('data-ghostwriter-preview-class'), doc.body.getAttribute('data-ghostwriter-preview-template')].filter(Boolean).join(' · '),
                };
            }

            if (status >= 400) {
                return { ok: false, kind: 'error', message: '', detail: String(status) };
            }

            return { ok: true };
        },

        /** The innermost region an element is in. */
        regionAt(regions, depth, element) {
            let best = null;

            for (const region of regions) {
                if (region.elements.some((el) => el === element || el.contains?.(element)) && (!best || depth(region) > depth(best))) {
                    best = region;
                }
            }

            return best;
        },

        /** A region's label: "Text", or "Why waiting rooms · in Text" for a block inside another. */
        label(region, byKey) {
            const parent = region.parent ? byKey[region.parent] : null;

            return parent ? t('{label} · in {parent}', { label: region.label || region.key, parent: parent.label || parent.key }) : (region.label || region.key);
        },

        /** How a desktop render fits a narrow panel. */
        fit(width, stageWidth) {
            if (width === 'phone') {
                return { width: Math.min(PHONE, stageWidth), scale: 1 };
            }

            return stageWidth < NARROW ? { width: DESKTOP, scale: stageWidth / DESKTOP } : { width: stageWidth, scale: 1 };
        },

        /**
         * The window the page is laid out for: the draft's scrolling pane,
         * less the frame's bar and padding. The frame is then as tall as
         * the page (framefit.js); this is its height until it's measured.
         */
        stageHeight(available, chrome) {
            return Math.max(MIN_STAGE, Math.floor((available || 0) - (chrome || 0)));
        },
    };

    Ghostwriter.PreviewHelpers = Helpers;

    const FURNITURE = 'header, footer, nav, aside, [role=banner], [role=contentinfo], [role=navigation], [role=complementary]';

    class PagePreview {
        /**
         * @param {object} options
         * @param {() => ({id: string, elementId: number, siteId: number})} options.target What to render.
         * @param {(view: string) => void} options.showBlocks
         * @param {(text: string) => void} options.announce
         * @param {(chip: object) => void} [options.onGap] A gap chip clicked (or Enter on it): {kind, hint, list?, value?, match, occurrence, element, frame}.
         * @param {() => void} [options.rendered] A new render is showing, its chips placed.
         * @param {(pick: object) => void} [options.onPick] Comment mode: a block clicked, or words selected: {key, label, units, path, planPath, kind, quote, rect, keyboard}.
         * @param {(number: number) => void} [options.onPin] A pin clicked.
         * @param {() => void} [options.onEscape] Esc in comment mode.
         */
        constructor(options) {
            this.options = options;
            this.width = 'desktop';
            this.frame = null;
            this.pending = null;
            this.timer = null;
            this.slow = null;
            this.sequence = 0;
            this.rendered = null;
            this.wanted = null;
            this.overlay = null;
            this.timing = {};
            // Comment mode and the pins to show ({number, status, kind, units, quote, label, path}).
            this.comments = { on: false, pins: [], picked: null, flash: null };

            this.root = document.createElement('div');
            this.root.className = 'gw-page';
            this.root.hidden = true;
            this.root.innerHTML = `
                <p class="gw-page__hint light" data-hint>${esc(t('Rendered with the site’s own templates. Hover to see the blocks. Nothing is saved until you use the draft.'))}</p>
                <div class="gw-page__window" role="region" aria-label="${esc(t('Page preview'))}" aria-busy="false" data-window>
                    <div class="gw-page__bar">
                        <span class="gw-page__address" data-address></span>
                        <span class="gw-page__status" data-status hidden><span class="gw-page__busy" aria-hidden="true"></span>${esc(t('Updating preview…'))}</span>
                        <span class="gw-page__tag">${esc(t('Preview · not saved'))}</span>
                    </div>
                    <div class="gw-page__stage" data-stage>
                        <div class="gw-page__message" data-message hidden></div>
                    </div>
                </div>`;
            this.stage = this.root.querySelector('[data-stage]');
            this.bar = this.root.querySelector('.gw-page__bar');
            this.message = this.root.querySelector('[data-message]');

            this.resizer = new ResizeObserver(() => this.fit());
            this.resizer.observe(this.stage);
            this.scroller = null;
            // The window's height the page is laid out for, in the CP's px.
            this.pane = MIN_STAGE;
        }

        /**
         * Shows the tab, rendering the draft if it changed since the last
         * render, unless a render is on its way or Ghostwriter is still
         * working on it (the last render stays until then).
         */
        show(draft, paused = false) {
            this.root.hidden = false;
            this.wanted = draft;
            this.fit();
            // Shown again: its page may have changed size while hidden.
            this.frame?.ghostwriterFit?.measure();
            this.sizeStage();

            if (draft && draft !== this.rendered && !this.pending && !this.timer && !(paused && this.frame)) {
                this.render(draft);
            }
        }

        /** Another piece: forget this one's render. */
        reset() {
            clearTimeout(this.timer);
            this.timer = null;
            this.sequence += 1;
            this.rendered = null;
            this.pending?.remove();
            this.pending = null;
            this.overlay?.stop();
            this.overlay = null;
            this.drop(this.frame);
            this.frame = null;
            this.message.hidden = true;
            this.address('');
            this.busy(false, false);
        }

        hide() {
            this.root.hidden = true;
            clearTimeout(this.timer);
            this.timer = null;
        }

        get visible() {
            return !this.root.hidden;
        }

        /**
         * The draft changed. While the tab shows, render again once the
         * changes stop (§12); otherwise when it's next shown.
         */
        changed(draft, immediately = false) {
            this.wanted = draft;
            clearTimeout(this.timer);
            this.timer = null;

            if (!this.visible || draft === this.rendered) {
                return;
            }

            this.timer = setTimeout(() => {
                this.timer = null;
                this.render(this.wanted);
            }, immediately ? 0 : DEBOUNCE);
        }

        setWidth(width) {
            this.width = width === 'phone' ? 'phone' : 'desktop';
            this.fit();
        }

        destroy() {
            clearTimeout(this.timer);
            clearTimeout(this.slow);
            this.resizer.disconnect();
            this.overlay?.stop();
            this.root.remove();
        }

        // ---- Rendering ----------------------------------------------------

        async render(draft) {
            const sequence = ++this.sequence;
            const target = this.options.target();

            this.busy(true);

            let data;
            const started = performance.now();

            try {
                const response = await Craft.sendActionRequest('POST', 'ghostwriter/preview/prepare', { data: { id: target.id, elementId: target.elementId, siteId: target.siteId } });
                data = response.data;
            } catch (error) {
                if (sequence !== this.sequence) return;

                this.rendered = draft;

                return this.fail({ kind: 'error', message: error?.response?.data?.message ?? '' });
            }

            if (sequence !== this.sequence) return;

            this.timing = { prepare: Math.round(performance.now() - started), prepareServer: data.ms, reused: data.reused };

            if (!data.preview) {
                this.rendered = draft;

                return this.fail({ kind: 'unavailable', message: data.message });
            }

            this.load(data, draft, sequence);
        }

        /**
         * The next render loads in a hidden frame and replaces the shown one
         * once it has loaded (double buffering, §12).
         */
        load(data, draft, sequence) {
            this.pending?.remove();

            const frame = document.createElement('iframe');
            frame.className = 'gw-page__frame is-loading';
            frame.title = t('Preview of the draft as a page');
            // Same origin so the blocks can be found, scripts for the site's
            // own; no forms, popups, modals or top navigation (§13).
            frame.setAttribute('sandbox', 'allow-same-origin allow-scripts');
            frame.setAttribute('referrerpolicy', 'no-referrer');
            frame.setAttribute('allow', '');
            frame.tabIndex = -1;
            // Tabbing into the page in comment mode lands on its first block, not its links.
            frame.addEventListener('focus', () => {
                if (this.comments.on && frame === this.frame) setTimeout(() => this.overlay?.focusTarget?.(), 0);
            });
            this.pending = frame;

            const loadStarted = performance.now();

            clearTimeout(this.slow);
            this.slow = setTimeout(() => {
                if (this.pending !== frame) return;

                frame.remove();
                this.pending = null;
                this.rendered = draft;
                this.fail(this.frame
                    ? { kind: 'slow', message: t('This page is slow to render; showing the last version.') }
                    : { kind: 'error', message: t('The page took too long to render.') });
            }, TIMEOUT);

            frame.addEventListener('load', () => {
                if (this.pending !== frame || sequence !== this.sequence) return;

                clearTimeout(this.slow);
                this.pending = null;
                this.rendered = draft;

                let doc = null;
                let status = 0;

                try {
                    doc = frame.contentDocument;
                    const navigation = frame.contentWindow.performance.getEntriesByType('navigation')[0];
                    status = navigation?.responseStatus ?? 0;
                    this.timing.render = navigation?.serverTiming?.find((entry) => entry.name === 'render')?.duration ?? null;
                } catch (error) {}

                this.timing.load = Math.round(performance.now() - loadStarted);

                const read = Helpers.readFrame(doc, status);

                if (!read.ok) {
                    frame.remove();

                    return this.fail(read);
                }

                // As tall as its page, measured before it swaps in, so the column keeps its place.
                framefit().then(({ fitFrame }) => {
                    frame.ghostwriterFit = fitFrame(frame, { viewport: this.viewport(), onHeight: () => frame === this.frame && this.sizeStage() });
                }).catch((error) => console.warn("Ghostwriter: the preview's frame couldn't be fitted to its page.", error)).finally(() => {
                    if (sequence !== this.sequence) return this.drop(frame);

                    this.swap(frame, data, sequence);
                });
            });

            frame.src = data.url;
            this.stage.appendChild(frame);
            this.fit();
        }

        swap(frame, data, sequence = 0) {
            const old = this.frame;

            this.overlay?.stop();
            this.drop(old);

            // The column keeps its scroll: the new page is already its own height.
            this.frame = frame;
            frame.tabIndex = this.comments.on ? 0 : -1;
            frame.classList.remove('is-loading');
            this.message.hidden = true;
            this.address(data.url);
            this.fit();

            const overlay = new Overlay(frame, data.map, (text) => this.note(text), (found) => !this.comments.on && this.options.onGap?.(found), {
                onPick: (pick) => this.pick(pick, data.map),
                onPin: (number) => this.options.onPin?.(number),
                onEscape: () => this.options.onEscape?.(),
                reveal: (top, smooth) => this.reveal(top, smooth),
            });
            this.overlay = overlay;
            overlay.scale = this.scale ?? 1;

            // Done once the blocks are found (or couldn't be: the page still shows).
            overlay.start().then(() => {
                if (this.overlay === overlay) this.postOutline(overlay.outline, sequence);
                this.root.dataset.located = String(overlay.located);
                this.root.dataset.missing = overlay.missing.join(' ');
                this.root.dataset.timing = JSON.stringify(this.timing);
                if (this.overlay === overlay) {
                    this.drawComments();
                    this.applySwitch(overlay, sequence);
                    this.options.rendered?.();
                }
            }, (error) => console.warn("Ghostwriter: the preview's blocks couldn't be found.", error)).finally(() => {
                if (this.overlay === overlay && !this.pending) this.busy(false);
            });
        }

        /**
         * The page's headings to the server, which keeps how the template
         * prints them per entry type (core's Seo\\RenderProfile). When that
         * changes, the draft's headings are fitted again: render once more.
         */
        async postOutline(headings, sequence) {
            if (!Array.isArray(headings)) return;

            const target = this.options.target();

            try {
                const { data } = await Craft.sendActionRequest('POST', 'ghostwriter/preview/outline', { data: { id: target.id, siteId: target.siteId, outline: headings.slice(0, 200) } });

                if (data?.changed && this.refitted !== sequence && sequence === this.sequence) {
                    this.refitted = sequence;
                    this.rendered = null;
                    this.render(this.wanted);
                }
            } catch (error) {
                // Only the profile is missed: the next render posts again.
            }
        }

        // ---- Comments -----------------------------------------------------

        /** Comment mode, and the pins to show. */
        setComments(on, pins) {
            this.comments.on = Boolean(on);
            this.comments.pins = pins ?? [];
            this.note('');
            // In comment mode the page's blocks are keyboard targets: the frame joins the Tab order.
            if (this.frame) this.frame.tabIndex = on ? 0 : -1;
            this.drawComments();
        }

        setPicked(key) {
            this.comments.picked = key;
            this.overlay?.setPicked?.(key);
        }

        /**
         * A layout just switched to (its `places`): once its render has
         * swapped in, the first block it changes is scrolled to in the
         * draft's column and every changed block outlined for a moment.
         * Only while the tab shows: a later visit isn't a switch.
         */
        switched(places) {
            this.switching = this.visible && places?.length ? { places, after: this.sequence } : null;
        }

        applySwitch(overlay, sequence) {
            if (!this.switching || sequence <= this.switching.after || overlay !== this.overlay || this.pending) return;

            const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

            overlay.highlight(Ghostwriter.LayoutHelpers?.keysForPlaces(overlay.map, this.switching.places) ?? [], { smooth: !reduce });
            this.switching = null;
        }

        /** The blocks of these comments flash once, on the render that shows their change. */
        flash(numbers) {
            this.comments.flash = numbers;
            this.drawComments();
        }

        async drawComments() {
            const overlay = this.overlay;

            if (!overlay?.ready) return;

            const { placePins, changedKeys } = await Ghostwriter.commentHelpers();
            const located = [...overlay.boxes.keys()].filter((key) => overlay.boxes.get(key));

            if (overlay !== this.overlay) return;

            overlay.setComments({ on: this.comments.on, pins: placePins(this.comments.pins, overlay.map, located), changed: changedKeys(this.comments.pins, overlay.map, located) });
            overlay.setPicked(this.comments.picked);

            if (this.comments.flash?.length && !this.pending) {
                const keys = changedKeys(this.comments.pins.filter((pin) => this.comments.flash.includes(pin.number)), overlay.map, located);

                if (keys.length) {
                    overlay.flash(keys);
                    this.comments.flash = null;
                }
            }
        }

        async pick(pick, map) {
            const { coverOf, planPaths } = await Ghostwriter.commentHelpers();
            const byKey = Object.fromEntries(map.map((block) => [block.key, block]));
            const places = planPaths(map);
            const region = this.overlay?.byKey?.[pick.key];

            this.options.onPick?.({
                ...pick,
                label: region ? Helpers.label(region, this.overlay.byKey) : (byKey[pick.key]?.label ?? ''),
                units: coverOf(pick.key, map),
                path: byKey[pick.key]?.path ?? null,
                planPath: places[pick.key] ?? places[byKey[pick.key]?.parent] ?? null,
                kind: pick.quote ? 'text' : 'block',
            });
        }

        /** "Show on page": scrolls to a comment's pin and focuses it. False when it has none here. */
        showComment(number) {
            return Boolean(this.overlay?.focusPin?.(number));
        }

        focusTarget(key = null) {
            this.overlay?.focusTarget?.(key);
        }

        /** Where a pin is, in the frame's document. */
        pinRect(number) {
            return this.overlay?.pinRect?.(number) ?? null;
        }

        /** A rect in the frame's document, in the CP page's coordinates now. */
        pageRect(rect) {
            const overlay = this.overlay;

            if (!overlay?.ready || !rect || !this.frame) return null;

            const view = overlay.toViewport(rect);
            const outer = this.frame.getBoundingClientRect();
            const scale = this.frame.offsetWidth ? outer.width / this.frame.offsetWidth : 1;

            return { left: outer.left + view.left * scale, top: outer.top + view.top * scale, right: outer.left + (view.left + view.width) * scale, bottom: outer.top + (view.top + view.height) * scale };
        }

        fail(state) {
            // The reason is announced instead.
            this.busy(false, false);

            // Too slow with a page showing: keep it, and say so.
            if (state.kind === 'slow') {
                this.note(state.message);
                this.options.announce?.(state.message);

                return;
            }

            this.overlay?.stop();
            this.overlay = null;
            this.drop(this.frame);
            this.frame = null;
            this.address('');
            this.sizeStage();

            let text;

            if (state.kind === 'unavailable') {
                text = state.message;
            } else if (state.kind === 'refused') {
                text = t('Your server stops pages showing in a frame, so the preview can’t show here.');
            } else {
                text = state.message ? t('The page template couldn’t render this draft: {reason}', { reason: state.message }) : t('The page template couldn’t render this draft.');
            }

            this.message.innerHTML = `<p>${esc(text)}</p>`
                + (state.kind === 'error' && state.detail ? `<p class="light gw-page__detail">${esc(state.detail)}</p>` : '')
                + `<button type="button" class="btn" data-action="view" data-view="blocks">${esc(t('Show blocks instead'))}</button>`;
            this.message.hidden = false;
            this.root.dataset.state = state.kind;
            this.options.announce?.(text);
        }

        note(text) {
            const hint = this.root.querySelector('[data-hint]');

            hint.textContent = text || (this.comments?.on
                ? t('Click a block, or select words in it, to comment. Tab moves to the blocks; arrows move between them; Enter comments.')
                : t('Rendered with the site’s own templates. Hover to see the blocks. Nothing is saved until you use the draft.'));
        }

        /**
         * "Updating preview…" in the address bar, only while a render is on
         * its way: from asking for it until its page has loaded and its
         * blocks are found, or it failed, or a newer one replaced it.
         */
        busy(on, announce = true) {
            const was = this.root.classList.contains('is-rendering');

            this.root.querySelector('[data-status]').hidden = !on;
            this.root.querySelector('[data-window]').setAttribute('aria-busy', on ? 'true' : 'false');
            this.root.classList.toggle('is-rendering', on);

            if (on) {
                delete this.root.dataset.state;
                this.note('');
            }

            if (on !== was && announce) {
                this.options.announce?.(on ? t('Updating preview…') : t('Preview updated.'));
            }
        }

        address(url) {
            let text = '';

            try {
                const parsed = new URL(url);
                text = parsed.host + parsed.pathname;
            } catch (error) {}

            this.root.querySelector('[data-address]').textContent = text;
        }

        /** A frame gone: it stops following its page. */
        drop(frame) {
            frame?.ghostwriterFit?.stop();
            frame?.remove();
        }

        /** The window's height the page is laid out for, in the frame's own px. */
        viewport() {
            return Math.round(this.pane / (this.scale || 1));
        }

        /** The stage is as tall as the page on show, scaled; the pane's height until there is one. */
        sizeStage() {
            const fitted = this.frame?.ghostwriterFit?.height;
            const height = fitted ? Math.ceil(fitted * (this.scale || 1)) : this.pane;

            if (this.stage.style && this.stage.style.height !== `${height}px`) this.stage.style.height = `${height}px`;
        }

        /**
         * A point of the page (its y, in the frame's px) to the top of the
         * draft's pane, which scrolls; the frame doesn't.
         */
        reveal(top, smooth = false) {
            const frame = this.frame;

            if (!frame) return;

            if (!this.scroller) {
                try {
                    frame.contentWindow.scrollTo(0, top);
                } catch (error) {}

                return;
            }

            const y = Math.max(0, Math.round(frame.getBoundingClientRect().top - this.scroller.getBoundingClientRect().top + this.scroller.scrollTop + Math.max(0, top) * (this.scale || 1)));

            if (smooth) {
                this.scroller.scrollTo({ top: y, behavior: 'smooth' });
            } else {
                this.scroller.scrollTop = y;
            }
        }

        /**
         * Desktop fills the panel, laid out at 1280 px and scaled down when
         * the panel is narrow; Phone is 390 px wide. The page is laid out for
         * a window as tall as the draft's scrolling pane (Helpers.stageHeight),
         * and its frame is as tall as the page: the pane scrolls as one
         * column, through the page; the frame never scrolls.
         */
        fit() {
            const stageWidth = this.stage.clientWidth;

            if (!stageWidth) return;

            const scroller = this.root.closest?.('.gw-draft__scroll') ?? null;

            if (scroller !== this.scroller) {
                if (this.scroller) this.resizer.unobserve(this.scroller);
                if (scroller) this.resizer.observe(scroller);
                this.scroller = scroller;
            }

            const style = scroller ? getComputedStyle(this.root) : null;
            const chrome = (parseFloat(style?.paddingTop) || 0) + (parseFloat(style?.paddingBottom) || 0) + (this.bar?.offsetHeight ?? 0) + 2;
            this.pane = Helpers.stageHeight(scroller ? scroller.clientHeight : (window.innerHeight || 0) * 0.75, chrome);

            const { width, scale } = Helpers.fit(this.width, stageWidth);

            this.scale = scale;
            this.root.dataset.width = this.width;

            const viewport = this.viewport();

            this.stage.querySelectorAll('iframe').forEach((frame) => {
                frame.style.width = `${width}px`;
                frame.style.transform = scale === 1 ? '' : `scale(${scale})`;

                // Fitted: laid out again if the pane's height changed. Not yet: the pane's height.
                if (frame.ghostwriterFit) frame.ghostwriterFit.setViewport(viewport);
                else frame.style.height = `${viewport}px`;
            });

            this.sizeStage();
            this.overlay?.setScale?.(scale);
        }
    }

    /**
     * The outlines, drawn inside the frame in a closed shadow root that the
     * site's CSS and scripts can't reach (§8.5). Each block is outlined, with
     * its name, while the pointer is over it. Boxes are in document
     * coordinates, so they scroll with the page; they're measured again
     * when the page resizes or scrolls, and when its images and fonts load.
     */
    class Overlay {
        constructor(frame, map, note, onGap = null, comment = {}) {
            this.frame = frame;
            this.onGap = onGap;
            this.comment = comment;
            this.ready = false;
            this.commenting = { on: false, pins: [], changed: [], flashing: new Set(), switched: [], picked: null, target: null, focused: null };
            this.pinButtons = new Map();
            this.targetButtons = new Map();
            this.map = map ?? [];
            this.note = note;
            this.regions = [];
            this.byKey = {};
            this.boxes = new Map();
            this.hovered = null;
            this.located = 0;
            this.missing = [];
            this.gaps = [];
            this.gapCounts = {};
            this.stops = [];
            this.queued = false;
        }

        async start() {
            const doc = this.frame.contentDocument;
            const win = this.frame.contentWindow;
            const { findMarkers, locate, measure, watch, contentArea, outline } = await locator();
            const { markGaps, countByRegion } = await Ghostwriter.gapMarkers();
            const labels = Ghostwriter.gapLabels();

            this.measureBox = measure;
            this.doc = doc;
            this.win = win;

            // Links carry the preview token and would leave the preview: they
            // do nothing here (§13). Forms are stopped by the sandbox too.
            const cancel = (event) => {
                if (event.target?.closest?.('a[href], area[href]')) {
                    event.preventDefault();
                }
            };
            const { quoteOf, findQuote } = await Ghostwriter.commentHelpers();
            this.quoteOf = quoteOf;
            this.findQuote = findQuote;

            // Comment mode: a click or a selection is a pick, and nothing on the page reacts to it.
            const fromOverlay = (event) => event.target === this.host;
            this.listen(doc, 'click', (event) => {
                if (!this.commenting.on || fromOverlay(event)) return;

                event.preventDefault();
                event.stopPropagation();
            }, true);
            this.listen(doc, 'mouseup', (event) => {
                if (!this.commenting.on || fromOverlay(event) || event.button !== 0) return;
                if (this.pickSelection()) return;

                const region = Helpers.regionAt(this.regions.filter((candidate) => this.boxes.get(candidate.key)), (r) => this.depth(r), event.target);

                if (region) this.comment.onPick?.({ key: region.key, quote: null, rect: { left: event.clientX + win.scrollX, top: event.clientY + win.scrollY, width: 1, height: 1 }, keyboard: false });
            });
            // In comment mode the page's own links and fields take no focus: the blocks do.
            this.listen(doc, 'focusin', (event) => {
                if (this.commenting.on && !fromOverlay(event)) setTimeout(() => this.focusTarget(), 0);
            });
            this.listen(doc, 'keydown', (event) => {
                if (!this.commenting.on) return;

                if (event.altKey && event.shiftKey && event.code === 'KeyM') {
                    event.preventDefault();
                    this.pickSelection(true);
                } else if (event.key === 'Escape' && !fromOverlay(event)) {
                    this.comment.onEscape?.();
                }
            });
            this.listen(doc, 'click', cancel, true);
            this.listen(doc, 'auxclick', cancel, true);
            this.listen(doc, 'submit', (event) => event.preventDefault(), true);

            const content = contentArea(doc);
            const isFurniture = (element) => Boolean(element?.closest?.(FURNITURE));
            const found = findMarkers(doc).marks;
            let marks = Helpers.pickMarks(found, this.map, content === doc.body ? null : content, isFurniture);

            const place = () => {
                const result = locate(doc, this.map, { marks });

                // The page's headings, for how the template prints them (the SEO
                // layer's render profile): every marker counts here, the header's too.
                this.outline ??= outline ? outline(doc, this.map, { regions: result.regions, marks: found }) : null;

                this.regions = result.regions;
                this.byKey = result.byKey;
                this.located = result.regions.length;
                this.missing = result.missing;
                // Then the gap markers as chips, inside the regions just found.
                this.gaps = markGaps(doc, { labels, onActivate: this.onGap ? (found) => this.onGap({ ...found, frame: this.frame }) : null });
                this.gapCounts = countByRegion(result.regions, this.gaps);
                this.note(result.partial ? t('Some blocks couldn’t be matched on this page.') : '');
                this.measure();
            };

            place();

            // Scripts that add marked text later: find it, and place again.
            const watcher = watch(doc, (more) => {
                marks = [...marks, ...Helpers.pickMarks(more, this.map, content === doc.body ? null : content, isFurniture)];
                place();
            });
            this.stops.push(() => watcher.stop());

            this.draw();

            // Re-measure: size changes (Desktop/Phone, the panel), scrolling,
            // and images, frames and fonts as they load.
            const again = () => this.again();
            const observer = new win.ResizeObserver(again);
            observer.observe(doc.documentElement);
            this.regions.forEach((region) => region.elements.forEach((element) => observer.observe(element)));
            this.stops.push(() => observer.disconnect());
            this.listen(win, 'resize', again);
            this.listen(win, 'scroll', again, { passive: true });
            this.listen(doc, 'load', again, true);
            doc.fonts?.ready.then(again);
            this.listen(doc.fonts, 'loadingdone', again);

            // Hover: the innermost block under the pointer.
            this.listen(doc, 'mouseover', (event) => event.target !== this.host && this.hover(event.target));
            this.listen(doc.documentElement, 'mouseleave', () => this.hover(null));
            this.ready = true;
        }

        listen(target, type, handler, options) {
            if (!target?.addEventListener) return;

            target.addEventListener(type, handler, options);
            this.stops.push(() => target.removeEventListener(type, handler, options));
        }

        again() {
            if (this.queued) return;

            this.queued = true;
            (this.win.requestAnimationFrame ?? setTimeout).call(this.win, () => {
                this.queued = false;
                this.measure();
            });
        }

        measure() {
            this.boxes = new Map(this.regions.map((region) => [region.key, this.measureBox(region, this.win)]));
            this.paint();
            this.paintComments();
        }

        depth(region) {
            let depth = 0;

            for (let parent = region.parent; parent; parent = this.byKey[parent]?.parent) {
                depth += 1;
            }

            return depth;
        }

        hover(element) {
            const region = element ? Helpers.regionAt(this.regions, (r) => this.depth(r), element) : null;

            if (region === this.hovered) return;

            this.hovered = region;
            this.paint();
        }

        draw() {
            const doc = this.doc;

            this.host = doc.createElement('div');
            this.host.setAttribute('aria-hidden', 'true');
            this.host.setAttribute('data-ghostwriter-overlay', '');
            this.host.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;margin:0;padding:0;border:0;z-index:2147483647;pointer-events:none';

            const shadow = this.host.attachShadow({ mode: 'closed' });
            const style = doc.createElement('style');
            style.textContent = `
                :host { all: initial; }
                .outline { position: absolute; box-sizing: border-box; border: 2px solid #5b4cf0; border-radius: 3px; pointer-events: none; display: none; }
                .outline.child { border-style: dashed; }
                .outline.inside .label { bottom: auto; top: 2px; left: 2px; }
                .label { position: absolute; left: -2px; bottom: 100%; background: #5b4cf0; color: #fff; font: 500 11px/1.6 system-ui, -apple-system, sans-serif; padding: 0 7px; border-radius: 4px 4px 4px 0; white-space: nowrap; max-width: calc(100% - 8px); overflow: hidden; text-overflow: ellipsis; }
                @media (forced-colors: active) { .outline { border-color: Highlight; } .label { background: Highlight; color: HighlightText; forced-color-adjust: none; } }
            `;
            style.textContent += `
                .marks, .targets, .pins { position: absolute; left: 0; top: 0; width: 0; height: 0; }
                .pin { position: absolute; pointer-events: auto; box-sizing: border-box; width: calc(26px / var(--s, 1)); height: calc(26px / var(--s, 1)); margin: 0; padding: 0; border-radius: calc(13px / var(--s, 1)) calc(13px / var(--s, 1)) calc(13px / var(--s, 1)) calc(3px / var(--s, 1)); background: #f5a524; color: #1c1c20; border: calc(2px / var(--s, 1)) solid #fff; box-shadow: 0 calc(2px / var(--s, 1)) calc(6px / var(--s, 1)) rgba(0,0,0,.3); font: 600 calc(12px / var(--s, 1))/1 system-ui, -apple-system, sans-serif; cursor: pointer; display: flex; align-items: center; justify-content: center; }
                .pin.sending { background: #9a9aa5; color: #fff; }
                .pin.changed { background: #2f9e6b; color: #fff; }
                .pin.replied { background: #5b4cf0; color: #fff; }
                .pin.refused, .pin.skipped, .pin.failed { background: #fff; color: #b42318; border-color: #b42318; }
                .pin.focused, .pin:focus-visible { outline: calc(3px / var(--s, 1)) solid #5b4cf0; outline-offset: calc(1px / var(--s, 1)); }
                .changed { position: absolute; box-sizing: border-box; border: calc(2px / var(--s, 1)) solid #2f9e6b; border-radius: calc(3px / var(--s, 1)); pointer-events: none; }
                .changed .chip { position: absolute; left: calc(4px / var(--s, 1)); top: -10px; background: #2f9e6b; color: #fff; font: 600 calc(11px / var(--s, 1))/1.6 system-ui, -apple-system, sans-serif; padding: 0 calc(7px / var(--s, 1)); border-radius: calc(4px / var(--s, 1)); white-space: nowrap; }
                .changed.flash { animation: gw-flash 2.6s ease-out 1; }
                @keyframes gw-flash { 0% { box-shadow: inset 0 0 0 calc(3px / var(--s, 1)) #2f9e6b, 0 0 0 calc(8px / var(--s, 1)) rgba(47,158,107,.35); } 100% { box-shadow: inset 0 0 0 calc(3px / var(--s, 1)) rgba(47,158,107,0), 0 0 0 calc(8px / var(--s, 1)) rgba(47,158,107,0); } }
                @media (prefers-reduced-motion: reduce) { .changed.flash { animation: none; } }
                .switched { position: absolute; box-sizing: border-box; border-radius: calc(4px / var(--s, 1)); pointer-events: none; animation: gw-switched 2.2s ease-out 1 forwards; }
                @keyframes gw-switched { 0%, 30% { box-shadow: inset 0 0 0 calc(3px / var(--s, 1)) #5b4cf0, 0 0 0 calc(6px / var(--s, 1)) rgba(91,76,240,.18); background: rgba(91,76,240,.06); } 100% { box-shadow: inset 0 0 0 calc(3px / var(--s, 1)) rgba(91,76,240,0), 0 0 0 calc(6px / var(--s, 1)) rgba(91,76,240,0); background: rgba(91,76,240,0); } }
                @media (prefers-reduced-motion: reduce) { .switched { animation: none; box-shadow: inset 0 0 0 calc(3px / var(--s, 1)) #5b4cf0; } }
                @media (forced-colors: active) { .switched { forced-color-adjust: none; animation: none; box-shadow: inset 0 0 0 3px Highlight; } }
                .picked { position: absolute; box-sizing: border-box; border: calc(2px / var(--s, 1)) solid #5b4cf0; border-radius: calc(3px / var(--s, 1)); background: rgba(91,76,240,.06); pointer-events: none; }
                .target { position: absolute; box-sizing: border-box; margin: 0; padding: 0; background: transparent; border: 0; opacity: 0; pointer-events: none; }
                .target:focus { opacity: 1; outline: calc(3px / var(--s, 1)) solid #5b4cf0; outline-offset: -3px; }
                @media (forced-colors: active) { .pin, .changed, .picked { forced-color-adjust: none; border-color: Highlight; } .target:focus { outline-color: Highlight; } }
            `;
            this.outline = doc.createElement('div');
            this.outline.className = 'outline';
            this.outline.setAttribute('aria-hidden', 'true');
            this.labelEl = doc.createElement('div');
            this.labelEl.className = 'label';
            this.outline.appendChild(this.labelEl);
            this.marksEl = doc.createElement('div');
            this.marksEl.className = 'marks';
            this.marksEl.setAttribute('aria-hidden', 'true');
            this.targetsEl = doc.createElement('div');
            this.targetsEl.className = 'targets';
            this.pinsEl = doc.createElement('div');
            this.pinsEl.className = 'pins';
            shadow.append(style, this.marksEl, this.outline, this.targetsEl, this.pinsEl);
            // The page's cursor while commenting: a style in the frame's own document (the preview only).
            this.cursor = doc.createElement('style');
            this.cursor.textContent = 'html[data-gw-commenting], html[data-gw-commenting] * { cursor: crosshair !important; }';
            (doc.head ?? doc.documentElement).appendChild(this.cursor);
            this.stops.push(() => {
                this.cursor.remove();
                doc.documentElement.removeAttribute('data-gw-commenting');
            });
            doc.documentElement.appendChild(this.host);
            this.host.style.setProperty('--s', String(this.scale ?? 1));
            this.stops.push(() => this.host.remove());
        }

        paint() {
            if (!this.outline) return;

            const region = this.hovered;
            const box = region ? this.boxes.get(region.key) : null;

            if (!box) {
                this.outline.style.display = 'none';

                return;
            }

            this.outline.style.display = 'block';
            this.outline.style.left = `${box.left}px`;
            this.outline.style.top = `${box.top}px`;
            this.outline.style.width = `${box.width}px`;
            this.outline.style.height = `${box.height}px`;
            this.outline.classList.toggle('child', Boolean(region.parent));
            // The name sits above the block, or inside it at the very top of the page.
            this.outline.classList.toggle('inside', box.top < 20);
            // From the map: text, never markup.
            this.labelEl.textContent = this.commenting.on ? `${Helpers.label(region, this.byKey)} · ${t('click to comment')}` : Helpers.label(region, this.byKey);
        }

        // ---- Comments -----------------------------------------------------

        /** A frame shown scaled down keeps its pins and labels readable. */
        setScale(scale) {
            this.scale = scale > 0 ? scale : 1;
            this.host?.style.setProperty('--s', String(this.scale));
            this.paintComments();
        }

        setComments({ on, pins, changed }) {
            const was = this.commenting.on;

            this.commenting.on = Boolean(on);
            this.commenting.pins = pins ?? [];
            this.commenting.changed = changed ?? [];

            if (was !== this.commenting.on && this.host) {
                if (this.commenting.on) {
                    this.host.removeAttribute('aria-hidden');
                    this.doc.documentElement.setAttribute('data-gw-commenting', '');
                } else {
                    this.host.setAttribute('aria-hidden', 'true');
                    this.doc.documentElement.removeAttribute('data-gw-commenting');
                    this.commenting.picked = null;
                }

                this.paint();
            }

            this.paintComments();
        }

        setPicked(key) {
            this.commenting.picked = key ?? null;
            this.paintComments();
        }

        flash(keys) {
            keys.forEach((key) => this.commenting.flashing.add(key));
            this.paintComments();
            setTimeout(() => keys.forEach((key) => this.commenting.flashing.delete(key)), 2700);
        }

        /**
         * A layout just switched to: its changed blocks outlined, fading over
         * about two seconds (a still outline for that long under reduced
         * motion), and the first brought into view.
         */
        highlight(keys, { smooth = true } = {}) {
            clearTimeout(this.unswitch);
            this.commenting.switched = keys.filter((key) => this.boxes.get(key));
            this.paintComments();

            const first = this.commenting.switched.length ? this.boxes.get(this.commenting.switched[0]) : null;

            if (first) (this.comment.reveal ?? ((top) => this.win.scrollTo(0, top)))(Math.max(0, first.top - 60), smooth);

            this.unswitch = setTimeout(() => {
                this.commenting.switched = [];
                this.paintComments();
            }, 2200);

            return this.commenting.switched.length;
        }

        focusPin(number) {
            this.commenting.focused = number;
            this.paintComments();
            const button = this.pinButtons.get(String(number));

            if (!button) return false;

            const pin = this.commenting.pins.find((candidate) => candidate.number === number);
            const box = pin ? this.boxes.get(pin.key) : null;

            // The frame is as tall as its page: the pane around it scrolls (reveal).
            if (box) (this.comment.reveal ?? ((top) => this.win.scrollTo(0, top)))(Math.max(0, box.top - 60));
            button.focus({ preventScroll: true });

            return true;
        }

        focusTarget(key = null) {
            if (key) this.commenting.target = key;
            this.paintComments();
            this.targetButtons.get(this.commenting.target)?.focus();
        }

        pinRect(number) {
            const button = this.pinButtons.get(String(number));

            return button ? this.docRect(button.getBoundingClientRect()) : null;
        }

        toViewport(rect) {
            return { left: rect.left - this.win.scrollX, top: rect.top - this.win.scrollY, width: rect.width, height: rect.height };
        }

        docRect(rect) {
            return { left: rect.left + this.win.scrollX, top: rect.top + this.win.scrollY, width: rect.width, height: rect.height };
        }

        /** Words selected in one block: a pick with their quote. */
        pickSelection(keyboard = false) {
            const selection = this.win.getSelection?.();

            if (!selection || selection.isCollapsed || !selection.rangeCount) return false;

            const range = selection.getRangeAt(0);
            const text = range.toString();

            if (!text.trim()) return false;

            const start = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
            const region = Helpers.regionAt(this.regions.filter((candidate) => this.boxes.get(candidate.key)), (r) => this.depth(r), start);

            if (!region) return false;

            let before = '';
            let after = '';

            try {
                const head = this.doc.createRange();
                head.setStartBefore(region.elements[0]);
                head.setEnd(range.startContainer, range.startOffset);
                before = head.toString();
                const tail = this.doc.createRange();
                tail.setStart(range.endContainer, range.endOffset);
                tail.setEndAfter(region.elements[region.elements.length - 1]);
                after = tail.toString();
            } catch (error) {}

            const quote = this.quoteOf(text, before, after);

            if (!quote) return false;

            this.comment.onPick?.({ key: region.key, quote, rect: this.docRect(range.getBoundingClientRect()), keyboard });

            return true;
        }

        /** The words of a comment on some, found again in its block: their last line's box. */
        quoteRect(key, quote) {
            const region = this.byKey[key];

            if (!region || !quote) return null;

            const nodes = [];
            let text = '';

            for (const element of region.elements) {
                const walker = this.doc.createTreeWalker(element, 4);

                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    nodes.push({ node, start: text.length });
                    text += node.nodeValue;
                }
            }

            const found = this.findQuote(text, { exact: quote });

            if (!found) return null;

            const at = (offset) => {
                const hit = [...nodes].reverse().find((entry) => entry.start <= offset);

                return hit ? [hit.node, Math.min(offset - hit.start, hit.node.nodeValue.length)] : null;
            };

            try {
                const range = this.doc.createRange();
                const start = at(found.start);
                const end = at(found.end);

                if (!start || !end) return null;

                range.setStart(...start);
                range.setEnd(...end);
                const rects = [...range.getClientRects()].filter((rect) => rect.width > 0);
                const last = rects[rects.length - 1] ?? range.getBoundingClientRect();

                return last && last.height > 0 ? this.docRect(last) : null;
            } catch (error) {
                return null;
            }
        }

        paintComments() {
            if (!this.pinsEl || !this.doc) return;

            const place = (element, box) => Object.assign(element.style, { left: `${box.left}px`, top: `${box.top}px`, width: `${box.width}px`, height: `${box.height}px` });
            const label = (key) => (this.byKey[key] ? Helpers.label(this.byKey[key], this.byKey) : '');

            // The Changed marks and the picked block.
            this.marksEl.replaceChildren();

            for (const key of this.commenting.changed) {
                const box = this.boxes.get(key);

                if (!box) continue;

                const mark = this.doc.createElement('div');
                mark.className = this.commenting.flashing.has(key) ? 'changed flash' : 'changed';
                place(mark, box);
                const chip = this.doc.createElement('span');
                chip.className = 'chip';
                chip.textContent = t('Changed');
                mark.appendChild(chip);
                this.marksEl.appendChild(mark);
            }

            // A layout just switched to: what it changes, outlined for a moment.
            for (const key of this.commenting.switched) {
                const box = this.boxes.get(key);

                if (!box) continue;

                const mark = this.doc.createElement('div');
                mark.className = 'switched';
                place(mark, box);
                this.marksEl.appendChild(mark);
            }

            const picked = this.commenting.picked ? this.boxes.get(this.commenting.picked) : null;

            if (picked) {
                const element = this.doc.createElement('div');
                element.className = 'picked';
                place(element, picked);
                this.marksEl.appendChild(element);
            }

            // Pins, by number; kept between paints, so focus stays on one.
            const pins = new Map(this.commenting.pins.filter((pin) => this.boxes.get(pin.key)).map((pin) => [String(pin.number), pin]));

            for (const [id, button] of this.pinButtons) {
                if (!pins.has(id)) {
                    button.remove();
                    this.pinButtons.delete(id);
                }
            }

            const perBlock = {};

            for (const [id, pin] of pins) {
                let button = this.pinButtons.get(id);

                if (!button) {
                    button = this.doc.createElement('button');
                    button.type = 'button';
                    button.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        this.comment.onPin?.(Number(id));
                    });
                    button.addEventListener('keydown', (event) => event.key === 'Escape' && this.comment.onEscape?.());
                    this.pinButtons.set(id, button);
                    this.pinsEl.appendChild(button);
                }

                const box = this.boxes.get(pin.key);
                const unit = 1 / (this.scale ?? 1);
                const nth = (perBlock[pin.key] = (perBlock[pin.key] ?? -1) + 1);
                const words = pin.quote ? this.quoteRect(pin.key, pin.quote) : null;
                const left = words ? words.left + words.width + 2 * unit : box.left + box.width - (32 + nth * 30) * unit;
                const top = words ? words.top - 14 * unit : box.top + 6 * unit;
                const name = t('Comment {number}, {state}, on {label}', { number: pin.number, state: pin.state ?? '', label: pin.label || label(pin.key) });

                button.className = `pin ${pin.status}${this.commenting.focused === pin.number ? ' focused' : ''}`;
                button.textContent = String(pin.number);
                button.style.left = `${Math.max(0, left)}px`;
                button.style.top = `${Math.max(0, top)}px`;
                button.setAttribute('aria-label', name);
                button.title = name;
                button.tabIndex = this.commenting.on ? 0 : -1;
            }

            // The keyboard's targets: one per block on the page, in reading order, one Tab stop.
            const wanted = this.commenting.on ? this.regions.filter((region) => this.boxes.get(region.key)).map((region) => region.key) : [];

            for (const [key, button] of this.targetButtons) {
                if (!wanted.includes(key)) {
                    button.remove();
                    this.targetButtons.delete(key);
                }
            }

            if (!wanted.includes(this.commenting.target)) this.commenting.target = wanted[0] ?? null;

            for (const key of wanted) {
                let button = this.targetButtons.get(key);

                if (!button) {
                    button = this.doc.createElement('button');
                    button.type = 'button';
                    button.className = 'target';
                    button.addEventListener('focus', () => {
                        this.commenting.target = key;
                        this.hovered = this.byKey[key] ?? null;
                        this.paint();
                        this.paintComments();
                    });
                    button.addEventListener('blur', () => {
                        if (this.hovered?.key === key) {
                            this.hovered = null;
                            this.paint();
                        }
                    });
                    button.addEventListener('keydown', (event) => this.targetKey(event, key));
                    this.targetButtons.set(key, button);
                    this.targetsEl.appendChild(button);
                }

                const count = this.commenting.pins.filter((pin) => pin.key === key).length;

                place(button, this.boxes.get(key));
                button.tabIndex = key === this.commenting.target ? 0 : -1;
                button.setAttribute('aria-label', count ? t('{label} block, {count} comments. Add a comment.', { label: label(key), count }) : t('{label} block. Add a comment.', { label: label(key) }));
            }
        }

        targetKey(event, key) {
            const keys = [...this.targetButtons.keys()];
            const at = keys.indexOf(key);
            const moves = { ArrowDown: at + 1, ArrowRight: at + 1, ArrowUp: at - 1, ArrowLeft: at - 1, Home: 0, End: keys.length - 1 };

            if (event.key in moves) {
                event.preventDefault();
                const next = keys[Math.min(Math.max(moves[event.key], 0), keys.length - 1)];
                this.commenting.target = next;
                this.paintComments();
                this.targetButtons.get(next)?.focus();

                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                const box = this.boxes.get(key);

                if (box) this.comment.onPick?.({ key, quote: null, rect: { ...box, height: Math.min(box.height, 40) }, keyboard: true });

                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                this.comment.onEscape?.();
            }
        }

        stop() {
            this.stops.splice(0).forEach((stop) => {
                try {
                    stop();
                } catch (error) {}
            });
        }
    }

    Ghostwriter.PagePreview = PagePreview;
})();
