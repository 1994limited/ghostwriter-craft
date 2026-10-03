/**
 * "Finish this page" on an entry's edit screen (the finish-this-page
 * design, §7 and §8.2): what an editor must still finish in the entry,
 * highlighted in the form, with a guide that walks through it.
 *
 *   the pill    beside the entry's buttons: "5 things to finish", then
 *               "Ready to publish"
 *   highlights  amber outlines with numbered tags on each field; the
 *               current one purple, fixed ones green. In CKEditor, the
 *               marker itself, through CKEditor's own markers
 *   the guide   bottom right: the step, its fixes, Back / Skip / Next
 *   the dock    the mark in a round button, with the count, when the
 *               guide is minimised
 *   the mark    flies to the current field with a short speech label
 *
 * What is unfinished comes from the server (ghostwriter/gaps/check), which
 * reads the entry as the form has it: Craft's draft or provisional draft,
 * so nothing is posted. It looks again after each autosave. Fixes write
 * into the form, never save: Craft's element editor autosaves the draft as
 * it does for any typing. Animations are all off under reduced motion.
 */
(function () {
    window.Ghostwriter = window.Ghostwriter || {};

    const t = (message, params) => Craft.t('ghostwriter', message, params);
    const esc = (text) => Ghostwriter.escape(text);
    const ENTRY = 'craft\\elements\\Entry';
    const PHONE = '(max-width: 639px)';
    const REDUCED = '(prefers-reduced-motion: reduce)';
    const FLIGHT = 900;
    const ANSWER_KINDS = ['ask'];
    const INLINE_KINDS = ['ask', 'link', 'link-broken', 'leftover-token', 'placeholder-text', 'image-placeholder', 'stock-preview'];

    /** A regular expression from core's patterns.json entry. */
    const pattern = (entry) => (entry ? new RegExp(entry.source, entry.flags.includes('g') ? entry.flags : entry.flags + 'g') : null);

    /**
     * What can be worked out without the page, kept apart so it can be
     * tested on its own (tests/js).
     */
    Ghostwriter.FinishHelpers = {
        /*
         * The guide's state, kept apart from the page so it can be tested,
         * as the Statamic addon keeps it (resources/js/finish/state.js):
         * one live list of gaps, straight from the latest check, drives the
         * count by Save, the guide's "2 of 5", its bar and every field's
         * highlight.
         *
         * - Steps are the gaps the last check found, nothing else: a gap
         *   that has gone is no longer a step, and a fix that makes a new
         *   gap (a placeholder swapped for a stock preview) gives a new,
         *   open step.
         * - Gaps that count (they block, or Craft requires them) come first;
         *   suggestions follow and are numbered separately.
         * - A field is "fixed" only when it had gaps in this view and the
         *   last check found none left in it.
         */

        isSuggestion(gap) {
            return gap.severity === 'suggestion';
        },

        /** The steps for a new check: the live gaps, counted ones first, each open or skipped. */
        stepsFrom(gaps, { skipped = new Set(), dismissed = new Set() } = {}) {
            const live = gaps.filter((gap) => !dismissed.has(gap.id));
            const counted = live.filter((gap) => !this.isSuggestion(gap));
            const suggested = live.filter((gap) => this.isSuggestion(gap));

            return [...counted, ...suggested].map((gap) => ({ gap, status: skipped.has(gap.id) ? 'skipped' : 'open' }));
        },

        /**
         * Where the guide stands after a new check: on the same gap if it is
         * still there; otherwise on whatever now sits at its place (the next
         * gap moved up), or the first open one.
         */
        currentAfter(steps, previousId, previousIndex) {
            const same = steps.findIndex((step) => step.gap.id === previousId);

            if (same >= 0) return same;
            if (previousIndex < steps.length) return Math.max(0, previousIndex);

            return this.firstOpen(steps);
        },

        firstOpen(steps) {
            const open = steps.findIndex((step) => step.status === 'open');

            return open >= 0 ? open : steps.length;
        },

        /** The next open step from `from`, coming round to the start; the end when none is open. */
        nextOpen(steps, from) {
            for (let i = from; i < steps.length; i++) {
                if (steps[i].status === 'open') return i;
            }

            for (let i = 0; i < Math.min(from, steps.length); i++) {
                if (steps[i].status === 'open') return i;
            }

            return steps.length;
        },

        /**
         * The numbers every part of the guide shows, from the one live list:
         * `count` for the pill and the dock (what blocks or is required and
         * isn't done), `total` for "n of total", and the step's own number
         * (suggestions numbered on their own).
         */
        counts(steps, index) {
            const counted = steps.filter((step) => !this.isSuggestion(step.gap));
            const suggestions = steps.length - counted.length;
            const step = steps[index];
            const suggestion = step ? this.isSuggestion(step.gap) : false;

            return {
                count: counted.filter((step) => step.status !== 'fixed').length,
                total: counted.length,
                suggestions,
                number: step ? (suggestion ? index - counted.length + 1 : index + 1) : 0,
                suggestion,
                left: counted.filter((step) => step.status !== 'fixed').length,
            };
        },

        /**
         * Each field's highlight and tag, by its form path: `current` when
         * the guide is on one of its gaps, else `open` with its steps;
         * `fixed` for a field that had gaps in this view and has none left.
         *
         * @return {Map<string, {state: string, steps: number[], step: object|null}>}
         */
        fieldStates(steps, index, touched = new Set()) {
            const fields = new Map();

            steps.forEach((step, i) => {
                const key = step.gap.dotted;
                const field = fields.get(key) ?? { state: 'open', steps: [], step: null };

                field.steps.push(i);

                if (i === index) {
                    field.state = 'current';
                    field.step = step;
                } else if (!field.step) {
                    field.step = step;
                }

                fields.set(key, field);
            });

            touched.forEach((key) => {
                if (!fields.has(key)) fields.set(key, { state: 'fixed', steps: [], step: null });
            });

            return fields;
        },

        /** A field's tag: "7 · License me" when current, "4 · Fill this in" or "4–6 · 3 to do" otherwise. */
        tagText(field, steps, t) {
            const say = t ?? ((message, params) => message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m));

            if (field.state === 'fixed') return say('Fixed ✓');

            const numbers = field.steps.map((i) => (this.isSuggestion(steps[i].gap) ? null : i + 1)).filter((n) => n !== null);
            const speech = field.step?.gap.speech ?? '';

            if (field.state === 'current' || numbers.length <= 1) {
                const n = field.state === 'current' ? field.steps.find((i) => steps[i] === field.step) + 1 : numbers[0];

                return n && !this.isSuggestion(field.step.gap) ? `${n} · ${speech}` : speech;
            }

            return `${numbers[0]}–${numbers[numbers.length - 1]} · ${say('{count} to do', { count: numbers.length })}`;
        },

        /**
         * A gap's identity in this view: kind, place in the form, hint and
         * which one. Craft gives a provisional draft's Matrix entries and
         * Neo blocks new IDs, which core's gap IDs include; this doesn't
         * change with them.
         */
        key(gap) {
            return [gap.kind, gap.dotted ?? gap.path, String(gap.hint ?? '').toLowerCase().replace(/\s+/g, ' ').trim(), gap.occurrence ?? 0].join('|');
        },

        /**
         * A link as CKEditor holds it in Craft: a reference tag
         * (`{entry:41@1:url||https://…}`) becomes `https://…#entry:41@1:url`.
         */
        editorHref(value, siteId) {
            const match = /^\{(entry|asset|category):(\d+)(?:@(\d+))?:url(?:\|\|(.*))?\}$/.exec(String(value ?? ''));

            return match ? `${match[4] ?? ''}#${match[1]}:${match[2]}@${match[3] ?? siteId}:url` : String(value ?? '');
        },

        /** Whether CKEditor's href points where a stored link (a reference tag or address) does. */
        sameTarget(href, stored) {
            const ref = /\{(entry|asset|category):(\d+)/.exec(String(stored ?? ''));

            return ref ? new RegExp(`#${ref[1]}:${ref[2]}(?:@\\d+)?:`).test(href) || href === stored : href === stored;
        },

        /**
         * Open Craft's own element picker for a field's element select
         * input. A field that is full (an image field holding the
         * placeholder) can't take another, so Craft hides its add button;
         * the chosen element replaces the given one (or the last), as
         * Craft's own "Replace" does. False when the field takes nothing
         * (read-only), so the fix isn't offered.
         */
        openPicker(input, replaceId = null) {
            if (!this.canPick(input)) return false;

            let $chip = replaceId === null || replaceId === undefined ? input.$elements.filter(() => false) : input.$elements.filter((i, element) => String(element.getAttribute('data-id')) === String(replaceId));

            if (!$chip.length && !input.canAddMoreElements()) {
                $chip = input.$elements.last();
            }

            if ($chip.length) {
                input._$replaceElement = $chip;
            }

            input.showModal();

            return true;
        },

        canPick(input) {
            return Boolean(input && typeof input.showModal === 'function' && input.settings?.allowAdd !== false);
        },
    };

    Ghostwriter.Finish = Garnish.Base.extend({
        init(config) {
            this.config = config;
            this.strings = config.strings ?? {};
            this.gaps = [];
            this.steps = [];
            this.index = 0;
            this.touched = new Map();
            this.done = false;
            this.shown = false;
            this.minimised = config.state !== 'open';
            this.checking = null;
            this.again = false;
            this.lastCount = null;
            this.editors = new Map();
            this.fieldsMarked = [];
            this.reduced = window.matchMedia(REDUCED).matches;
            this.phone = window.matchMedia(PHONE).matches;
            this.dismissed = this.loadDismissed();
            this.patterns = {
                ask: pattern(config.patterns?.ask),
                leftover: pattern(config.patterns?.leftover),
                placeholder: Object.values(config.patterns?.placeholderText ?? {}).map(pattern),
            };

            $(() => this.start());
        },

        start() {
            this.$pill = $('#gw-finish-pill');
            this.build();

            const reduce = window.matchMedia(REDUCED);
            const phone = window.matchMedia(PHONE);
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

            this.addListener(this.$pill, 'click', () => {
                this.minimise(false);
                this.go(this.firstOpen());
            });

            // Craft's element editor autosaves the draft as the form changes;
            // each time, look again at what is left.
            const editor = this.editor();

            if (editor) {
                editor.on('update', () => this.check());
                editor.on('createProvisionalDraft', () => this.check());
            }

            // Highlights in text follow typing straight away; the server
            // check follows the autosave.
            this.addListener(Garnish.$doc, 'keydown', 'keys');
            this.addListener(Garnish.$win, 'resize', () => this.placeFlyer());
            document.addEventListener('scroll', () => this.placeFlyer(), true);

            // Measured again when the form moves under it: a banner appearing,
            // a block expanding, CKEditor growing.
            const form = Craft.cp?.$primaryForm?.[0];

            if (form) {
                new ResizeObserver(() => this.placeFlyer()).observe(form);
                new MutationObserver((records) => {
                    if (records.some((record) => !(record.target instanceof Element) || !record.target.closest('.gw-gap-tag'))) this.placeFlyer();
                }).observe(form, { childList: true, subtree: true });
            }

            // A draft just put into the form: the guide opens, whatever was remembered.
            const openForDraft = () => {
                if (this.config.openAfterDraft) {
                    this.forceOpen = true;
                }
            };

            if (Ghostwriter.draftApplied) openForDraft();
            document.addEventListener('ghostwriter:draft-applied', (event) => {
                if (event.detail?.finish) openForDraft();
            });

            this.$root.toggleClass('gw-finish--minimised', this.minimised);
            this.check();
        },

        /** Craft's element editor for the page's form. */
        editor() {
            return Craft.cp?.$primaryForm?.data('elementEditor') ?? null;
        },

        /** The element the form is editing now: the provisional draft once there is one. */
        elementId() {
            return this.editor()?.settings?.elementId ?? this.config.elementId;
        },

        /* ------------------------------------------------------------------
         * What is left
         * ------------------------------------------------------------------ */

        async check() {
            if (this.checking) {
                this.again = true;

                return this.checking;
            }

            this.checking = (async () => {
                try {
                    const { data } = await Craft.sendActionRequest('GET', 'ghostwriter/gaps/check', {
                        params: { elementId: this.elementId(), siteId: this.config.siteId },
                    });

                    this.receive(data);
                } catch (error) {
                    // Nothing to show is better than a broken page.
                } finally {
                    this.checking = null;

                    if (this.again) {
                        this.again = false;
                        this.check();
                    }
                }
            })();

            return this.checking;
        },

        receive(data) {
            const H = Ghostwriter.FinishHelpers;
            const previousId = this.steps[this.index]?.gap.id;
            const previousIndex = this.index;

            this.mode = data.mode;
            this.gaps = (data.gaps ?? []).map((gap) => ({ ...gap, serverId: gap.id, id: H.key(gap) }));
            this.steps = H.stepsFrom(this.gaps, { skipped: this.skippedSet(), dismissed: new Set(this.dismissed) });
            this.gaps.forEach((gap) => this.touched.set(gap.dotted, gap));
            this.index = this.started ? H.currentAfter(this.steps, previousId, previousIndex) : H.firstOpen(this.steps);
            this.done = this.index >= this.steps.length;

            const count = this.count();

            // On load, only when something blocks publishing, so a new,
            // empty entry isn't nagged about its title. Once shown, it
            // stays for this view (and says "Ready to publish").
            if (this.gaps.some((gap) => gap.severity === 'blocks') || this.forceOpen) {
                this.shown = true;
            }

            this.paint();

            if (this.forceOpen && this.shown && this.steps.length) {
                this.forceOpen = false;
                this.minimise(false, false);
                this.go(H.firstOpen(this.steps));
            } else if (!this.started && !this.minimised && this.shown && this.steps.length) {
                this.go(this.index, { quiet: true });
            }

            this.started = true;

            if (this.lastCount !== null && count !== this.lastCount) {
                this.bump();
                this.announce(count ? this.countText(count) : this.strings.ready);
            }

            this.lastCount = count;
        },

        /** The pill's number: what blocks or Craft requires, not yet done. */
        count() {
            return Ghostwriter.FinishHelpers.counts(this.steps, this.index).count;
        },

        countText(count) {
            return count === 1 ? this.strings.countOne : (this.strings.count ?? '').replace('{count}', count);
        },

        firstOpen() {
            return Ghostwriter.FinishHelpers.firstOpen(this.steps);
        },

        /* ------------------------------------------------------------------
         * Drawing
         * ------------------------------------------------------------------ */

        build() {
            const icon = this.config.icon ?? '';

            this.$root = $('<div class="gw-finish"/>').appendTo(Garnish.$bod);
            this.$live = $('<div class="visually-hidden" role="status" aria-live="polite"/>').appendTo(this.$root);

            this.$guide = $(`
                <section class="gw-finish-guide" role="region" aria-label="${esc(this.strings.title)}" aria-describedby="gw-finish-keys">
                    <div class="gw-finish-guide__handle" aria-hidden="true"></div>
                    <header class="gw-finish-guide__head">
                        <span class="gw-finish-guide__mark" aria-hidden="true">${icon}</span>
                        <h2 class="gw-finish-guide__title">${esc(this.strings.title)}</h2>
                        <span class="gw-finish-guide__step light"></span>
                        <button type="button" class="gw-finish-guide__min" aria-label="${esc(t('Minimise'))}" title="${esc(t('Minimise'))}">&#8212;</button>
                    </header>
                    <div class="gw-finish-guide__bar" aria-hidden="true"></div>
                    <div class="gw-finish-guide__body">
                        <p class="gw-finish-guide__message" tabindex="-1"></p>
                        <p class="gw-finish-guide__note light hidden"></p>
                        <div class="gw-finish-guide__answer hidden"></div>
                        <div class="gw-finish-guide__fixes"></div>
                    </div>
                    <footer class="gw-finish-guide__foot">
                        <button type="button" class="gw-finish-guide__nav" data-go="prev">${esc(t('← Back'))}</button>
                        <button type="button" class="gw-finish-guide__nav" data-go="skip">${esc(t('Skip for now'))}</button>
                        <button type="button" class="gw-finish-guide__nav" data-go="next">${esc(t('Next →'))}</button>
                    </footer>
                    <button type="button" class="gw-finish-guide__compact" aria-label="${esc(t('Show the step'))}"></button>
                    <p id="gw-finish-keys" class="visually-hidden">${esc(t('Alt+Shift+N for the next step, Alt+Shift+P for the one before, Alt+Shift+G to open or minimise.'))}</p>
                </section>`).appendTo(this.$root);

            this.$dock = $(`
                <button type="button" class="gw-finish-dock" aria-keyshortcuts="Alt+Shift+G">
                    <span class="gw-finish-dock__mark" aria-hidden="true">${icon}</span>
                    <span class="gw-finish-dock__count" aria-hidden="true"></span>
                    <span class="gw-finish-dock__tip" aria-hidden="true"></span>
                </button>`).appendTo(this.$root);

            this.$flyer = $(`
                <div class="gw-finish-flyer" aria-hidden="true">
                    <span class="gw-finish-flyer__trail"></span>
                    <span class="gw-finish-flyer__tilt"><span class="gw-finish-flyer__bob">${icon}</span></span>
                    <span class="gw-finish-flyer__say"></span>
                </div>`).appendTo(this.$root);

            this.addListener(this.$guide.find('.gw-finish-guide__min'), 'click', () => this.minimise(true));
            this.addListener(this.$dock, 'click', () => {
                this.minimise(false);
                this.go(this.done ? this.firstOpen() : this.index);
            });
            this.addListener(this.$guide.find('[data-go]'), 'click', (event) => {
                const go = event.currentTarget.dataset.go;
                go === 'prev' ? this.prev() : go === 'next' ? this.next() : this.skip();
            });
            this.addListener(this.$guide.find('.gw-finish-guide__compact'), 'click', () => this.$guide.removeClass('gw-finish-guide--compact'));
            this.addListener(this.$guide.find('.gw-finish-guide__handle'), 'click', () => this.$guide.toggleClass('gw-finish-guide--compact'));

            // Esc minimises, only from inside the guide, so it never takes
            // Esc from Craft's slideouts and modals.
            this.addListener(this.$guide, 'keydown', (event) => {
                if (event.key === 'Escape' && !$(event.target).is('.gw-finish-answer')) {
                    event.stopPropagation();
                    this.minimise(true);
                }
            });
        },

        paint() {
            const count = this.count();

            this.paintPill(count);
            this.paintDock(count);
            this.paintFields();
            this.paintEditors();

            if (!this.shown) {
                this.$root.addClass('hidden');

                return;
            }

            this.$root.removeClass('hidden');

            if (!this.minimised) {
                this.paintGuide();
                this.followCurrent();
            }
        },

        /** The step the guide is on, while it is open and not at the end. */
        currentStep() {
            return !this.minimised && !this.done ? this.steps[this.index] ?? null : null;
        },

        /**
         * Keep the mark on the current step's field, with its speech label:
         * after a check (a fix may have changed the gap there, a
         * placeholder now a stock preview) as well as on moving.
         */
        followCurrent() {
            const step = this.currentStep();

            if (!step) return;

            const field = this.locate(step.gap) ?? this.cardFor(step.gap)?.card ?? null;

            if (field !== this.flyTarget || this.flySpeech !== step.gap.speech) {
                field ? this.flyTo(field, step.gap.speech) : this.flyHome(step.gap.speech);
            } else {
                this.placeFlyer();
            }
        },

        paintPill(count) {
            const $text = this.$pill.find('.gw-finish-pill__text');

            if (!this.shown) {
                this.$pill.addClass('hidden');

                return;
            }

            this.$pill.removeClass('hidden').toggleClass('gw-finish-pill--ready', count === 0);
            $text.text(count ? this.countText(count) : this.strings.ready);
            this.$pill.attr('aria-label', `${this.strings.title}: ${count ? this.countText(count) : this.strings.ready}`);
            this.$pill.attr('title', t('Alt+Shift+G opens or minimises the guide'));
        },

        paintDock(count) {
            this.$dock.find('.gw-finish-dock__count').text(count || '✓').toggleClass('gw-finish-dock__count--ready', count === 0);
            this.$dock.find('.gw-finish-dock__tip').text(count ? this.countText(count) : this.strings.ready);
            this.$dock.attr('aria-label', `${this.strings.title}: ${count ? this.countText(count) : this.strings.ready}`);
        },

        /**
         * Each field's outline and tag, from the live list: amber while it
         * has gaps, purple while the guide is on one, green once it had gaps
         * and has none. The tag sits beside the field's name; text and a 2px
         * outline say the state as well as the colour does.
         */
        paintFields() {
            const H = Ghostwriter.FinishHelpers;

            this.fieldsMarked.forEach((field) => {
                field.classList.remove('gw-gap-host', 'gw-gap-host--open', 'gw-gap-host--current', 'gw-gap-host--fixed', 'gw-gap-host--skipped', 'gw-gap-host--suggestion');
                field.querySelectorAll(':scope > .gw-gap-tag, :scope > .heading .gw-gap-tag').forEach((tag) => tag.remove());
                field.querySelector(':scope > .gw-gap-note')?.remove();

                const described = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((id) => !id.startsWith('gw-gap-note-'));
                described.length ? field.setAttribute('aria-describedby', described.join(' ')) : field.removeAttribute('aria-describedby');
            });

            this.fieldsMarked = [];

            if (!this.shown) return;

            const steps = this.steps;
            const current = this.currentStep();
            const fields = H.fieldStates(steps, current ? this.index : -1, new Set(this.touched.keys()));
            const byElement = new Map();

            // Fields to elements; a block shown as a card carries all of its fields.
            fields.forEach((field, dotted) => {
                const gap = field.step?.gap ?? this.touched.get(dotted);
                const element = gap ? this.locate(gap) ?? this.cardFor(gap)?.card ?? null : null;

                if (!element) return;

                const merged = byElement.get(element);

                if (!merged) {
                    byElement.set(element, { ...field, steps: [...field.steps] });
                } else {
                    merged.steps = [...merged.steps, ...field.steps].sort((a, b) => a - b);

                    if (field.state === 'current') {
                        merged.state = 'current';
                        merged.step = field.step;
                    } else if (merged.state === 'fixed' && field.state !== 'fixed') {
                        merged.state = field.state;
                        merged.step = field.step;
                    }
                }
            });

            byElement.forEach((field, element) => {
                const live = field.steps.map((i) => steps[i]);
                const state = field.state === 'current' ? 'current' : field.state === 'fixed' || live.every((step) => step.status === 'fixed') ? 'fixed' : live.some((step) => step.status === 'open') ? 'open' : 'skipped';
                const shown = { ...field, state: state === 'fixed' ? 'fixed' : field.state };
                const text = H.tagText(shown, steps, t);
                const first = field.step?.gap;

                element.classList.add('gw-gap-host', `gw-gap-host--${state}`);

                if (state !== 'current' && state !== 'fixed' && live.length && live.every((step) => H.isSuggestion(step.gap))) {
                    element.classList.add('gw-gap-host--suggestion');
                }

                const tag = document.createElement('button');
                const target = field.state === 'current' ? this.index : field.steps.find((i) => steps[i].status !== 'fixed') ?? field.steps[0];

                tag.type = 'button';
                tag.className = 'gw-gap-tag';
                tag.textContent = text;
                tag.setAttribute('aria-label', state === 'fixed' ? t('Fixed: {label}', { label: this.touchedLabel(element) }) : t('{tag}: {label}', { tag: text, label: first?.label ?? '' }));

                if (target !== undefined) {
                    tag.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        this.minimise(false);
                        this.go(target);
                    });
                } else {
                    tag.disabled = true;
                }

                // Beside the field's name, in its own heading row, so it
                // never covers a control; on a card, in its corner.
                const heading = element.querySelector(':scope > .heading');
                const name = heading?.querySelector(':scope > label, :scope > legend, :scope > .label, :scope > h2, :scope > h3');

                if (name) {
                    name.after(tag);
                } else if (heading) {
                    heading.prepend(tag);
                } else {
                    element.prepend(tag);
                }

                if (first) {
                    const note = document.createElement('span');
                    note.className = 'gw-gap-note visually-hidden';
                    note.id = `gw-gap-note-${Math.random().toString(36).slice(2, 8)}`;
                    note.textContent = t('Ghostwriter: {speech}', { speech: first.speech.toLowerCase() });
                    element.append(note);
                    element.setAttribute('aria-describedby', [element.getAttribute('aria-describedby'), note.id].filter(Boolean).join(' '));
                }

                this.fieldsMarked.push(element);
            });
        },

        /** A fixed field's name, for its tag's label. */
        touchedLabel(element) {
            for (const gap of this.touched.values()) {
                if (this.locate(gap) === element) return gap.label;
            }

            return '';
        },

        paintGuide() {
            const H = Ghostwriter.FinishHelpers;
            const $guide = this.$guide;
            const steps = this.steps;
            const numbers = H.counts(steps, this.index);

            this.$root.removeClass('gw-finish--minimised');
            $guide.find('.gw-finish-guide__bar').html(steps.map((step, i) => {
                const state = step.status === 'fixed' ? 'fixed' : step.status === 'skipped' ? 'skipped' : i === this.index && !this.done ? 'current' : 'open';

                return `<i class="gw-finish-guide__seg gw-finish-guide__seg--${state}"></i>`;
            }).join(''));

            const $message = $guide.find('.gw-finish-guide__message');
            const $note = $guide.find('.gw-finish-guide__note');
            const $fixes = $guide.find('.gw-finish-guide__fixes').empty();
            const $answer = $guide.find('.gw-finish-guide__answer').addClass('hidden').empty();

            if (this.done || !steps.length) {
                const left = numbers.left;

                $guide.find('.gw-finish-guide__step').text('');
                $message.text(left ? (left === 1 ? this.strings.skippedOne : (this.strings.skipped ?? '').replace('{count}', left)) : this.strings.done);
                $note.addClass('hidden');
                $guide.addClass('gw-finish-guide--done');

                if (left) {
                    const $again = $(`<button type="button" class="btn small">${esc(t('Go through again'))}</button>`).appendTo($fixes);
                    $again.on('click', () => this.restart());
                }

                this.flyHome();
                this.compactText(t('All done'));

                return;
            }

            $guide.removeClass('gw-finish-guide--done');

            const step = steps[this.index];
            const gap = step.gap;
            const fixed = step.status === 'fixed';
            const where = numbers.suggestion ? t('Suggestion {n} of {total}', { n: numbers.number, total: numbers.suggestions }) : t('{n} of {total}', { n: numbers.number, total: numbers.total });

            $guide.find('.gw-finish-guide__step').text(where);
            $message.text(fixed ? t('Fixed. {message}', { message: gap.message }) : gap.message);

            const notes = [];

            // Fact messages say it already; links don't.
            if (gap.reason && !fixed && gap.kind.startsWith('link')) notes.push(gap.reason);
            if (numbers.suggestion) notes.push(t('A suggestion: it won’t stop the page going live.'));
            if (gap.stock?.requested && !fixed) notes.push(t('Licence requested by {name}', { name: gap.stock.requested.name ?? '' }));

            if (this.index === 0 && numbers.suggestions && numbers.total) {
                notes.push(`${this.countText(numbers.total)}, ${numbers.suggestions === 1 ? this.strings.suggestionsOne : (this.strings.suggestions ?? '').replace('{count}', numbers.suggestions)}.`);
            }

            $note.text(notes.join(' ')).toggleClass('hidden', !notes.length);

            if (!fixed) {
                this.renderFixes(gap, $fixes, $answer);
            }

            $guide.find('[data-go="prev"]').prop('disabled', this.index === 0);
            this.compactText(`${where} · ${gap.speech} · ${t('Next')}`);
        },

        compactText(text) {
            this.$guide.find('.gw-finish-guide__compact').text(text);
        },

        /** The editor's own words for a fix, primary first. */
        renderFixes(gap, $fixes, $answer) {
            const fixes = this.fixesFor(gap);

            fixes.forEach((fix) => {
                if (fix.action === 'answer') {
                    this.renderAnswer(gap, $answer.removeClass('hidden'));

                    return;
                }

                const cost = fix.cost === 'model' ? ` <span class="gw-finish-fix__cost">${esc(t('uses Ghostwriter'))}</span>` : '';
                const $button = $(`<button type="button" class="btn small${fix.primary ? ' submit' : ''}">${fix.primary ? '<span class="gw-mark" aria-hidden="true"></span>' : ''}${esc(fix.label)}${cost}</button>`);

                $button.on('click', async () => {
                    $button.addClass('loading').prop('disabled', true);

                    try {
                        await this.fix(gap, fix);
                    } finally {
                        $button.removeClass('loading').prop('disabled', false);
                    }
                });
                $fixes.append($button);
            });

            Ghostwriter.prepareButtons($fixes);
        },

        /**
         * The fixes core gave, adjusted to what this person and this form
         * can do: the stock feature's own actions for a preview, and focus
         * for anything whose field can't be reached here.
         */
        fixesFor(gap) {
            let fixes = [...(gap.fixes ?? [])];

            if (gap.kind === 'stock-preview' && gap.stock) {
                const item = gap.stock;
                fixes = [];

                if (item.mayLicense) {
                    fixes.push({ action: 'license', label: item.cost ? t('License ({cost})', { cost: item.costShort ?? item.cost }) : t('License'), primary: true });
                } else if (item.mayReplace) {
                    fixes.push({ action: 'replace-again', label: t('Download again and replace'), primary: true });
                } else if (item.mayRequest) {
                    fixes.push({ action: 'request-licence', label: t('Request licence'), primary: true });
                }

                if (item.mayRefresh) fixes.push({ action: 'refresh-preview', label: t('Refresh preview') });
                fixes.push({ action: 'choose-another', label: t('Choose another') });
            }

            // An image inline in rich text has no image dialog of its own.
            if (gap.meta?.inline && ['find-photo', 'choose-asset', 'choose-another'].some((action) => fixes.some((fix) => fix.action === action)) && gap.kind !== 'link') {
                fixes = fixes.filter((fix) => !['find-photo', 'choose-asset', 'choose-another'].includes(fix.action));
                fixes.unshift({ action: 'focus', label: t('Show me'), primary: true });
            }

            // Pickers only where the field can take an element (not read-only).
            const located = this.locate(gap);

            if (located) {
                fixes = fixes.filter((fix) => {
                    if (fix.action === 'choose-asset') return Ghostwriter.FinishHelpers.canPick(this.selectInput(located));
                    if (fix.action === 'choose-entry' && !gap.meta?.inline) return Ghostwriter.FinishHelpers.canPick(this.selectInput(located, 'entry') ?? this.selectInput(located));

                    return true;
                });

                if (fixes.length && !fixes.some((fix) => fix.primary)) fixes[0].primary = true;
            }

            // A field that isn't in the form (a block in cards view): open it first.
            if (!this.locate(gap) && this.cardFor(gap)) {
                fixes.unshift({ action: 'open-block', label: t('Open block'), primary: true });
                fixes.forEach((fix, i) => (fix.primary = i === 0));
            }

            return fixes;
        },

        /**
         * A fact to add: a box to type it into. Always editable; Enter, or
         * leaving the box, puts it in the field; Esc puts it back (C1).
         */
        renderAnswer(gap, $answer) {
            const id = `gw-finish-answer-${Date.now()}`;

            $answer.html(`
                <label for="${id}" class="visually-hidden">${esc(t('What should it say?'))}</label>
                <div class="flex flex-nowrap">
                    <input id="${id}" type="text" class="text fullwidth gw-finish-answer" placeholder="${esc(gap.hint ?? '')}" autocomplete="off">
                    <button type="button" class="btn small submit gw-finish-answer__go">${esc(t('Put it in'))}</button>
                </div>`);

            const $input = $answer.find('input');
            const $go = $answer.find('.gw-finish-answer__go');
            let put = false;

            const commit = async () => {
                const text = $input.val().trim();

                if (!text || put) return;

                put = true;

                if (await this.replaceMarker(gap, text)) {
                    this.fixed(gap);
                } else {
                    put = false;
                    Craft.cp.displayError(t('Ghostwriter couldn’t find that gap in the field any more.'));
                }
            };

            Ghostwriter.prepareButtons($go);
            $go.on('click', commit);
            $input.on('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    commit();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    $input.val('');
                    $input.trigger('blur');
                }
            });
            $input.on('blur', () => setTimeout(() => {
                if (!$answer.find(':focus').length) commit();
            }, 150));
        },

        /* ------------------------------------------------------------------
         * Moving between steps
         * ------------------------------------------------------------------ */

        async go(index, options = {}) {
            const steps = this.steps;

            if (!steps.length) {
                this.done = true;
                this.paint();

                return;
            }

            this.done = index >= steps.length;
            this.index = Math.max(0, Math.min(index, steps.length - 1));

            const step = steps[this.index];

            if (!this.done && step.status === 'skipped') {
                step.status = 'open';
                this.forgetSkip(step.gap.id);
            }

            this.paint();

            if (this.done || this.minimised) return;

            const numbers = Ghostwriter.FinishHelpers.counts(steps, this.index);

            if (!options.quiet) {
                this.announce(`${numbers.suggestion ? t('Suggestion {n} of {total}.', { n: numbers.number, total: numbers.suggestions }) : t('Step {n} of {total}.', { n: numbers.number, total: numbers.total })} ${step.gap.message}`);
                this.$guide.find('.gw-finish-guide__message').trigger('focus');
            }

            const field = (await this.reveal(step.gap)) ?? this.cardFor(step.gap)?.card ?? null;

            if (field) {
                this.scrollTo(field);
                this.paintFields();
                this.paintEditors();
                this.flyTo(field, step.gap.speech);
            } else {
                this.flyHome(step.gap.speech);
            }
        },

        next() {
            if (!this.done) this.go(this.index + 1);
        },

        prev() {
            if (this.index > 0 || this.done) this.go(this.done ? this.steps.length - 1 : this.index - 1);
        },

        skip() {
            const step = this.steps[this.index];

            if (!this.done && step) {
                if (step.status !== 'fixed') {
                    step.status = 'skipped';
                    this.rememberSkip(step.gap.id);
                }

                this.advance();
            }
        },

        /** On to the next step still to do, or to the end. */
        advance() {
            const next = this.index + 1;
            const open = Ghostwriter.FinishHelpers.nextOpen(this.steps, next);

            this.go(open < next ? this.steps.length : open);
        },

        restart() {
            this.steps.forEach((step) => {
                if (step.status === 'skipped') {
                    step.status = 'open';
                    this.forgetSkip(step.gap.id);
                }
            });

            this.go(this.firstOpen());
        },

        /** Marked done straight away; the next check takes it off the list. */
        fixed(gap) {
            const step = this.steps.find((s) => s.gap.id === gap.id);

            if (step) step.status = 'fixed';

            const left = this.count();

            this.announce(left ? t('Fixed. {left} left.', { left }) : t('Fixed. {ready}', { ready: this.strings.ready }));
            this.bump();
            this.advance();
        },

        keys(event) {
            if (!event.altKey || !event.shiftKey || event.metaKey || event.ctrlKey) return;

            const typing = $(event.target).is('input, textarea, select, [contenteditable="true"], [contenteditable=""]');
            const code = event.code;

            if (typing || !['KeyN', 'KeyP', 'KeyG'].includes(code) || this.$root.hasClass('hidden')) return;

            event.preventDefault();

            if (code === 'KeyG') {
                this.minimise(!this.minimised);

                if (!this.minimised) this.go(this.done ? this.firstOpen() : this.index);
            } else {
                this.minimise(false);
                code === 'KeyN' ? this.next() : this.prev();
            }
        },

        announce(text) {
            this.$live.text('');
            setTimeout(() => this.$live.text(text), 50);
        },

        /* ------------------------------------------------------------------
         * Minimise and restore, remembered for this person
         * ------------------------------------------------------------------ */

        minimise(on, remember = true) {
            if (on === this.minimised) return;

            this.minimised = on;

            if (remember) {
                Craft.sendActionRequest('POST', 'ghostwriter/gaps/guide', { data: { state: on ? 'minimised' : 'open' } }).catch(() => {});
            }

            const still = this.reduced;
            const $guide = this.$guide;
            const $dock = this.$dock;

            clearTimeout(this.minimiseTimer);

            if (on) {
                this.announce(t('Finish this page minimised.'));

                if (still) {
                    this.$root.addClass('gw-finish--minimised');
                    this.paint();
                    $dock.trigger('focus');

                    return;
                }

                $guide.removeClass('gw-finish-guide--unfurling').addClass('gw-finish-guide--sucking');
                this.flyToDock();
                this.minimiseTimer = setTimeout(() => {
                    $guide.removeClass('gw-finish-guide--sucking');
                    this.$root.addClass('gw-finish--minimised');
                    $dock.addClass('gw-finish-dock--popping');
                    this.puff();
                    this.paint();
                    $dock.trigger('focus');
                    setTimeout(() => $dock.removeClass('gw-finish-dock--popping').addClass('gw-finish-dock--waving'), 450);
                    setTimeout(() => $dock.removeClass('gw-finish-dock--waving'), 1200);
                }, 480);

                return;
            }

            this.announce(t('Finish this page opened.'));

            if (still) {
                this.$root.removeClass('gw-finish--minimised');
                this.paint();

                return;
            }

            $dock.addClass('gw-finish-dock--leaping');
            this.minimiseTimer = setTimeout(() => {
                $dock.removeClass('gw-finish-dock--leaping');
                this.$root.removeClass('gw-finish--minimised');
                $guide.addClass('gw-finish-guide--unfurling');
                this.$flyer.removeClass('gw-finish-flyer--hidden');
                this.paint();
                setTimeout(() => $guide.removeClass('gw-finish-guide--unfurling'), 600);
            }, 450);
        },

        /**
         * Where the dock is, or will be: it isn't drawn while the guide is
         * open, so its corner is worked out from its own size and inset.
         */
        dockRect() {
            const drawn = this.$dock[0].getBoundingClientRect();

            if (drawn.width) return drawn;

            const size = 56;
            const inset = this.phone ? 16 : 24;
            const left = document.documentElement.dir === 'rtl' ? inset : window.innerWidth - inset - size;
            const top = window.innerHeight - inset - size;

            return { left, top, width: size, height: size, right: left + size, bottom: top + size };
        },

        /** A few wisps as the dock pops in. */
        puff() {
            if (this.reduced) return;

            const rect = this.dockRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const mirror = document.documentElement.dir === 'rtl' ? -1 : 1;

            [[-38, -26], [-44, 8], [-20, -42], [6, -46], [-34, 30]].forEach(([dx, dy], i) => {
                const wisp = document.createElement('span');
                wisp.className = 'gw-finish-wisp';
                wisp.style.left = `${x - 4}px`;
                wisp.style.top = `${y - 4}px`;
                wisp.style.setProperty('--dx', `${dx * mirror}px`);
                wisp.style.setProperty('--dy', `${dy}px`);
                wisp.style.animationDelay = `${i * 40}ms`;
                this.$root[0].appendChild(wisp);
                setTimeout(() => wisp.remove(), 900);
            });
        },

        bump() {
            const $count = this.$dock.find('.gw-finish-dock__count');

            $count.removeClass('gw-finish-dock__count--bump');
            void $count[0].offsetWidth;
            $count.addClass('gw-finish-dock__count--bump');
        },

        /* ------------------------------------------------------------------
         * The flying mark
         * ------------------------------------------------------------------ */

        flyTo(field, speech) {
            this.flyTarget = field;
            this.flySpeech = speech;
            this.$flyer.removeClass('gw-finish-flyer--home gw-finish-flyer--hidden');
            this.placeFlyer(true);
        },

        flyHome(speech = null) {
            this.flyTarget = null;
            this.flySpeech = speech ?? (this.done ? t('All done!') : '');
            this.$flyer.addClass('gw-finish-flyer--home').removeClass('gw-finish-flyer--hidden');
            this.placeFlyer(true);
        },

        flyToDock() {
            const rect = this.dockRect();

            this.flyTarget = null;
            this.$flyer.removeClass('gw-finish-flyer--arrived');
            this.move(rect.left + 6, rect.top + 6, 'scale(.6) rotate(360deg)');
            setTimeout(() => this.$flyer.addClass('gw-finish-flyer--hidden'), 450);
        },

        /**
         * Where the mark sits: beside the current field's tag, or above the
         * guide. Measured again on scroll (any scrolling pane), resize and
         * when the form changes. Not on phones, where the tag says enough.
         */
        placeFlyer(flying = false) {
            if (!this.$flyer || this.phone || this.minimised) {
                this.$flyer?.addClass('gw-finish-flyer--hidden');

                return;
            }

            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => {
                const mirror = document.documentElement.dir === 'rtl';
                let x;
                let y;

                if (this.flyTarget && document.body.contains(this.flyTarget)) {
                    const box = this.flyTarget.getBoundingClientRect();
                    const tag = this.flyTarget.querySelector(':scope > .heading .gw-gap-tag, :scope > .gw-gap-tag')?.getBoundingClientRect();

                    // Just past the tag beside the field's name, above the field.
                    if (tag && tag.width) {
                        x = mirror ? Math.max(8, tag.left - 52) : Math.min(tag.right + 8, window.innerWidth - 60);
                        y = Math.max(8, tag.top - 34);
                    } else {
                        x = mirror ? box.left + 120 : box.right - 170;
                        y = Math.max(8, box.top - 46);
                    }

                    // Off screen: wait by the guide until it scrolls back.
                    if (box.bottom < 0 || box.top > window.innerHeight) {
                        this.flyTarget = null;
                    }
                }

                if (x === undefined) {
                    const guide = this.$guide[0].getBoundingClientRect();
                    x = mirror ? guide.left + 16 : guide.right - 60;
                    y = guide.top - 50;
                }

                if (flying && this.lastX !== undefined) {
                    this.$flyer.find('.gw-finish-flyer__tilt').css('transform', `rotate(${x < this.lastX ? -12 : 12}deg)`);
                }

                this.$flyer.removeClass('gw-finish-flyer--arrived');
                this.$flyer.find('.gw-finish-flyer__say').text(this.flySpeech ?? '');
                this.move(Math.max(4, x), Math.max(4, y));
                this.lastX = x;

                clearTimeout(this.arriveTimer);
                this.arriveTimer = setTimeout(() => {
                    this.$flyer.addClass('gw-finish-flyer--arrived');
                    this.$flyer.find('.gw-finish-flyer__tilt').css('transform', 'rotate(0deg)');
                }, this.reduced || !flying ? 0 : FLIGHT);
            });
        },

        move(x, y, extra = '') {
            this.$flyer.css('transform', `translate(${Math.round(x)}px, ${Math.round(y)}px) ${extra}`);
        },

        scrollTo(field) {
            const box = field.getBoundingClientRect();

            if (box.top < 80 || box.bottom > window.innerHeight - (this.phone ? 260 : 40)) {
                field.scrollIntoView({ block: 'center', behavior: this.reduced ? 'auto' : 'smooth' });
            }
        },

        /* ------------------------------------------------------------------
         * Finding a gap's field in the form
         * ------------------------------------------------------------------ */

        /**
         * The field's container in the form, or null when it isn't drawn
         * (a block in cards view, until it is opened). Top-level fields by
         * their ID; fields in a Matrix entry or Neo block within that block,
         * whose ID Craft keeps up to date when a provisional draft is made;
         * a block opened in a slideout, in the slideout.
         */
        locate(gap) {
            const location = gap.location ?? {};
            const handle = location.handle;

            if (!handle) return null;

            if (!(location.blocks ?? []).length) {
                const form = Craft.cp?.$primaryForm?.[0] ?? document;

                return form.querySelector(handle === 'title' ? '#title-field' : `#fields-${CSS.escape(handle)}-field`)
                    ?? this.fieldIn(form, handle, null);
            }

            const block = this.blockElement(location.elementId);

            if (block) return this.fieldIn(block, handle, block);

            const slideout = this.slideoutFor(location.elementId);

            return slideout ? this.fieldIn(slideout, handle, null) : null;
        },

        /**
         * A Matrix entry's or Neo block's element in the form. Once the
         * provisional draft exists, the server knows a changed block by
         * its draft ID while the form keeps the canonical one; Craft's
         * element editor maps them (draftElementIds).
         */
        blockElement(id) {
            const find = (n) => document.querySelector(`.matrixblock[data-id="${n}"], .ni_block[data-neo-b-id="${n}"]`);
            const map = this.editor()?.draftElementIds ?? {};

            return find(id) ?? find(Object.keys(map).find((canonical) => String(map[canonical]) === String(id)) ?? '') ?? (map[id] ? find(map[id]) : null);
        },

        /** The field with this handle directly in a block, not in a block inside it. */
        fieldIn(root, handle, block) {
            const fields = root.querySelectorAll(`[data-attribute="${CSS.escape(handle)}"]`);

            for (const field of fields) {
                const owner = field.parentElement?.closest('.matrixblock, .ni_block, .element-editor, .so-content');

                if (block ? owner === block : !field.parentElement?.closest('.matrixblock, .ni_block')) {
                    return field;
                }
            }

            return null;
        },

        /** An element editor slideout open on this nested entry. */
        slideoutFor(id) {
            for (const container of document.querySelectorAll('.slideout-container .slideout')) {
                const editor = $(container).find('form, .so-body').addBack().data('elementEditor') ?? $(container).data('slideout')?.elementEditor;
                const settings = editor?.settings;

                if (settings && [settings.elementId, settings.canonicalId].map(Number).includes(Number(id))) {
                    return container;
                }
            }

            return null;
        },

        /** A nested entry shown as a card (Matrix's cards or index view). */
        cardFor(gap) {
            const ids = gap.location?.blocks ?? [];

            for (const id of ids) {
                if (!this.blockElement(id)) {
                    const card = document.querySelector(`.nested-element-cards .element.card[data-id="${id}"], [data-id="${id}"].element.card, .elementindex [data-id="${id}"]`);

                    if (card) return { id, card };
                }
            }

            return null;
        },

        /**
         * Show the field: its tab, and every block it is in, expanded.
         * Resolves with the field, or null when it can't be shown here.
         */
        async reveal(gap) {
            for (const id of gap.location?.blocks ?? []) {
                const block = this.blockElement(id);

                if (!block) break;

                const entry = $(block).data('entry') ?? $(block).data('block');

                if (block.classList.contains('collapsed') || block.classList.contains('is-collapsed')) {
                    entry?.expand?.();
                }
            }

            const field = this.locate(gap);

            if (!field) return null;

            this.showTab(field);

            return field;
        },

        /** Select the tab a field is on, through Craft's own tab buttons. */
        showTab(field) {
            let node = field.parentElement;

            while (node && node !== document.body) {
                if (node.id && (node.classList.contains('hidden') || node.getAttribute('aria-hidden') === 'true' || node.hidden)) {
                    const tab = document.querySelector(`[aria-controls="${CSS.escape(node.id)}"], a[href="#${CSS.escape(node.id)}"]`);

                    if (tab) {
                        tab.click();
                    }
                }

                node = node.parentElement;
            }
        },

        /** The input to write to for a plain field: a text input or textarea. */
        inputIn(field) {
            return field?.querySelector('input[type="text"]:not(.gw-finish-answer), textarea:not([style*="display: none"]):not(.hidden)') ?? null;
        },

        /** The CKEditor in a field, if it has one. */
        ckeditorIn(field) {
            const editable = field?.querySelector('.ck-editor__editable');

            return editable?.ckeditorInstance ?? null;
        },

        /* ------------------------------------------------------------------
         * Highlights inside CKEditor, with CKEditor's own markers, so they
         * survive the editor redrawing itself
         * ------------------------------------------------------------------ */

        paintEditors() {
            const byEditor = new Map();
            const current = this.currentStep()?.gap ?? null;

            (this.shown ? this.steps : []).forEach(({ gap, status }) => {
                if (!INLINE_KINDS.includes(gap.kind) || status === 'fixed') return;

                const editor = this.ckeditorIn(this.locate(gap));

                if (!editor) return;

                if (!byEditor.has(editor)) byEditor.set(editor, []);
                byEditor.get(editor).push(gap);
            });

            // Editors no longer holding a gap lose their marks.
            this.editors.forEach((state, editor) => {
                if (!byEditor.has(editor)) this.markEditor(editor, [], null);
            });

            byEditor.forEach((gaps, editor) => this.markEditor(editor, gaps, current));
        },

        markEditor(editor, gaps, current) {
            let state = this.editors.get(editor);

            if (!state) {
                state = { classes: {}, gaps: [] };
                this.editors.set(editor, state);

                try {
                    editor.conversion.for('editingDowncast').markerToHighlight({
                        model: 'gw-gap',
                        view: (data) => ({ classes: state.classes[data.markerName] ?? ['gw-gap-mark'] }),
                    });
                } catch (error) {
                    // Registered already, for an editor made again.
                }

                let timer = null;

                editor.model.document.on('change:data', () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => this.markEditor(editor, state.gaps, state.current), 300);
                });
            }

            state.gaps = gaps;
            state.current = current;

            const ranges = gaps.map((gap) => [gap, this.rangeFor(editor, gap)]).filter(([, range]) => range);

            editor.model.change((writer) => {
                for (const marker of Array.from(editor.model.markers.getMarkersGroup('gw-gap'))) {
                    writer.removeMarker(marker);
                }

                ranges.forEach(([gap, range], i) => {
                    const name = `gw-gap:${i}`;
                    state.classes[name] = ['gw-gap-mark', current && current.id === gap.id ? 'gw-gap-mark--current' : `gw-gap-mark--${gap.severity === 'suggestion' ? 'suggestion' : 'open'}`];
                    writer.addMarker(name, { range, usingOperation: false, affectsData: false });
                });
            });
        },

        /**
         * Where a gap is in CKEditor's model: the nth match of its marker
         * (a fact to add, template text, placeholder words), or the words
         * of a link to choose or to a deleted page.
         */
        rangeFor(editor, gap) {
            const model = editor.model;
            const root = model.document.getRoot();
            const found = [];

            if (gap.kind === 'link' || gap.kind === 'link-broken') {
                const range = model.createRangeIn(root);

                for (const item of range.getItems()) {
                    if (!item.is('$textProxy')) continue;

                    const href = item.getAttribute('linkHref') ?? '';
                    const match = gap.kind === 'link' ? href.includes('#gw-link:') && (gap.hint ? href.endsWith(`#gw-link:${gap.hint}`) : true) : this.sameTarget(href, gap.hint);

                    if (match) {
                        const last = found[found.length - 1];

                        // One link may be split across text nodes (bold inside it).
                        if (last && last.end.isEqual(model.createPositionBefore(item))) {
                            found[found.length - 1] = model.createRange(last.start, model.createPositionAfter(item));
                        } else {
                            found.push(model.createRange(model.createPositionBefore(item), model.createPositionAfter(item)));
                        }
                    }
                }

                return found[gap.occurrence ?? 0] ?? found[0] ?? null;
            }

            // An image inline in CKEditor: the image itself, by its asset.
            if (gap.kind === 'image-placeholder' || gap.kind === 'stock-preview') {
                const id = String(gap.meta?.asset?.id ?? gap.stock?.assetId ?? '');

                for (const item of model.createRangeIn(root).getItems()) {
                    if (item.is('element') && id && new RegExp(`[#{]asset:${id}(?:\\D|$)`).test(String(item.getAttribute('src') ?? ''))) {
                        found.push(model.createRangeOn(item));
                    }
                }

                return found[gap.occurrence ?? 0] ?? found[0] ?? null;
            }

            const regex = this.regexFor(gap);

            if (!regex) return null;

            for (const block of this.textBlocks(model, root)) {
                const { text, positions } = block;

                regex.lastIndex = 0;
                let match;

                while ((match = regex.exec(text)) !== null) {
                    if (gap.kind === 'ask' && gap.hint && this.normalise(match[1]) !== this.normalise(gap.hint)) continue;
                    if (gap.kind === 'leftover-token' && gap.hint && match[0] !== gap.hint && match[1] !== gap.hint) continue;
                    if (gap.kind === 'placeholder-text' && gap.hint && match[0] !== gap.hint) continue;

                    found.push(model.createRange(positions[match.index], positions[match.index + match[0].length]));

                    if (match[0] === '') regex.lastIndex++;
                }
            }

            return found[gap.occurrence ?? 0] ?? null;
        },

        editorHref(value) {
            return Ghostwriter.FinishHelpers.editorHref(value, this.config.siteId);
        },

        sameTarget(href, stored) {
            return Ghostwriter.FinishHelpers.sameTarget(href, stored);
        },

        regexFor(gap) {
            if (gap.kind === 'ask') return this.patterns.ask;
            if (gap.kind === 'leftover-token') return this.patterns.leftover;

            if (gap.kind === 'placeholder-text') {
                const hint = (gap.hint ?? '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

                return hint ? new RegExp(hint, 'g') : null;
            }

            return null;
        },

        normalise(text) {
            return (text ?? '').toLowerCase().replace(/\s+/g, ' ').trim();
        },

        /**
         * Each paragraph-like element's text, with the model position of
         * every character, so a match in the text is a range in the model.
         */
        *textBlocks(model, root) {
            const walk = function* (element) {
                let text = '';
                const positions = [];
                let hasText = false;

                for (const child of element.getChildren()) {
                    if (child.is('$text')) {
                        for (let i = 0; i < child.data.length; i++) {
                            positions.push(model.createPositionAt(child.parent, child.startOffset + i));
                        }

                        text += child.data;
                        hasText = true;
                    } else if (child.is('element')) {
                        if (hasText) {
                            positions.push(model.createPositionBefore(child));
                            text += '￼';
                        }

                        yield* walk(child);
                    }
                }

                if (hasText) {
                    positions.push(model.createPositionAt(element, 'end'));
                    yield { text, positions, element };
                }
            };

            yield* walk(root);
        },

        /* ------------------------------------------------------------------
         * Fixes. Each writes into the form, never saves: Craft autosaves
         * the draft as it would for any typing.
         * ------------------------------------------------------------------ */

        async fix(gap, fix) {
            const field = await this.reveal(gap);

            switch (fix.action) {
                case 'link':
                    return this.linkTo(gap, field, fix.value, fix);
                case 'choose-entry':
                    return this.chooseEntry(gap, field);
                case 'remove-link':
                    return this.removeLink(gap, field);
                case 'find-photo':
                case 'choose-another':
                    return this.imageDialog(field);
                case 'choose-asset':
                    return this.chooseAsset(gap, field);
                case 'leave-empty':
                    return this.leaveEmpty(gap, field);
                case 'license':
                    return Ghostwriter.Stock.license(gap.stock, () => this.stockChanged(gap, field));
                case 'request-licence':
                    return Ghostwriter.Stock.act('stock/request', gap.stock, $(), () => this.stockChanged(gap, field));
                case 'refresh-preview':
                    return Ghostwriter.Stock.act('stock/refresh', gap.stock, $(), () => this.stockChanged(gap, field));
                case 'replace-again':
                    return Ghostwriter.Stock.act('stock/replace-again', gap.stock, $(), () => this.stockChanged(gap, field));
                case 'write-for-me':
                case 'write-around':
                    return this.write(gap, field, fix.action);
                case 'remove':
                    if (await this.replaceMarker(gap, '')) this.fixed(gap);

                    return;
                case 'dismiss':
                    this.dismissed.push(gap.id);
                    this.saveDismissed();
                    this.steps = this.steps.filter((step) => step.gap.id !== gap.id);

                    return this.go(this.index);
                case 'open-block':
                    return this.openBlock(gap);
                case 'focus':
                default:
                    return this.focus(gap, field);
            }
        },

        /** Focus the field, and select the gap in it so typing replaces it. */
        focus(gap, field) {
            if (!field) return;

            const editor = this.ckeditorIn(field);

            if (editor) {
                const range = INLINE_KINDS.includes(gap.kind) ? this.rangeFor(editor, gap) : null;

                editor.editing.view.focus();

                if (range) {
                    editor.model.change((writer) => writer.setSelection(range));
                }

                return;
            }

            const input = this.inputIn(field);

            if (input) {
                input.focus();
                const at = this.markerIn(input.value, gap);

                if (at) input.setSelectionRange(at.index, at.index + at.length);

                return;
            }

            field.querySelector('button, input, select, textarea, [tabindex]')?.focus();
        },

        /** The nth match of the gap's marker in a plain value. */
        markerIn(value, gap) {
            const regex = this.regexFor(gap);

            if (!regex) return null;

            regex.lastIndex = 0;
            let n = 0;
            let match;

            while ((match = regex.exec(value)) !== null) {
                const same = gap.kind === 'ask' ? !gap.hint || this.normalise(match[1]) === this.normalise(gap.hint) : !gap.hint || match[0] === gap.hint || match[1] === gap.hint;

                if (same) {
                    if (n === (gap.occurrence ?? 0)) return { index: match.index, length: match[0].length };
                    n++;
                }
            }

            return null;
        },

        /**
         * Put text where the marker is (or take the marker out): in
         * CKEditor through its model, so undo works; in a plain input by
         * its value, as if typed.
         */
        async replaceMarker(gap, text) {
            const field = await this.reveal(gap);
            const editor = this.ckeditorIn(field);

            if (editor) {
                const range = this.rangeFor(editor, gap);

                if (!range) return false;

                editor.model.change((writer) => {
                    const attributes = Object.fromEntries(range.start.textNode?.getAttributes?.() ?? []);
                    writer.remove(range);

                    if (text) writer.insertText(text, attributes, range.start);
                });

                return true;
            }

            const input = this.inputIn(field);
            const at = input ? this.markerIn(input.value, gap) : null;

            if (!at) return false;

            let value = input.value.slice(0, at.index) + text + input.value.slice(at.index + at.length);

            if (!text) value = value.replace(/ {2,}/g, ' ').replace(/ ([.,;:!?])/g, '$1');

            this.setInput(input, value);

            return true;
        },

        setInput(input, value) {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        /** Point a link to choose (inline, or a Link field) at an entry. */
        async linkTo(gap, field, href, fix) {
            const editor = this.ckeditorIn(field);

            if (editor && gap.meta?.inline) {
                const range = this.rangeFor(editor, gap);

                if (!range) return;

                editor.model.change((writer) => writer.setAttribute('linkHref', this.editorHref(href), range));
                this.fixed(gap);

                return;
            }

            // Craft's Link field: Entry, then the entry chosen as its picker would.
            const match = /\{entry:(\d+)(?:@(\d+))?/.exec(String(href ?? ''));

            if (field && match && (await this.setLinkField(field, Number(match[1]), Number(match[2] ?? this.config.siteId), fix?.params?.title))) {
                this.fixed(gap);

                return;
            }

            return this.chooseEntry(gap, field);
        },

        async setLinkField(field, id, siteId, label) {
            const $select = $(field).find('select.fieldtoggle').first();
            const $elementSelect = $(field).find('[data-link-type="entry"] .elementselect').first();
            const input = $elementSelect.data('elementSelect');

            if (!$select.length || !input) return false;

            $select.val('entry').trigger('change');

            try {
                const { data } = await Craft.sendActionRequest('POST', 'app/render-elements', {
                    data: { elements: [{ type: ENTRY, id, siteId, instances: [{ context: 'field', ui: 'chip', size: 'small', showActionMenu: true }] }] },
                });
                const html = data.elements?.[id]?.[0];

                if (!html) return false;

                input.$elements.each((i, element) => input.removeElement($(element)));
                input.selectElements([{ id, siteId, label, $element: $(html) }]);
                this.clearPlaceholderLabel(field);
                await Craft.appendHeadHtml(data.headHtml);
                await Craft.appendBodyHtml(data.bodyHtml);

                return true;
            } catch (error) {
                return false;
            }
        },

        /** The field's element select input (Assets, or a Link field's entry picker). */
        selectInput(field, linkType = null) {
            const $root = linkType ? $(field).find(`[data-link-type="${linkType}"]`) : $(field);

            return $root.find('.elementselect').first().data('elementSelect') ?? null;
        },

        /**
         * "Choose from Assets": the field's own asset picker, through its
         * input, replacing the placeholder or preview it holds.
         */
        chooseAsset(gap, field) {
            const input = this.selectInput(field);

            // The autosave that follows the choice brings the next check.
            if (!Ghostwriter.FinishHelpers.openPicker(input, gap.meta?.asset?.id ?? gap.stock?.assetId ?? null)) {
                this.focus(gap, field);
            }
        },

        /**
         * A Link field the house style left as "Link to choose": once it
         * points somewhere, the placeholder words go, so Craft shows the
         * entry's own title (or the editor writes their own).
         */
        clearPlaceholderLabel(field) {
            const label = field?.querySelector('input[name$="[label]"]');

            if (label && label.value.trim() === 'Link to choose') {
                this.setInput(label, '');
            }
        },

        /** Craft's own entry picker, for an inline link or a Link field. */
        chooseEntry(gap, field) {
            const editor = this.ckeditorIn(field);

            if (!editor || !gap.meta?.inline) {
                const $select = $(field).find('select.fieldtoggle').first();

                if ($select.length && $select.find('option[value="entry"]').length) {
                    $select.val('entry').trigger('change');
                }

                const input = this.selectInput(field, 'entry') ?? this.selectInput(field);

                if (!Ghostwriter.FinishHelpers.openPicker(input)) {
                    this.focus(gap, field);

                    return;
                }

                const chosen = () => {
                    input.off?.('selectElements', chosen);
                    this.clearPlaceholderLabel(field);
                };

                input.on?.('selectElements', chosen);

                return;
            }

            Craft.createElementSelectorModal(ENTRY, {
                criteria: { siteId: this.config.siteId, uri: ':notempty:' },
                multiSelect: false,
                onSelect: (elements) => {
                    const chosen = elements[0];

                    if (!chosen) return;

                    const range = this.rangeFor(editor, gap);

                    if (!range) return;

                    editor.model.change((writer) => writer.setAttribute('linkHref', `${chosen.url ?? ''}#entry:${chosen.id}@${chosen.siteId}:url`, range));
                    this.fixed(gap);
                },
            });
        },

        removeLink(gap, field) {
            const editor = this.ckeditorIn(field);
            const range = editor ? this.rangeFor(editor, gap) : null;

            if (!range) return this.focus(gap, field);

            editor.model.change((writer) => writer.removeAttribute('linkHref', range));
            this.fixed(gap);
        },

        /** The image dialog for this field (Find a photo, Make one). */
        imageDialog(field) {
            const holder = field?.querySelector('[data-ghostwriter-image]');
            const button = holder ? $(holder).data('gwImageButton') : null;

            if (button) {
                button.open();
                $(holder).one('change', () => this.check());

                return;
            }

            field?.querySelector('.elementselect .btn.add')?.click();
        },

        /** Take the placeholder out of a field that may be left empty. */
        leaveEmpty(gap, field) {
            const input = $(field).find('.elementselect').first().data('elementSelect');
            const id = gap.meta?.asset?.id;

            if (!input) return this.focus(gap, field);

            input.$elements.filter((i, element) => !id || String($(element).data('id')) === String(id)).each((i, element) => input.removeElement($(element)));
            this.fixed(gap);
        },

        /** A preview licensed, refreshed or requested: its field and the count change. */
        stockChanged(gap, field) {
            if (field) $(field).find('[data-ghostwriter-image]').trigger('ghostwriter:stock-changed');

            this.check();
        },

        /**
         * "Write it for me" and "Write around it": one small request each,
         * in the queue. The answer goes into the field like typing.
         */
        async write(gap, field, action) {
            let sentence = null;
            const editor = this.ckeditorIn(field);
            const input = editor ? null : this.inputIn(field);

            if (action === 'write-around') {
                sentence = editor ? this.sentenceInEditor(editor, gap) : this.sentenceIn(input?.value ?? '', gap);

                if (!sentence) {
                    Craft.cp.displayError(t('Ghostwriter couldn’t find that gap in the field any more.'));

                    return;
                }
            }

            let started;

            try {
                started = (await Craft.sendActionRequest('POST', 'ghostwriter/gaps/fill', {
                    data: { elementId: this.elementId(), siteId: this.config.siteId, gap: gap.serverId ?? gap.id, sentence: sentence?.text ?? '' },
                })).data;
                Craft.cp?.runQueue?.();
            } catch (error) {
                Craft.cp.displayError(error?.response?.data?.message ?? t('Something went wrong.'));

                return;
            }

            let result = null;

            for (let i = 0; i < 120 && !result; i++) {
                await new Promise((resolve) => setTimeout(resolve, 1500));

                try {
                    const { data } = await Craft.sendActionRequest('GET', 'ghostwriter/gaps/fill-status', { params: { id: started.id } });

                    if (data.status !== 'working') result = data;
                } catch (error) {
                    return;
                }
            }

            if (!result || result.status !== 'done') {
                Craft.cp.displayError(result?.message ?? t('Something went wrong.'));

                return;
            }

            const text = result.text ?? '';

            if (action === 'write-around') {
                if (editor) {
                    editor.model.change((writer) => {
                        writer.remove(sentence.range);
                        writer.insertText(text, sentence.range.start);
                    });
                } else if (input) {
                    this.setInput(input, input.value.slice(0, sentence.start) + text + input.value.slice(sentence.end));
                }
            } else if (editor) {
                editor.setData(`<p>${esc(text)}</p>`);
            } else if (input) {
                this.setInput(input, text);
            }

            this.fixed(gap);
        },

        /** The sentence around the gap's marker in a plain value. */
        sentenceIn(value, gap) {
            const at = this.markerIn(value, gap);

            if (!at) return null;

            const start = Math.max(value.lastIndexOf('. ', at.index) + 2, value.lastIndexOf('\n', at.index) + 1, 0);
            const stop = [value.indexOf('. ', at.index + at.length), value.indexOf('\n', at.index + at.length)].filter((i) => i >= 0);
            const end = stop.length ? Math.min(...stop) + 1 : value.length;

            return { text: value.slice(start === 1 ? 0 : start, end).trim(), start: start === 1 ? 0 : start, end };
        },

        sentenceInEditor(editor, gap) {
            const range = this.rangeFor(editor, gap);

            if (!range) return null;

            for (const block of this.textBlocks(editor.model, editor.model.document.getRoot())) {
                const index = block.positions.findIndex((position) => position.isEqual(range.start));

                if (index < 0) continue;

                const text = block.text;
                const before = text.lastIndexOf('. ', index);
                const start = before < 0 ? 0 : before + 2;
                const after = text.indexOf('. ', index);
                const end = after < 0 ? text.length : after + 1;

                return {
                    text: text.slice(start, end).trim(),
                    range: editor.model.createRange(block.positions[start], block.positions[end]),
                };
            }

            return null;
        },

        /** A block in cards view: its own slideout, which has the field. */
        openBlock(gap) {
            const found = this.cardFor(gap);

            if (!found) return;

            const slideout = Craft.createElementEditor(ENTRY, found.card, { siteId: this.config.siteId });

            slideout?.on?.('load', () => setTimeout(() => this.go(this.index), 100));
            slideout?.on?.('submit close', () => this.check());
        },

        /* ------------------------------------------------------------------
         * Skipped and dismissed, for this person and this page
         * ------------------------------------------------------------------ */

        storageKey(kind) {
            const id = this.editor()?.settings?.canonicalId ?? this.config.elementId;

            return `ghostwriter:finish:${kind}:${id}:${this.config.siteId}`;
        },

        skippedSet() {
            try {
                return new Set(JSON.parse(sessionStorage.getItem(this.storageKey('skipped')) ?? '[]'));
            } catch (error) {
                return new Set();
            }
        },

        skippedBefore(id) {
            try {
                return JSON.parse(sessionStorage.getItem(this.storageKey('skipped')) ?? '[]').includes(id);
            } catch (error) {
                return false;
            }
        },

        rememberSkip(id) {
            try {
                const skipped = new Set(JSON.parse(sessionStorage.getItem(this.storageKey('skipped')) ?? '[]'));
                skipped.add(id);
                sessionStorage.setItem(this.storageKey('skipped'), JSON.stringify([...skipped]));
            } catch (error) {}
        },

        forgetSkip(id) {
            try {
                const skipped = JSON.parse(sessionStorage.getItem(this.storageKey('skipped')) ?? '[]').filter((other) => other !== id);
                sessionStorage.setItem(this.storageKey('skipped'), JSON.stringify(skipped));
            } catch (error) {}
        },

        loadDismissed() {
            try {
                return JSON.parse(localStorage.getItem(this.storageKey('dismissed')) ?? '[]');
            } catch (error) {
                return [];
            }
        },

        saveDismissed() {
            try {
                localStorage.setItem(this.storageKey('dismissed'), JSON.stringify(this.dismissed));
            } catch (error) {}
        },
    });
})();
