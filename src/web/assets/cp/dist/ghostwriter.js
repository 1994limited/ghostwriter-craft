/**
 * Ghostwriter's control panel screens, written on Craft's own Garnish and
 * Craft.* helpers so they behave like the rest of the control panel.
 *
 * Model calls run in Craft's queue. While one is in flight a screen polls
 * its status action until the answer lands.
 */
(function () {
    window.Ghostwriter = window.Ghostwriter || {};

    const POLL = 2500;

    /**
     * Post to a Ghostwriter action and hand back the JSON, or show why not.
     */
    Ghostwriter.request = async function (method, action, data) {
        try {
            const response = await Craft.sendActionRequest(method, `ghostwriter/${action}`, method === 'GET' ? { params: data } : { data });

            // A job was just queued. Craft only starts its queue by itself
            // when a page loads, so ask it to run now, as Craft's own
            // screens do; otherwise the job waits for the next page load.
            if (method !== 'GET' && response.data?.status === 'working') {
                Craft.cp?.runQueue?.();
            }

            return response.data;
        } catch (error) {
            Craft.cp.displayError(error?.response?.data?.message ?? Craft.t('ghostwriter', 'Something went wrong.'));

            throw error;
        }
    };

    /**
     * Give buttons the inner markup Craft's own buttons have: the text in a
     * label and a spinner beside it. Craft shows a busy (.loading) button by
     * hiding the label and showing that spinner, so without it nothing
     * would change.
     */
    Ghostwriter.prepareButtons = function (root) {
        $(root).find('.btn').addBack('.btn').each((i, button) => {
            if (button.querySelector(':scope > .label, :scope > .spinner, :scope > .inline-flex')) {
                return;
            }

            const $button = $(button);
            const html = $button.html();

            $button.empty().append($('<div class="label"/>').html(html)).append('<div class="spinner spinner-absolute"></div>');
        });
    };

    $(() => Ghostwriter.prepareButtons(document.body));

    /**
     * Change the page's address without reloading, so a refresh lands where
     * the person is. A null value takes the parameter away.
     */
    Ghostwriter.address = function (params) {
        try {
            const url = new URL(window.location.href);

            Object.entries(params).forEach(([key, value]) => (value === null ? url.searchParams.delete(key) : url.searchParams.set(key, value)));
            window.history.replaceState(window.history.state, '', url.toString());
        } catch (error) {}
    };

    /** Whether stored writing holds a gap marker to show as a chip (core's markers.js does the rest). */
    Ghostwriter.hasGapMarkers = function (text) {
        return typeof text === 'string' && (text.includes('[[') || text.includes('#gw-link:'));
    };

    // Safe in text and in attributes alike.
    Ghostwriter.escape = function (text) {
        const div = document.createElement('div');
        div.textContent = text ?? '';

        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    /**
     * A guide's screen (the voice, or the image style): generate, edit by
     * hand, and for the voice refine by asking.
     */
    Ghostwriter.GuideScreen = Garnish.Base.extend({
        init(config) {
            this.config = config;
            this.current = config.state;
            this.timer = null;

            this.$save = $('#gw-voice-save');
            this.$document = $('#gw-voice-document');
            this.$scan = $('#gw-voice-scan');
            this.$send = $('#gw-voice-send');
            this.$message = $('#gw-voice-message');

            this.addListener(this.$save, 'click', 'save');
            this.addListener(this.$scan, 'click', 'scan');
            this.addListener(this.$send, 'click', 'refine');
            this.addListener(this.$document, 'input', 'render');
            this.addListener($('#gw-voice-sections input[type=checkbox]'), 'change', 'render');

            // Cmd/Ctrl+S saves, as on any other edit screen.
            Garnish.uiLayerManager.registerShortcut({ keyCode: Garnish.S_KEY, ctrl: true }, () => this.save());

            this.render();

            if (this.working()) {
                this.poll();
            }
        },

        working() {
            return this.current.status === 'working';
        },

        dirty() {
            return this.$document.val().trim() !== (this.current.document ?? '').trim();
        },

        apply(data) {
            const edited = this.dirty();

            this.current = data;

            // A finished scan or refinement replaces the text; an unsaved
            // hand edit is only overwritten when the guide itself changed.
            if (!edited || data.status !== 'working') {
                this.$document.val(data.document ?? '');
            }

            this.render();

            if (this.working()) {
                this.poll();
            }
        },

        render() {
            const state = this.current;
            const working = this.working();
            const dirty = this.dirty();

            $('#gw-voice-scanning').toggleClass('hidden', !(working && state.task === 'scan'));
            $('#gw-voice-empty').toggleClass('hidden', state.exists || working);
            $('#gw-voice-editor').toggleClass('hidden', !state.exists || (working && state.task === 'scan'));
            $('#gw-voice-refine').toggleClass('hidden', !state.exists);
            $('#gw-voice-error').toggleClass('hidden', state.status !== 'failed').find('[data-error]').text(state.error ?? '');
            $('#gw-voice-updated').toggleClass('hidden', !state.updatedAt).text(Craft.t('ghostwriter', this.config.labels.updated, { when: state.updatedAt }));
            $('#gw-voice-scanned').toggleClass('hidden', !state.scanned.length).text(Craft.t('ghostwriter', this.config.labels.scanned, { count: state.scanned.length }));
            $('#gw-voice-dirty').toggleClass('hidden', !dirty);

            this.$document.prop('readonly', working);
            this.$save.toggleClass('disabled', !dirty || working).prop('disabled', !dirty || working);
            // Craft styles a disabled button by its class, not the attribute.
            // Nothing ticked is nothing to read.
            const scanOff = !this.config.configured || working || !this.selected().length;
            const sendOff = !this.config.configured || working || dirty;

            this.$scan.toggleClass('loading', working && state.task === 'scan').toggleClass('disabled', scanOff).prop('disabled', scanOff);
            this.$send.toggleClass('loading', working && state.task === 'refine').toggleClass('disabled', sendOff).prop('disabled', sendOff);
            this.$message.prop('disabled', working);

            if (state.exists) {
                Ghostwriter.prepareButtons(this.$scan);
                this.$scan.removeClass('submit').find('.label').text(this.config.labels.rescan);
                $('#gw-voice-heading').text(this.config.labels.headingAgain);
            }

            $('#gw-voice-messages').html(state.messages.map((message) => `<div class="gw-message gw-message--${message.role === 'user' ? 'user' : 'assistant'}">${Ghostwriter.escape(message.content)}</div>`).join(''));
        },

        poll() {
            clearTimeout(this.timer);

            this.timer = setTimeout(async () => {
                try {
                    this.apply(await Ghostwriter.request('GET', this.config.actions.status));
                } catch (error) {
                    this.poll();
                }
            }, POLL);
        },

        selected() {
            return $('#gw-voice-sections input[type=checkbox]:checked').map((i, input) => input.value).get();
        },

        async scan() {
            if (!this.selected().length) {
                return;
            }

            if (this.current.exists && !confirm(this.config.labels.confirm)) {
                return;
            }

            try {
                this.apply(await Ghostwriter.request('POST', this.config.actions.scan, { sections: this.selected() }));
            } catch (error) {}
        },

        async refine() {
            const message = this.$message.val().trim();

            if (!message) {
                return;
            }

            this.$message.val('');

            try {
                this.apply(await Ghostwriter.request('POST', this.config.actions.refine, { message }));
            } catch (error) {
                this.$message.val(message);
            }
        },

        async save() {
            if (!this.dirty() || this.working()) {
                return;
            }

            try {
                const data = await Ghostwriter.request('POST', this.config.actions.update, { document: this.$document.val() });

                this.current = data;
                this.$document.val(data.document);
                this.render();
                Craft.cp.displaySuccess(this.config.labels.saved);
            } catch (error) {}
        },
    });
})();

/**
 * The panel on an entry's edit screen, in a modal over the form. The
 * steps follow the Statamic addon's panel:
 *
 *   setup    teach Ghostwriter a kind of content
 *   type     choose what to write (skipped when there is only one kind)
 *   write    the conversation, with the draft beside it. It opens by
 *            asking for the quick details, fills in the brief from the
 *            reply and shows it as a card to check; agreeing starts the
 *            writing, and the card folds away to "Show the brief".
 *
 * "Use this draft" writes the draft into the entry's Craft draft on the
 * server, then reloads the form so the person sees it there, filled in.
 */
(function () {
    const POLL = 2500;
    const MAX_EXAMPLES = 6;
    const esc = (text) => Ghostwriter.escape(text);
    const t = (message, params) => Craft.t('ghostwriter', message, params);
    const NOTICE = 'ghostwriter:applied';
    const STILL = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    // A draft just put into the form, said once the form has reloaded: one
    // notification, listing anything still to do. With notes it stays until
    // it is closed, since the notes are a to-do list.
    $(() => {
        try {
            const notes = JSON.parse(sessionStorage.getItem(NOTICE) ?? 'null');

            if (notes) {
                sessionStorage.removeItem(NOTICE);
                Ghostwriter.announceApplied(notes.message, notes.notes ?? []);

                // "Finish this page" opens by itself after a draft goes in.
                Ghostwriter.draftApplied = Boolean(notes.finish);
                document.dispatchEvent(new CustomEvent('ghostwriter:draft-applied', { detail: { finish: Boolean(notes.finish) } }));
            }
        } catch (error) {}
    });

    /**
     * "Started by Ann · last changed by you", when conversations are shared.
     */
    Ghostwriter.people = function (item) {
        const t = (message, params) => Craft.t('ghostwriter', message, params);

        return [
            item.startedBy ? t('Started by {name}', { name: item.startedBy }) : null,
            item.touchedBy ? t('last changed by {name}', { name: item.touchedBy }) : null,
            item.waitingOn ? t('{name} is waiting on Ghostwriter', { name: item.waitingOn }) : null,
        ].filter(Boolean).join(' · ');
    };

    Ghostwriter.announceApplied = function (message, notes) {
        if (!notes.length) {
            return Craft.cp.displaySuccess(message);
        }

        const $list = $('<ul class="gw-notes"/>').append(notes.map((note) => $('<li/>').text(note)));

        return Craft.cp.displaySuccess(message, { details: $list, persist: true });
    };

    /**
     * "Write with Ghostwriter" beside "New entry" on the entry index, while
     * a section Ghostwriter writes for is chosen and the person may make
     * entries in it. It starts a new entry with the panel open, as the
     * dashboard does.
     */
    Ghostwriter.IndexButton = Garnish.Base.extend({
        init(config) {
            this.config = config;

            $(() => {
                const index = Craft.elementIndex;

                if (!index || !index.on) return;

                // After Craft has drawn its own button for the source.
                index.on('selectSource selectSite', () => setTimeout(() => this.update(index)));
                this.update(index);
            });
        },

        update(index) {
            $('#gw-index-write').remove();

            const handle = index.$source?.data('handle');
            const url = handle ? this.config.sections[handle] : null;
            const allowed = (index.publishableSections ?? []).some((section) => section.handle === handle);
            const $new = index.$newEntryBtnGroup;

            if (!url || !allowed || !$new?.length || !$new.closest('body').length) return;

            $(`<a id="gw-index-write" class="btn gw-index-write" href="${esc(url)}"><span class="gw-mark" aria-hidden="true"></span>${esc(this.config.label)}</a>`).insertBefore($new);
        },
    });

    Ghostwriter.Launcher = Garnish.Base.extend({
        init(config) {
            this.config = config;
            this.modal = null;
            this.panel = null;

            $(() => {
                this.addListener($('#ghostwriter-launch'), 'click', () => this.open(this.panel ? null : config.current));

                // A conversation named in the address wins; otherwise carry
                // on with this entry's own, unless a plan idea is being opened.
                if (config.open) {
                    this.open(config.open !== 'new' ? config.open : (config.idea ? null : config.current));
                }
            });
        },

        open(resume = null) {
            if (this.modal) {
                this.modal.show();

                if (resume) this.panel.openSession(resume);

                return;
            }

            // A large modal over the form: the entry stays where it is
            // underneath, and the draft is put into it when it is ready.
            const $modal = $('<div class="modal gw-modal" role="dialog"/>').attr('aria-label', t('Ghostwriter')).appendTo(Garnish.$bod);
            const $body = $('<div class="gw-panel"/>').appendTo($modal);

            this.modal = new Garnish.Modal($modal, {
                hideOnEsc: true,
                hideOnShadeClick: false,
                resizable: false,
            });

            this.panel = new Ghostwriter.Panel($body, this.config, {
                resume,
                close: () => this.modal.hide(),
                applied: (data) => this.applied(data),
            });
        },

        /**
         * The draft is in the entry's Craft draft. Reload the form to show it,
         * without the parameters that would open the panel again.
         */
        applied(data) {
            try {
                sessionStorage.setItem(NOTICE, JSON.stringify({ message: this.config.editing ? t('Changes added to the form. Check them over, then save.') : t('Draft added to the form. Check it over, then save.'), notes: data.notes ?? [], finish: Boolean(data.finish) }));
            } catch (error) {}

            const url = new URL(window.location.href);
            url.searchParams.delete('ghostwriter');
            url.searchParams.delete('idea');

            if (data.draftId && !url.searchParams.get('draftId')) {
                url.searchParams.set('draftId', data.draftId);
            }

            window.location.href = url.toString();
        },
    });

    /**
     * A gap resolved from its chip, in the draft itself: the small popover a
     * chip opens in the Preview or the Text tab, anchored to the chip inside
     * the draft (over the preview's frame).
     *
     * - A fact to add: "Only you know this: adult ticket price", an answer
     *   box, Add it, and Leave it for later.
     * - A count to check: "Counted from ‘…’. 3 areas, is that right?", with
     *   Looks right, Change it and Remove it.
     * - A link to choose: the entries its hint suggests (no model), or
     *   Choose an entry to search for one.
     *
     * The answer goes into the draft exactly as typed (`resolve`); Leave it
     * for later changes nothing. It is a dialog: focus moves into it, Tab
     * stays in it, Esc closes it, and the panel puts focus back on the chip.
     */
    class GapPopover {
        /**
         * @param {object} gap {kind, hint, value?, list?, element, frame?}
         * @param {HTMLElement} container The positioned element it sits in.
         * @param {{resolve: (value: string, reference: ?string) => void, close: (refocus: boolean) => void, search: (q: string) => Promise<Array>}} actions
         */
        constructor(gap, container, actions) {
            this.gap = gap;
            this.container = container;
            this.actions = actions;
            this.busy = false;
            this.id = `gw-gap-${++GapPopover.ids}`;

            const root = document.createElement('div');
            root.className = 'gw-gap-popover';
            root.setAttribute('role', 'dialog');
            root.setAttribute('aria-modal', 'true');
            root.setAttribute('aria-labelledby', `${this.id}-title`);
            root.setAttribute('tabindex', '-1');
            root.dataset.kind = gap.kind;
            root.dataset.ghostwriterGapPopover = '';
            this.root = root;
            this.draw();
            container.appendChild(root);

            this.place = this.place.bind(this);
            this.outside = this.outside.bind(this);
            root.addEventListener('keydown', (event) => this.key(event));
            root.addEventListener('click', (event) => this.click(event));
            root.addEventListener('input', (event) => this.input(event));
            document.addEventListener('scroll', this.place, true);
            window.addEventListener('resize', this.place);
            gap.frame?.contentWindow?.addEventListener('scroll', this.place, { passive: true });
            document.addEventListener('mousedown', this.outside, true);
            gap.frame?.contentDocument?.addEventListener('mousedown', this.outside, true);

            this.place();
            this.focusFirst();

            if (gap.kind === 'link') this.suggest();
        }

        title() {
            if (this.gap.kind === 'ask') return t('Only you know this: {hint}', { hint: this.gap.hint });
            if (this.gap.kind === 'check') return t('Counted from ‘{list}’. {value}, is that right?', { list: this.gap.list ?? '', value: this.gap.value ?? this.gap.hint });

            return t('Link to choose');
        }

        draw(state = {}) {
            const gap = this.gap;
            const field = (name, label, value = '') => `<label class="gw-gap-popover__label" for="${this.id}-${name}">${esc(label)}</label><input id="${this.id}-${name}" type="text" class="text fullwidth" data-gap-input="${name}" value="${esc(value)}" autocomplete="off">`;
            let body = '';

            if (gap.kind === 'ask') {
                body = `${field('answer', t('Your answer goes into the draft exactly as you type it.'))}
                    <div class="gw-gap-popover__actions">
                        <button type="button" class="btn" data-gap="later">${esc(t('Leave it for later'))}</button>
                        <button type="button" class="btn submit" data-gap="answer">${esc(t('Add it'))}</button>
                    </div>`;
            } else if (gap.kind === 'check') {
                body = state.changing
                    ? `${field('change', t('What the page should say'), gap.value ?? gap.hint)}
                        <div class="gw-gap-popover__actions">
                            <button type="button" class="btn" data-gap="cancel-change">${esc(t('Cancel'))}</button>
                            <button type="button" class="btn submit" data-gap="save-change">${esc(t('Save'))}</button>
                        </div>`
                    : `<div class="gw-gap-popover__actions">
                            <button type="button" class="btn" data-gap="remove">${esc(t('Remove it'))}</button>
                            <button type="button" class="btn" data-gap="change">${esc(t('Change it'))}</button>
                            <button type="button" class="btn submit" data-gap="confirm">${esc(t('Looks right'))}</button>
                        </div>`;
            } else {
                const list = (entries, prefix) => entries.length
                    ? `<ul class="gw-gap-popover__entries">${entries.map((entry, i) => `<li><button type="button" class="gw-gap-popover__entry" data-gap="choose" data-entry="${i}" data-from="${prefix}"><strong>${esc(prefix === 'suggested' ? t('Link to {title}', { title: entry.title }) : entry.title)}</strong>${entry.url ? `<span class="light">${esc(entry.url)}</span>` : ''}</button></li>`).join('')}</ul>`
                    : '';

                body = `${gap.hint ? `<p class="light gw-gap-popover__meant">${esc(t('Ghostwriter meant: {hint}', { hint: gap.hint }))}</p>` : ''}
                    ${state.choosing
                        ? `${field('query', t('Find a page by its title'), state.query ?? '')}
                            <p class="visually-hidden" role="status">${esc(state.results ? t('{count, plural, =1{# page found} other{# pages found}}', { count: state.results.length }) : '')}</p>
                            ${state.results ? (state.results.length ? list(state.results, 'results') : `<p class="light">${esc(t('No page has that title.'))}</p>`) : ''}`
                        : (this.suggestions === undefined
                            ? `<p class="light" role="status">${esc(t('Looking for pages…'))}</p>`
                            : (this.suggestions.length ? list(this.suggestions, 'suggested') : `<p class="light">${esc(t('No page matches it. Choose one instead.'))}</p>`))}
                    <div class="gw-gap-popover__actions">
                        <button type="button" class="btn" data-gap="later">${esc(t('Leave it for later'))}</button>
                        ${state.choosing ? '' : `<button type="button" class="btn" data-gap="choose-entry">${esc(t('Choose an entry'))}</button>`}
                    </div>`;
            }

            this.state = state;
            this.root.innerHTML = `<p class="gw-gap-popover__title" id="${this.id}-title">${esc(this.title())}</p>${body}`;
            Ghostwriter.prepareButtons(this.root);
        }

        // Below the chip (above it when there's no room), inside the container.
        place() {
            const gap = this.gap;

            if (!gap.element?.isConnected) return;

            const outer = this.container.getBoundingClientRect();
            const box = gap.element.getBoundingClientRect();
            let anchor = box;

            if (gap.frame) {
                const frame = gap.frame.getBoundingClientRect();
                const scale = gap.frame.offsetWidth ? frame.width / gap.frame.offsetWidth : 1;

                anchor = { left: frame.left + box.left * scale, top: frame.top + box.top * scale, bottom: frame.top + box.bottom * scale };
            }

            const width = Math.min(340, Math.max(220, this.container.clientWidth - 16));
            const height = this.root.offsetHeight || 160;
            const left = Math.min(Math.max(8, anchor.left - outer.left), Math.max(8, this.container.clientWidth - width - 8));
            const below = anchor.bottom - outer.top + 6;
            const above = anchor.top - outer.top - height - 6;
            const top = below + height > this.container.clientHeight - 4 && above > 4 ? above : Math.min(below, Math.max(4, this.container.clientHeight - height - 4));

            Object.assign(this.root.style, { left: `${left}px`, top: `${top}px`, width: `${width}px` });
        }

        focusFirst() {
            (this.root.querySelector('input, button:not([disabled])') ?? this.root).focus();
        }

        focusable() {
            return [...this.root.querySelectorAll('input, button:not([disabled])')].filter((element) => element.offsetParent !== null);
        }

        key(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();

                return this.actions.close(true);
            }

            if (event.key === 'Tab') {
                const all = this.focusable();
                const first = all[0];
                const last = all[all.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }

                return;
            }

            if (event.key === 'Enter' && event.target.matches?.('[data-gap-input]')) {
                event.preventDefault();
                event.stopPropagation();

                const name = event.target.dataset.gapInput;

                if (name === 'answer') this.submit('answer');
                if (name === 'change') this.submit('save-change');
            }
        }

        click(event) {
            const button = event.target.closest('[data-gap]');

            if (button) this.submit(button.dataset.gap, button);
        }

        input(event) {
            if (event.target.dataset.gapInput !== 'query') return;

            clearTimeout(this.searchTimer);
            const query = event.target.value;
            this.searchTimer = setTimeout(async () => {
                const results = await this.actions.search(query);

                this.draw({ choosing: true, query, results });
                const input = this.root.querySelector('[data-gap-input=query]');
                input?.focus();
                input?.setSelectionRange(query.length, query.length);
                this.place();
            }, 250);
        }

        submit(action, button = null) {
            if (this.busy && !['later', 'cancel-change', 'change', 'choose-entry'].includes(action)) return;

            const value = (name) => this.root.querySelector(`[data-gap-input=${name}]`)?.value ?? '';

            switch (action) {
                case 'later':
                    return this.actions.close(true);
                case 'answer':
                    return value('answer').trim() === '' ? null : this.resolve(value('answer'));
                case 'confirm':
                    return this.resolve(this.gap.value ?? this.gap.hint);
                case 'remove':
                    return this.resolve('');
                case 'change':
                    this.draw({ changing: true });
                    this.place();

                    return this.root.querySelector('[data-gap-input=change]')?.select();
                case 'cancel-change':
                    this.draw({});

                    return this.focusFirst();
                case 'save-change':
                    return value('change').trim() === '' ? null : this.resolve(value('change').trim());
                case 'choose-entry':
                    this.draw({ choosing: true });
                    this.place();

                    return this.root.querySelector('[data-gap-input=query]')?.focus();
                case 'choose': {
                    const entries = button.dataset.from === 'suggested' ? this.suggestions : this.state.results;
                    const entry = entries?.[Number(button.dataset.entry)];

                    return entry ? this.resolve(entry.url ?? String(entry.value), String(entry.value)) : null;
                }
            }

            return null;
        }

        resolve(value, reference = null) {
            this.busy = true;
            this.root.querySelectorAll('.btn.submit').forEach((b) => b.classList.add('loading'));
            this.actions.resolve(value, reference);
        }

        idle() {
            this.busy = false;
            this.root.querySelectorAll('.btn.loading').forEach((b) => b.classList.remove('loading'));
        }

        async suggest() {
            this.suggestions = await this.actions.search(this.gap.hint);

            if (!this.state?.choosing && this.root.isConnected) {
                this.draw({});
                this.place();
            }
        }

        // A press outside closes it, without moving focus back.
        outside(event) {
            if (this.root.contains(event.target) || event.target === this.gap.element || this.gap.element?.contains?.(event.target)) return;

            this.actions.close(false);
        }

        destroy() {
            clearTimeout(this.searchTimer);
            document.removeEventListener('scroll', this.place, true);
            window.removeEventListener('resize', this.place);
            this.gap.frame?.contentWindow?.removeEventListener('scroll', this.place);
            document.removeEventListener('mousedown', this.outside, true);
            this.gap.frame?.contentDocument?.removeEventListener('mousedown', this.outside, true);
            this.root.remove();
        }
    }

    GapPopover.ids = 0;
    Ghostwriter.GapPopover = GapPopover;

    Ghostwriter.Panel = Garnish.Base.extend({
        init($container, config, options) {
            this.$container = $container;
            this.config = config;
            this.options = options;

            this.info = null;
            this.type = null;
            this.examples = [];
            // The brief card as the person is changing it, before it is sent.
            this.card = null;
            this.cardKey = null;
            this.errors = {};
            this.busy = false;
            this.adding = false;
            this.session = null;
            this.message = '';
            this.editing = false;
            this.raw = '';
            this.showBrief = false;
            this.timer = null;
            this.ticker = null;
            this.waited = 0;
            this.learn = { title: '', picked: [] };
            // The content plan idea this piece is being written from, if any.
            this.planned = null;
            // How the draft is shown: as the page it makes (the default,
            // where the site can show it), its blocks, or just its words.
            this.view = this.config.preview ? 'preview' : 'blocks';
            // On a phone, the phone layout, unless the person chose otherwise.
            this.width = window.innerWidth < 640 ? 'phone' : 'desktop';
            this.page = null;
            // The layout cards above the draft, kept between redraws.
            this.cards = null;
            // The gap chip whose popover is open (Preview or Text), and the popover.
            this.gap = null;
            this.popover = null;
            this.refocusPreview = false;
            // In a narrow panel the conversation and the draft are one column,
            // one at a time, with a switch between them at the top.
            this.narrow = false;
            this.pane = 'conversation';
            this.draftFresh = false;

            try {
                const view = localStorage.getItem('ghostwriter:draft-view');

                if (['blocks', 'text'].includes(view) || (view === 'preview' && this.config.preview)) this.view = view;
                const width = localStorage.getItem('ghostwriter:preview-width');

                if (['desktop', 'phone'].includes(width)) this.width = width;
            } catch (error) {}

            this.addListener(this.$container, 'click', 'onClick');
            this.addListener(this.$container, 'input', 'onInput');
            this.addListener(this.$container, 'change', 'onInput');
            this.addListener(this.$container, 'keydown', 'onKeydown');
            this.addListener(this.$container, 'focusin', 'onFocusIn');
            this.addListener(this.$container, 'focusout', 'onFocusOut');
            this.addListener(this.$container, 'mousedown', 'onMouseDown');

            this.sizer = new ResizeObserver(() => this.measure());
            this.sizer.observe(this.$container[0]);

            this.render();
            this.start();
        },

        async start() {
            await this.load();

            // An entry that exists already: its content as it stands is the
            // draft, and the conversation is about what to change.
            if (this.config.editing) {
                try {
                    this.receive(await Ghostwriter.request('POST', 'sessions/edit', { elementId: this.config.elementId, siteId: this.config.siteId }));
                } catch (error) {}

                return;
            }

            if (this.options.resume) {
                await this.openSession(this.options.resume);
            } else if (this.config.idea) {
                const idea = this.info?.ideas.find((candidate) => candidate.id === this.config.idea);

                if (idea) this.fromIdea(idea);
            }
        },

        // ---- Data ---------------------------------------------------------

        async load() {
            const wasLearning = this.learning();

            try {
                this.info = await Ghostwriter.request('GET', 'sections/show', { section: this.config.section, entryType: this.config.entryType ?? '' });
            } catch (error) {
                return;
            }

            if (this.info.state.status === 'working') {
                this.later(() => this.load());
            } else {
                if (wasLearning && this.info.state.status === 'idle') {
                    this.adding = false;
                    Craft.cp.displaySuccess(t('Learned. It is now on the list.'));
                }

                if (this.nothingToChoose() && !this.adding && !this.type) {
                    this.choose(this.info.types[0]);
                }
            }

            this.render();
        },

        async reloadEntry() {
            if (!window.confirm(t('Start again from the entry as it stands? Changes asked for here that are not yet in the entry are lost.'))) {
                return;
            }

            try {
                this.receive(await Ghostwriter.request('POST', 'sessions/edit', { elementId: this.config.elementId, siteId: this.config.siteId, fresh: 1 }));
            } catch (error) {}
        },

        async openSession(id) {
            try {
                this.receive(await Ghostwriter.request('GET', 'sessions/show', { id }));
            } catch (error) {}
        },

        receive(data) {
            const changed = data.draft !== this.session?.draft;
            const stepChanged = !this.session;
            const filled = data.stage === 'proposed' && this.session?.stage === 'filling';

            // Another piece: its own preview. The same one: render the new
            // draft once the changes stop, or at once for the first draft.
            const opened = data.id !== this.session?.id;

            if (data.id !== this.session?.id) {
                this.page?.reset();
                this.cards?.reset();
                this.closeGap(false);
                this.pane = data.draft ? 'draft' : 'conversation';
                this.draftFresh = false;
            } else if (this.page && data.draft && this.pageKey(data) !== this.pageKey(this.session)) {
                this.page.changed(this.pageKey(data), !this.session?.draft);
            }

            this.session = data;
            this.config.current = data.id;

            if (data.id) Ghostwriter.address({ ghostwriter: data.id, idea: null });

            if (changed) {
                this.raw = data.draft ?? '';
                this.editing = false;

                // In a narrow panel, a new draft while the conversation shows: marked on Draft.
                if (!opened && this.narrow && this.pane === 'conversation' && data.draft) this.draftFresh = true;
            }

            // Questions waiting, or the brief to check: the conversation.
            if ((data.waitingOnYou === true && data.status !== 'working') || filled) this.pane = 'conversation';

            if (data.status === 'working') {
                this.later(() => this.openSession(data.id));
            }

            this.tick();

            if (stepChanged) {
                this.render();
            } else {
                this.renderConversation();
                this.renderDraft();
                this.renderComposer();
            }

            this.measure();
            this.applyPane();

            this.$container.find('.gw-chat-log').each((i, log) => (log.scrollTop = log.scrollHeight));

            // The brief is filled in: say so, and bring the card into view
            // with the focus on it, unless the person is typing elsewhere.
            if (filled) {
                this.announce(t('brief.filled'));

                const $card = this.$container.find('.gw-brief-card');
                const active = document.activeElement;

                $card[0]?.scrollIntoView({ block: 'start', behavior: STILL() ? 'auto' : 'smooth' });

                if (!active || active === document.body || !$.contains(this.$container[0], active) || active.disabled) {
                    $card.find('[data-card-heading]').trigger('focus');
                }
            }

            // Questions waiting, or the quick details: put the cursor where
            // the answer goes.
            if (this.asking() || (data.stage === 'details' && stepChanged)) {
                this.$container.find('[data-model="message"]').trigger('focus');
            }
        },

        // Said to screen readers, politely.
        announce(text) {
            const $live = this.$container.find('[data-live]');

            $live.text('');
            setTimeout(() => $live.text(text), 50);
        },

        later(callback) {
            clearTimeout(this.timer);
            this.timer = setTimeout(callback, POLL);
        },

        // Count from the message being answered, so reopening the panel
        // mid-turn shows how long it has really been.
        tick() {
            clearInterval(this.ticker);

            if (!this.working()) {
                return;
            }

            const since = Date.parse(this.session.since ?? '') || Date.now();
            const update = () => {
                this.waited = Math.max(0, Math.round((Date.now() - since) / 1000));
                this.$container.find('[data-progress]').text(this.progress());
                this.$container.find('[data-elapsed]').text(`${Math.floor(this.waited / 60)}:${String(this.waited % 60).padStart(2, '0')}`);
            };

            update();
            this.ticker = setInterval(update, 1000);
        },

        // ---- State --------------------------------------------------------

        step() {
            if (!this.info || (this.busy && !this.session)) return 'loading';
            if (this.session) return 'write';
            if (this.adding || this.learning()) return 'setup';

            return 'type';
        },

        learning() {
            return this.info?.state.status === 'working';
        },

        // With only the general brief on offer and no kinds to suggest, there
        // is nothing to choose between.
        nothingToChoose() {
            return this.info.types.length === 1 && this.info.kinds.length === 0 && this.info.ideas.length === 0;
        },

        working() {
            return this.session?.status === 'working';
        },

        asking() {
            return this.session?.waitingOnYou === true && !this.working();
        },

        progress() {
            const drafted = !!this.session?.draft;

            if (this.session?.stage === 'filling') return t('brief.filling');
            if (this.session?.layouts?.planning) return t('Finding other layouts…');

            if (this.waited < 8) return drafted ? t('Reading your message…') : t('Reading the brief…');
            if (this.waited < 30) return drafted ? t('Revising the draft…') : t('Thinking it through…');

            return drafted ? t('Still revising. Long drafts take a while…') : t('Writing. Long drafts take a while…');
        },

        // `examples` preselects the entries to model this piece on: a kind's
        // members, or whatever the type itself was taught from. Choosing
        // opens the conversation with the quick-details question; nothing
        // is kept until the person answers it.
        choose(type, examples = null) {
            this.type = type;
            this.examples = (examples ?? type.examples ?? []).map(Number);
            this.planned = null;
            this.errors = {};
            this.receive(this.opening(type));
        },

        // A piece not started yet: the question, waiting for its answer.
        opening(type) {
            const ask = t('brief.ask');

            return {
                id: null, stage: 'details', status: 'idle', error: null, editing: false, waitingOnYou: false,
                type, title: '', card: null, briefText: null, since: null, draft: null, preview: [], words: 0,
                messages: [{ role: 'assistant', step: 'ask', content: ask, html: `<p>${esc(ask)}</p>` }],
            };
        },

        // An idea from the content plan: its kind of content, and the brief
        // filled in from its title and notes, ready to check.
        async fromIdea(idea) {
            const types = this.info.types;
            const type = types.find((candidate) => candidate.handle === idea.type) ?? types.find((candidate) => candidate.generic);

            if (!this.info.configured) return this.choose(type);

            this.type = type;
            this.examples = (type.examples ?? []).map(Number);
            this.planned = idea.id;
            this.busy = true;
            this.render();

            try {
                const data = await Ghostwriter.request('POST', 'sessions/from-idea', { idea: idea.id, type: type.handle, examples: this.examples, elementId: this.config.elementId, siteId: this.config.siteId });

                this.busy = false;
                this.receive(data);
            } catch (error) {
                this.busy = false;
                this.render();
            }
        },

        // ---- Actions ------------------------------------------------------

        onInput(event) {
            const $el = $(event.target);
            const model = $el.data('model');

            if (!model) return;

            const value = event.target.type === 'checkbox' ? event.target.checked : $el.val();

            if (model === 'card-answer') this.card.answers[$el.data('handle')] = value;
            else if (model === 'card-title') this.card.title = value;
            else if (model === 'message') this.message = value;
            else if (model === 'raw') this.raw = value;
            else if (model === 'learn-title') this.learn.title = value;
            else if (model === 'card-example' || model === 'learn-example') {
                const list = model === 'card-example' ? this.card.examples : this.learn.picked;
                const id = Number($el.val());
                const next = event.target.checked ? [...list, id].slice(0, MAX_EXAMPLES) : list.filter((picked) => picked !== id);

                if (model === 'card-example') this.card.examples = next;
                else this.learn.picked = next;

                this.renderPicked($el.closest('.gw-picker'), next);
            } else if (model === 'filter') {
                const term = String(value).trim().toLowerCase();
                $el.closest('.gw-picker').find('[data-entry]').each((i, row) => $(row).toggleClass('hidden', !!term && !row.dataset.title.includes(term)));
            }

            if (model === 'message') {
                this.$container.find('[data-action="send"]').prop('disabled', this.working() || !this.message.trim()).toggleClass('disabled', this.working() || !this.message.trim());
            }
        },

        onKeydown(event) {
            const model = $(event.target).data('model');

            // Conversation | Draft in a narrow panel: arrows, Home and End.
            if (event.target.dataset?.pane && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();

                const next = event.key === 'Home' ? 'conversation' : event.key === 'End' ? 'draft' : (this.pane === 'draft' ? 'conversation' : 'draft');

                return this.showPane(next, true);
            }

            // A piece of writing showing chips: Enter, F2 or typing starts editing it.
            if (this.isPainted(event.target) && this.editKey(event)) return;

            // The draft's tabs: arrows, Home and End move between them.
            if (event.target.getAttribute?.('role') === 'tab' && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                const tabs = $(event.target).closest('[role=tablist]').find('[role=tab]').toArray();
                const at = tabs.indexOf(event.target);
                const next = { ArrowLeft: at - 1, ArrowRight: at + 1, Home: 0, End: tabs.length - 1 }[event.key];

                event.preventDefault();

                return this.switchView(tabs[(next + tabs.length) % tabs.length].dataset.view, true);
            }
            const $field = $(event.target).closest('[data-edit-path], [data-edit-extra]');

            // Writing edited in place (the draft's, or an extra's): Escape
            // puts it back as it was (and leaves the panel open), Enter
            // finishes a one-line piece. Not on a chip, or a read view.
            if ($field.length && (this.isPainted($field[0]) || $(event.target).closest('.gw-gap').length)) return;

            if ($field.length && event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();

                if ($field.data('format') === 'html') $field.html($field.data('was') ?? '');
                else $field[0].innerText = $field.data('was') ?? '';

                $field[0].blur();

                return;
            }

            if ($field.length && event.key === 'Enter' && !$field.data('multiline')) {
                event.preventDefault();
                $field[0].blur();

                return;
            }

            if (model === 'message' && event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                this.send();
            }

            // In the brief card, ⌘↵ agrees (or saves, once agreed).
            if (String(model).startsWith('card-') && event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                this.briefAction(this.session.card?.agreed ? 'edit-brief' : 'agree');
            }
        },

        onClick(event) {
            const $target = $(event.target).closest('[data-action]');

            if (!$target.length || $target.prop('disabled')) return;

            event.preventDefault();

            const action = $target.data('action');
            const types = this.info?.types ?? [];

            switch (action) {
                case 'close': return this.options.close();
                case 'pane': return this.showPane($target.data('pane'));
                case 'teach': this.adding = true; return this.render();
                case 'cancel-teach': this.adding = false; return this.render();
                case 'learn': return this.startLearning();
                case 'choose': return this.choose(types.find((type) => type.handle === $target.data('type')));
                case 'choose-kind': return this.choose(types.find((type) => type.generic), this.info.kinds[$target.data('kind')].examples);
                case 'choose-general': return this.choose(types.find((type) => type.generic), []);
                case 'idea': return this.fromIdea(this.info.ideas.find((idea) => idea.id === $target.data('idea')));
                case 'agree': return this.briefAction('agree');
                case 'try-again': return this.briefAction('try-again');
                case 'save-brief': return this.briefAction('edit-brief');
                case 'resume': return this.openSession($target.data('id'));
                case 'toggle-brief':
                    this.showBrief = !this.showBrief;
                    this.renderConversation();
                    this.$container.find('[data-action="toggle-brief"]').trigger('focus');

                    return;
                case 'send': return this.send();
                case 'retry': return this.retry();
                case 'skip': this.message = t('Please draft it with what you have. Put anything you are unsure of in square brackets.'); return this.send();
                case 'reload-entry': return this.reloadEntry();
                case 'edit': this.editing = true; return this.renderDraft();
                case 'view': return this.switchView($target.data('view'), $target.is('[role=tab]'));
                case 'width':
                    this.width = $target.data('width');
                    try { localStorage.setItem('ghostwriter:preview-width', this.width); } catch (error) {}

                    return this.renderDraft();
                case 'cancel-edit': this.editing = false; this.raw = this.session.draft; return this.renderDraft();
                case 'save-draft': return this.saveDraft();
                case 'apply': return this.apply();
                case 'start-over': return this.startOver();
                case 'delete-extra': return this.deleteExtra($target.data('item'), $target.data('label'));
            }
        },

        async startLearning() {
            try {
                this.info = await Ghostwriter.request('POST', 'sections/analyse', { section: this.config.section, title: this.learn.title.trim(), examples: this.learn.picked });
                this.render();
                this.later(() => this.load());
            } catch (error) {}
        },

        // The brief card's buttons: "Looks right, start writing", "Try
        // again", and once agreed "Save the brief". Each sends the card as
        // the person left it.
        async briefAction(action) {
            if (this.busy || this.working() || !this.card) return;

            this.busy = action;
            this.errors = {};
            this.renderConversation();

            try {
                const data = await Ghostwriter.request('POST', `sessions/${action}`, { id: this.session.id, title: this.card.title, answers: this.card.answers, examples: this.card.examples });

                this.busy = false;

                if (action === 'edit-brief') {
                    this.cardKey = null;
                    Craft.cp.displayNotice(t('brief.saved'));
                    this.announce(t('brief.saved'));
                }

                if (action === 'agree') this.showBrief = false;

                this.receive(data);
            } catch (error) {
                this.busy = false;
                this.errors = error?.response?.data?.errors ?? {};
                this.renderConversation();
                this.$container.find('.gw-brief-card .has-errors textarea').first().trigger('focus');

                if (error?.response?.status === 409) this.openSession(this.session.id);
            }
        },

        async send() {
            const message = this.message.trim();

            if (!message || this.working()) return;

            this.message = '';
            this.$container.find('[data-model="message"]').val('');

            try {
                this.receive(this.session.id
                    ? await Ghostwriter.request('POST', 'sessions/message', { id: this.session.id, message })
                    // The answer to the quick-details question starts the piece.
                    : await Ghostwriter.request('POST', 'sessions/open', { type: this.type.handle, examples: this.examples, elementId: this.config.elementId, siteId: this.config.siteId, message }));
            } catch (error) {
                this.message = message;
                this.renderComposer();

                // Someone else's message is being answered: show it, and
                // wait with them.
                if (error?.response?.status === 409 && this.session.id) this.openSession(this.session.id);
            }
        },

        /**
         * Run the turn that failed again, with the same message.
         */
        async retry() {
            if (this.working()) return;

            try {
                this.receive(await Ghostwriter.request('POST', 'sessions/retry', { id: this.session.id }));
            } catch (error) {
                if (error?.response?.status === 409) this.openSession(this.session.id);
            }
        },

        // Writing edited where it is shown: remembered on the way in, saved
        // on the way out if it changed. A read view showing chips is not being
        // edited until it is clicked (startEditing()).
        onFocusIn(event) {
            const $field = $(event.target).closest('[data-edit-path], [data-edit-extra]');

            // Its read view, with chips: nothing is being edited yet.
            if (!$field.length || this.isPainted($field[0])) return;

            $field.data('was', this.fieldValue($field));
        },

        async onFocusOut(event) {
            const $field = $(event.target).closest('[data-edit-path], [data-edit-extra]');

            if (!$field.length || this.isPainted($field[0]) || event.target !== $field[0]) return;

            const value = this.fieldValue($field);

            if (value === $field.data('was')) {
                // Unchanged: back to the chips.
                if (this.currentView() === 'text') setTimeout(() => this.paintChips(), 0);

                return;
            }

            $field.addClass('is-saving');

            try {
                // An extra's words go to the extra; the rest to its place in the draft.
                const data = $field.is('[data-edit-extra]')
                    ? await Ghostwriter.request('POST', 'sessions/edit-extra', {
                        id: this.session.id,
                        item: $field.attr('data-edit-extra'),
                        part: $field.attr('data-part') ?? '',
                        format: $field.data('format'),
                        value,
                    })
                    : await Ghostwriter.request('POST', 'sessions/edit-field', {
                        id: this.session.id,
                        path: $field.attr('data-edit-path'),
                        format: $field.data('format'),
                        value,
                    });

                this.session = data;
                this.raw = data.draft ?? '';
                this.page?.changed(this.pageKey(data));

                // Redrawn only when nothing else is being typed in.
                if (!this.$container.find('[data-edit-path]:focus, [data-edit-extra]:focus').length) {
                    const scroll = this.$container.find('.gw-draft__body').scrollTop();
                    this.renderDraft();
                    this.$container.find('.gw-draft__body').scrollTop(scroll);
                }

                this.$container.find('.gw-draft__toolbar [data-words]').text(t('{count} words', { count: data.words.toLocaleString() }));
            } catch (error) {
                $field.removeClass('is-saving');
            }
        },

        // What the writing holds, as it is saved: never a chip's markup.
        fieldValue($field) {
            const element = $field[0];
            const html = $field.data('format') === 'html';

            if (element.querySelector('.gw-gap') && Ghostwriter.gapMarkersLoaded) {
                const clone = element.cloneNode(true);
                Ghostwriter.gapMarkersLoaded.unmarkGaps(clone);

                return html ? clone.innerHTML : clone.textContent.replace(/\n$/, '');
            }

            return html ? $field.html() : element.innerText.replace(/\n$/, '');
        },

        // A piece of writing that can be changed in place.
        editable(node, extra = '') {
            const off = this.working() || this.editing;
            // An extra's words are edited as the extra; the rest where they
            // are in the draft, which every layout shares.
            const where = node.extra
                ? `data-edit-extra="${esc(node.extra)}" data-part="${esc(node.part ?? '')}"`
                : `data-edit-path="${esc(JSON.stringify(node.path))}"`;

            // Writing with a marker in it shows chips in the Text tab (paintChips()).
            const raw = (text) => (Ghostwriter.hasGapMarkers(text) ? `data-raw="${esc(text)}"` : '');

            if (node.kind === 'html') {
                return `<div class="gw-prose gw-editable ${extra}" ${off ? '' : 'contenteditable="true"'} ${where} data-format="html" data-multiline="1" aria-label="${esc(node.label)}" ${raw(node.html)}>${node.html}</div>`;
            }

            return `<div class="gw-editable gw-pre ${extra}" ${off ? '' : 'contenteditable="plaintext-only"'} ${where} data-format="text" data-multiline="${node.multiline ? 1 : 0}" aria-label="${esc(node.label)}" ${raw(node.text)}>${esc(node.text)}</div>`;
        },

        // Words a layout put together from several places in the draft:
        // shown, not edited here, since there's no one place to put them.
        assembled(node, extra = '') {
            const note = t('Put together for this layout from several parts of the draft. Change these words in the “{layout}” layout, or ask in the conversation.', { layout: this.writerName() });
            const body = node.kind === 'html' ? `<div class="gw-prose">${node.html}</div>` : `<div class="gw-pre">${esc(node.text ?? '')}</div>`;

            return `<div class="gw-assembled ${extra}" title="${esc(note)}">${body}<p class="gw-assembled__note light">${esc(note)}</p></div>`;
        },

        writerName() {
            return this.session?.layouts?.plans?.find((plan) => plan.writer)?.name ?? t('As written');
        },

        // The draft as a page to read: only its words, in order, each
        // editable, with a quiet note of where it sits.
        textView(nodes) {
            const out = [];

            const walk = (list, where) => list.forEach((node) => {
                if (node.editable || node.assembled) {
                    const shown = node.assembled ? this.assembled(node) : this.editable(node);

                    out.push(node.handle === 'title' && !where && !node.assembled
                        ? this.editable(node, 'gw-text-title')
                        : `<div class="gw-text-part">${where ? `<div class="gw-text-where">${esc(where)}</div>` : ''}${shown}</div>`);
                } else if (node.kind === 'blocks') {
                    node.items.forEach((block) => walk(block.fields, block.label));
                } else if (node.kind === 'rows') {
                    node.items.forEach((row) => walk(row, node.label));
                } else if (node.kind === 'group') {
                    walk(node.fields, node.label);
                }
            });

            walk(nodes, '');

            return (out.length ? `<article class="gw-text-view">${out.join('')}</article>` : `<p class="light">${esc(t('There is no writing in this draft yet.'))}</p>`)
                + this.extrasView(this.session?.extras ?? []);
        },

        // The extras the writer prepared with the draft (§3): one card per
        // extra, each item editable where it is (C1) with where it came
        // from, and ✕ to delete. A layout uses them where it has room.
        extrasView(extras) {
            if (!extras.length) return '';

            const off = this.working() || this.editing;
            const field = (item, part, text, label) => `<span class="gw-editable gw-pre gw-extra__text" ${off ? '' : 'contenteditable="plaintext-only"'} data-edit-extra="${esc(item.id)}" data-part="${esc(part)}" data-format="text" data-multiline="0" ${Ghostwriter.hasGapMarkers(text) ? `data-raw="${esc(text)}"` : ''} aria-label="${esc(label)}">${esc(text)}</span>`;
            const items = (extra) => extra.items.map((item, n) => {
                const parts = Object.entries(item.parts ?? {}).filter(([name]) => name !== 'for');
                const label = (part) => t('{extra} {number}: {part}', { extra: extra.label, number: n + 1, part });
                const source = item.source?.label
                    ? (item.source.url ? `<a href="${esc(item.source.url)}" target="_blank" rel="noopener">${esc(item.source.label)} <span aria-hidden="true">↗</span></a>` : esc(item.source.label))
                    : '';

                return `<li class="gw-extra__item">
                    <div class="gw-extra__words">
                        ${parts.filter(([name]) => name === 'question').map(([name, text]) => field(item, name, text, label(name))).join('')}
                        ${field(item, '', item.text, label(t('text')))}
                        ${parts.filter(([name]) => name !== 'question').map(([name, text]) => `<span class="gw-extra__part"><span class="gw-extra__part-name">${esc(name)}</span>${field(item, name, text, label(name))}</span>`).join('')}
                    </div>
                    <div class="gw-extra__meta">
                        ${source ? `<span class="gw-extra__source gw-extra__source--${esc(item.source.kind ?? 'none')}" title="${esc(item.source.label)}">${source}</span>` : ''}
                        ${item.state ? `<span class="gw-extra__state gw-extra__state--${esc(item.state.key)}">${esc(item.state.label)}</span>` : ''}
                        ${extra.items.length > 1 ? `<button type="button" class="gw-extra__delete" data-action="delete-extra" data-item="${esc(item.id)}" data-label="${esc(label(t('text')))}" aria-label="${esc(t('Delete {extra} {number}', { extra: extra.label, number: n + 1 }))}" ${off ? 'disabled' : ''}>✕</button>` : ''}
                    </div>
                </li>`;
            }).join('');

            return `<section class="gw-extras" aria-labelledby="gw-extras-heading">
                <h3 id="gw-extras-heading">${esc(t('Extras'))}</h3>
                <p class="light">${esc(t('Prepared with the draft, only from what you told me, the draft itself or your existing pages. A layout uses them where it has room; one that isn’t used is never put in the entry. Click to change one.'))}</p>
                ${extras.map((extra) => `<div class="gw-extra">
                    <div class="gw-extra__head">
                        <strong>${esc(extra.label)}</strong>
                        ${extra.usedLabel ? `<span class="gw-extra__use ${extra.used ? 'is-used' : ''}">${esc(extra.usedLabel)}</span>` : ''}
                        <button type="button" class="gw-extra__delete" data-action="delete-extra" data-item="${esc(extra.id)}" data-label="${esc(extra.label)}" aria-label="${esc(t('Delete the {extra} extra', { extra: extra.label }))}" title="${esc(t('Delete'))}" ${off ? 'disabled' : ''}>✕</button>
                    </div>
                    <ul>${items(extra)}</ul>
                </div>`).join('')}
            </section>`;
        },

        async deleteExtra(item, label) {
            if (this.working()) return;

            try {
                const data = await Ghostwriter.request('POST', 'sessions/delete-extra', { id: this.session.id, item });

                this.session = data;
                this.page?.changed(this.pageKey(data));
                this.renderDraft();
                this.announce(t('{extra} deleted.', { extra: label }));
                this.$container.find('#gw-extras-heading').attr('tabindex', '-1').trigger('focus');
            } catch (error) {}
        },

        async chooseLayout(plan) {
            if (this.working() || plan === this.session?.layouts?.chosen) return;

            try {
                const data = await Ghostwriter.request('POST', 'sessions/choose-layout', { id: this.session.id, plan });

                this.session = data;
                this.page?.changed(this.pageKey(data), true);
                this.renderDraft();
                this.announce(t('{layout} layout.', { layout: data.layouts.chosenName ?? plan }));
            } catch (error) {
                if (error?.response?.status === 409) this.openSession(this.session.id);
            }
        },

        async refreshLayouts() {
            if (this.working()) return;

            try {
                this.receive(await Ghostwriter.request('POST', 'sessions/refresh-layouts', { id: this.session.id }));
                this.announce(t('Finding other layouts…'));
            } catch (error) {
                if (error?.response?.status === 409) this.openSession(this.session.id);
            }
        },

        // What the Preview shows: the draft in the chosen layout, with its extras.
        pageKey(session) {
            return session?.draft ? `${session.draft}\n#layout:${session.layouts?.key ?? ''}` : null;
        },

        async saveDraft() {
            try {
                const data = await Ghostwriter.request('POST', 'sessions/draft', { id: this.session.id, draft: this.raw });

                this.page?.changed(this.pageKey(data));
                this.session = data;
                this.editing = false;
                this.renderDraft();
            } catch (error) {}
        },

        async apply() {
            this.busy = true;
            this.renderDraft();

            try {
                // Whatever the person has typed into the form so far is saved
                // to the draft first, so nothing of theirs is lost.
                const editor = $('form').toArray().map((form) => $(form).data('elementEditor')).find(Boolean);

                if (editor) {
                    await editor.checkForm(true);
                }

                const data = await Ghostwriter.request('POST', 'sessions/apply', { id: this.session.id, elementId: editor?.settings?.elementId ?? this.config.elementId, siteId: this.config.siteId });

                this.options.applied(data);
            } catch (error) {
                this.busy = false;
                this.renderDraft();
            }
        },

        startOver() {
            clearTimeout(this.timer);
            clearInterval(this.ticker);

            this.session = null;
            this.card = null;
            this.cardKey = null;
            this.showBrief = false;
            this.config.current = null;
            Ghostwriter.address({ ghostwriter: 'new', idea: null });

            // With only one kind there is nothing to choose: ask again.
            if (this.nothingToChoose() && this.type) return this.choose(this.type);

            this.type = null;
            this.render();
        },

        // ---- Rendering ----------------------------------------------------

        render() {
            const step = this.step();

            const header = `
                <header class="gw-panel__header">
                    <h1 class="gw-panel__title"><span class="gw-icon" aria-hidden="true">${this.config.icon ?? ''}</span>${esc(t('Ghostwriter'))}</h1>
                    <button type="button" class="btn" data-action="close">${esc(t('Close'))}</button>
                    <div class="visually-hidden" aria-live="polite" data-live></div>
                </header>`;

            let body = '';

            if (step === 'loading') {
                body = `<div class="gw-empty"><div class="spinner"></div></div>`;
            } else {
                body = this.alerts(step) + this[`render_${step}`]();
            }

            this.$container.html(header + `<div class="gw-panel__body gw-panel__body--${step}">${body}</div>`);

            if (step === 'write') {
                this.renderConversation();
                this.renderDraft();
                this.renderComposer();
                this.narrow = false;
                this.measure();
                this.applyPane();
            }

            Ghostwriter.prepareButtons(this.$container);
        },

        alerts(step) {
            let html = '';

            if (!this.info.configured) {
                html += `<p class="warning with-icon gw-alert"><strong>${esc(t('No API key yet.'))}</strong> ${esc(t('Add {key} to your .env file, then reload this page. Ghostwriter cannot write anything until it is set.', { key: this.info.keyName ?? this.info.provider }))}</p>`;
            }

            if (!this.info.hasVoice && step !== 'write') {
                html += `<p class="notice with-icon gw-alert">${esc(t('No voice guide yet. Ghostwriter will still write, but in a plain voice rather than yours.'))} <a href="${esc(this.info.voiceUrl)}">${esc(t('Create the voice guide'))}</a></p>`;
            }

            return html;
        },

        render_setup() {
            return `
                <div class="gw-narrow">
                    <h2>${esc(t('Teach Ghostwriter a kind of content'))}</h2>
                    <p class="light">${esc(t('Worth doing for something you write often. It reads the entries you point it at and writes a brief of its own for that kind, with questions that fit. This takes about a minute and happens once.'))}</p>
                    ${this.info.state.status === 'failed' ? `<p class="error with-icon">${esc(this.info.state.error)}</p>` : ''}
                    <div class="field">
                        <div class="heading"><label for="gw-learn-title">${esc(t('What is this kind of content called?'))}</label></div>
                        <div class="instructions"><p>${esc(t('For example “Case study” or “Service page”. Leave blank and Ghostwriter will name it.'))}</p></div>
                        <div class="input"><input id="gw-learn-title" class="text fullwidth" data-model="learn-title" value="${esc(this.learn.title)}" ${this.learning() ? 'disabled' : ''}></div>
                    </div>
                    ${this.info.entries.length ? `
                    <div class="field">
                        <div class="heading"><label>${esc(t('Model it on'))}</label></div>
                        <div class="instructions"><p>${esc(t('Tick up to six entries that are good examples. Leave them all unticked to use the newest published entries.'))}</p></div>
                        ${this.picker('learn-example', this.learn.picked)}
                    </div>` : ''}
                    <div class="gw-actions">
                        <button type="button" class="btn submit ${this.learning() ? 'loading' : ''}" data-action="learn" ${!this.info.configured || this.learning() ? 'disabled' : ''}>${esc(this.learning() ? t('Reading the entries…') : t('Learn this'))}</button>
                        ${this.learning() ? '' : `<button type="button" class="btn" data-action="cancel-teach">${esc(t('Cancel'))}</button>`}
                    </div>
                </div>`;
        },

        render_type() {
            const learned = this.info.types.filter((type) => !type.generic);
            const card = (attrs, title, subtitle) => `<button type="button" class="gw-card" ${attrs}><strong>${esc(title)}</strong>${subtitle ? `<span class="light">${esc(subtitle)}</span>` : ''}</button>`;

            return `
                <div class="gw-wide">
                    <div class="flex flex-justify gw-row">
                        <h2>${esc(t('What are you writing?'))}</h2>
                        <button type="button" class="btn small" data-action="teach">${esc(t('Teach a kind'))}</button>
                    </div>
                    ${this.info.ideas.length ? `
                        <h3 class="gw-subheading">${esc(t('From the content plan'))}</h3>
                        <div class="gw-ideas">${this.info.ideas.map((idea) => card(`data-action="idea" data-idea="${esc(idea.id)}" title="${esc(idea.why)}"`, idea.title, idea.why)).join('')}</div>` : ''}
                    ${learned.length ? `<div class="gw-cards">${learned.map((type) => card(`data-action="choose" data-type="${esc(type.handle)}"`, type.title, type.description)).join('')}</div>` : ''}
                    ${this.info.kinds.length ? `
                        <h3 class="gw-subheading">${esc(t('Something like what is already here'))}</h3>
                        <div class="gw-cards">${this.info.kinds.map((kind, i) => card(`data-action="choose-kind" data-kind="${i}" title="${esc(kind.titles.slice(0, 8).join(', '))}"`, kind.label, t('{count} entries built the same way', { count: kind.count }))).join('')}</div>` : ''}
                    <button type="button" class="gw-card gw-card--dashed" data-action="choose-general">
                        <strong>${esc(t('Something else'))}</strong>
                        <span class="light">${esc(t('Describe what you want. Pick entries to model it on, or let Ghostwriter choose the shape.'))}</span>
                    </button>
                    ${this.carryOn()}
                </div>`;
        },

        carryOn() {
            if (!this.info.sessions.length) return '';

            return `
                <h3 class="gw-subheading">${esc(t('Or carry on with'))}</h3>
                <ul class="gw-list">${this.info.sessions.map((item) => `
                    <li><button type="button" class="gw-list__item" data-action="resume" data-id="${esc(item.id)}">
                        <span>${esc(item.title)}</span><span class="light">${esc([item.type, item.updatedAt, Ghostwriter.people(item)].filter(Boolean).join(' · '))}</span>
                    </button></li>`).join('')}
                </ul>`;
        },

        // Tall enough to show the whole answer, however it got there.
        // The card sits in the conversation column, about 50 characters wide.
        rowsFor(question, answer, width = 50) {
            const lines = String(answer ?? '').split('\n').reduce((total, line) => total + Math.max(1, Math.ceil(line.length / width)), 0);

            return Math.min(Math.max(lines + 1, question.type === 'text' ? 1 : 3), 16);
        },

        picker(model, picked) {
            const entries = this.info.entries;

            return `
                <div class="gw-picker">
                    ${entries.length > 8 ? `<input class="text fullwidth gw-picker__filter" data-model="filter" placeholder="${esc(t('Filter…'))}" aria-label="${esc(t('Filter entries'))}">` : ''}
                    <div class="gw-picker__list">
                        ${entries.map((entry) => `
                            <div class="gw-picker__row" data-entry data-title="${esc(entry.title.toLowerCase())}" style="padding-inline-start: ${entry.depth * 1.25}rem">
                                <input type="checkbox" class="checkbox" id="gw-${model}-${entry.id}" data-model="${model}" value="${entry.id}" ${picked.includes(entry.id) ? 'checked' : ''} ${!picked.includes(entry.id) && picked.length >= MAX_EXAMPLES ? 'disabled' : ''}>
                                <label for="gw-${model}-${entry.id}">${esc(entry.title)}${entry.live ? '' : ` <span class="light">(${esc(t('not live'))})</span>`}</label>
                            </div>`).join('')}
                    </div>
                    <p class="light gw-picker__picked">${this.pickedText(picked)}</p>
                </div>`;
        },

        renderPicked($picker, picked) {
            $picker.find('.gw-picker__picked').html(this.pickedText(picked));
            $picker.find('input[type=checkbox]').each((i, box) => {
                box.disabled = !box.checked && picked.length >= MAX_EXAMPLES;
            });
        },

        pickedText(picked) {
            const titles = this.info.entries.filter((entry) => picked.includes(entry.id)).map((entry) => entry.title);

            return titles.length ? `${esc(t('Modelled on'))}: ${esc(titles.join(', '))}` : '';
        },

        // The conversation and the draft: side by side in a wide panel; in a
        // narrow one (measure()), one column, one at a time, with
        // Conversation | Draft at the top. Grid and flex only, so nothing
        // can sit over anything else at any width.
        render_write() {
            const tab = (name, label) => `<button type="button" role="tab" class="gw-write-switch__tab" id="gw-pane-tab-${name}" data-action="pane" data-pane="${name}" aria-controls="gw-pane-${name}">${esc(label)}<span class="gw-write-switch__dot" aria-hidden="true"></span><span class="visually-hidden" data-pane-note></span></button>`;

            return `
                <div class="gw-write-switch" role="tablist" aria-label="${esc(t('Writing panel'))}" hidden>
                    ${tab('conversation', t('Conversation'))}${tab('draft', t('Draft'))}
                </div>
                <div class="gw-write">
                    <section class="gw-convo" id="gw-pane-conversation" aria-label="${esc(t('Conversation'))}">
                        <div class="gw-chat-log"></div>
                        <div class="gw-composer"></div>
                    </section>
                    <section class="gw-draft" id="gw-pane-draft" aria-label="${esc(t('Draft'))}"></section>
                </div>`;
        },

        // Two columns need about 900 px of panel (the conversation at 360,
        // the draft at 540); below that, one column with a switch. The
        // panel's own width, not the window's.
        measure() {
            const body = this.$container.find('.gw-panel__body')[0] ?? this.$container[0];
            const style = getComputedStyle(body);
            const width = body.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0);
            const narrow = width > 0 && width < 900;

            if (narrow !== this.narrow) {
                this.narrow = narrow;
                this.applyPane();
            }
        },

        applyPane() {
            const $body = this.$container.find('.gw-panel__body--write');

            if (!$body.length) return;

            const narrow = this.narrow;
            const asking = this.session ? this.asking() : false;
            const working = this.session ? this.working() : false;

            $body.toggleClass('is-narrow', narrow);
            $body.find('.gw-write-switch').prop('hidden', !narrow);

            ['conversation', 'draft'].forEach((name) => {
                const chosen = this.pane === name;
                const dot = name === 'conversation' ? (asking || working) : this.draftFresh;
                const note = name === 'conversation' ? (asking ? t('(waiting for your answer)') : '') : (this.draftFresh ? t('(updated)') : '');

                $body.find(`#gw-pane-tab-${name}`).attr({ 'aria-selected': String(chosen), tabindex: chosen ? 0 : -1 })
                    .find('.gw-write-switch__dot').toggleClass('is-on', dot).toggleClass('is-asking', name === 'conversation' && asking).end()
                    .find('[data-pane-note]').text(note);
                $body.find(`#gw-pane-${name}`).toggleClass('is-away', narrow && !chosen).attr({ role: narrow ? 'tabpanel' : null, 'aria-labelledby': narrow ? `gw-pane-tab-${name}` : null, 'aria-label': narrow ? null : (name === 'conversation' ? t('Conversation') : t('Draft')) });
            });
        },

        showPane(pane, focus = false) {
            this.pane = pane;

            if (pane === 'draft') this.draftFresh = false;

            this.applyPane();

            if (pane === 'draft') this.page?.fit?.();
            if (focus) this.$container.find(`#gw-pane-tab-${pane}`).trigger('focus');
        },

        renderConversation() {
            const session = this.session;
            const conversation = session.messages;
            const asking = this.asking();

            // A piece from the old brief screen shows its brief as it was
            // written. Editing an entry has no brief to show: the entry is
            // the brief.
            let html = session.editing || session.briefText === null || session.briefText === undefined ? '' : `
                <div class="gw-brief">
                    <button type="button" class="gw-link" data-action="toggle-brief" aria-expanded="${this.showBrief}">${esc(this.showBrief ? t('brief.hide') : t('brief.show'))}</button>
                    ${this.showBrief ? `<div class="gw-pre">${esc(session.briefText)}</div>` : ''}
                </div>`;

            conversation.forEach((entry, index) => {
                if (entry.step === 'card') {
                    html += session.editing ? '' : this.briefCard(entry);

                    return;
                }

                const mine = entry.role === 'user';
                const waiting = !mine && asking && index === conversation.length - 1;

                html += `
                    <div class="gw-bubble gw-bubble--${mine ? 'me' : 'them'} ${waiting ? 'gw-bubble--asking' : ''}">
                        <div class="gw-bubble__who">${waiting ? `<span class="gw-bubble__flag">${esc(t('Ghostwriter needs your answer'))}</span>` : esc(!mine ? t('Ghostwriter') : (entry.mine === false ? entry.from : t('You')))}</div>
                        ${!mine && entry.html ? `<div class="gw-bubble__text gw-prose">${entry.html}</div>` : `<div class="gw-bubble__text gw-pre">${esc(entry.content)}</div>`}
                        ${entry.draft ? `<div class="gw-bubble__draft">✓ ${esc(this.draftNote(entry.draft))}</div>` : ''}
                    </div>`;
            });

            if (this.working()) {
                // Someone else's request: one run at a time, and it is theirs.
                html += session.waitingOn
                    ? `<div class="gw-bubble gw-bubble--them gw-working" role="status"><div class="spinner small"></div><span>${esc(t('{name} is waiting on Ghostwriter', { name: session.waitingOn }))}</span></div>`
                    : `<div class="gw-bubble gw-bubble--them gw-working" role="status"><div class="spinner small"></div><span data-progress>${esc(this.progress())}</span><span class="gw-elapsed" data-elapsed></span></div>`;
            }

            // With nothing to choose, the conversation opens at once; other
            // pieces to carry on with are listed under the question.
            if (!session.id && this.nothingToChoose()) {
                html += `<div class="gw-carry-on">${this.carryOn()}</div>`;
            }

            if (session.status === 'failed') {
                const retry = session.messages[session.messages.length - 1]?.role === 'user' || session.stage === 'filling';

                html += `<div class="gw-failed" role="alert">
                    <p class="error with-icon"><strong>${esc(t('That didn’t work'))}</strong> ${esc(session.error)}</p>
                    ${retry ? `<button type="button" class="btn small" data-action="retry">${esc(t('Try again'))}</button>` : ''}
                </div>`;
            }

            // Whatever had the focus in the card keeps it when it is redrawn.
            const focused = document.activeElement && $.contains(this.$container.find('.gw-chat-log')[0] ?? document.body, document.activeElement) ? document.activeElement.id : null;

            this.$container.find('.gw-chat-log').html(html);
            Ghostwriter.prepareButtons(this.$container.find('.gw-chat-log'));

            if (focused) document.getElementById(focused)?.focus();

            this.tick();
        },

        // The brief card as the person is changing it: what the server last
        // sent, with their changes until it sends another.
        cardState() {
            const card = this.session.card;

            if (!card) return null;

            const key = JSON.stringify([this.session.id, card.attempt, card.agreed, card.title, card.answers, card.examples]);

            if (key !== this.cardKey || !this.card) {
                this.cardKey = key;
                this.card = { title: card.title, answers: { ...card.answers }, examples: (card.examples ?? []).map(Number) };
                this.errors = {};
            }

            return this.card;
        },

        // The brief, filled in, in the conversation: a labelled region with
        // the working title, every question with its answer and the entries
        // to model it on, all editable. Before it is agreed it has "Looks
        // right, start writing" and "Try again"; after, it folds away to
        // "Show the brief" and can still be changed.
        briefCard(entry) {
            const session = this.session;
            const card = this.cardState();
            const questions = session.type?.questions ?? [];
            const agreed = session.card.agreed;
            const proposed = session.stage === 'proposed';

            if (!card) return '';

            if (agreed && !this.showBrief) {
                return `
                    <div class="gw-brief">
                        <button type="button" class="gw-link" data-action="toggle-brief" aria-expanded="false" aria-controls="gw-brief-card">${esc(t('brief.show'))}</button>
                    </div>`;
            }

            // Not while Ghostwriter works on the piece: it would write over the change.
            const off = this.working() || !!this.busy || (!proposed && !agreed);
            const disabled = off ? 'disabled' : '';
            const open = new Set(session.card.open ?? []);

            return `
                <section class="gw-brief-card ${agreed ? 'gw-brief-card--agreed' : ''}" id="gw-brief-card" role="region" aria-labelledby="gw-brief-card-heading">
                    <div class="gw-brief-card__head">
                        <h3 id="gw-brief-card-heading" tabindex="-1" data-card-heading>${esc(t('brief.region'))}</h3>
                        ${agreed ? `<button type="button" class="gw-link" data-action="toggle-brief" aria-expanded="true" aria-controls="gw-brief-card">${esc(t('brief.hide'))}</button>` : ''}
                    </div>
                    ${agreed ? '' : `<div class="gw-prose gw-brief-card__intro">${entry.html ?? `<p>${esc(entry.content)}</p>`}</div>`}
                    <div class="field">
                        <div class="heading"><label for="gw-brief-title">${esc(t('brief.title'))}</label></div>
                        <div class="input"><input id="gw-brief-title" class="text fullwidth" data-model="card-title" value="${esc(card.title)}" maxlength="200" ${disabled}></div>
                    </div>
                    ${questions.map((question) => `
                        <div class="field ${this.errors[question.handle] ? 'has-errors' : ''}">
                            <div class="heading"><label class="${question.required ? 'required' : ''}" for="gw-brief-${esc(question.handle)}">${esc(question.label)}</label></div>
                            ${question.instructions ? `<div class="instructions" id="gw-brief-${esc(question.handle)}-help"><p>${esc(question.instructions)}</p></div>` : ''}
                            <div class="input">
                                <textarea id="gw-brief-${esc(question.handle)}" class="text fullwidth ${open.has(question.handle) ? 'gw-brief-card__open' : ''}" rows="${this.rowsFor(question, card.answers[question.handle])}" data-model="card-answer" data-handle="${esc(question.handle)}" ${question.instructions ? `aria-describedby="gw-brief-${esc(question.handle)}-help"` : ''} ${this.errors[question.handle] ? 'aria-invalid="true"' : ''} ${disabled}>${esc(card.answers[question.handle] ?? '')}</textarea>
                            </div>
                            ${this.errors[question.handle] ? `<ul class="errors"><li>${esc(this.errors[question.handle])}</li></ul>` : ''}
                        </div>`).join('')}
                    ${this.info.entries.length ? `
                        <fieldset class="field gw-brief-card__examples" ${disabled}>
                            <legend class="heading"><span>${esc(t('brief.model-on'))}</span></legend>
                            ${this.picker('card-example', card.examples)}
                        </fieldset>` : ''}
                    ${proposed ? `
                        <div class="gw-brief-card__actions">
                            <button type="button" class="btn submit ${this.busy === 'agree' ? 'loading' : ''}" data-action="agree" ${off || !this.info.configured ? 'disabled' : ''}>${esc(t('brief.agree'))}</button>
                            <button type="button" class="btn ${this.busy === 'try-again' ? 'loading' : ''}" data-action="try-again" ${off || !this.info.configured ? 'disabled' : ''}>${esc(t('brief.try-again'))}</button>
                        </div>` : ''}
                    ${agreed ? `
                        <div class="gw-brief-card__actions">
                            <button type="button" class="btn submit ${this.busy === 'edit-brief' ? 'loading' : ''}" data-action="save-brief" ${off ? 'disabled' : ''}>${esc(t('brief.save'))}</button>
                        </div>` : ''}
                </section>`;
        },

        renderComposer() {
            const asking = this.asking();
            const working = this.working();
            const stage = this.session.stage;
            const details = stage === 'details';
            const before = stage === 'filling' || stage === 'proposed';
            const startOver = this.session.editing
                ? `<button type="button" class="btn small gw-quiet" data-action="reload-entry" title="${esc(t('Throw away the changes asked for here and start again from the entry as it stands.'))}">${esc(t('Start again from the entry'))}</button>`
                : `<button type="button" class="btn small gw-quiet" data-action="start-over">${esc(t('Start over'))}</button>`;

            // While the brief is being filled in or checked, it is answered
            // in the card, not here.
            if (before) {
                this.$container.find('.gw-composer').removeClass('gw-composer--asking').html(`
                    <p class="light gw-composer__note">${esc(stage === 'proposed' ? t('Check the brief above, then start writing.') : t('brief.filling'))}</p>
                    <div class="gw-composer__actions">${startOver}</div>`);
                Ghostwriter.prepareButtons(this.$container.find('.gw-composer'));

                return;
            }

            const placeholder = details
                ? t('A working title, and a line or two about it…')
                : asking ? t('Type your answers here. Short is fine; number them if it helps.') : this.session.draft ? t('Ask for a change…') : t('Answer the questions…');

            this.$container.find('.gw-composer').toggleClass('gw-composer--asking', asking).html(`
                ${asking ? `<p class="gw-composer__flag"><span class="gw-dot" aria-hidden="true"></span>${esc(this.session.draft ? t('Your turn: answer above to carry on.') : t('Your turn: answer the questions above and the draft follows.'))}</p>` : ''}
                <textarea class="text fullwidth" rows="4" data-model="message" aria-label="${esc(details ? t('brief.ask') : t('Your message'))}" ${working || !this.info.configured && details ? 'disabled' : ''} placeholder="${esc(placeholder)}">${esc(this.message)}</textarea>
                <div class="gw-composer__actions">
                    ${startOver}
                    <span class="light smalltext">${esc(t('⌘↵ to send'))}</span>
                    <button type="button" class="btn submit ${working ? 'loading' : ''} ${working || !this.message.trim() ? 'disabled' : ''}" data-action="send" ${working || !this.message.trim() ? 'disabled' : ''}>${esc(working ? t('Working…') : t('Send'))}</button>
                </div>`);

            Ghostwriter.prepareButtons(this.$container.find('.gw-composer'));
        },

        renderDraft() {
            const session = this.session;
            const working = this.working();
            let toolbar = `<span class="light" data-words>${esc(session.draft ? t('{count} words', { count: session.words.toLocaleString() }) : t('Draft'))}</span>`;

            const view = this.currentView();

            if (session.draft && !this.editing && !session.draftProblem) {
                const tab = (name, label) => `<button type="button" role="tab" id="gw-tab-${name}" class="btn small ${view === name ? 'active' : ''}" data-action="view" data-view="${name}" aria-selected="${view === name}" aria-controls="gw-draft-panel" tabindex="${view === name ? 0 : -1}">${esc(label)}</button>`;

                toolbar += `<div class="btngroup gw-view-switch" role="tablist" aria-label="${esc(t('Show the draft as'))}">
                    ${this.config.preview ? tab('preview', t('Preview')) : ''}${tab('blocks', t('Blocks'))}${tab('text', t('Text'))}
                </div>`;

                if (view === 'preview') {
                    toolbar += `<div class="btngroup gw-width-switch" role="group" aria-label="${esc(t('Preview width'))}">
                        <button type="button" class="btn small ${this.width === 'desktop' ? 'active' : ''}" data-action="width" data-width="desktop" aria-pressed="${this.width === 'desktop'}">${esc(t('Desktop'))}</button>
                        <button type="button" class="btn small ${this.width === 'phone' ? 'active' : ''}" data-action="width" data-width="phone" aria-pressed="${this.width === 'phone'}">${esc(t('Phone'))}</button>
                    </div>`;
                }
            }
            let body = '';

            if (session.draft) {
                toolbar += this.editing
                    ? `<div class="flex"><button type="button" class="btn small" data-action="cancel-edit">${esc(t('Cancel'))}</button><button type="button" class="btn small submit" data-action="save-draft">${esc(t('Save changes'))}</button></div>`
                    : `<div class="flex">
                           <button type="button" class="btn small" data-action="edit" ${working ? 'disabled' : ''} title="${esc(t('Change the structure: add, move or remove blocks'))}">${esc(t('Edit YAML'))}</button>
                           <button type="button" class="btn small submit ${this.busy ? 'loading' : ''} ${working || session.draftProblem ? 'disabled' : ''}" data-action="apply" ${working || session.draftProblem || this.busy ? 'disabled' : ''}>${esc(session.editing ? t('Use these changes') : (session.layouts?.plans?.length > 1 && session.layouts.chosenName ? t('Use this draft ({layout})', { layout: session.layouts.chosenName }) : t('Use this draft')))}</button>
                       </div>`;
            }

            if (!session.draft && ['details', 'filling', 'proposed'].includes(session.stage)) {
                body = `<div class="gw-empty"><p>${esc(t('The draft appears here once the brief is agreed.'))}</p></div>`;
            } else if (!session.draft) {
                body = `<div class="gw-empty">${
                    working
                        ? `<div class="spinner"></div><p>${esc(t('Ghostwriter is working. A draft usually takes a minute or two.'))}</p>`
                        : this.asking()
                            ? `<p class="gw-empty__title">${esc(t('Ghostwriter has questions for you first'))}</p><p>${esc(t('They are in the conversation. Answer them there and the draft will appear here, or let it write around what it does not know.'))}</p><button type="button" class="btn" data-action="skip">${esc(t('Just draft it with what you have'))}</button>`
                            : `<p>${esc(t('No draft yet. Answer the questions in the conversation and the draft will appear here.'))}</p>`
                }</div>`;
            } else {
                body = (session.draftProblem ? `<p class="warning with-icon">${esc(session.draftProblem)}</p>` : '')
                    + (this.editing || session.draftProblem
                        ? `<textarea class="text fullwidth code gw-raw" rows="28" data-model="raw">${esc(this.raw)}</textarea>`
                        : (view === 'text' ? this.textView(session.preview) : (view === 'preview' ? '' : this.preview(session.preview))));

                if (!this.editing && !session.draftProblem && !working && view !== 'preview') {
                    body = `<p class="light gw-edit-hint">${esc(t('Click any writing (or Tab to it) to change it. It’s saved when you leave it; Esc puts it back.'))}</p>` + body;
                }
            }

            if (session.appliedAt && !this.editing) {
                body = `<p class="light gw-applied">${esc(t('This draft has been put into the entry. Using it again replaces what is in the form.'))}</p>` + body;
            }

            // The preview's frame is kept between redraws: moving or
            // rebuilding a frame loads its page again.
            const $draft = this.$container.find('.gw-draft');

            if (!$draft.children('.gw-draft__toolbar').length) {
                $draft.html('<div class="gw-draft__toolbar"></div><div class="gw-draft__body"></div>');
            }

            $draft.children('.gw-draft__toolbar').html(toolbar);

            // The layout cards, between the toolbar and the draft, in every view.
            this.cards ??= new Ghostwriter.LayoutCards({
                choose: (plan) => this.chooseLayout(plan),
                refresh: () => this.refreshLayouts(),
                prepare: (plan) => Ghostwriter.request('POST', 'preview/prepare', { id: this.session.id, elementId: this.formElementId(), siteId: this.config.siteId, plan }),
            });

            if (!$.contains($draft[0], this.cards.root)) $draft.children('.gw-draft__toolbar').after(this.cards.root);

            this.cards.update(this.editing ? null : session, { busy: working || this.busy, previewable: this.config.preview });
            $draft.children('.gw-draft__body')
                .html(body)
                .attr({ id: 'gw-draft-panel', role: session.draft && !this.editing ? 'tabpanel' : null, 'aria-labelledby': session.draft && !this.editing ? `gw-tab-${view}` : null })
                .toggleClass('gw-draft__body--slim', view === 'preview');

            if (view === 'preview') {
                this.page ??= new Ghostwriter.PagePreview({
                    target: () => ({ id: this.session.id, elementId: this.formElementId(), siteId: this.config.siteId }),
                    announce: (text) => this.announce(text),
                    onGap: (found) => this.openGap(found),
                    rendered: () => this.previewRendered(),
                });

                if (!$.contains($draft[0], this.page.root)) $draft.append(this.page.root);

                this.page.setWidth(this.width);
                this.page.show(this.pageKey(session), working);
            } else {
                this.page?.hide();
            }

            Ghostwriter.prepareButtons($draft.children('.gw-draft__toolbar, .gw-draft__body'));
            Ghostwriter.prepareButtons(this.page?.root);

            // Another tab, or Edit YAML: a popover belongs to the chip it came from.
            if (this.gap && (view !== this.gapView || this.editing)) this.closeGap(false);

            if (view === 'text' && !this.editing) this.paintChips();
        },

        // ---- Gaps resolved from their chips -------------------------------

        // A chip clicked (or Enter on it) in the Preview or the Text tab.
        openGap(found) {
            if (this.working() || this.editing) return;

            this.closeGap(false);

            const container = this.$container.find('.gw-draft')[0];

            if (!container) return;

            this.gap = found;
            this.gapView = this.currentView();
            this.popover = new GapPopover(found, container, {
                resolve: (value, reference) => this.resolveGap(value, reference),
                close: (refocus) => this.closeGap(refocus),
                search: (q) => this.searchLinks(q),
            });
        },

        // Closed: focus back on the chip (or, once it has gone, where it was).
        closeGap(refocus = true) {
            const gap = this.gap;

            this.popover?.destroy();
            this.popover = null;
            this.gap = null;

            if (!refocus || !gap) return;

            if (gap.element?.isConnected) gap.element.focus();
            else if (gap.host?.isConnected) (gap.host.querySelector('.gw-gap[tabindex], a.gw-gap') ?? gap.host).focus();
            else this.$container.find('.gw-page__frame:not(.is-loading)').first().trigger('focus');
        },

        // Written into the draft as typed: no model. Saved like any hand
        // edit, under the piece's lock; the Preview, layouts, Blocks and Text follow.
        async resolveGap(value, reference) {
            const gap = this.gap;

            if (!gap) return;

            const where = gap.host
                ? (gap.host.dataset.editExtra !== undefined ? { item: gap.host.dataset.editExtra, part: gap.host.dataset.part ?? '' } : { path: gap.host.dataset.editPath })
                : {};

            try {
                const data = await Ghostwriter.request('POST', 'sessions/resolve-gap', {
                    id: this.session.id,
                    kind: gap.kind,
                    hint: gap.hint,
                    list: gap.list ?? '',
                    occurrence: gap.occurrence ?? 0,
                    value,
                    reference: reference ?? '',
                    ...where,
                });

                this.refocusPreview = Boolean(gap.frame);
                this.closeGap(false);
                this.receive(data);
                this.page?.changed(this.pageKey(data));
                this.announce({ ask: t('Added to the draft.'), check: value === '' ? t('Count removed from the draft.') : t('Count confirmed in the draft.'), link: t('Link chosen in the draft.') }[gap.kind]);

                // Back where the chip was: the next chip in that writing, or the writing.
                if (gap.host) {
                    const path = gap.host.dataset.editPath;
                    const item = gap.host.dataset.editExtra;
                    const $host = item !== undefined ? this.$container.find(`[data-edit-extra="${CSS.escape(item)}"][data-part="${CSS.escape(gap.host.dataset.part ?? '')}"]`) : this.$container.find(`[data-edit-path="${CSS.escape(path ?? '')}"]`);
                    const host = $host[0];

                    (host?.querySelector('.gw-gap[tabindex], a.gw-gap') ?? host)?.focus();
                }
            } catch (error) {
                this.popover?.idle();
            }
        },

        async searchLinks(q) {
            if (!q || !q.trim()) return [];

            try {
                return (await Ghostwriter.request('GET', 'sessions/links', { id: this.session.id, q })).entries ?? [];
            } catch (error) {
                return [];
            }
        },

        // After a gap resolved in the Preview: the next chip on the page, or the page.
        previewRendered() {
            if (!this.refocusPreview) return;

            this.refocusPreview = false;

            const frame = this.$container.find('.gw-page__frame:not(.is-loading)')[0];
            const chip = frame?.contentDocument?.querySelector('.gw-gap[tabindex], a.gw-gap');

            (chip ?? frame)?.focus();
        },

        // ---- Chips in the Text tab ----------------------------------------
        //
        // Writing that holds a marker shows it as a chip while it's read;
        // the chip opens the popover. It can be focused (Tab) but isn't
        // editable until its words are clicked, or Enter, F2 or typing
        // starts editing: then it's the stored words, raw markers and all,
        // so nothing saved can hold a chip.

        isPainted(element) {
            return element?.dataset?.gwChips !== undefined;
        },

        async paintChips() {
            const markers = Ghostwriter.gapMarkersLoaded ?? await Ghostwriter.gapMarkers?.().catch(() => null);

            if (!markers || this.currentView() !== 'text' || this.editing) return;

            markers.injectStyles(document);

            const labels = Ghostwriter.gapLabels?.() ?? {};
            const off = this.working();

            this.$container.find('.gw-draft__body [data-raw]').each((i, host) => {
                if (host === document.activeElement || this.isPainted(host)) return;

                const raw = host.getAttribute('data-raw');
                const html = host.dataset.format === 'html';

                if (html) host.innerHTML = raw;
                else host.textContent = raw;

                const chips = markers.markGaps(host, { labels, onActivate: off ? null : (found) => this.openGap({ ...found, host }) });

                if (!chips.length) return;

                chips.forEach((found) => found.element.setAttribute('contenteditable', 'false'));
                host.dataset.gwChips = '';
                host.dataset.gwEditable = host.getAttribute('contenteditable') ?? '';

                if (host.dataset.gwEditable !== '') {
                    host.setAttribute('contenteditable', 'false');
                    host.setAttribute('tabindex', '0');
                }
            });
        },

        // The stored words back, editable, with the caret where it was clicked (or at the end).
        startEditing(host, point = null) {
            if (!this.isPainted(host)) return;

            const raw = host.getAttribute('data-raw');

            if (host.dataset.format === 'html') host.innerHTML = raw;
            else host.textContent = raw;

            const editable = host.dataset.gwEditable;

            delete host.dataset.gwChips;
            delete host.dataset.gwEditable;

            if (!editable) return;

            host.setAttribute('contenteditable', editable);
            host.removeAttribute('tabindex');
            host.focus();

            const selection = document.getSelection();
            let range = point && document.caretRangeFromPoint ? document.caretRangeFromPoint(point.x, point.y) : null;

            if (!range || !host.contains(range.startContainer)) {
                range = document.createRange();
                range.selectNodeContents(host);
                range.collapse(false);
            }

            selection.removeAllRanges();
            selection.addRange(range);
        },

        editKey(event) {
            const typing = event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;

            if (event.key !== 'Enter' && event.key !== 'F2' && !typing) return false;

            event.preventDefault();
            this.startEditing(event.target);

            if (typing) document.execCommand?.('insertText', false, event.key);

            return true;
        },

        // A press on a read view's words (not a chip): editing starts there. A chip's opens its popover.
        onMouseDown(event) {
            const host = event.target.closest?.('[data-gw-chips]');

            if (!host || event.button !== 0) return;

            event.preventDefault();

            if (!event.target.closest('.gw-gap')) this.startEditing(host, { x: event.clientX, y: event.clientY });
        },

        // Preview where it can show, otherwise the person's choice.
        currentView() {
            const session = this.session;

            if (this.view === 'preview' && (!this.config.preview || !session?.draft || this.editing || session.draftProblem)) {
                return 'blocks';
            }

            return this.view;
        },

        switchView(view, focus = false) {
            this.view = view;

            try { localStorage.setItem('ghostwriter:draft-view', view); } catch (error) {}

            this.renderDraft();

            if (focus) this.$container.find(`#gw-tab-${view}`).trigger('focus');
        },

        // The entry the form is on: its draft, once Craft has made one.
        formElementId() {
            const editor = $('form').toArray().map((form) => $(form).data('elementEditor')).find(Boolean);

            return editor?.settings?.elementId ?? this.config.elementId;
        },

        // "Draft updated · 957 → 1,012 words (+55)"
        draftNote(draft) {
            const words = (count) => Number(count).toLocaleString();

            if (draft.change === 'written' || draft.was === null) {
                return t('Draft written · {words} words', { words: words(draft.words) });
            }

            const difference = draft.words - draft.was;
            const change = difference === 0 ? t('same length') : `${difference > 0 ? '+' : '−'}${words(Math.abs(difference))}`;

            return t('Draft updated · {was} → {words} words ({change})', { was: words(draft.was), words: words(draft.words), change });
        },

        // The draft as the server laid it out: each field under its label,
        // blocks in order under their names. Rich text arrives as HTML the
        // server has already escaped.
        preview(nodes, nested = false) {
            return `<div class="gw-preview ${nested ? 'gw-preview--nested' : ''}">${nodes.map((node) => `
                <div class="gw-preview__field">
                    <div class="gw-preview__label">${esc(node.label)}</div>
                    ${node.editable ? this.editable(node) : (node.assembled ? this.assembled(node) : this.previewValue(node))}
                </div>`).join('')}</div>`;
        },

        previewValue(node) {
            switch (node.kind) {
                case 'html':
                    return `<div class="gw-prose">${node.html}</div>`;
                case 'list':
                    return `<ul class="gw-chips">${node.items.map((item) => `<li>${esc(item)}</li>`).join('')}</ul>`;
                case 'blocks':
                    return node.items.map((block) => `
                        <div class="gw-block">
                            <div class="gw-block__name">${esc(block.label)}${block.known ? '' : ` <span class="error">${esc(t('Unknown block, will be left out'))}</span>`}</div>
                            ${block.fields.length ? `<div class="gw-block__body">${this.preview(block.fields, true)}</div>` : block.known ? `<div class="gw-block__body light">${esc(t('Uses its usual settings.'))}</div>` : ''}
                        </div>`).join('');
                case 'rows':
                    return node.items.map((row) => `<div class="gw-block"><div class="gw-block__body">${this.preview(row, true)}</div></div>`).join('');
                case 'group':
                    return this.preview(node.fields, true);
                default:
                    return `<div class="gw-pre">${esc(node.text)}</div>`;
            }
        },
    });
})();

