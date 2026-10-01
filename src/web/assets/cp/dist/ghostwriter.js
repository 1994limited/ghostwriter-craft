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
            const scanOff = !this.config.configured || working;
            const sendOff = !this.config.configured || working || dirty;

            this.$scan.toggleClass('loading', working && state.task === 'scan').toggleClass('disabled', scanOff).prop('disabled', scanOff);
            this.$send.toggleClass('loading', working && state.task === 'refine').toggleClass('disabled', sendOff).prop('disabled', sendOff);
            this.$message.prop('disabled', working);

            if (state.exists) {
                Ghostwriter.prepareButtons(this.$scan);
                this.$scan.removeClass('submit').find('.label').text(this.config.labels.rescan);
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
 *   brief    the questionnaire, with a quick brief that fills it in
 *   write    the conversation, with the draft beside it
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

    // Notes from a draft just put into the form, shown once the form has reloaded.
    $(() => {
        try {
            const notes = JSON.parse(sessionStorage.getItem(NOTICE) ?? 'null');

            if (notes) {
                sessionStorage.removeItem(NOTICE);
                Craft.cp.displaySuccess(notes.message);
                notes.notes.forEach((note) => Craft.cp.displayNotice(note));
            }
        } catch (error) {}
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
                sessionStorage.setItem(NOTICE, JSON.stringify({ message: this.config.editing ? t('Changes added to the form. Check them over, then save.') : t('Draft added to the form. Check it over, then save.'), notes: data.notes ?? [] }));
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

    Ghostwriter.Panel = Garnish.Base.extend({
        init($container, config, options) {
            this.$container = $container;
            this.config = config;
            this.options = options;

            this.info = null;
            this.type = null;
            this.examples = [];
            this.answers = {};
            this.errors = {};
            this.quick = { title: '', notes: '' };
            this.guessed = false;
            this.guessing = false;
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
            // How the draft is shown: its blocks, or just its words.
            try {
                this.view = localStorage.getItem('ghostwriter:view') === 'text' ? 'text' : 'blocks';
            } catch (error) {
                this.view = 'blocks';
            }

            this.addListener(this.$container, 'click', 'onClick');
            this.addListener(this.$container, 'input', 'onInput');
            this.addListener(this.$container, 'change', 'onInput');
            this.addListener(this.$container, 'keydown', 'onKeydown');
            this.addListener(this.$container, 'focusin', 'onFocusIn');
            this.addListener(this.$container, 'focusout', 'onFocusOut');

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

            this.session = data;
            this.config.current = data.id;
            Ghostwriter.address({ ghostwriter: data.id, idea: null });

            if (changed) {
                this.raw = data.draft ?? '';
                this.editing = false;
            }

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

            this.$container.find('.gw-chat-log').each((i, log) => (log.scrollTop = log.scrollHeight));

            // Questions waiting: put the cursor where the answer goes.
            if (this.asking()) {
                this.$container.find('[data-model="message"]').trigger('focus');
            }
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

            const since = Date.parse(this.session.messages.at(-1)?.at ?? '') || Date.now();
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
            if (!this.info) return 'loading';
            if (this.session) return 'write';
            if (this.adding || this.learning()) return 'setup';
            if (!this.type) return 'type';

            return 'brief';
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

            if (this.waited < 8) return drafted ? t('Reading your message…') : t('Reading the brief…');
            if (this.waited < 30) return drafted ? t('Revising the draft…') : t('Thinking it through…');

            return drafted ? t('Still revising. Long drafts take a while…') : t('Writing. Long drafts take a while…');
        },

        // `examples` preselects the entries to model this piece on: a kind's
        // members, or whatever the type itself was taught from.
        choose(type, examples = null) {
            this.type = type;
            this.examples = (examples ?? type.examples ?? []).map(Number);
            this.answers = Object.fromEntries(type.questions.map((question) => [question.handle, '']));
            this.guessed = false;
            this.planned = null;
            this.quick = { title: '', notes: '' };
            this.errors = {};
            this.render();
        },

        // An idea from the content plan: pick its kind of content, put its
        // title and notes in the quick brief, and fill the brief in.
        fromIdea(idea) {
            const types = this.info.types;

            this.choose(types.find((type) => type.handle === idea.type) ?? types.find((type) => type.generic));
            this.planned = idea.id;
            this.quick = { title: idea.title, notes: [idea.why, idea.notes].filter(Boolean).join('\n\n') };
            this.render();

            if (this.info.configured) this.guess();
        },

        // ---- Actions ------------------------------------------------------

        onInput(event) {
            const $el = $(event.target);
            const model = $el.data('model');

            if (!model) return;

            const value = event.target.type === 'checkbox' ? event.target.checked : $el.val();

            if (model === 'answer') this.answers[$el.data('handle')] = value;
            else if (model === 'quick-title') this.quick.title = value;
            else if (model === 'quick-notes') this.quick.notes = value;
            else if (model === 'message') this.message = value;
            else if (model === 'raw') this.raw = value;
            else if (model === 'learn-title') this.learn.title = value;
            else if (model === 'example' || model === 'learn-example') {
                const list = model === 'example' ? this.examples : this.learn.picked;
                const id = Number($el.val());
                const next = event.target.checked ? [...list, id].slice(0, MAX_EXAMPLES) : list.filter((picked) => picked !== id);

                if (model === 'example') this.examples = next;
                else this.learn.picked = next;

                this.renderPicked($el.closest('.gw-picker'), next);
            } else if (model === 'filter') {
                const term = String(value).trim().toLowerCase();
                $el.closest('.gw-picker').find('[data-entry]').each((i, row) => $(row).toggleClass('hidden', !!term && !row.dataset.title.includes(term)));
            }

            if (model === 'quick-title') {
                this.$container.find('[data-action="guess"]').prop('disabled', !this.info.configured || this.guessing || !this.quick.title.trim()).toggleClass('disabled', !this.quick.title.trim());
            }

            if (model === 'message') {
                this.$container.find('[data-action="send"]').prop('disabled', this.working() || !this.message.trim()).toggleClass('disabled', this.working() || !this.message.trim());
            }
        },

        onKeydown(event) {
            const model = $(event.target).data('model');

            if (model === 'message' && event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                this.send();
            }

            if (model === 'quick-title' && event.key === 'Enter') {
                event.preventDefault();
                if (this.quick.title.trim()) this.guess();
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
                case 'teach': this.adding = true; return this.render();
                case 'cancel-teach': this.adding = false; return this.render();
                case 'learn': return this.startLearning();
                case 'choose': return this.choose(types.find((type) => type.handle === $target.data('type')));
                case 'choose-kind': return this.choose(types.find((type) => type.generic), this.info.kinds[$target.data('kind')].examples);
                case 'choose-general': return this.choose(types.find((type) => type.generic), []);
                case 'idea': return this.fromIdea(this.info.ideas.find((idea) => idea.id === $target.data('idea')));
                case 'change-type': this.type = null; return this.render();
                case 'guess': return this.guess();
                case 'start': return this.startWriting();
                case 'resume': return this.openSession($target.data('id'));
                case 'toggle-brief': this.showBrief = !this.showBrief; return this.renderConversation();
                case 'send': return this.send();
                case 'skip': this.message = t('Please draft it with what you have. Put anything you are unsure of in square brackets.'); return this.send();
                case 'reload-entry': return this.reloadEntry();
                case 'edit': this.editing = true; return this.renderDraft();
                case 'view': this.view = $target.data('view'); try { localStorage.setItem('ghostwriter:view', this.view); } catch (error) {} return this.renderDraft();
                case 'cancel-edit': this.editing = false; this.raw = this.session.draft; return this.renderDraft();
                case 'save-draft': return this.saveDraft();
                case 'apply': return this.apply();
                case 'start-over': return this.startOver();
            }
        },

        async startLearning() {
            try {
                this.info = await Ghostwriter.request('POST', 'sections/analyse', { section: this.config.section, title: this.learn.title.trim(), examples: this.learn.picked });
                this.render();
                this.later(() => this.load());
            } catch (error) {}
        },

        // Title and notes in, a filled-in questionnaire out, for checking.
        async guess() {
            this.guessing = true;
            this.render();

            try {
                const data = await Ghostwriter.request('POST', 'sessions/brief', { type: this.type.handle, title: this.quick.title, notes: this.quick.notes });

                this.answers = { ...this.answers, ...data.answers };
                this.guessed = true;
                this.errors = {};
            } catch (error) {}

            this.guessing = false;
            this.render();
        },

        async startWriting() {
            this.busy = true;
            this.errors = {};
            this.render();

            try {
                const data = await Craft.sendActionRequest('POST', 'ghostwriter/sessions/start', {
                    data: { type: this.type.handle, answers: this.answers, examples: this.examples, elementId: this.config.elementId, siteId: this.config.siteId, idea: this.planned },
                });

                Craft.cp.runQueue?.();
                this.busy = false;
                this.receive(data.data);
            } catch (error) {
                this.errors = error?.response?.data?.errors ?? {};
                Craft.cp.displayError(error?.response?.data?.message ?? t('Something went wrong.'));
                this.busy = false;
                this.render();
            }
        },

        async send() {
            const message = this.message.trim();

            if (!message || this.working()) return;

            this.message = '';
            this.$container.find('[data-model="message"]').val('');

            try {
                this.receive(await Ghostwriter.request('POST', 'sessions/message', { id: this.session.id, message }));
            } catch (error) {
                this.message = message;
                this.renderComposer();
            }
        },

        // Writing edited where it is shown: remembered on the way in, saved
        // on the way out if it changed.
        onFocusIn(event) {
            const $field = $(event.target).closest('[data-edit-path]');

            if ($field.length) $field.data('was', this.fieldValue($field));
        },

        async onFocusOut(event) {
            const $field = $(event.target).closest('[data-edit-path]');

            if (!$field.length) return;

            const value = this.fieldValue($field);

            if (value === $field.data('was')) return;

            $field.addClass('is-saving');

            try {
                const data = await Ghostwriter.request('POST', 'sessions/edit-field', {
                    id: this.session.id,
                    path: $field.attr('data-edit-path'),
                    format: $field.data('format'),
                    value,
                });

                this.session = data;
                this.raw = data.draft ?? '';

                // Redrawn only when nothing else is being typed in.
                if (!this.$container.find('[data-edit-path]:focus').length) {
                    const scroll = this.$container.find('.gw-draft__body').scrollTop();
                    this.renderDraft();
                    this.$container.find('.gw-draft__body').scrollTop(scroll);
                }

                this.$container.find('.gw-draft__toolbar [data-words]').text(t('{count} words', { count: data.words.toLocaleString() }));
            } catch (error) {
                $field.removeClass('is-saving');
            }
        },

        fieldValue($field) {
            return $field.data('format') === 'html' ? $field.html() : $field[0].innerText.replace(/\n$/, '');
        },

        // A piece of writing that can be changed in place.
        editable(node, extra = '') {
            const off = this.working() || this.editing;
            const path = esc(JSON.stringify(node.path));

            if (node.kind === 'html') {
                return `<div class="gw-prose gw-editable ${extra}" ${off ? '' : 'contenteditable="true"'} data-edit-path="${path}" data-format="html" aria-label="${esc(node.label)}">${node.html}</div>`;
            }

            return `<div class="gw-editable gw-pre ${extra}" ${off ? '' : 'contenteditable="plaintext-only"'} data-edit-path="${path}" data-format="text" aria-label="${esc(node.label)}">${esc(node.text)}</div>`;
        },

        // The draft as a page to read: only its words, in order, each
        // editable, with a quiet note of where it sits.
        textView(nodes) {
            const out = [];

            const walk = (list, where) => list.forEach((node) => {
                if (node.editable) {
                    out.push(node.handle === 'title' && !where
                        ? this.editable(node, 'gw-text-title')
                        : `<div class="gw-text-part">${where ? `<div class="gw-text-where">${esc(where)}</div>` : ''}${this.editable(node)}</div>`);
                } else if (node.kind === 'blocks') {
                    node.items.forEach((block) => walk(block.fields, block.label));
                } else if (node.kind === 'rows') {
                    node.items.forEach((row) => walk(row, node.label));
                } else if (node.kind === 'group') {
                    walk(node.fields, node.label);
                }
            });

            walk(nodes, '');

            return out.length ? `<article class="gw-text-view">${out.join('')}</article>` : `<p class="light">${esc(t('There is no writing in this draft yet.'))}</p>`;
        },

        async saveDraft() {
            try {
                const data = await Ghostwriter.request('POST', 'sessions/draft', { id: this.session.id, draft: this.raw });

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
            this.config.current = null;
            Ghostwriter.address({ ghostwriter: 'new', idea: null });
            this.type = this.nothingToChoose() ? this.type : null;
            this.render();
        },

        // ---- Rendering ----------------------------------------------------

        render() {
            const step = this.step();

            const header = `
                <header class="gw-panel__header">
                    <h1 class="gw-panel__title"><span class="gw-icon" aria-hidden="true">${this.config.icon ?? ''}</span>${esc(t('Ghostwriter'))}</h1>
                    <button type="button" class="btn" data-action="close">${esc(t('Close'))}</button>
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
                        <button type="button" class="btn small" data-action="teach">${esc(t('Teach it a kind'))}</button>
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
                        <span>${esc(item.title)}</span><span class="light">${esc(item.type)} · ${esc(item.updatedAt)}</span>
                    </button></li>`).join('')}
                </ul>`;
        },

        render_brief() {
            const type = this.type;

            return `
                <div class="gw-wide">
                    <div class="flex flex-justify gw-row">
                        <div>
                            <h2>${esc(type.title)}</h2>
                            <p class="light">${esc(type.description)}</p>
                        </div>
                        ${this.nothingToChoose() ? '' : `<button type="button" class="btn small" data-action="change-type">${esc(t('Change'))}</button>`}
                    </div>

                    <div class="gw-box">
                        <h3>${esc(t('Quick brief'))}</h3>
                        <p class="light">${esc(t('Give it a title and anything you already know. Ghostwriter fills in the questions below with its best guess, for you to check and change.'))}</p>
                        <input class="text fullwidth" data-model="quick-title" placeholder="${esc(t('Working title'))}" value="${esc(this.quick.title)}" ${this.guessing ? 'disabled' : ''}>
                        <textarea class="text fullwidth" rows="3" data-model="quick-notes" placeholder="${esc(t('Notes: the angle, who it is for, points to make, projects to mention…'))}" ${this.guessing ? 'disabled' : ''}>${esc(this.quick.notes)}</textarea>
                        <div class="flex flex-justify gw-row">
                            <span class="light ${this.guessing ? 'gw-busy-note' : ''}" role="status">${this.guessing
                                ? `<span class="spinner small"></span> ${esc(t('Filling in the brief from your title and notes. This usually takes under a minute.'))}`
                                : esc(this.guessed ? t('Filled in below. Anything in [square brackets] needs you.') : t('Optional. You can also just answer the questions.'))}</span>
                            <button type="button" class="btn ${this.guessing ? 'loading' : ''} ${!this.quick.title.trim() ? 'disabled' : ''}" data-action="guess" ${!this.info.configured || this.guessing || !this.quick.title.trim() ? 'disabled' : ''}>${esc(this.guessed ? t('Guess again') : t('Fill in the brief'))}</button>
                        </div>
                    </div>

                    ${type.questions.map((question) => `
                        <div class="field ${this.errors[question.handle] ? 'has-errors' : ''}">
                            <div class="heading"><label class="${question.required ? 'required' : ''}" for="gw-q-${esc(question.handle)}">${esc(question.label)}</label></div>
                            ${question.instructions ? `<div class="instructions"><p>${esc(question.instructions)}</p></div>` : ''}
                            <div class="input">
                                <textarea id="gw-q-${esc(question.handle)}" class="text fullwidth" rows="${this.rowsFor(question)}" data-model="answer" data-handle="${esc(question.handle)}">${esc(this.answers[question.handle] ?? '')}</textarea>
                            </div>
                            ${this.errors[question.handle] ? `<ul class="errors"><li>${esc(this.errors[question.handle])}</li></ul>` : ''}
                        </div>`).join('')}

                    ${this.info.entries.length ? `
                        <div class="field">
                            <div class="heading"><label>${esc(t('Model it on'))}</label></div>
                            <div class="instructions"><p>${esc(t('Optional. Tick up to six entries and the draft follows how they are built. With none ticked, Ghostwriter goes by the brief and how this section is usually written.'))}</p></div>
                            ${this.picker('example', this.examples)}
                        </div>` : ''}

                    <div class="flex flex-justify gw-row gw-actions">
                        <span class="light">${esc(this.busy ? t('Starting…') : t('Short answers are fine. Ghostwriter asks for anything it still needs before it writes.'))}</span>
                        <button type="button" class="btn submit ${this.busy ? 'loading' : ''}" data-action="start" ${!this.info.configured || this.busy ? 'disabled' : ''}>${esc(t('Start writing'))}</button>
                    </div>

                    ${this.nothingToChoose() ? this.carryOn() : ''}
                </div>`;
        },

        // Tall enough to show the whole answer, however it got there.
        rowsFor(question) {
            const lines = String(this.answers[question.handle] ?? '').split('\n').reduce((total, line) => total + Math.max(1, Math.ceil(line.length / 85)), 0);

            return Math.min(Math.max(lines + 1, question.type === 'text' ? 1 : 3), 16);
        },

        picker(model, picked) {
            const entries = this.info.entries;

            return `
                <div class="gw-picker">
                    ${entries.length > 8 ? `<input class="text fullwidth gw-picker__filter" data-model="filter" placeholder="${esc(t('Filter…'))}">` : ''}
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

        render_write() {
            return `
                <div class="gw-write">
                    <section class="gw-convo" aria-label="${esc(t('Conversation'))}">
                        <div class="gw-chat-log"></div>
                        <div class="gw-composer"></div>
                    </section>
                    <section class="gw-draft" aria-label="${esc(t('Draft'))}"></section>
                </div>`;
        },

        renderConversation() {
            const session = this.session;
            const conversation = session.messages.slice(1);
            const asking = this.asking();

            // Editing an entry has no brief to show: the entry is the brief.
            let html = session.editing ? '' : `
                <div class="gw-brief">
                    <button type="button" class="gw-link" data-action="toggle-brief">${esc(this.showBrief ? t('Hide the brief') : t('Show the brief'))}</button>
                    ${this.showBrief ? `<div class="gw-pre">${esc(session.messages[0]?.content ?? '')}</div>` : ''}
                </div>`;

            conversation.forEach((entry, index) => {
                const mine = entry.role === 'user';
                const waiting = !mine && asking && index === conversation.length - 1;

                html += `
                    <div class="gw-bubble gw-bubble--${mine ? 'me' : 'them'} ${waiting ? 'gw-bubble--asking' : ''}">
                        <div class="gw-bubble__who">${esc(mine ? t('You') : t('Ghostwriter'))}${waiting ? ` · <span class="gw-bubble__flag">${esc(t('needs your answer'))}</span>` : ''}</div>
                        <div class="gw-bubble__text gw-pre">${esc(entry.content)}</div>
                        ${entry.draft ? `<div class="gw-bubble__draft">✓ ${esc(this.draftNote(entry.draft))}</div>` : ''}
                    </div>`;
            });

            if (this.working()) {
                html += `<div class="gw-bubble gw-bubble--them gw-working" role="status"><div class="spinner small"></div><span data-progress>${esc(this.progress())}</span><span class="gw-elapsed" data-elapsed></span></div>`;
            }

            if (session.status === 'failed') {
                html += `<p class="error with-icon"><strong>${esc(t('That did not work.'))}</strong> ${esc(session.error)}</p>`;
            }

            this.$container.find('.gw-chat-log').html(html);
            Ghostwriter.prepareButtons(this.$container.find('.gw-chat-log'));
            this.tick();
        },

        renderComposer() {
            const asking = this.asking();
            const working = this.working();

            this.$container.find('.gw-composer').toggleClass('gw-composer--asking', asking).html(`
                ${asking ? `<p class="gw-composer__flag"><span class="gw-dot" aria-hidden="true"></span>${esc(this.session.draft ? t('Your turn: answer above to carry on.') : t('Your turn: answer the questions above and the draft follows.'))}</p>` : ''}
                <textarea class="text fullwidth" rows="4" data-model="message" ${working ? 'disabled' : ''} placeholder="${esc(asking ? t('Type your answers here. Short is fine; number them if it helps.') : this.session.draft ? t('Ask for a change…') : t('Answer the questions…'))}">${esc(this.message)}</textarea>
                <div class="gw-composer__actions">
                    ${this.session.editing
                        ? `<button type="button" class="btn small gw-quiet" data-action="reload-entry" title="${esc(t('Throw away the changes asked for here and start again from the entry as it stands.'))}">${esc(t('Start again from the entry'))}</button>`
                        : `<button type="button" class="btn small gw-quiet" data-action="start-over">${esc(t('Start over'))}</button>`}
                    <span class="light smalltext">${esc(t('⌘↵ to send'))}</span>
                    <button type="button" class="btn submit ${working ? 'loading' : ''} ${working || !this.message.trim() ? 'disabled' : ''}" data-action="send" ${working || !this.message.trim() ? 'disabled' : ''}>${esc(working ? t('Working…') : t('Send'))}</button>
                </div>`);

            Ghostwriter.prepareButtons(this.$container.find('.gw-composer'));
        },

        renderDraft() {
            const session = this.session;
            const working = this.working();
            let toolbar = `<span class="light" data-words>${esc(session.draft ? t('{count} words', { count: session.words.toLocaleString() }) : t('Draft'))}</span>`;

            if (session.draft && !this.editing && !session.draftProblem) {
                toolbar += `<div class="btngroup gw-view-switch" role="group" aria-label="${esc(t('Show the draft as'))}">
                    <button type="button" class="btn small ${this.view === 'blocks' ? 'active' : ''}" data-action="view" data-view="blocks" aria-pressed="${this.view === 'blocks'}">${esc(t('Blocks'))}</button>
                    <button type="button" class="btn small ${this.view === 'text' ? 'active' : ''}" data-action="view" data-view="text" aria-pressed="${this.view === 'text'}">${esc(t('Text'))}</button>
                </div>`;
            }
            let body = '';

            if (session.draft) {
                toolbar += this.editing
                    ? `<div class="flex"><button type="button" class="btn small" data-action="cancel-edit">${esc(t('Cancel'))}</button><button type="button" class="btn small submit" data-action="save-draft">${esc(t('Save changes'))}</button></div>`
                    : `<div class="flex">
                           <button type="button" class="btn small" data-action="edit" ${working ? 'disabled' : ''} title="${esc(t('Change the structure: add, move or remove blocks'))}">${esc(t('Edit YAML'))}</button>
                           <button type="button" class="btn small submit ${this.busy ? 'loading' : ''} ${working || session.draftProblem ? 'disabled' : ''}" data-action="apply" ${working || session.draftProblem || this.busy ? 'disabled' : ''}>${esc(session.editing ? t('Use these changes') : t('Use this draft'))}</button>
                       </div>`;
            }

            if (!session.draft) {
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
                        : (this.view === 'text' ? this.textView(session.preview) : this.preview(session.preview)));

                if (!this.editing && !session.draftProblem && !working) {
                    body = `<p class="light gw-edit-hint">${esc(t('Click any text to change it. Changes are saved as you leave each piece.'))}</p>` + body;
                }
            }

            if (session.appliedAt && !this.editing) {
                body = `<p class="light gw-applied">${esc(t('This draft has been put into the entry. Using it again replaces what is in the form.'))}</p>` + body;
            }

            this.$container.find('.gw-draft').html(`<div class="gw-draft__toolbar">${toolbar}</div><div class="gw-draft__body">${body}</div>`);

            Ghostwriter.prepareButtons(this.$container.find('.gw-draft'));
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
                    ${node.editable ? this.editable(node) : this.previewValue(node)}
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

            // Sections queued by the automatic check: start the queue now
            // rather than waiting for another page to.
            // Craft.cp is only there once the page is ready.
            $(() => {
                if (config.checking?.length || $('[data-busy="1"]').length) {
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
        },

        working() {
            return this.plan.status === 'working';
        },

        apply(data) {
            const finished = this.working() && data.status === 'idle';

            this.plan = data;

            // Fresh suggestions: everything ticked to start with.
            if (data.pending.length && !this.chosen.length) this.chosen = data.pending.map((idea, i) => i);
            if (!data.pending.length) this.chosen = [];

            this.render();

            if (data.pending.length) this.review();
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
                ? t('Remove all {count} ideas from the list? Started and dismissed ones stay. This cannot be undone.', { count })
                : t('Delete all {count} dismissed ideas? Ghostwriter will no longer know not to suggest them again.', { count });

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

            if (this.plan.status === 'failed') {
                html += `<p class="error with-icon gw-alert"><strong>${esc(t('That did not work.'))}</strong> ${esc(this.plan.error)}</p>`;
            }

            if (started.length) {
                html += `<section class="gw-section"><h2>${esc(t('In progress'))}</h2><p class="light">${esc(t('Started, and not yet saved as an entry.'))}</p>
                    <div class="gw-plan-list">${started.map((idea) => `
                        <div class="gw-plan-item">
                            <div class="gw-plan-item__text"><strong>${esc(idea.title)}</strong><span class="light">${esc(idea.sectionTitle)} · ${esc(t(STAGES[idea.stage] ?? 'Started'))}</span></div>
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
                                <button type="button" class="btn small" data-plan="reopen" data-id="${esc(idea.id)}">${esc(t('Put back'))}</button>
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
        // without deciding drops them all.
        review() {
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
                        <button type="button" class="btn submit" data-review="keep">${esc(t('Add the ticked ones'))}</button>
                    </div></div>
                </div>`);

            Ghostwriter.prepareButtons($body);

            if (this.modal) {
                this.modal.$container.empty().append($body);
                this.modal.show();
            } else {
                const $container = $('<div class="modal gw-review-modal"/>').append($body).appendTo(Garnish.$bod);
                this.modal = new Garnish.Modal($container, { hideOnShadeClick: false, onHide: () => this.decided || this.decide(true) });
            }

            this.decided = false;
            this.modal.$container.find('input[type=checkbox]').on('change', (event) => {
                const i = Number(event.target.value);
                this.chosen = event.target.checked ? [...this.chosen, i] : this.chosen.filter((n) => n !== i);
            });
            this.modal.$container.find('[data-review]').on('click', (event) => this.decide(event.currentTarget.dataset.review === 'drop'));
        },

        async decide(discard) {
            if (this.decided) return;

            this.decided = true;

            const chosen = discard ? [] : this.chosen;
            this.modal.hide();

            if (await this.send('plan/accept', { chosen, discard: discard ? 1 : 0 }) && !discard) {
                Craft.cp.displaySuccess(t('{count, plural, =1{# idea} other{# ideas}} added to the plan.', { count: chosen.length }));
            }
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
            this.render();
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
                case 'learn-all': return this.post('sections/learn-all-kinds', { section: $target.data('section') }, $target);
                case 'dismiss-kind': return this.post('sections/dismiss-kind', { section: $target.data('section'), id: $target.data('id') }, $target);
                case 'imagery': return this.post('imagery/scan', { sections: d.sections.filter((s) => s.writeFor).map((s) => s.handle) }, $target);
                case 'plan': return this.post('plan/suggest', {}, $target);
                case 'hide': return this.post('setup/hide', { hidden: 1 }, $target).then(() => (window.location.href = Craft.getCpUrl('ghostwriter')));
            }
        },

        render() {
            const steps = this.state.steps;
            const step = steps[this.current];
            const last = this.current === steps.length - 1;
            const working = steps.some((candidate) => candidate.working);

            const done = steps.filter((candidate) => candidate.done).length;
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
                    <p class="gw-wizard__progress">${esc(t('{done} of {total} done', { done, total: steps.length }))}</p>
                    <div class="gw-wizard__meter" role="progressbar" aria-valuemin="0" aria-valuemax="${steps.length}" aria-valuenow="${done}"><span style="width: ${Math.round((done / steps.length) * 100)}%"></span></div>
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
                            ? `<button type="button" class="btn" data-wizard="hide">${esc(t('Finish and hide this'))}</button>`
                            : `<button type="button" class="btn ${step.done || step.optional ? 'submit' : ''}" data-wizard="next">${esc(step.done ? t('Next') : step.optional ? t('Skip') : t('Next'))}</button>`}
                    </div>
                </footer>
                </div>`);

            Ghostwriter.prepareButtons(this.$root);

            if (working) this.poll();
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
                <button type="button" class="btn ${v.exists ? '' : 'submit'}" data-wizard="voice" ${this.state.configured ? '' : 'disabled'}>${esc(v.exists ? t('Read the site again') : t('Write the voice guide'))}</button>`;
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
                                ${k.suggestions.length > 1 ? `<button type="button" class="btn small" data-wizard="learn-all" data-section="${esc(k.handle)}" ${k.learning.status === 'working' ? 'disabled' : ''}>${esc(t('Learn all {count}', { count: k.suggestions.length }))}</button>` : ''}
                                <button type="button" class="btn small" data-wizard="suggest-kinds" data-section="${esc(k.handle)}" ${k.state === 'working' || !this.state.configured ? 'disabled' : ''}>${esc(t('Suggest kinds'))}</button>
                            </div>
                        </div>
                        ${k.state === 'working' ? this.working(t('Looking at the entries.')) : ''}
                        ${k.learning.status === 'working' ? this.working(t('Learning. About a minute each.')) : ''}
                        ${this.failed(k.state === 'failed' ? k.error : null)}${this.failed(k.learning.status === 'failed' ? k.learning.error : null)}
                        ${k.types.length ? `<p class="gw-wizard__ok">✓ ${esc(t('Learned'))}: ${k.types.map((type) => `<a href="${esc(type.url)}">${esc(type.title)}</a>`).join(', ')}</p>` : ''}
                        ${k.suggestions.map((s) => `
                            <div class="gw-type gw-type--suggested">
                                <span><strong>${esc(s.title)}</strong> <span class="light">${esc(s.description)}</span>${s.why ? `<span class="light smalltext gw-why">${esc(s.why)}</span>` : ''}</span>
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
                ${p.pending ? `<p class="gw-wizard__ok">${esc(t('{count} ideas are waiting for you to look over.', { count: p.pending }))} <a class="btn small submit" href="${esc(p.url)}">${esc(t('Look over them'))}</a></p>` : ''}
                ${p.ideas ? `<p class="gw-wizard__ok">✓ ${esc(t('{count} ideas on the plan.', { count: p.ideas }))} <a href="${esc(p.url)}">${esc(t('Open the content plan'))}</a></p>` : ''}
                ${this.keyless()}
                ${p.pending ? '' : `<button type="button" class="btn ${p.ideas ? '' : 'submit'}" data-wizard="plan" ${this.state.configured ? '' : 'disabled'}>${esc(t('Suggest ideas'))}</button>`}`;
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
     * The Ghostwriter button on an image field, beside "Add an asset" and
     * "Upload a file". It finds a photograph, has a picture made, or sets a
     * logo on a ground, and puts the one chosen into the field the way an
     * upload would, through the field's own input.
     */
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
            this.mode = this.config.canFind ? 'find' : (this.config.canMake ? 'make' : 'logo');

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
                        <div class="btngroup gw-image-tabs" role="tablist">
                            ${this.config.canFind ? `<button type="button" class="btn" data-mode="find">${t('Find a photo')}</button>` : ''}
                            ${this.config.canMake ? `<button type="button" class="btn" data-mode="make">${t('Make one')}</button>` : ''}
                            ${this.config.canLogo ? `<button type="button" class="btn" data-mode="logo">${t('Logo card')}</button>` : ''}
                        </div>
                        <button type="button" class="btn gw-image-close">${t('Close')}</button>
                    </div>
                    <div class="gw-panel__body">
                        <div class="gw-image-pane" data-pane="find">
                            <p class="light">${t('Ghostwriter reads the block this field is in, and the rest of the page, then searches free photo libraries and picks the photos that best suit the images already used here.')}</p>
                            <div class="flex gw-image-form">
                                <input type="text" class="text fullwidth gw-image-words" placeholder="${Ghostwriter.escape(t('What should it show? Leave empty and Ghostwriter will choose'))}">
                                <button type="button" class="btn submit gw-image-search">${t('Search')}</button>
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
                        <div class="gw-image-pane hidden" data-pane="logo">
                            <p class="light">${t('Your logo centred on a flat colour or a gradient, drawn exactly as it is. Use a PNG or SVG with a transparent background.')}</p>
                            <div class="gw-image-logo">
                                <label>${t('Logo')} <input type="file" accept="image/png,image/svg+xml,image/webp" class="gw-logo-file"></label>
                                <label>${t('Colour')} <input type="text" class="text gw-logo-colour" placeholder="${Ghostwriter.escape(t('The logo’s own, or #hex'))}"></label>
                                <label>${t('Second colour, for a gradient')} <input type="text" class="text gw-logo-colour-to" placeholder="#hex"></label>
                                <label class="gw-logo-white"><input type="checkbox" class="gw-logo-white-check" checked> ${t('Make the logo white')}</label>
                            </div>
                            <button type="button" class="btn submit gw-image-logo-make">${t('Make the card and use it')}</button>
                            <div class="gw-image-status"></div>
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
            this.addListener($modal.find('.gw-image-logo-make'), 'click', 'logo');

            this.show(this.mode);
        },

        show(mode) {
            this.mode = mode;
            this.$modal.find('.gw-image-tabs .btn').each((i, button) => $(button).toggleClass('active', $(button).data('mode') === mode).attr('aria-pressed', $(button).data('mode') === mode));
            this.$modal.find('.gw-image-pane').each((i, pane) => $(pane).toggleClass('hidden', $(pane).data('pane') !== mode));
        },

        pane(mode) {
            return this.$modal.find(`[data-pane="${mode}"]`);
        },

        status($pane, html) {
            $pane.find('.gw-image-status').html(html);
        },

        target() {
            return { fieldId: this.config.fieldId, elementId: this.config.elementId, siteId: this.config.siteId };
        },

        async find() {
            const t = this.t;
            const $pane = this.pane('find');
            const $search = $pane.find('.gw-image-search');

            $search.addClass('loading');
            $pane.find('.gw-image-grid').empty();

            try {
                const data = await Ghostwriter.request('POST', 'images/start', { ...this.target(), mode: 'find', words: $pane.find('.gw-image-words').val() });
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

        async logo() {
            const t = this.t;
            const $pane = this.pane('logo');
            const $go = $pane.find('.gw-image-logo-make');
            const file = $pane.find('.gw-logo-file')[0].files[0];

            if (!file) {
                Craft.cp.displayError(t('Choose the logo file first.'));

                return;
            }

            const form = new FormData();
            Object.entries({ ...this.target(), colour: $pane.find('.gw-logo-colour').val(), colourTo: $pane.find('.gw-logo-colour-to').val(), white: $pane.find('.gw-logo-white-check').is(':checked') ? 1 : 0 }).forEach(([key, value]) => form.append(key, value));
            form.append('logo', file);

            $go.addClass('loading');

            try {
                await this.place(await Ghostwriter.request('POST', 'images/logo', form));
            } catch (error) {
            } finally {
                $go.removeClass('loading');
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
                this.status($pane, `<p class="error">${Ghostwriter.escape(data.error ?? this.t('That did not work.'))}</p>`);
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

            if (data.terms?.length) {
                $pane.find('.gw-image-words').val(data.terms.join('; '));
            }

            data.options.forEach((photo) => {
                const $card = $(`
                    <figure class="gw-photo${photo.picked ? ' gw-photo--picked' : ''}">
                        <img src="${Ghostwriter.escape(photo.thumb)}" alt="" loading="lazy">
                        <figcaption>
                            ${photo.picked ? `<span class="gw-photo__badge">${t('Best match')}</span>` : ''}
                            <span class="light">${Ghostwriter.escape(photo.credit)} · ${Ghostwriter.escape(photo.licence)}</span>
                            <button type="button" class="btn small submit">${t('Use this')}</button>
                        </figcaption>
                    </figure>`);

                // A thumbnail that will not load is no use to choose from.
                $card.find('img').on('error', () => $card.remove());

                $grid.append($card);
                Ghostwriter.prepareButtons($card);
                this.addListener($card.find('.btn'), 'click', (event) => this.use($(event.currentTarget), { source: photo.source, photo: photo.id, term: photo.term }));
            });
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
            this.addListener($made.find('.gw-made-use'), 'click', (event) => this.use($(event.currentTarget), {}));
            this.addListener($made.find('.gw-made-again'), 'click', 'make');
        },

        async use($button, extra) {
            $button.addClass('loading');
            this.$modal.find('.gw-photo .btn').addClass('disabled');

            try {
                await this.place(await Ghostwriter.request('POST', 'images/use', { id: this.request.id, ...extra }));
            } catch (error) {
            } finally {
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

            Craft.cp.displaySuccess(t('Image added.'));
            this.modal.hide();
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
                new Ghostwriter.ImageButton(holder);
            }
        });
    };

    $(() => {
        Ghostwriter.initImageButtons(document.body);

        new MutationObserver((changes) => changes.forEach((change) => change.addedNodes.forEach((node) => node.nodeType === 1 && Ghostwriter.initImageButtons(node)))).observe(document.body, { childList: true, subtree: true });
    });
})();
