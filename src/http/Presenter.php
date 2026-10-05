<?php

namespace nineteenninetyfour\ghostwriter\http;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use DateTime;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Progress;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Record;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Asks;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use nineteenninetyfour\ghostwriter\comments\DraftComments;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * Shapes sessions for the control panel.
 */
class Presenter
{
    /** @var array<int, string> */
    private static array $names = [];

    /**
     * A person's name as the control panel shows it, for "who sent this".
     */
    public static function name(?int $userId): string
    {
        if ($userId === null) {
            return Craft::t('ghostwriter', 'Someone');
        }

        return self::$names[$userId] ??= (Craft::$app->getUsers()->getUserById($userId)?->getName() ?? Craft::t('ghostwriter', 'Someone'));
    }

    /**
     * The comments, or none shown when they can't be read: the panel still
     * works without them.
     *
     * @return array<string, mixed>|null
     */
    private function comments(Session $session): ?array
    {
        try {
            return (new DraftComments())->present($session);
        } catch (\Throwable $exception) {
            Craft::warning("Ghostwriter couldn't show the comments: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }
    }

    private function me(): ?int
    {
        $id = Craft::$app->getUser()->getId();

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Session $session): array
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($session->kind);
        $entry = $this->entryFor($session);
        $progress = Progress::of($session, $this->record($entry), $plugin->domain->options());

        return [
            'id' => $session->id,
            'title' => $session->title(),
            'type' => $type?->title ?? $session->kind,
            'section' => ($section = $type ? $plugin->types->section($type) : null) ? Craft::t('site', $section->name) : null,
            'status' => $session->status,
            'hasDraft' => $session->draft !== null,
            'stage' => $progress->stage,
            // Done with: the draft has become a saved entry, or the changes
            // to an existing one have been put into its draft.
            'finished' => $progress->finished,
            'entryUrl' => $entry && !$entry->getIsUnpublishedDraft() ? $entry->getCpEditUrl() : null,
            'updatedAt' => Craft::$app->getFormatter()->asRelativeTime(new DateTime((string) $session->updatedAt)),
            // Sessions are resumed where they were started: on the entry
            // they are being written into.
            'url' => $entry ? UrlHelper::urlWithParams((string) $entry->getCpEditUrl(), ['ghostwriter' => $session->id]) : null,
            // Shared, a piece is removed only by whoever started it or a manager.
            'canDelete' => $plugin->domain->access()->canDelete($session, $plugin->domain->viewer()),
        ] + $this->people($session);
    }

    /**
     * Whether a piece is done with (E6): its draft has become a saved entry.
     * The same rule moves it to Done on the plan, and stops it going back
     * to the ideas.
     */
    public function finished(Session $session): bool
    {
        return Progress::of($session, $this->record($this->entryFor($session)), Plugin::getInstance()->domain->options())->finished;
    }

    /**
     * Who started a piece and who last did something to it, when
     * conversations are shared; and whether someone else is waiting on
     * Ghostwriter for it now.
     *
     * @return array{startedBy: ?string, touchedBy: ?string, waitingOn: ?string}
     */
    private function people(Session $session): array
    {
        $domain = Plugin::getInstance()->domain;
        $me = $this->me();
        $shared = $domain->options()->shared;
        $startedBy = self::user($session->startedBy);
        $touched = self::user($session->touchedBy) ?? $startedBy;
        $waitingOn = self::user($session->waitingOn($domain->viewer()));

        return [
            'startedBy' => $shared ? ($startedBy === $me ? Craft::t('ghostwriter', 'you') : self::name($startedBy)) : null,
            'touchedBy' => $shared && $touched !== $startedBy ? ($touched === $me ? Craft::t('ghostwriter', 'you') : self::name($touched)) : null,
            // Only when shared: a private piece is only ever its starter's
            // to wait on, and naming them elsewhere (the plan) would leak it.
            'waitingOn' => $shared && $waitingOn !== null ? self::name($waitingOn) : null,
        ];
    }

    /**
     * The entry a session is written into, as it now stands: still a draft
     * nobody has saved, or an entry in its own right.
     */
    public function entryFor(Session $session): ?Entry
    {
        $id = $session->source ?? $session->recordId;

        if (!$id) {
            return null;
        }

        return Entry::find()->id((int) $id)->drafts(null)->provisionalDrafts(false)->siteId($session->siteId ?? '*')->status(null)->one();
    }

    /**
     * What Craft knows of the entry, for where the piece has got to: an
     * unpublished draft is not saved content yet.
     */
    private function record(?Entry $entry): Record
    {
        return match (true) {
            $entry === null => Record::none(),
            $entry->getIsUnpublishedDraft() => Record::unsaved(),
            default => Record::saved($entry->getStatus() === Entry::STATUS_LIVE, $entry->dateUpdated),
        };
    }

    /**
     * A user ID as Craft keeps it.
     */
    private static function user(int|string|null $id): ?int
    {
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * A writer's message with questions, as its card shows it, with who
     * answered. Null for one without.
     *
     * @return array<string, mixed>|null
     */
    private function asked(Session $session, int $index): ?array
    {
        $next = $session->messages[$index + 1] ?? null;
        $asked = Asks::present($session->messages[$index], is_array($next) ? $next : null);

        if ($asked === null) {
            return null;
        }

        $by = $asked['answered'] && is_array($next) ? self::user($next['by'] ?? $session->startedBy) : null;

        return $asked + ['answeredBy' => $by === null ? null : ($by === $this->me() ? Craft::t('ghostwriter', 'you') : self::name($by))];
    }

    private function markdown(string $text): string
    {
        static $converter = null;
        $converter ??= new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return trim((string) $converter->convert($text));
    }

    /**
     * The messages the conversation shows. A piece that started by editing
     * an entry opens with a note to the writer, which isn't shown.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visible(Session $session): array
    {
        $messages = BriefThread::visible($session);
        $first = $session->messages[0] ?? null;

        if ($session->isEditing() && is_array($first) && $first['role'] === 'user' && BriefThread::step($first) === null && BriefThread::text($session) === null && BriefThread::card($session) === null) {
            unset($messages[0]);
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Session $session): array
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($session->kind);
        $problem = null;
        $words = 0;
        $preview = [];
        $layouts = new LayoutsPresenter();

        if ($session->draft !== null) {
            try {
                $draft = Draft::parse($session->draft);
                // The words, not the links chosen for fields the draft doesn't hold.
                $words = (new Draft(array_diff_key($draft->data, [MarkerResolver::CHOSEN_LINKS => true]), $draft->raw))->wordCount();

                // The chosen layout's blocks and words (the draft itself
                // with the writer's layout).
                if ($entryType = $type ? $plugin->types->entryType($type->forSession($session)) : null) {
                    $preview = $layouts->preview($session, $draft, (new SchemaReader())->read($entryType));
                }
            } catch (InvalidArgumentException $exception) {
                $problem = $exception->getMessage();
            }
        }

        $last = $session->messages === [] ? null : $session->messages[array_key_last($session->messages)];
        $stage = BriefThread::stage($session);
        $card = BriefThread::card($session);

        return [
            'id' => $session->id,
            'editing' => $session->isEditing(),
            // Where the piece has got to, from the quick details to the draft.
            'stage' => $stage->value,
            // Whether the writer has asked something and is waiting for an
            // answer. The brief's own question and card are answered in
            // their own way.
            'waitingOnYou' => $stage->agreed()
                && $session->status === Session::IDLE
                && $last !== null
                && $last['role'] === 'assistant'
                && ($last['asks'] ?? ($session->draft === null && !$session->isEditing())),
            'type' => $type ? $plugin->types->questionnaire($type) : null,
            'title' => $session->title(),
            'status' => $session->status,
            'error' => $session->error,
            // The brief card: the working title, an answer for every
            // question (anything only the person knows in [square
            // brackets]) and the entries to model it on.
            'card' => $card ? $card->toArray() + ['open' => $card->open(), 'agreed' => BriefThread::agreed($session)] : null,
            // A piece started from the brief screen before 1.6 shows its
            // brief as it was written.
            'briefText' => $card ? null : BriefThread::text($session),
            // When the work under way was asked for, to count from.
            'since' => $last['at'] ?? null,
            // Replies use lists and bold, so they are shown as markdown, with
            // any HTML in them escaped. What the person typed stays as typed.
            // A person's message says who sent it, since others may carry on
            // the same conversation. Older ones were the starter's. Only what
            // the conversation shows: the latest brief card, and none of the
            // brief's workings.
            // The writer's questions (core's Studio\Asks) come with the
            // answers given in the next message, as `asked`; that message is
            // shown in their card, and only what else it said on its own.
            'messages' => array_values(array_map(fn(int $index, array $message) => ['step' => BriefThread::step($message)] + ($message['role'] === 'assistant'
                ? ['asked' => $this->asked($session, $index)] + $message + ['html' => $this->markdown((string) ($message['content'] ?? ''))]
                : $message + [
                    'mine' => self::user($message['by'] ?? $session->startedBy) === $this->me(),
                    'from' => self::name(self::user($message['by'] ?? $session->startedBy)),
                ]), array_keys($visible = $this->visible($session)), $visible)),
            'draft' => $session->draft,
            'draftProblem' => $problem,
            'preview' => $preview,
            // The layout cards and the Text tab's extras (§3).
            'layouts' => $layouts->layouts($session),
            'extras' => $layouts->extras($session),
            // The comments sent on the draft (conversation messages), each
            // with its state, in the chosen layout; and the next pin's number.
            'comments' => $session->draft !== null && $problem === null ? $this->comments($session) : null,
            'words' => $words,
            'usage' => $session->usage,
            'appliedAt' => $session->appliedAt,
            'images' => [],
            'shared' => $plugin->domain->options()->shared,
            // What the SEO pass did: the links Ghostwriter added (the Text
            // and Blocks tabs mark them, with a popover), its one-line
            // notice, and whether it is still checking a first draft.
            'seo' => $this->seo($session),
        ] + $this->people($session);
    }

    /**
     * @return array{checking: bool, notice: ?string, links: list<array<string, mixed>>, search: array<string, mixed>|null}
     */
    private function seo(Session $session): array
    {
        $state = SeoState::of($session);
        $message = $state->message();

        return [
            'checking' => DraftLayouts::isChecking($session),
            'notice' => $message === null ? null : Gaps::translate($message),
            // `open_url`: the page on the site, for "Open page".
            'links' => array_map(fn(array $link) => $link + ['open_url' => self::siteUrl($link['url'] ?? null, $session->siteId)], $state->links),
            // The Text tab's Search section: the SEO title, description
            // and address (SEO layer §9.5).
            'search' => $this->search($session),
        ];
    }

    /**
     * The Search section as the panel draws it: core's rows (SearchSection)
     * with their notes translated, the count's range as its tooltip says
     * it, and whether Try again is under way or failed. Null when the page
     * has neither SEO fields nor an address.
     *
     * @return array<string, mixed>|null
     */
    public function search(Session $session): ?array
    {
        $search = (new DraftLayouts())->search($session);

        if ($search === null) {
            return null;
        }

        $row = function(?array $row): ?array {
            if ($row === null) {
                return null;
            }

            $note = is_array($row['note'] ?? null) ? Gaps::translate(new Message((string) $row['note']['key'], $row['note']['params'] ?? [])) : null;
            $extra = ['note' => $note];

            if (isset($row['min'], $row['max'])) {
                $extra['range'] = Gaps::translate(new Message('seo.search.range', ['min' => $row['min'], 'max' => $row['max']]));
            }

            if (($row['role'] ?? null) === 'title') {
                $extra['usesTitle'] = Gaps::translate(new Message('seo.search.uses-title', ['title' => (string) ($row['pageTitle'] ?? '')]));
            }

            return array_replace($row, $extra);
        };

        return [
            'title' => $row($search['title']),
            'description' => $row($search['description']),
            'address' => $row($search['address']),
            'fields' => $search['fields'],
            'busy' => DraftLayouts::isSearching($session),
            'failed' => DraftLayouts::hasSearchFailed($session) ? Gaps::translate(new Message('seo.search.failed')) : null,
        ];
    }

    /** A site-relative address ("/contact") on the piece's site; a full one as it is. */
    private static function siteUrl(mixed $url, ?int $siteId): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        try {
            return UrlHelper::siteUrl(ltrim($url, '/'), null, null, $siteId);
        } catch (\Throwable) {
            return $url;
        }
    }
}