/**
 * The dashboard: removing a piece from the list, and checking back while a
 * section is being learned.
 */
(function () {
    Ghostwriter.Dashboard = Garnish.Base.extend({
        init(config) {
            this.labels = config;

            this.addListener($('[data-remove-session]'), 'click', 'remove');
            this.addListener($('[data-suggest-kinds]'), 'click', 'suggest');
            this.addListener($('[data-learn-kind]'), 'click', 'learn');
            this.addListener($('[data-dismiss-kind]'), 'click', 'dismiss');
            this.addListener($('[data-learn-all]'), 'click', 'learnAll');

            // Sections still being looked at or learned: start the queue now
            // rather than waiting for another page to.
            // Craft.cp is only there once the page is ready.
            $(() => {
                if ($('[data-busy="1"]').length) {
                    Craft.cp.runQueue?.();
                }

                if ($('[data-busy="1"]').length) {
                    this.watch();
                }
            });
        },

        // Takes the conversation and draft off the list. The entry, if there is one, is untouched.
        async remove(event) {
            const $button = $(event.currentTarget);

            if (!confirm(Craft.t('ghostwriter', this.labels.confirm, { title: $button.data('title') }))) {
                return;
            }

            try {
                await Ghostwriter.request('POST', 'sessions/delete', { id: $button.data('remove-session') });
                $button.closest('tr').remove();
            } catch (error) {}
        },

        async suggest(event) {
            const section = $(event.currentTarget).data('suggest-kinds');

            $(event.currentTarget).addClass('loading').prop('disabled', true);

            try {
                await Ghostwriter.request('POST', 'sections/suggest-kinds', section ? { section } : {});
                this.busy(section ? $(`[data-section="${section}"].gw-section-row`) : $('.gw-section-row'), this.labels.looking);
            } catch (error) {
                $(event.currentTarget).removeClass('loading').prop('disabled', false);
            }
        },

        async learn(event) {
            const $button = $(event.currentTarget);

            $button.addClass('loading').prop('disabled', true);

            try {
                await Ghostwriter.request('POST', 'sections/learn-kind', { section: $button.data('section'), id: $button.data('learn-kind') });
                $button.closest('li').remove();
                this.busy($(`[data-section="${$button.data('section')}"].gw-section-row`), this.labels.learning);
            } catch (error) {
                $button.removeClass('loading').prop('disabled', false);
            }
        },

        async learnAll(event) {
            const $button = $(event.currentTarget);
            const section = $button.data('learn-all');

            // A model call per kind, about a minute each, and no undoing it.
            if (!confirm(Craft.t('ghostwriter', this.labels.confirmLearnAll, { count: $button.data('count') }))) {
                return;
            }

            $button.addClass('loading').prop('disabled', true);

            try {
                await Ghostwriter.request('POST', 'sections/learn-all-kinds', { section });
                $button.closest('.gw-suggestions').remove();
                this.busy($(`[data-section="${section}"].gw-section-row`), Craft.t('ghostwriter', this.labels.learningAll, { count: $button.data('count') }));
            } catch (error) {
                $button.removeClass('loading').prop('disabled', false);
            }
        },

        async dismiss(event) {
            const $button = $(event.currentTarget);

            try {
                await Ghostwriter.request('POST', 'sections/dismiss-kind', { section: $button.data('section'), id: $button.data('dismiss-kind') });
                $button.closest('li').remove();
            } catch (error) {}
        },

        /**
         * Say what is happening under the section, and check back. The page
         * reloads once the queue has finished, not before, so the request
         * that runs the queue is never cut short.
         */
        busy($rows, text) {
            $rows.each((i, row) => {
                $(row).find('.gw-busy').remove();
                $(row).children('.flex').first().after(`<p class="light gw-busy"><span class="spinner small"></span> ${Ghostwriter.escape(text)}</p>`);
            });

            if (!this.watching) {
                this.watch();
            }
        },

        // Check back while a section is being looked at or learned, and
        // show the result once it is in.
        watch() {
            this.watching = true;

            setTimeout(async () => {
                try {
                    const { sections } = await Ghostwriter.request('GET', 'sections/kinds');
                    const busy = Object.values(sections).some((state) => state.status === 'working' || state.learning?.status === 'working');

                    if (!busy) {
                        window.location.reload();

                        return;
                    }
                } catch (error) {
                    return;
                }

                this.watch();
            }, 3000);
        },
    });
})();

