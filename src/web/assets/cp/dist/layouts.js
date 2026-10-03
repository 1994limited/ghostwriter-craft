/**
 * The writing panel's layout cards (page preview design §3, §5): the
 * writer's draft and up to two other layouts of the same words, each with
 * a live thumbnail of the page it makes, its name, a line on what it does,
 * how many blocks it has, and "Suggested" on the one most like the site's
 * own pages. Choosing one is stored on the piece, for everyone on it.
 *
 * The thumbnails are the Preview's own renders (ghostwriter/preview/prepare
 * with the card's plan), scaled down. A render is reused for the same
 * values, so a card that hasn't changed isn't loaded again. Where the
 * section can't show a page, a card shows its blocks as an outline.
 *
 * The row is kept between the panel's redraws, so the thumbnails' frames
 * aren't loaded again each time.
 */
(function () {
    window.Ghostwriter = window.Ghostwriter || {};

    const t = (message, params) => (window.Craft?.t ? Craft.t('ghostwriter', message, params) : message.replace(/\{(\w+)\}/g, (m, name) => params?.[name] ?? m));
    const esc = (text) => Ghostwriter.escape(text);

    /** The width a thumbnail's page is laid out at, before it is scaled to the card. */
    const PAGE_WIDTH = 1280;

    /**
     * What can be worked out without the page, for the tests.
     */
    const Helpers = {
        /**
         * The cards to show: none with one layout or none (the row is
         * hidden), the writer's and two placeholders while the planner
         * works, and otherwise every layout.
         */
        cards(layouts) {
            const plans = layouts?.plans ?? [];

            if (layouts?.planning) {
                const writer = plans.find((plan) => plan.writer) ?? { id: 'w', name: t('As written'), description: '', blocks: null, writer: true };

                return [writer, { id: 'finding-1', skeleton: true }, { id: 'finding-2', skeleton: true }];
            }

            return plans.length > 1 ? plans : [];
        },

        /** "6 blocks" */
        count(plan) {
            if (plan.blocks === null || plan.blocks === undefined) return '';

            return plan.blocks === 1 ? t('1 block') : t('{count} blocks', { count: plan.blocks });
        },
    };

    Ghostwriter.LayoutHelpers = Helpers;

    class LayoutCards {
        /**
         * @param {object} options
         * @param {(plan: string) => void} options.choose
         * @param {() => void} options.refresh
         * @param {(plan: string) => Promise<object>} options.prepare A thumbnail's render: {preview, url, hash}.
         */
        constructor(options) {
            this.options = options;
            this.version = null;
            this.thumbs = new Map();
            this.root = document.createElement('div');
            this.root.className = 'gw-layouts';
            this.root.hidden = true;
            this.root.addEventListener('click', (event) => this.onClick(event));
            this.root.addEventListener('keydown', (event) => this.onKeydown(event));
            this.resizer = new ResizeObserver(() => this.fitAll());
            this.resizer.observe(this.root);
        }

        /**
         * Draws the row for the piece as it is. `version` changes whenever
         * a layout's page could have: the draft, the layouts, the extras.
         */
        update(session, { busy = false, previewable = true } = {}) {
            const layouts = session?.layouts;
            const cards = session?.draft && !session.draftProblem ? Helpers.cards(layouts) : [];

            this.root.hidden = cards.length === 0;

            if (!cards.length) {
                return;
            }

            const chosen = layouts.chosen ?? 'w';
            const stale = cards.some((card) => card.stale);
            const focused = this.root.contains(document.activeElement) ? document.activeElement.dataset.plan : null;

            this.previewable = previewable;

            const head = `
                <span class="gw-layouts__label" id="gw-layouts-label">${esc(t('Layout'))}</span>
                ${layouts.planning ? `<span class="gw-layouts__note light"><span class="gw-page__busy" aria-hidden="true"></span>${esc(t('Finding other layouts…'))}</span>` : ''}
                ${stale && !layouts.planning ? `<span class="gw-layouts__note light">${esc(t('The draft changed since these were laid out.'))}</span><button type="button" class="btn small" data-layout-action="refresh" ${busy ? 'disabled' : ''}>${esc(t('Refresh layouts'))}</button>` : ''}`;

            // The same cards: changed where they are, so their frames
            // aren't moved (a moved frame loads its page again).
            const shape = JSON.stringify(cards.map((card) => [card.id, Boolean(card.skeleton)]));

            if (shape === this.shape && this.root.querySelector('.gw-layouts__cards')) {
                this.root.querySelector('.gw-layouts__head').innerHTML = head;
                cards.forEach((card, i) => {
                    const old = this.root.querySelector(`[data-index="${i}"]`);
                    const fresh = document.createElement('div');

                    fresh.innerHTML = this.card(card, chosen, busy, i);

                    const next = fresh.firstElementChild;

                    if (!old || !next || card.skeleton) return;

                    [...old.attributes].forEach((attribute) => old.removeAttribute(attribute.name));
                    [...next.attributes].forEach((attribute) => old.setAttribute(attribute.name, attribute.value));
                    old.querySelector('.gw-layout-card__title').replaceWith(next.querySelector('.gw-layout-card__title'));
                    old.querySelector('.gw-layout-card__desc').replaceWith(next.querySelector('.gw-layout-card__desc'));
                });
            } else {
                this.shape = shape;
                this.root.innerHTML = `
                    <div class="gw-layouts__head">${head}</div>
                    <div class="gw-layouts__cards" role="group" aria-labelledby="gw-layouts-label">
                        ${cards.map((card, i) => this.card(card, chosen, busy, i)).join('')}
                    </div>`;
            }

            const version = `${session.id}|${session.draft}|${layouts.key}`;
            const changed = version !== this.version;
            this.version = version;

            cards.filter((card) => !card.skeleton).forEach((card) => this.thumb(card, changed));
            this.fitAll();

            if (focused && !this.root.contains(document.activeElement)) this.root.querySelector(`[data-plan="${CSS.escape(focused)}"]`)?.focus();
        }

        card(card, chosen, busy, index) {
            if (card.skeleton) {
                return `<div class="gw-layout-card is-skeleton" aria-hidden="true"><div class="gw-layout-card__thumb"></div><p class="gw-layout-card__desc">${esc(t('Finding other layouts…'))}</p></div>`;
            }

            const on = card.id === chosen;
            const off = busy || card.stale;
            const state = card.stale ? t('Needs refreshing') : '';
            const label = [card.name, card.suggested ? t('Suggested') : '', Helpers.count(card), card.description, state].filter(Boolean).join('. ');

            return `<button type="button" class="gw-layout-card${on ? ' is-chosen' : ''}${card.stale ? ' is-stale' : ''}" data-plan="${esc(card.id)}" data-index="${index}" aria-pressed="${on}" aria-label="${esc(label)}" ${off ? 'aria-disabled="true"' : ''}>
                <span class="gw-layout-card__thumb" data-thumb="${esc(card.id)}" aria-hidden="true"></span>
                <span class="gw-layout-card__title" aria-hidden="true">
                    <span class="gw-layout-card__name">${esc(card.name)}</span>
                    ${card.suggested ? `<span class="gw-layout-card__badge">${esc(t('Suggested'))}</span>` : ''}
                    <span class="gw-layout-card__count">${esc(Helpers.count(card))}</span>
                </span>
                <span class="gw-layout-card__desc" aria-hidden="true">${esc(card.stale ? t('Needs refreshing') : card.description)}</span>
            </button>`;
        }

        /**
         * A card's thumbnail: its frame, kept from the last draw, or a new
         * render once the piece has changed.
         */
        thumb(card, changed) {
            const slot = this.root.querySelector(`[data-thumb="${CSS.escape(card.id)}"]`);
            const held = this.thumbs.get(card.id);

            if (!slot) return;

            if (held && held.el.parentElement !== slot) {
                slot.replaceChildren(held.el);
            }

            if (held && !changed) {
                return;
            }

            if (!held) {
                slot.innerHTML = '<span class="gw-layout-card__loading"></span>';
            }

            const version = this.version;

            this.options.prepare(card.id).then((data) => {
                if (version !== this.version) return;

                if (!data?.preview) {
                    return this.outline(card);
                }

                const current = this.thumbs.get(card.id);

                if (current && current.url === data.url) return;

                const frame = document.createElement('iframe');
                frame.className = 'gw-layout-card__frame';
                frame.setAttribute('sandbox', 'allow-same-origin allow-scripts');
                frame.setAttribute('referrerpolicy', 'no-referrer');
                frame.setAttribute('loading', 'lazy');
                frame.setAttribute('aria-hidden', 'true');
                frame.tabIndex = -1;
                frame.title = '';
                frame.src = data.url;
                // From where the page's own content starts, past its header.
                frame.addEventListener('load', () => {
                    try {
                        const doc = frame.contentDocument;
                        const main = doc.querySelector('main, [role=main]');
                        const top = main ? main.getBoundingClientRect().top + frame.contentWindow.scrollY : 0;

                        doc.documentElement.style.scrollBehavior = 'auto';
                        frame.contentWindow.scrollTo(0, Math.max(0, top - 16));
                        doc.querySelectorAll('a[href]').forEach((link) => link.setAttribute('tabindex', '-1'));
                    } catch (error) {}
                });

                const el = document.createElement('span');
                el.className = 'gw-layout-card__page';
                el.appendChild(frame);

                current?.el.remove();
                this.thumbs.set(card.id, { url: data.url, el });

                const target = this.root.querySelector(`[data-thumb="${CSS.escape(card.id)}"]`);

                if (target) {
                    target.replaceChildren(el);
                    this.fit(el);
                }
            }, () => this.outline(card));
        }

        /** No page to show: the card's blocks, as an outline. */
        outline(card) {
            const target = this.root.querySelector(`[data-thumb="${CSS.escape(card.id)}"]`);

            if (!target) return;

            const types = card.outline ?? [];
            const el = document.createElement('span');
            el.className = 'gw-layout-card__outline';
            el.innerHTML = types.map((type) => `<span>${esc(type)}</span>`).join('');
            this.thumbs.set(card.id, { url: null, el });
            target.innerHTML = '';
            target.appendChild(el);
        }

        /** A thumbnail's page laid out at desktop width, scaled to its card. */
        fit(el) {
            const frame = el.querySelector('iframe');
            const box = el.parentElement;

            if (!frame || !box?.clientWidth) return;

            const scale = box.clientWidth / PAGE_WIDTH;

            frame.style.width = `${PAGE_WIDTH}px`;
            frame.style.height = `${Math.ceil(box.clientHeight / scale)}px`;
            frame.style.transform = `scale(${scale})`;
        }

        fitAll() {
            this.root.querySelectorAll('.gw-layout-card__page').forEach((el) => this.fit(el));
        }

        onClick(event) {
            const action = event.target.closest('[data-layout-action]');

            if (action && !action.disabled) {
                return this.options.refresh();
            }

            const card = event.target.closest('[data-plan]');

            if (!card || card.getAttribute('aria-disabled') === 'true' || card.getAttribute('aria-pressed') === 'true') return;

            this.options.choose(card.dataset.plan);
        }

        /** ← and → move between the cards; Enter and Space choose, as buttons do. */
        onKeydown(event) {
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;

            const buttons = [...this.root.querySelectorAll('[data-plan]')];
            const at = buttons.indexOf(document.activeElement);

            if (at < 0) return;

            event.preventDefault();

            const step = ['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 1;
            const rtl = getComputedStyle(this.root).direction === 'rtl' && ['ArrowLeft', 'ArrowRight'].includes(event.key) ? -1 : 1;

            buttons[(at + step * rtl + buttons.length) % buttons.length].focus();
        }

        /** Another piece: forget this one's thumbnails. */
        reset() {
            this.thumbs.forEach((thumb) => thumb.el.remove());
            this.thumbs.clear();
            this.version = null;
            this.shape = null;
        }
    }

    Ghostwriter.LayoutCards = LayoutCards;
})();
