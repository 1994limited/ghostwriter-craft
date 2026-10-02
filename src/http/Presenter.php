<?php

namespace nineteenninetyfour\ghostwriter\http;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use DateTime;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Progress;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Record;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftPreview;
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

    private function markdown(string $text): string
    {
        static $converter = null;
        $converter ??= new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return trim((string) $converter->convert($text));
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

        if ($session->draft !== null) {
            try {
                $draft = Draft::parse($session->draft);
                $words = $draft->wordCount();

                if ($entryType = $type ? $plugin->types->entryType($type->forSession($session)) : null) {
                    $preview = (new DraftPreview())->render($draft->data, (new SchemaReader())->read($entryType));
                }
            } catch (InvalidArgumentException $exception) {
                $problem = $exception->getMessage();
            }
        }

        $last = $session->messages === [] ? null : $session->messages[array_key_last($session->messages)];

        return [
            'id' => $session->id,
            'editing' => $session->isEditing(),
            // Whether the writer has asked something and is waiting for an answer.
            'waitingOnYou' => $session->status === Session::IDLE
                && $last !== null
                && $last['role'] === 'assistant'
                && ($last['asks'] ?? ($session->draft === null && !$session->isEditing())),
            'type' => $type ? $plugin->types->questionnaire($type) : null,
            'title' => $session->title(),
            'status' => $session->status,
            'error' => $session->error,
            // Replies use lists and bold, so they are shown as markdown, with
            // any HTML in them escaped. What the person typed stays as typed.
            // A person's message says who sent it, since others may carry on
            // the same conversation. Older ones were the starter's.
            'messages' => array_map(fn(array $message) => $message['role'] === 'assistant'
                ? $message + ['html' => $this->markdown((string) ($message['content'] ?? ''))]
                : $message + [
                    'mine' => self::user($message['by'] ?? $session->startedBy) === $this->me(),
                    'from' => self::name(self::user($message['by'] ?? $session->startedBy)),
                ], $session->messages),
            'draft' => $session->draft,
            'draftProblem' => $problem,
            'preview' => $preview,
            'words' => $words,
            'usage' => $session->usage,
            'appliedAt' => $session->appliedAt,
            'images' => [],
            'shared' => $plugin->domain->options()->shared,
        ] + $this->people($session);
    }
}