/**
 * The markdown editor around a guide's textarea: marks applied to the
 * selection, and a Preview tab rendered by the server.
 */
(function () {
    Ghostwriter.MarkdownEditor = Garnish.Base.extend({
        init(container) {
            this.$container = $(container);
            this.$text = this.$container.find('textarea');
            this.$preview = this.$container.find('.gw-md__preview');
            this.$tools = this.$container.find('.gw-md__tools');

            this.addListener(this.$container.find('[data-md]'), 'click', (event) => this.apply($(event.currentTarget).data('md')));
            this.addListener(this.$container.find('[data-md-tab]'), 'click', (event) => this.tab($(event.currentTarget).data('md-tab')));
            this.addListener(this.$text, 'keydown', 'shortcut');
        },

        shortcut(event) {
            if (!(event.metaKey || event.ctrlKey)) return;

            const action = { b: 'bold', i: 'italic', k: 'link' }[event.key.toLowerCase()];

            if (action) {
                event.preventDefault();
                this.apply(action);
            }
        },

        apply(action) {
            const text = this.$text[0];
            const { selectionStart: start, selectionEnd: end, value } = text;
            const selected = value.slice(start, end);
            const wrap = (before, after, placeholder) => {
                const inner = selected || placeholder;
                return { insert: before + inner + after, select: [start + before.length, start + before.length + inner.length] };
            };

            // Line marks go at the start of every line the selection touches.
            const lines = (prefix) => {
                const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                const block = value.slice(lineStart, end) || '';
                const marked = block.split('\n').map((line, i) => (typeof prefix === 'function' ? prefix(i) : prefix) + line.replace(/^(#{1,6} |> |- |\d+\. )/, '')).join('\n');

                return { from: lineStart, insert: marked, select: [lineStart, lineStart + marked.length] };
            };

            const edits = {
                bold: () => wrap('**', '**', Craft.t('ghostwriter', 'bold text')),
                italic: () => wrap('*', '*', Craft.t('ghostwriter', 'italic text')),
                link: () => {
                    const url = prompt(Craft.t('ghostwriter', 'Link to'), 'https://');
                    return url ? wrap('[', `](${url})`, Craft.t('ghostwriter', 'link text')) : null;
                },
                h2: () => lines('## '),
                h3: () => lines('### '),
                ul: () => lines('- '),
                ol: () => lines((i) => `${i + 1}. `),
                quote: () => lines('> '),
            };

            const edit = edits[action]?.();

            if (!edit) return;

            const from = edit.from ?? start;

            text.focus();
            text.setSelectionRange(from, end);

            // execCommand keeps the browser's undo history; fall back if it is not there.
            if (!document.execCommand?.('insertText', false, edit.insert)) {
                text.value = value.slice(0, from) + edit.insert + value.slice(end);
            }

            text.setSelectionRange(...edit.select);
            this.$text.trigger('input');
        },

        async tab(which) {
            this.$container.find('[data-md-tab]').each((i, button) => {
                const on = button.dataset.mdTab === which;
                $(button).toggleClass('active', on).attr('aria-selected', on ? 'true' : 'false');
            });

            const previewing = which === 'preview';

            this.$text.toggleClass('hidden', previewing);
            this.$tools.toggleClass('hidden', previewing);
            this.$preview.toggleClass('hidden', !previewing);

            if (!previewing) {
                this.$text.trigger('focus');
                return;
            }

            this.$preview.html('<div class="spinner"></div>');

            try {
                const data = await Ghostwriter.request('POST', 'preview/markdown', { markdown: this.$text.val() });
                this.$preview.html(data.html || `<p class="light">${Ghostwriter.escape(Craft.t('ghostwriter', 'Nothing to preview.'))}</p>`);
            } catch (error) {
                this.$preview.empty();
            }
        },
    });

    $(() => $('[data-markdown-editor]').each((i, container) => new Ghostwriter.MarkdownEditor(container)));
})();

/**
 * The content plan screen. Ideas are drawn from the plan's data and redrawn
 * after every change; suggestions wait in a modal to be looked over.
 */
(function () {
    const esc = (text) => Ghostwriter.escape(text);
    const t = (message, params) => Craft.t('ghostwriter', message, params);

    const STAGES = {
        failed: 'Something went wrong last time',
        working: 'Ghostwriter is writing',
        interview: 'Waiting on your answers',
        draft: 'Draft ready to use',
        in_form: 'In the entry, not saved',
        saved: 'Saved',
        published: 'Published',
    };

    Ghostwriter.PlanScreen = Garnish.Base.extend({
        init(config) {
            this.config = config;
            this.plan = config.plan;
            this.chosen = [];
            this.showDone = false;
            this.timer = null;
            this.modal = null;

            this.$root = $('#gw-plan');

            this.addListener($('#gw-plan-suggest'), 'click', 'suggest');
            this.addListener($('#gw-plan-add'), 'click', 'add');
            this.addListener($('#gw-plan-clear'), 'click', () => this.clear('open'));
            this.addListener(this.$root, 'click', 'onClick');

            this.apply(this.plan);

            // Sent here to look them over (from Get started): open them.
            if (new URLSearchParams(window.location.search).get('review')) {
                Ghostwriter.address({ review: null });
                this.review();
            }
        },

        working() {
            return this.plan.status === 'working';
        },

        apply(data) {
            const finished = this.working() && data.status === 'idle';
            const batch = JSON.stringify(data.pending.map((idea) => idea.title));

            this.plan = data;

            // A fresh batch of suggestions: everything ticked to start with.
            if (batch !== this.batch) this.chosen = data.pending.map((idea, i) => i);
            this.batch = batch;

            this.render();

            // Suggestions that have just arrived open by themselves. Closing
            // them leaves them waiting, with a card at the top to reopen them.
            if (finished && data.pending.length) this.review();
            if (data.status === 'working') this.poll();
            if (finished && !data.pending.length && data.status !== 'failed') Craft.cp.displayNotice(t('Nothing new to suggest this time.'));
        },

        poll() {
            clearTimeout(this.timer);

            this.timer = setTimeout(async () => {
                try {
                    this.apply(await Ghostwriter.request('GET', 'plan/status'));
                } catch (error) {
                    this.poll();
                }
            }, 2500);
        },

        async send(action, data) {
            try {
                this.apply(await Ghostwriter.request('POST', action, data));

                return true;
            } catch (error) {
                return false;
            }
        },

        suggest() {
            return this.send('plan/suggest', {
                sections: $('#gw-plan-sections input:checked').map((i, input) => input.value).get(),
                steer: $('#gw-plan-steer').val(),
            });
        },

        async add() {
            const title = $('#gw-plan-title').val().trim();

            if (!title) return;

            if (await this.send('plan/add', { title, section: $('#gw-plan-section').val(), notes: $('#gw-plan-notes').val() })) {
                $('#gw-plan-title, #gw-plan-notes').val('');
            }
        },

        // Started pieces stay: they belong to a conversation, not the list.
        clear(status) {
            const count = this.plan.ideas.filter((idea) => idea.status === status).length;
            const question = status === 'open'
                ? t('{count, plural, =1{Remove the one idea from the list?} other{Remove all # ideas from the list?}} Started and dismissed ones stay. This cannot be undone.', { count })
                : t('{count, plural, =1{Delete the dismissed idea?} other{Delete all # dismissed ideas?}} Ghostwriter will no longer know not to suggest them again.', { count });

            if (confirm(question)) this.send('plan/clear', { status });
        },

        onClick(event) {
            const $target = $(event.target).closest('[data-plan]');

            if (!$target.length) return;

            const id = $target.data('id');

            switch ($target.data('plan')) {
                case 'dismiss': return this.send('plan/update', { id, status: 'dismissed' });
                case 'reopen': return this.send('plan/update', { id, status: 'open' });
                case 'delete': return this.send('plan/delete', { id });
                case 'clear-dismissed': return this.clear('dismissed');
                case 'review': return this.review();
                case 'toggle-done': this.showDone = !this.showDone; return this.render();
            }
        },

        render() {
            const ideas = this.plan.ideas;
            const open = ideas.filter((idea) => idea.status === 'open');
            const started = ideas.filter((idea) => idea.status === 'drafted' && !idea.finished);
            const done = ideas.filter((idea) => idea.status === 'dismissed' || (idea.status === 'drafted' && idea.finished));
            const groups = this.config.sections.map((section) => ({ ...section, ideas: open.filter((idea) => idea.section === section.handle) })).filter((group) => group.ideas.length);

            $('#gw-plan-clear').toggleClass('hidden', !open.length);
            Ghostwriter.prepareButtons('#gw-plan-suggest');
            $('#gw-plan-suggest').toggleClass('loading', this.working()).prop('disabled', this.working() || !this.config.configured).find('.label').text(this.working() ? t('Looking…') : t('Suggest ideas'));

            let html = '';

            // Suggestions not yet looked over wait here until they are.
            if (this.plan.pending.length) {
                html += `<section class="gw-waiting" aria-label="${esc(t('Suggestions waiting'))}">
                    <div><strong>${esc(t('{count, plural, =1{# suggestion waiting} other{# suggestions waiting}}', { count: this.plan.pending.length }))}</strong>
                    <span class="light">${esc(t('Ghostwriter’s ideas, to keep or dismiss.'))}</span></div>
                    <button type="button" class="btn submit" data-plan="review">${esc(t('Review'))}</button>
                </section>`;
            }

            if (this.plan.status === 'failed') {
                html += `<p class="error with-icon gw-alert"><strong>${esc(t('That didn’t work'))}</strong> ${esc(this.plan.error)}</p>`;
            }

            if (started.length) {
                html += `<section class="gw-section"><h2>${esc(t('In progress'))}</h2><p class="light">${esc(t('Started, and not yet saved as an entry.'))}</p>
                    <div class="gw-plan-list">${started.map((idea) => `
                        <div class="gw-plan-item">
                            <div class="gw-plan-item__text"><strong>${esc(idea.title)}</strong><span class="light">${esc([idea.sectionTitle, t(STAGES[idea.stage] ?? 'Started'), Ghostwriter.people(idea)].filter(Boolean).join(' · '))}</span></div>
                            <div class="gw-plan-item__actions">
                                ${idea.resumeUrl ? `<a class="btn small submit" href="${esc(idea.resumeUrl)}">${esc(t('Resume'))}</a>` : ''}
                                <button type="button" class="btn small" data-plan="reopen" data-id="${esc(idea.id)}">${esc(t('Back to ideas'))}</button>
                            </div>
                        </div>`).join('')}</div></section>`;
            }

            if (!groups.length && !started.length) {
                html += `<div class="gw-empty">${this.working() ? `<div class="spinner"></div><p>${esc(t('Reading what the site has and looking for what is missing. This takes a minute or so.'))}</p>` : `<p>${esc(t('Nothing on the plan yet. Ask Ghostwriter what is missing, or add an idea of your own.'))}</p>`}</div>`;
            }

            groups.forEach((group) => {
                html += `<section class="gw-section"><h2>${esc(group.title)}</h2><p class="light">${esc(t('{count, plural, =1{# to write} other{# to write}}', { count: group.ideas.length }))}</p>
                    <div class="gw-plan-list">${group.ideas.map((idea) => `
                        <div class="gw-plan-item">
                            <div class="gw-plan-item__text">
                                <strong>${esc(idea.title)}</strong>
                                ${idea.typeTitle ? `<span class="gw-badge">${esc(idea.typeTitle)}</span>` : ''}${idea.source === 'suggested' ? `<span class="gw-badge gw-badge--blue">${esc(t('Suggested'))}</span>` : ''}
                                ${idea.why ? `<p>${esc(idea.why)}</p>` : ''}
                                ${idea.notes ? `<p class="light gw-pre">${esc(idea.notes)}</p>` : ''}
                            </div>
                            <div class="gw-plan-item__actions">
                                ${idea.draftUrl ? `<a class="btn small submit" href="${esc(idea.draftUrl)}">${esc(t('Draft this'))}</a>` : ''}
                                <button type="button" class="btn small" data-plan="dismiss" data-id="${esc(idea.id)}">${esc(t('Not this one'))}</button>
                            </div>
                        </div>`).join('')}</div></section>`;
            });

            if (done.length) {
                html += `<section class="gw-section">
                    <button type="button" class="btn small" data-plan="toggle-done">${esc(this.showDone ? t('Hide finished and dismissed') : t('Show {count} finished and dismissed', { count: done.length }))}</button>
                    ${this.showDone ? `<div class="gw-plan-list">${done.map((idea) => `
                        <div class="gw-plan-item">
                            <div class="gw-plan-item__text"><span class="${idea.status === 'dismissed' ? 'gw-struck' : ''}">${esc(idea.title)}</span> <span class="light">${esc(idea.sectionTitle)} · ${esc(idea.status === 'drafted' ? t(STAGES[idea.stage] ?? 'Started') : t('Dismissed'))}</span></div>
                            <div class="gw-plan-item__actions">
                                ${idea.entryUrl ? `<a class="btn small" href="${esc(idea.entryUrl)}">${esc(t('Open entry'))}</a>` : ''}
                                ${idea.status === 'dismissed' ? `<button type="button" class="btn small" data-plan="reopen" data-id="${esc(idea.id)}">${esc(t('Put back'))}</button>` : ''}
                                <button type="button" class="btn small" data-plan="delete" data-id="${esc(idea.id)}">${esc(t('Delete'))}</button>
                            </div>
                        </div>`).join('')}
                        ${done.some((idea) => idea.status === 'dismissed') ? `<button type="button" class="btn small" data-plan="clear-dismissed">${esc(t('Delete all dismissed'))}</button>` : ''}
                    </div>` : ''}
                </section>`;
            }

            this.$root.html(html);
            Ghostwriter.prepareButtons(this.$root);
        },

        // Ticked suggestions join the plan; unticked ones are kept as
        // dismissed so they are not suggested again. Closing the box
        // decides nothing: they wait, for the card at the top to reopen.
        // Only "Drop them all" throws the batch away.
        review() {
            if (!this.plan.pending.length) return;

            const pending = this.plan.pending;
            const $body = $(`
                <div class="gw-review">
                    <div class="gw-review__body">
                        <h2>${esc(t('Ghostwriter suggests'))}</h2>
                        <p class="light">${esc(t('Tick the ones worth writing. Unticked ones are kept as dismissed, so they are not suggested again.'))}</p>
                        ${pending.map((idea, i) => `
                            <div class="gw-review__idea">
                                <input type="checkbox" class="checkbox" id="gw-pending-${i}" value="${i}" ${this.chosen.includes(i) ? 'checked' : ''}>
                                <label for="gw-pending-${i}"><strong>${esc(idea.title)}</strong></label>
                                <div class="light smalltext">${esc(idea.sectionTitle)}${idea.typeTitle ? ` · ${esc(idea.typeTitle)}` : ''}</div>
                                ${idea.why ? `<p>${esc(idea.why)}</p>` : ''}
                                ${idea.notes ? `<p class="light gw-pre">${esc(idea.notes)}</p>` : ''}
                            </div>`).join('')}
                    </div>
                    <div class="footer"><div class="buttons right">
                        <button type="button" class="btn" data-review="drop">${esc(t('Drop them all'))}</button>
                        <button type="button" class="btn submit" data-review="keep">${esc(this.keepLabel())}</button>
                    </div></div>
                </div>`);

            Ghostwriter.prepareButtons($body);

            if (this.modal) {
                this.modal.$container.empty().append($body);
                this.modal.show();
            } else {
                const $container = $('<div class="modal gw-review-modal"/>').append($body).appendTo(Garnish.$bod);
                this.modal = new Garnish.Modal($container, { hideOnShadeClick: false });
            }

            this.decided = false;
            this.modal.$container.find('input[type=checkbox]').on('change', (event) => {
                const i = Number(event.target.value);
                this.chosen = event.target.checked ? [...this.chosen, i] : this.chosen.filter((n) => n !== i);
                this.modal.$container.find('[data-review="keep"] .label').text(this.keepLabel());
            });
            this.modal.$container.find('[data-review]').on('click', (event) => this.decide(event.currentTarget.dataset.review === 'drop'));
        },

        // "Add 3 to the plan", or with none ticked, "Add none, dismiss the rest".
        keepLabel() {
            return this.chosen.length ? t('Add {count} to the plan', { count: this.chosen.length }) : t('Add none, dismiss the rest');
        },

        async decide(discard) {
            if (this.decided) return;

            this.decided = true;

            const chosen = discard ? [] : this.chosen;
            const total = this.plan.pending.length;
            this.modal.hide();

            if (!await this.send('plan/accept', { chosen, discard: discard ? 1 : 0 }) || discard) {
                this.decided = false;

                return;
            }

            Craft.cp.displaySuccess(chosen.length
                ? t('{count, plural, =1{# idea} other{# ideas}} added to the plan.', { count: chosen.length })
                : t('{count, plural, =1{Dismissed the suggestion.} other{Dismissed # suggestions.}}', { count: total }));
        },
    });
})();

/**
 * Getting started, as a wizard: one step at a time, each doing its job in
 * place, with the steps along the top to move between. Every step is read
 * from the state of the site, so it ticks itself off.
 */
(function () {
    const esc = (text) => Ghostwriter.escape(text);
    const t = (message, params) => Craft.t('ghostwriter', message, params);

    Ghostwriter.SetupScreen = Garnish.Base.extend({
        init(state) {
            this.state = state;
            this.timer = null;
            this.$root = $('#gw-setup');

            // Start where there is something to do, or where the address says.
            const fromHash = Number((window.location.hash.match(/step-(\d+)/) ?? [])[1]);
            const firstOpen = state.steps.findIndex((step) => !step.done && !step.optional);

            this.current = fromHash >= 1 && fromHash <= state.steps.length ? fromHash - 1 : Math.max(0, firstOpen);

            this.addListener(this.$root, 'click', 'onClick');
            this.addListener(this.$root, 'change', 'onChange');
            this.render();
        },

        // The voice is read from the sections ticked: with none, there is
        // nothing to read.
        onChange() {
            const none = !this.$root.find('input[name="voiceRead"]:checked').length;

            this.$root.find('[data-wizard="voice"]').prop('disabled', none || !this.state.configured).toggleClass('disabled', none || !this.state.configured);
        },

        go(index) {
            this.current = Math.min(Math.max(0, index), this.state.steps.length - 1);
            Ghostwriter.address({});
            history.replaceState(history.state, '', `#step-${this.current + 1}`);
            this.render();
            this.$root[0].scrollIntoView({ block: 'start', behavior: 'smooth' });
        },

        async refresh() {
            try {
                this.state = await Ghostwriter.request('GET', 'setup/status');
                this.render();
            } catch (error) {}
        },

        poll() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.refresh(), 3000);
        },

        async post(route, data, $button) {
            $button?.addClass('loading').prop('disabled', true);

            try {
                await Ghostwriter.request('POST', route, data);
                await this.refresh();
            } catch (error) {
                $button?.removeClass('loading').prop('disabled', false);
            }
        },

        onClick(event) {
            const $target = $(event.target).closest('[data-wizard]');

            if (!$target.length || $target.prop('disabled')) return;

            const d = this.state.details;
            const ticked = (name) => this.$root.find(`input[name="${name}"]:checked`).map((i, input) => input.value).get();

            switch ($target.data('wizard')) {
                case 'go': return this.go(Number($target.data('step')));
                case 'next': return this.go(this.current + 1);
                case 'back': return this.go(this.current - 1);
                case 'check': return this.refresh();
                case 'save-sections': return this.post('setup/sections', { sections: ticked('writeFor'), voiceSections: ticked('voice') }, $target);
                case 'voice': return this.post('voice/scan', { sections: ticked('voiceRead') }, $target);
                case 'suggest-kinds': return this.post('sections/suggest-kinds', $target.data('section') ? { section: $target.data('section') } : {}, $target);
                case 'learn-kind': return this.post('sections/learn-kind', { section: $target.data('section'), id: $target.data('id') }, $target);
                case 'learn-all':
                    if (!confirm(t('Learn all {count} suggested kinds, one after another? This takes about a minute each.', { count: $target.data('count') }))) return;

                    return this.post('sections/learn-all-kinds', { section: $target.data('section') }, $target);
                case 'dismiss-kind': return this.post('sections/dismiss-kind', { section: $target.data('section'), id: $target.data('id') }, $target);
                case 'imagery': return this.post('imagery/scan', { sections: d.sections.filter((s) => s.writeFor).map((s) => s.handle) }, $target);
                case 'plan': return this.post('plan/suggest', { steer: this.$root.find('[data-wizard-steer]').val() ?? '' }, $target);
                case 'hide': return this.post('setup/hide', { hidden: 1 }, $target).then(() => (window.location.href = Craft.getCpUrl('ghostwriter')));
            }
        },

        render() {
            const steps = this.state.steps;
            const step = steps[this.current];
            const last = this.current === steps.length - 1;
            const working = steps.some((candidate) => candidate.working);

            if (step.key === 'kinds') this.lookForKinds();

            // Only the steps setup needs are counted; the optional ones say so.
            const required = steps.filter((candidate) => !candidate.optional);
            const done = required.filter((candidate) => candidate.done).length;
            const status = (candidate) => candidate.working ? t('Working…') : candidate.done ? t('Done') : candidate.optional ? t('Optional') : t('To do');

            const bar = steps.map((candidate, i) => `
                <li class="gw-wizard__stop ${i === this.current ? 'is-current' : ''} ${candidate.done ? 'is-done' : ''} ${candidate.working ? 'is-working' : ''}">
                    <button type="button" data-wizard="go" data-step="${i}" ${i === this.current ? 'aria-current="step"' : ''}>
                        <span class="gw-wizard__mark" aria-hidden="true">${candidate.done ? '✓' : i + 1}</span>
                        <span class="gw-wizard__label">
                            <span class="gw-wizard__name">${esc(candidate.title)}</span>
                            <span class="gw-wizard__status">${esc(status(candidate))}</span>
                        </span>
                    </button>
                </li>`).join('');

            this.$root.html(`
                <aside class="gw-wizard__rail">
                    <p class="gw-wizard__progress">${esc(t('{done} of {total} done', { done, total: required.length }))}</p>
                    <div class="gw-wizard__meter" role="progressbar" aria-valuemin="0" aria-valuemax="${required.length}" aria-valuenow="${done}"><span style="width: ${Math.round((done / Math.max(1, required.length)) * 100)}%"></span></div>
                    <ol class="gw-wizard__bar">${bar}</ol>
                </aside>
                <div class="gw-wizard__main">
                <section class="gw-wizard__step">
                    <p class="light gw-wizard__count">${esc(t('Step {n} of {total}', { n: this.current + 1, total: steps.length }))}${step.optional ? ` · ${esc(t('Optional'))}` : ''}${step.done ? ` · <span class="gw-wizard__done">${esc(t('Done'))}</span>` : ''}</p>
                    <h2>${esc(step.title)}</h2>
                    <p class="gw-wizard__lead">${esc(step.text)}</p>
                    <div class="gw-wizard__body">${this[`step_${step.key}`](step)}</div>
                </section>
                <footer class="gw-wizard__footer">
                    <button type="button" class="btn" data-wizard="back" ${this.current === 0 ? 'disabled' : ''}>${esc(t('Back'))}</button>
                    <div class="flex">
                        ${last
                            ? (this.state.details.canHide
                                ? `<button type="button" class="btn" data-wizard="hide">${esc(t('Finish and hide this'))}</button>`
                                : `<a class="btn" href="${esc(Craft.getCpUrl('ghostwriter'))}">${esc(t('Finish'))}</a>`)
                            : `<button type="button" class="btn ${step.done || step.optional ? 'submit' : ''}" data-wizard="next">${esc(step.done ? t('Next') : step.optional ? t('Skip') : t('Next'))}</button>`}
                    </div>
                </footer>
                </div>`);

            Ghostwriter.prepareButtons(this.$root);

            if (working) this.poll();
        },

        // Get started is the one place kinds are suggested without a click:
        // once per page load, when the step is shown, for the sections due a look,
        // when the setting is on. The server decides which are due.
        lookForKinds() {
            if (this.lookedForKinds || !this.state.configured || !this.state.details.autoKinds) return;

            this.lookedForKinds = true;

            Ghostwriter.request('POST', 'sections/suggest-kinds', { due: 1 })
                .then((data) => { if (data.status === 'working') this.refresh(); })
                .catch(() => {});
        },

        working(text) {
            return `<p class="gw-wizard__working"><span class="spinner small"></span> ${esc(text)} ${esc(t('You can leave this page; it carries on.'))}</p>`;
        },

        failed(error) {
            return error ? `<p class="error with-icon">${esc(error)}</p>` : '';
        },

        keyless() {
            return this.state.configured ? '' : `<p class="warning with-icon">${esc(t('This needs the API key from step 1.'))}</p>`;
        },

        step_key(step) {
            const d = this.state.details;

            return step.done
                ? `<p class="gw-wizard__ok">✓ ${esc(t('Connected. Ghostwriter writes with {provider}.', { provider: d.provider }))}</p>
                   ${d.canChangeSettings ? `<p class="light">${esc(t('To use a different provider or model, change it in the settings.'))} <a href="${esc(Craft.getCpUrl('settings/plugins/ghostwriter'))}">${esc(t('Open the settings'))}</a></p>` : ''}`
                : `<ol class="gw-wizard__list">
                       <li>${esc(t('Get an API key from your provider. Ghostwriter is set to use {provider}.', { provider: d.provider }))}</li>
                       <li>${t('Add it to your project’s <code>.env</code> file as <code>{key}=…</code>', { key: esc(d.keyName ?? 'the API key') })}</li>
                       <li>${esc(t('Come back here and check again.'))}</li>
                   </ol>
                   ${d.otherKey ? `<p class="gw-wizard__hint">${esc(t('There is already a key for {other}. To write with it instead, choose {other} in the settings.', { other: d.otherKey }))}${d.canChangeSettings ? ` <a href="${esc(Craft.getCpUrl('settings/plugins/ghostwriter'))}">${esc(t('Open the settings'))}</a>` : ''}</p>` : ''}
                   <p class="light">${esc(t('The key is read from .env each time it is needed and is never stored by Ghostwriter.'))}</p>
                   <button type="button" class="btn submit" data-wizard="check">${esc(t('Check again'))}</button>`;
        },

        step_sections() {
            const d = this.state.details;

            if (!d.canChangeSettings) {
                return `<p>${esc(t('Ghostwriter writes for: {list}.', { list: d.sections.filter((s) => s.writeFor).map((s) => s.title).join(', ') }))}</p><p class="light">${esc(t('An admin can change this in the settings.'))}</p>`;
            }

            return `
                <table class="data fullwidth gw-wizard__table">
                    <thead><tr><th scope="col">${esc(t('Section'))}</th><th scope="col">${esc(t('Write for it'))}</th><th scope="col">${esc(t('Learn the voice from it'))}</th></tr></thead>
                    <tbody>${d.sections.map((s) => `
                        <tr>
                            <th scope="row">${esc(s.title)} <span class="light">(${esc(t('{count} live', { count: s.entries }))})</span></th>
                            <td><input type="checkbox" class="checkbox" id="gw-w-${esc(s.handle)}" name="writeFor" value="${esc(s.handle)}" ${s.writeFor ? 'checked' : ''}><label for="gw-w-${esc(s.handle)}"><span class="visually-hidden">${esc(t('Write for {section}', { section: s.title }))}</span></label></td>
                            <td><input type="checkbox" class="checkbox" id="gw-v-${esc(s.handle)}" name="voice" value="${esc(s.handle)}" ${s.voice ? 'checked' : ''}><label for="gw-v-${esc(s.handle)}"><span class="visually-hidden">${esc(t('Learn the voice from {section}', { section: s.title }))}</span></label></td>
                        </tr>`).join('')}
                    </tbody>
                </table>
                <p class="light">${esc(t('Learn the voice from the sections with your best writing, such as news or articles. Pages of links and listings teach it little.'))}</p>
                <button type="button" class="btn submit" data-wizard="save-sections">${esc(t('Save these sections'))}</button>`;
        },

        step_voice(step) {
            const v = this.state.details.voice;

            if (step.working) return this.working(t('Reading your published content and writing the guide. This takes a minute or so.'));

            const choose = this.state.details.sections.filter((s) => s.writeFor || s.voice).map((s) => `
                <div><input type="checkbox" class="checkbox" id="gw-r-${esc(s.handle)}" name="voiceRead" value="${esc(s.handle)}" ${s.voice && s.entries ? 'checked' : ''} ${s.entries ? '' : 'disabled'}><label for="gw-r-${esc(s.handle)}">${esc(s.title)} <span class="light">(${esc(s.entries)})</span></label></div>`).join('');

            return `
                ${this.failed(v.status === 'failed' ? v.error : null)}
                ${v.exists ? `<div class="gw-wizard__guide gw-prose">${v.excerpt}</div><p><a href="${esc(Craft.getCpUrl('ghostwriter/voice'))}">${esc(t('Read and edit the whole guide'))}</a></p><h3>${esc(t('Write it again'))}</h3>` : ''}
                <p class="light">${esc(t('Read the newest published entries from:'))}</p>
                <div class="gw-checks">${choose}</div>
                ${this.keyless()}
                <button type="button" class="btn ${v.exists ? '' : 'submit'}" data-wizard="voice" ${this.state.configured && this.state.details.sections.some((s) => (s.writeFor || s.voice) && s.voice && s.entries) ? '' : 'disabled'}>${esc(v.exists ? t('Read the site again') : t('Write the voice guide'))}</button>`;
        },

        step_kinds() {
            const kinds = this.state.details.kinds;
            const anyLooked = kinds.some((k) => k.suggestions.length || k.types.length || k.state !== 'idle');

            return `
                ${this.keyless()}
                ${anyLooked ? '' : `<button type="button" class="btn submit" data-wizard="suggest-kinds" ${this.state.configured ? '' : 'disabled'}>${esc(t('Suggest kinds for every section'))}</button>`}
                <div class="gw-wizard__kinds">${kinds.map((k) => `
                    <div class="gw-wizard__section">
                        <div class="flex flex-justify">
                            <h3>${esc(k.title)}</h3>
                            <div class="flex">
                                ${k.suggestions.length > 1 ? `<button type="button" class="btn small" data-wizard="learn-all" data-section="${esc(k.handle)}" data-count="${k.suggestions.length}" ${k.learning.status === 'working' ? 'disabled' : ''}>${esc(t('Learn all {count}', { count: k.suggestions.length }))}</button>` : ''}
                                <button type="button" class="btn small" data-wizard="suggest-kinds" data-section="${esc(k.handle)}" ${k.state === 'working' || !this.state.configured ? 'disabled' : ''}>${esc(t('Suggest kinds'))}</button>
                            </div>
                        </div>
                        ${k.state === 'working' ? this.working(t('Looking at the entries.')) : ''}
                        ${k.learning.status === 'working' ? this.working(t('Learning. About a minute each.')) : ''}
                        ${this.failed(k.state === 'failed' ? k.error : null)}${this.failed(k.learning.status === 'failed' ? k.learning.error : null)}
                        ${k.types.length ? `<p class="gw-wizard__ok">✓ ${esc(t('Learned'))}: ${k.types.map((type) => `<a href="${esc(type.url)}">${esc(type.title)}</a>`).join(', ')}</p>` : ''}
                        ${k.suggestions.map((s) => `
                            <div class="gw-type gw-type--suggested">
                                <span><strong>${esc(s.title)}</strong> <span class="light">${esc(s.description)}</span>${s.why ? `<span class="light smalltext gw-why">${esc(s.why)}</span>` : ''}${s.exampleTitles?.length ? `<span class="light smalltext gw-why">${esc(t('For example: {titles}', { titles: s.exampleTitles.map((title) => `“${title}”`).join(', ') }))}</span>` : ''}</span>
                                <span class="flex">
                                    <button type="button" class="btn small submit" data-wizard="learn-kind" data-section="${esc(k.handle)}" data-id="${esc(s.id)}" ${k.learning.status === 'working' ? 'disabled' : ''}>${esc(t('Learn this'))}</button>
                                    <button type="button" class="btn small" data-wizard="dismiss-kind" data-section="${esc(k.handle)}" data-id="${esc(s.id)}">${esc(t('Not this'))}</button>
                                </span>
                            </div>`).join('')}
                        ${!k.types.length && !k.suggestions.length && k.state === 'idle' ? `<p class="light">${esc(t('Nothing suggested yet. Without a kind, the general brief is used.'))}</p>` : ''}
                    </div>`).join('')}
                </div>`;
        },

        step_imagery(step) {
            const i = this.state.details.imagery;

            if (step.working) return this.working(t('Looking at the images your entries use. This takes a minute or so.'));

            return `
                ${this.failed(i.status === 'failed' ? i.error : null)}
                ${i.exists ? `<div class="gw-wizard__guide gw-prose">${i.excerpt}</div><p><a href="${esc(Craft.getCpUrl('ghostwriter/imagery'))}">${esc(t('Read and edit the whole guide'))}</a></p>` : ''}
                ${this.keyless()}
                <button type="button" class="btn ${i.exists ? '' : 'submit'}" data-wizard="imagery" ${this.state.configured ? '' : 'disabled'}>${esc(i.exists ? t('Look at the images again') : t('Describe the images'))}</button>`;
        },

        step_plan(step) {
            const p = this.state.details.plan;

            if (step.working) return this.working(t('Reading what the site has and looking for what is missing.'));

            return `
                ${this.failed(p.status === 'failed' ? p.error : null)}
                ${p.pending ? `<p class="gw-wizard__ok">${esc(t('{count, plural, =1{# suggestion is} other{# suggestions are}} waiting for you to look over.', { count: p.pending }))} <a class="btn small submit" href="${esc(Craft.getUrl(p.url, { review: 1 }))}">${esc(t('Look over them'))}</a></p>` : ''}
                ${p.ideas ? `<p class="gw-wizard__ok">✓ ${esc(t('{count, plural, =1{# idea} other{# ideas}} on the plan.', { count: p.ideas }))} <a href="${esc(p.url)}">${esc(t('Open the content plan'))}</a></p>` : ''}
                ${this.keyless()}
                ${p.pending ? '' : `
                    <textarea class="text fullwidth gw-wizard__steer" rows="2" data-wizard-steer aria-label="${esc(t('Anything to steer it'))}" placeholder="${esc(t('Optional: anything to steer it. “More for owners.” “Events.”'))}" ${this.state.configured ? '' : 'disabled'}></textarea>
                    <button type="button" class="btn ${p.ideas ? '' : 'submit'}" data-wizard="plan" ${this.state.configured ? '' : 'disabled'}>${esc(t('Suggest ideas'))}</button>`}`;
        },

        step_write(step) {
            const options = step.action?.options ?? [];

            return `
                <p class="light">${esc(t('A new entry opens with Ghostwriter on it. Give it a title and a few notes and it fills in the brief; it asks for what it still needs, then drafts. “Use this draft” puts it into the entry for you to check and save.'))}</p>
                <div class="gw-wizard__write">${options.map((option) => `<a class="btn submit" href="${esc(option.url)}">${esc(t('Write in {section}', { section: option.label }))}</a>`).join('')}</div>`;
        },
    });

    // Get started can be put away, from the dashboard or its own page, and
    // brought back from the foot of the dashboard.
    $(document).on('click', '[data-hide-setup]', async (event) => {
        const $button = $(event.currentTarget).addClass('loading');

        try {
            await Ghostwriter.request('POST', 'setup/hide', { hidden: 1 });

            if ($button.data('hide-setup') === 'leave') {
                window.location.href = Craft.getCpUrl('ghostwriter');

                return;
            }

            window.location.reload();
        } catch (error) {
            $button.removeClass('loading');
        }
    });

    $(document).on('click', '[data-show-setup]', async () => {
        try {
            await Ghostwriter.request('POST', 'setup/hide', { hidden: 0 });
            window.location.reload();
        } catch (error) {}
    });

    /**
     * Stock photos: the badge that marks a preview (on an image field, in
     * the asset's sidebar, on the ledger screen), and what its buttons do:
     * License & replace (with the confirm step), Request licence, Refresh
     * preview, Download again and replace.
     */
    Ghostwriter.Stock = {
        t(message, params) {
            return Craft.t('ghostwriter', message, params);
        },

        /** The badge: its state and library, and what can be done. */
        badgeHtml(item) {
            const t = this.t;
            const esc = Ghostwriter.escape;
            const actions = [];

            if (item.mayLicense) {
                actions.push(`<button type="button" class="btn small submit" data-stock="license">${t('License')}</button>`);
            }

            if (item.mayReplace) {
                actions.push(`<button type="button" class="btn small submit" data-stock="replace">${t('Download again and replace')}</button>`);
            }

            if (item.mayRefresh) {
                actions.push(`<button type="button" class="btn small" data-stock="refresh">${t('Refresh preview')}</button>`);
            }

            if (!item.mayLicense && !item.mayReplace && ['preview', 'failed', 'licensing'].includes(item.state)) {
                actions.push(`<span class="light">${t('Ask a manager to license')}</span>`);

                if (item.mayRequest) {
                    actions.push(`<button type="button" class="btn small" data-stock="request">${t('Request licence')}</button>`);
                } else if (item.requested) {
                    actions.push(`<span class="light">${esc(t('Licence requested by {name}', { name: item.requested.name ?? '' }))}</span>`);
                }
            }

            return `
                <div class="gw-stock-badge gw-stock-badge--${esc(item.state)}${item.expired ? ' gw-stock-badge--expired' : ''}" data-stock-id="${esc(item.id)}">
                    <span class="gw-stock-badge__status">${esc(item.status)}</span>
                    <span class="light">${esc(item.library)} ${esc(item.externalId)}</span>
                    ${item.error && item.state !== 'preview' ? `<span class="error gw-stock-badge__error">${esc(item.error)}</span>` : ''}
                    ${actions.length ? `<span class="gw-stock-badge__actions">${actions.join(' ')}</span>` : ''}
                </div>`;
        },

        /**
         * Put badges into a container and wire their buttons. `changed` is
         * called with the result of anything that changes a record.
         */
        render($container, items, changed) {
            $container.html(items.map((item) => this.badgeHtml(item)).join(''));
            Ghostwriter.prepareButtons($container);

            items.forEach((item) => {
                const $badge = $container.find(`[data-stock-id="${item.id}"]`);
                this.wire($badge, item, changed);
            });
        },

        wire($root, item, changed) {
            $root.find('[data-stock="license"]').on('click', () => this.license(item, changed));
            $root.find('[data-stock="request"]').on('click', (event) => this.act('stock/request', item, $(event.currentTarget), changed));
            $root.find('[data-stock="refresh"]').on('click', (event) => this.act('stock/refresh', item, $(event.currentTarget), changed));
            $root.find('[data-stock="replace"]').on('click', (event) => this.act('stock/replace-again', item, $(event.currentTarget), changed));
        },

        async act(action, item, $button, changed) {
            $button.addClass('loading');

            try {
                const result = await Ghostwriter.request('POST', action, { id: item.id });
                Craft.cp.displaySuccess(result.message);
                changed?.(result);
            } catch (error) {
            } finally {
                $button.removeClass('loading');
            }
        },

        /**
         * The confirm step (§8.3): the photo, the licence option, what it
         * costs in the account's own terms, any restrictions and notices,
         * the credit that will be kept; then License & replace.
         */
        async license(item, changed) {
            const t = this.t;
            const esc = Ghostwriter.escape;
            let quote;

            try {
                quote = await Ghostwriter.request('GET', 'stock/quote', { id: item.id });
            } catch (error) {
                return;
            }

            const $modal = $('<div class="modal gw-license-modal" role="dialog"/>').attr('aria-label', t('License & replace')).appendTo(Garnish.$bod);
            const options = quote.quotes.map((option, i) => `<option value="${esc(option.option)}"${i === 0 ? ' selected' : ''}>${esc(option.name)}</option>`).join('');

            $modal.html(`
                <div class="gw-panel">
                    <div class="gw-panel__header">
                        <h2 class="gw-panel__title">${t('License & replace')}</h2>
                        <button type="button" class="btn gw-license-close">${t('Close')}</button>
                    </div>
                    <div class="gw-panel__body gw-license">
                        <div class="gw-license__photo">
                            ${quote.thumb ? `<img src="${esc(quote.thumb)}" alt="">` : ''}
                            <div>
                                <strong>${esc(quote.title ?? '')}</strong>
                                <div class="light">${esc(quote.library)} ${esc(quote.externalId)}</div>
                            </div>
                        </div>
                        <div class="field">
                            <div class="heading"><label for="gw-license-option">${t('Licence')}</label></div>
                            ${quote.quotes.length > 1
                                ? `<div class="select"><select id="gw-license-option">${options}</select></div>`
                                : `<div>${esc(quote.quotes[0]?.name ?? '')}</div><input type="hidden" id="gw-license-option" value="${esc(quote.quotes[0]?.option ?? '')}">`}
                        </div>
                        <p class="gw-license__cost"></p>
                        <div class="gw-license__notices"></div>
                        ${quote.restrictions ? `<p class="warning with-icon">${esc(quote.restrictions)}</p>` : ''}
                        ${quote.editorial ? `<label class="gw-license__ack"><input type="checkbox" id="gw-license-ack"> ${t('This image is for editorial use only. I’ll use it on news or other editorial pages, not to advertise or promote anything.')}</label>` : ''}
                        ${quote.credit ? `<p class="gw-license__credit">${t('Credit:')} <strong>${esc(quote.credit)}</strong><br><span class="light">${t('If this page is news, a blog post or other editorial use, show this credit next to the image.')}</span></p>` : ''}
                        <p class="error gw-license__error hidden" role="alert"></p>
                    </div>
                    <div class="gw-panel__footer">
                        <button type="button" class="btn submit gw-license-go">${t('License & replace')}</button>
                    </div>
                </div>`);

            const modal = new Garnish.Modal($modal, { hideOnEsc: true, hideOnShadeClick: true, resizable: false, onHide: () => setTimeout(() => modal.destroy?.(), 300) });
            Ghostwriter.prepareButtons($modal);

            const describe = () => {
                const chosen = quote.quotes.find((option) => option.option === $modal.find('#gw-license-option').val()) ?? quote.quotes[0];
                $modal.find('.gw-license__cost').text(chosen?.cost ?? '');
                $modal.find('.gw-license__notices').html((chosen?.notices ?? []).map((notice) => `<p class="notice with-icon">${esc(notice)}</p>`).join(''));
                modal.updateSizeAndPosition?.();
            };

            describe();
            $modal.find('#gw-license-option').on('change', describe);
            $modal.find('.gw-license-close').on('click', () => modal.hide());
            $modal.find('img').on('load', () => modal.updateSizeAndPosition?.());

            $modal.find('.gw-license-go').on('click', async (event) => {
                const $go = $(event.currentTarget);

                if (quote.editorial && !$modal.find('#gw-license-ack').prop('checked')) {
                    $modal.find('.gw-license__error').text(t('This image is for editorial use only. Tick the box to confirm, then license it.')).removeClass('hidden');

                    return;
                }

                $go.addClass('loading').prop('disabled', true);
                $modal.find('.gw-license__error').addClass('hidden');

                try {
                    const result = await Ghostwriter.request('POST', 'stock/license', { id: item.id, option: $modal.find('#gw-license-option').val(), acknowledge: $modal.find('#gw-license-ack').prop('checked') ? 1 : 0 });

                    (result.replaced ? Craft.cp.displaySuccess : Craft.cp.displayError).call(Craft.cp, result.message);
                    modal.hide();
                    changed?.(result);
                } catch (error) {
                    $modal.find('.gw-license__error').text(error?.response?.data?.message ?? t('Something went wrong.')).removeClass('hidden');
                    modal.updateSizeAndPosition?.();

                    // The price changed: show the new one before asking again.
                    if (error?.response?.status === 409) {
                        try {
                            quote = await Ghostwriter.request('GET', 'stock/quote', { id: item.id });
                            describe();
                        } catch (e) {}
                    }
                } finally {
                    $go.removeClass('loading').prop('disabled', false);
                }
            });
        },

        /** The asset sidebar's panel: everything the ledger knows, and the badge's buttons. */
        panelHtml(item) {
            const t = this.t;
            const esc = Ghostwriter.escape;
            const row = (label, value) => value ? `<div class="data"><h5 class="heading">${esc(label)}</h5><div class="value">${value}</div></div>` : '';
            const licence = item.licence ? [item.licence.orderId, item.licence.cost, item.licence.by, item.licence.at].filter(Boolean).map(esc).join(' · ') : '';
            const used = (item.usages ?? []).map((usage) => `<div><a href="${esc(usage.url)}">${esc(usage.title ?? usage.label)}</a><br><span class="light">${esc(usage.label)}</span></div>`).join('');

            return [
                row(t('Status'), esc(item.status)),
                row(t('Library'), `${esc(item.library)} ${esc(item.externalId)}`),
                row(t('Licence'), licence),
                row(t('Credit'), esc(item.credit ?? '')),
                row(t('Restrictions'), esc(item.restrictions ?? '')),
                row(t('Used on'), used),
                `<div class="gw-stock-panel__badge"></div>`,
            ].join('');
        },
    };

    /**
     * The "Stock images" screen: each row's actions (License, Reconcile,
     * Remove preview, Request licence, Download licence record), and
     * looking again at where images are used.
     */
    Ghostwriter.StockLedger = Garnish.Base.extend({
        init() {
            const t = (message, params) => Craft.t('ghostwriter', message, params);
            const reload = () => window.location.reload();

            $('[data-stock-row]').each((i, row) => {
                const item = JSON.parse(row.dataset.stockRow);
                const $cell = $(row).find('.gw-stock-ledger__actions');
                const buttons = [];

                if (item.mayLicense) buttons.push(`<button type="button" class="btn small submit" data-stock="license">${t('License')}</button>`);
                if (item.mayReplace) buttons.push(`<button type="button" class="btn small submit" data-stock="replace">${t('Download again and replace')}</button>`);
                if (item.mayReconcile) buttons.push(`<button type="button" class="btn small" data-stock="reconcile">${t('Reconcile')}</button>`);
                if (item.mayRequest) buttons.push(`<button type="button" class="btn small" data-stock="request">${t('Request licence')}</button>`);
                if (item.mayRemove) buttons.push(`<button type="button" class="btn small" data-stock="remove">${t('Remove preview')}</button>`);
                if (item.licence) buttons.push(`<a class="btn small" href="${Ghostwriter.escape(Craft.getActionUrl('ghostwriter/stock/record', { id: item.id }))}">${t('Download licence record')}</a>`);

                $cell.html(`<div class="gw-stock-ledger__buttons">${buttons.join('')}</div>`);
                Ghostwriter.prepareButtons($cell);
                Ghostwriter.Stock.wire($cell, item, reload);
                $cell.find('[data-stock="reconcile"]').on('click', (event) => Ghostwriter.Stock.act('stock/reconcile', item, $(event.currentTarget), reload));
                $cell.find('[data-stock="remove"]').on('click', (event) => {
                    if (window.confirm(t('Remove this preview? Its stand-in is deleted from Assets and comes out of any entry it is in.'))) {
                        Ghostwriter.Stock.act('stock/remove', item, $(event.currentTarget), reload);
                    }
                });
            });

            $('#gw-stock-resync').on('click', async (event) => {
                const $button = $(event.currentTarget).addClass('loading');

                try {
                    const result = await Ghostwriter.request('POST', 'stock/resync', {});
                    Craft.cp.displaySuccess(result.message);
                    reload();
                } catch (error) {
                    $button.removeClass('loading');
                }
            });
        },
    });

    /**
     * The stock panel in an asset's sidebar.
     */
    Ghostwriter.initStockPanels = function (root) {
        $(root).find('.gw-stock-panel[data-ghostwriter-stock]').addBack('.gw-stock-panel[data-ghostwriter-stock]').each((i, panel) => {
            if (panel.dataset.ghostwriterReady) return;

            panel.dataset.ghostwriterReady = '1';

            const show = (item) => {
                const $panel = $(panel).html(Ghostwriter.Stock.panelHtml(item));
                const actionable = item.mayLicense || item.mayReplace || item.mayRefresh || item.mayRequest || item.requested;

                if (actionable) {
                    Ghostwriter.Stock.render($panel.find('.gw-stock-panel__badge'), [item], (result) => result.badge && show(result.badge));
                }
            };

            show(JSON.parse(panel.dataset.ghostwriterStock));
        });
    };

    /**
     * The Ghostwriter button on an image field, beside "Add an asset" and
     * "Upload a file". It finds a photograph or has a picture made, and puts
     * the one chosen into the field the way an upload would, through the
     * field's own input. The server only offers it when one of the two can
     * be done.
     */
    /**
     * Whether "Include editorial images" applies to a "Search in" choice:
     * only a source that can return editorial-only images (core's
     * Capabilities::$editorial), never the free libraries.
     */
    Ghostwriter.editorialFor = function (sources, value) {
        return Boolean((sources ?? []).find((source) => source.value === (value ?? 'free'))?.editorial);
    };

    Ghostwriter.ImageButton = Garnish.Base.extend({
        init(holder) {
            const t = (message, params) => Craft.t('ghostwriter', message, params);

            this.t = t;
            this.config = JSON.parse(holder.dataset.ghostwriterImage);
            this.$holder = $(holder);
            this.$select = this.$holder.prevAll('.elementselect').first();

            if (!this.$select.length) {
                this.$select = this.$holder.parent().find('.elementselect').first();
            }

            this.modal = null;
            this.request = null;
            this.timer = null;
            this.mode = this.config.canFind ? 'find' : 'make';

            this.$button = $('<button type="button" class="btn dashed gw-image-launch"/>').append('<span class="gw-mark" aria-hidden="true"></span>', document.createTextNode(t('Ghostwriter')));
            const $row = this.$select.find('> .flex').first();

            // Beside "Add an asset" and "Upload a file", before any search
            // box. Craft hides that row once the field is full, so then the
            // button sits under the field instead: a full field may be
            // holding only a placeholder, or a picture to replace.
            const place = () => {
                const $buttons = $row.children('.btn').not(this.$button);

                if ($row.length && !$row.hasClass('hidden') && $buttons.length) {
                    $buttons.last().after(this.$button);
                    this.$holder.addClass('hidden');
                } else {
                    this.$holder.append(this.$button).removeClass('hidden');
                }
            };

            place();

            if ($row.length) {
                new MutationObserver(place).observe($row[0], { attributes: true, attributeFilter: ['class'] });
            }
            Ghostwriter.prepareButtons(this.$button);
            this.addListener(this.$button, 'click', 'open');

            // Stock photo previews in the field: a badge each, under the field.
            this.$badges = $('<div class="gw-stock-badges"/>').insertAfter(this.$holder);
            this.badges(this.config.stock ?? []);
            this.addListener(this.$holder, 'ghostwriter:stock-changed', 'refreshBadges');
            this.$select.on('change', () => this.refreshBadges());
        },

        badges(items) {
            this.stockItems = items;

            if (this.$modal) {
                this.current();
            }

            Ghostwriter.Stock.render(this.$badges, items, (result) => {
                // Licensed: the field's thumbnail shows the new file.
                if (result.thumb && result.assetId) {
                    this.$select.find(`.element[data-id="${result.assetId}"] img`).attr({ src: result.thumb, srcset: result.thumb });
                }

                this.refreshBadges();
            });
        },

        async refreshBadges() {
            const ids = this.$select.find('.element[data-id]').map((i, element) => $(element).data('id')).get();

            try {
                const { data } = await Craft.sendActionRequest('GET', 'ghostwriter/stock/badges', { params: { assetIds: ids } });
                this.badges(data.badges ?? []);
            } catch (error) {}
        },

        open() {
            if (this.modal) {
                this.modal.show();

                return;
            }

            const t = this.t;
            const $modal = $('<div class="modal gw-image-modal" role="dialog"/>').attr('aria-label', t('Ghostwriter images')).appendTo(Garnish.$bod);

            $modal.html(`
                <div class="gw-panel">
                    <div class="gw-panel__header">
                        <h2 class="gw-panel__title"><span class="gw-icon" aria-hidden="true">${this.config.icon ?? ''}</span>${Ghostwriter.escape(t('Image for {label}', { label: this.config.label }))}</h2>
                        ${this.config.canFind && this.config.canMake ? `
                        <div class="btngroup gw-image-tabs" role="tablist">
                            <button type="button" class="btn" data-mode="find">${t('Find a photo')}</button>
                            <button type="button" class="btn" data-mode="make">${t('Make one')}</button>
                        </div>` : ''}
                        <button type="button" class="btn gw-image-close">${t('Close')}</button>
                    </div>
                    <div class="gw-panel__body">
                        <div class="gw-image-pane" data-pane="find">
                            <div class="gw-image-current hidden"></div>
                            <p class="light gw-image-intro">${Ghostwriter.escape(this.intro(this.config.source))}</p>
                            <div class="flex gw-image-form">
                                <input type="text" class="text fullwidth gw-image-words" placeholder="${Ghostwriter.escape(t('What should it show? Leave empty and Ghostwriter will choose'))}">
                                ${this.sourcePicker()}
                                <button type="button" class="btn submit gw-image-search">${t('Search')}</button>
                            </div>
                            <div class="gw-image-filters${Ghostwriter.editorialFor(this.config.sources, this.config.source) ? '' : ' hidden'}">
                                <div class="gw-image-editorial-row">
                                    <label class="gw-image-editorial"><input type="checkbox" aria-describedby="gw-image-editorial-hint"${this.config.editorial ? ' checked' : ''}> ${t('Include editorial images')}</label>
                                    <span class="info">${Ghostwriter.escape(t('Editorial photos show real news and events: public figures, sports, named brands and places. You can use them only in news or educational content, such as a story or blog post about the event, not in advertising or anything that promotes a product. They need their credit line shown next to the image. Off: only creative photos, which are safe on any page.'))}</span>
                                </div>
                                <p id="gw-image-editorial-hint" class="light gw-image-editorial__hint">${t('News and event photos. Not for advertising or promotion.')}</p>
                            </div>
                            <div class="gw-image-status"></div>
                            <div class="gw-image-grid"></div>
                        </div>
                        <div class="gw-image-pane hidden" data-pane="make">
                            <p class="light">${t('A new picture in the style of the images already used here, about the block and page it sits on.')}</p>
                            <textarea class="text fullwidth gw-image-direction" rows="3" placeholder="${Ghostwriter.escape(t('Anything it should show (optional)'))}"></textarea>
                            <div class="gw-image-source">
                                <label>${t('An image of your own to put in it, such as a product shot (optional)')}</label>
                                <input type="file" accept="image/png,image/jpeg,image/webp" class="gw-image-source-file">
                            </div>
                            <button type="button" class="btn submit gw-image-make">${t('Make the picture')}</button>
                            <div class="gw-image-status"></div>
                            <div class="gw-image-made"></div>
                        </div>
                    </div>
                </div>`);

            this.$modal = $modal;
            this.modal = new Garnish.Modal($modal, { hideOnEsc: true, hideOnShadeClick: true, resizable: false });

            Ghostwriter.prepareButtons($modal);

            this.addListener($modal.find('.gw-image-close'), 'click', () => this.modal.hide());
            this.addListener($modal.find('.gw-image-tabs .btn'), 'click', (event) => this.show($(event.currentTarget).data('mode')));
            this.addListener($modal.find('.gw-image-search'), 'click', 'find');
            this.addListener($modal.find('.gw-image-words'), 'keydown', (event) => event.key === 'Enter' && this.find());
            this.addListener($modal.find('.gw-image-make'), 'click', 'make');
            this.addListener($modal.find('.gw-image-source-select'), 'change', (event) => {
                const source = $(event.currentTarget).val();

                $modal.find('.gw-image-intro').text(this.intro(source));
                // Only where the source can return editorial images.
                $modal.find('.gw-image-filters').toggleClass('hidden', !Ghostwriter.editorialFor(this.config.sources, source));
                this.fit();
            });

            // The (i) beside "Include editorial images": Craft's own info icon,
            // focusable, and opened by a tap as well as a hover.
            Craft.initUiElements($modal);

            this.show(this.mode);
            this.current();
            this.describe();
        },

        /**
         * Say so up front when no other entry has an image in this place,
         * since there is then no style to match.
         */
        async describe() {
            try {
                const data = await Craft.sendActionRequest('GET', 'ghostwriter/images/slot', { params: this.target() });

                if (!data.data.references) {
                    this.$modal.find('.gw-image-pane').prepend(`<p class="light gw-image-unmatched">${Ghostwriter.escape(this.t('No other entry has an image here yet, so there is no style to match.'))}</p>`);
                    this.fit();
                }
            } catch (error) {}
        },

        show(mode) {
            this.mode = mode;
            this.$modal.find('.gw-image-tabs .btn').each((i, button) => $(button).toggleClass('active', $(button).data('mode') === mode).attr('aria-pressed', $(button).data('mode') === mode));
            this.$modal.find('.gw-image-pane').each((i, pane) => $(pane).toggleClass('hidden', $(pane).data('pane') !== mode));
            this.fit();
        },

        // The dialog is as tall as what it holds, up to the window.
        fit() {
            this.modal?.updateSizeAndPosition?.();
        },

        pane(mode) {
            return this.$modal.find(`[data-pane="${mode}"]`);
        },

        status($pane, html) {
            $pane.find('.gw-image-status').html(html);
            this.fit();
        },

        target() {
            return { fieldId: this.config.fieldId, elementId: this.config.elementId, siteId: this.config.siteId };
        },

        /**
         * "Search in": the free libraries, each paid library, or
         * everything. Only shown when there is a paid library to choose.
         */
        sourcePicker() {
            const sources = this.config.sources ?? [];

            if (!this.hasPaid()) {
                return '';
            }

            const options = sources.map((source) => `<option value="${Ghostwriter.escape(source.value)}"${source.value === this.config.source ? ' selected' : ''}${source.disabled ? ' disabled' : ''}>${Ghostwriter.escape(source.label)}</option>`).join('');

            return `<label class="gw-image-source-pick"><span class="light">${this.t('Search in:')}</span> <span class="select small"><select class="gw-image-source-select" aria-label="${Ghostwriter.escape(this.t('Search in'))}">${options}</select></span></label>`;
        },

        hasPaid() {
            return (this.config.sources ?? []).some((source) => source.value !== 'free');
        },

        /**
         * What the Find a photo tab does, for where it searches: free
         * libraries are picked to suit the page; a paid library's results
         * are in its own order.
         */
        intro(source) {
            const t = this.t;
            const paid = (this.config.sources ?? []).filter((option) => !['free', 'everything'].includes(option.value) && !option.disabled);
            const chosen = paid.find((option) => option.value === source);

            if (chosen) {
                return t('Searches {library} for this part of the page. Results are in {library}’s order.', { library: chosen.short ?? chosen.label });
            }

            if (source === 'everything') {
                return t('Searches the free libraries and {libraries} for this part of the page. Free photos are picked to suit the page; {libraries} results follow in their own order.', { libraries: paid.map((option) => option.short ?? option.label).join(', ') });
            }

            return t('Ghostwriter reads the block this field is in, and the rest of the page, then searches free photo libraries and picks the photos that best suit the page’s words and the images already used here.');
        },

        /**
         * At the top of the dialog, the preview the field holds now, with
         * what can be done about it.
         */
        current() {
            const $current = this.$modal.find('.gw-image-current');
            const items = this.stockItems ?? [];

            $current.toggleClass('hidden', !items.length).empty();

            if (items.length) {
                $current.append(`<h3 class="gw-image-current__heading">${this.t('In this field now')}</h3>`, $('<div/>'));
                Ghostwriter.Stock.render($current.children('div'), items, () => {
                    this.refreshBadges();
                });
            }
        },

        async find() {
            const t = this.t;
            const $pane = this.pane('find');
            const $search = $pane.find('.gw-image-search');
            const source = $pane.find('.gw-image-source-select').val() ?? 'free';
            // Hidden for a source with no editorial images: then it never applies.
            const editorial = !$pane.find('.gw-image-filters').hasClass('hidden') && $pane.find('.gw-image-editorial input').prop('checked') ? 1 : 0;

            $search.addClass('loading');
            $pane.find('.gw-image-grid').empty();
            this.config.source = source;

            try {
                const data = await Ghostwriter.request('POST', 'images/start', { ...this.target(), mode: 'find', words: $pane.find('.gw-image-words').val(), source, editorial });
                this.status($pane, `<div class="gw-empty"><div class="spinner"></div><p>${t('Reading the page and searching the photo libraries…')}</p></div>`);
                this.follow(data, $search);
            } catch (error) {
                $search.removeClass('loading');
            }
        },

        async make() {
            const t = this.t;
            const $pane = this.pane('make');
            const $make = $pane.find('.gw-image-make');
            const form = new FormData();
            const file = $pane.find('.gw-image-source-file')[0].files[0];

            Object.entries({ ...this.target(), mode: 'make', direction: $pane.find('.gw-image-direction').val() }).forEach(([key, value]) => form.append(key, value));

            if (file) {
                form.append('source', file);
            }

            $make.addClass('loading');
            $pane.find('.gw-image-made').empty();

            try {
                const data = await Ghostwriter.request('POST', 'images/start', form);
                this.status($pane, `<div class="gw-empty"><div class="spinner"></div><p>${t('Making the picture. This can take a minute or two.')}</p></div>`);
                this.follow(data, $make);
            } catch (error) {
                $make.removeClass('loading');
            }
        },

        /**
         * Poll a search or a picture being made until it is ready.
         */
        follow(data, $button) {
            clearTimeout(this.timer);
            this.request = data;

            if (data.status === 'working') {
                this.timer = setTimeout(async () => {
                    try {
                        this.follow(await Ghostwriter.request('GET', 'images/status', { id: data.id }), $button);
                    } catch (error) {
                        $button.removeClass('loading');
                    }
                }, 2500);

                return;
            }

            $button.removeClass('loading');

            const $pane = this.pane(data.mode);

            if (data.status === 'failed' || data.error) {
                this.status($pane, `<p class="error">${Ghostwriter.escape(data.error ?? this.t('That didn’t work'))}</p>`);
            } else {
                this.status($pane, '');
            }

            if (data.mode === 'find') {
                this.photos(data);
            } else if (data.preview) {
                this.made(data);
            }
        },

        photos(data) {
            const t = this.t;
            const $pane = this.pane('find');
            const $grid = $pane.find('.gw-image-grid').empty();
            const SHOWN = 3;

            $pane.find('.gw-image-note, .gw-image-searched, .gw-image-more').remove();

            if (data.terms?.length) {
                $pane.find('.gw-image-words').val(data.terms.slice(0, 3).join('; '));
                $grid.before(`<p class="light gw-image-searched">${Ghostwriter.escape(t('Searched for: {terms}', { terms: data.terms.join('; ') }))}</p>`);
            }

            // Say how these were chosen. "Best match" is only ever on photos
            // a model judged against the page.
            let note = null;

            const free = data.options.filter((photo) => !photo.paid);

            if (!free.length) {
                // Paid results alone: the line below says whose order they are in.
            } else if (data.noneFit) {
                note = t('None of these quite fit the page, even after searching again. Try other words.');
            } else if (!data.judged) {
                note = t('These were not compared with the page, so they are in search order.');
            } else if (!data.withReferences) {
                note = t('Compared with the page; there are no other images here to match.');
            }

            if (note) {
                $grid.before(`<p class="light gw-image-note">${Ghostwriter.escape(note)}</p>`);
            }

            // No model judges a paid library's photos: their terms forbid it.
            (data.paidLibraries ?? []).forEach((library) => {
                $grid.before(`<p class="light gw-image-note">${Ghostwriter.escape(t('Shown in {library}’s order. Ghostwriter doesn’t rank paid libraries.', { library }))}</p>`);
            });

            data.options.forEach((photo, i) => {
                const $card = $(`
                    <figure class="gw-photo${photo.picked ? ' gw-photo--picked' : ''}${photo.paid ? ' gw-photo--paid' : ''}${i >= SHOWN ? ' hidden' : ''}">
                        <div class="gw-photo__media">
                            <img src="${Ghostwriter.escape(photo.thumb)}" alt="${Ghostwriter.escape(photo.alt ?? '')}" loading="lazy"${photo.reason ? ` title="${Ghostwriter.escape(photo.reason)}"` : ''}>
                            <span class="gw-chip gw-chip--source">${Ghostwriter.escape(photo.source_label ?? photo.source)}</span>
                            <span class="gw-chip gw-chip--cost">${Ghostwriter.escape(photo.paid ? photo.cost : t('Free'))}</span>
                            ${photo.editorial ? `<span class="gw-chip gw-chip--editorial" tabindex="0" title="${Ghostwriter.escape(photo.restrictions ?? t('Editorial use only'))}">${t('Editorial')}</span>` : ''}
                        </div>
                        <figcaption>
                            ${photo.picked && data.judged ? `<span class="gw-photo__badge">${t('Best match')}</span>` : ''}
                            <span class="light">${/^https:\/\//.test(photo.credit_url ?? '') ? `<a href="${Ghostwriter.escape(photo.credit_url).replace(/"/g, '&quot;')}" target="_blank" rel="noopener noreferrer">${Ghostwriter.escape(photo.credit)}</a>` : Ghostwriter.escape(photo.credit)} · ${Ghostwriter.escape(photo.licence)}</span>
                            <button type="button" class="btn small submit">${photo.paid ? t('Insert preview') : t('Use this')}</button>
                        </figcaption>
                    </figure>`);

                // A thumbnail that will not load is no use to choose from.
                $card.find('img').on('error', () => $card.remove());

                $grid.append($card);
                Ghostwriter.prepareButtons($card);

                const choose = () => this.use($card.find('.btn'), { source: photo.source, photo: photo.id, term: photo.term });

                // The picture itself can be clicked, as well as "Use this".
                this.addListener($card.find('.btn'), 'click', choose);
                this.addListener($card.find('img'), 'click', choose);
            });

            // The best three first; the rest on request.
            const more = data.options.length - SHOWN;

            if (more > 0) {
                const $more = $(`<button type="button" class="btn small gw-image-more" aria-expanded="false">${Ghostwriter.escape(t('View {count} more', { count: more }))}</button>`);

                $grid.after($more);
                this.addListener($more, 'click', () => {
                    const open = $more.attr('aria-expanded') !== 'true';

                    $grid.children('.gw-photo').slice(SHOWN).toggleClass('hidden', !open);
                    $more.attr('aria-expanded', open ? 'true' : 'false').text(open ? t('Show the best three') : t('View {count} more', { count: more }));
                    this.fit();
                });
            }

            this.fit();
        },

        made(data) {
            const t = this.t;
            const $made = this.pane('make').find('.gw-image-made').html(`
                <figure class="gw-photo gw-photo--made">
                    <img src="${Ghostwriter.escape(data.preview)}" alt="">
                    <figcaption>
                        <button type="button" class="btn submit gw-made-use">${t('Use this')}</button>
                        <button type="button" class="btn gw-made-again">${t('Make another')}</button>
                    </figcaption>
                </figure>`);

            Ghostwriter.prepareButtons($made);
            $made.find('img').on('load', () => this.fit());
            this.addListener($made.find('.gw-made-use'), 'click', (event) => this.use($(event.currentTarget), {}));
            this.addListener($made.find('.gw-made-again'), 'click', 'make');
        },

        async use($button, extra) {
            if (this.using) return;

            this.using = true;
            $button.addClass('loading');
            this.$modal.find('.gw-photo .btn').addClass('disabled');

            try {
                await this.place(await Ghostwriter.request('POST', 'images/use', { id: this.request.id, ...extra }));
            } catch (error) {
            } finally {
                this.using = false;
                $button.removeClass('loading');
                this.$modal.find('.gw-photo .btn').removeClass('disabled');
            }
        },

        /**
         * Put a new asset into the field, as Craft does after an upload:
         * any placeholder comes out first, and a one-image field gives up
         * the image it had.
         */
        async place(result) {
            const t = this.t;
            const select = this.$select.data('elementSelect');

            if (!select) {
                Craft.cp.displayNotice(t('Image saved to Assets. Add it to the field with “Add an asset”.'));
                this.modal.hide();

                return;
            }

            const chips = () => select.getElements ? select.getElements() : select.$elements;

            chips().each((i, element) => {
                if ((result.placeholderIds ?? []).includes(parseInt($(element).data('id'), 10))) {
                    select.removeElement($(element));
                }
            });

            const limit = select.settings.limit;

            if (limit && chips().length >= limit) {
                if (limit === 1) {
                    select.removeElement(chips().first());
                } else {
                    Craft.cp.displayError(t('This field is full. Image saved to Assets; remove an image from the field to make room.'));

                    return;
                }
            }

            const viewMode = select.settings.viewMode;
            const { data } = await Craft.sendActionRequest('POST', 'app/render-elements', {
                data: {
                    elements: [{
                        type: 'craft\\elements\\Asset',
                        id: result.assetId,
                        siteId: select.settings.criteria?.siteId ?? this.config.siteId,
                        instances: [{
                            context: 'field',
                            ui: ['list', 'list-inline', 'large', 'thumbs'].includes(viewMode) ? 'chip' : 'card',
                            size: ['large', 'thumbs'].includes(viewMode) ? 'large' : 'small',
                            showActionMenu: select.settings.showActionMenu,
                        }],
                    }],
                },
            });

            select.selectElements([Craft.getElementInfo(data.elements[result.assetId][0])]);
            await Craft.appendHeadHtml(data.headHtml);
            await Craft.appendBodyHtml(data.bodyHtml);
            select.$container.trigger('change');

            Craft.cp.displaySuccess(result.preview
                ? t('Preview added. Only signed-in editors see the photo; license it before publishing.')
                : t('Image added. Save to keep it.'));
            this.modal.hide();
            this.$holder.trigger('ghostwriter:stock-changed');
        },
    });

    /**
     * Image fields come and go as blocks are added, so look for new buttons
     * whenever the page changes.
     */
    Ghostwriter.initImageButtons = function (root) {
        $(root).find('[data-ghostwriter-image]').addBack('[data-ghostwriter-image]').each((i, holder) => {
            if (!holder.dataset.ghostwriterReady) {
                holder.dataset.ghostwriterReady = '1';
                // Kept on the holder, so "Finish this page" can open it for its field.
                $(holder).data('gwImageButton', new Ghostwriter.ImageButton(holder));
            }
        });
    };

    $(() => {
        Ghostwriter.initImageButtons(document.body);
        Ghostwriter.initStockPanels(document.body);

        new MutationObserver((changes) => changes.forEach((change) => change.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
                Ghostwriter.initImageButtons(node);
                Ghostwriter.initStockPanels(node);
            }
        }))).observe(document.body, { childList: true, subtree: true });
    });
})();
