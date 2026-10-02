<?php

namespace nineteenninetyfour\ghostwriter\http;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use DateTime;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftPreview;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;

/**
 * Shapes sessions for the control panel.
 */
class Presenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Session $session): array
    {
        $type = Plugin::getInstance()->types->find($session->type);
        $entry = $this->entryFor($session);
        $stage = $this->stage($session, $entry);

        return [
            'id' => $session->id,
            'title' => $session->title(),
            'type' => $type?->title ?? $session->type,
            'section' => ($section = $type?->craftSection()) ? Craft::t('site', $section->name) : null,
            'status' => $session->status,
            'hasDraft' => $session->draft !== null,
            'stage' => $stage,
            // Done with: the draft has become a saved entry, or the changes
            // to an existing one have been put into its draft.
            'finished' => $session->status !== Session::WORKING && ($session->source ? $session->appliedAt !== null : in_array($stage, ['saved', 'published'], true)),
            'entryUrl' => $entry && !$entry->getIsUnpublishedDraft() ? $entry->getCpEditUrl() : null,
            'updatedAt' => Craft::$app->getFormatter()->asRelativeTime(new DateTime((string) $session->updatedAt)),
            // Sessions are resumed where they were started: on the entry
            // they are being written into.
            'url' => $entry ? UrlHelper::urlWithParams((string) $entry->getCpEditUrl(), ['ghostwriter' => $session->id]) : null,
        ];
    }

    /**
     * The entry a session is written into, as it now stands: still a draft
     * nobody has saved, or an entry in its own right.
     */
    public function entryFor(Session $session): ?Entry
    {
        $id = $session->source ?? $session->elementId;

        if (!$id) {
            return null;
        }

        return Entry::find()->id($id)->drafts(null)->provisionalDrafts(false)->siteId($session->siteId ?? '*')->status(null)->one();
    }

    private function stage(Session $session, ?Entry $entry): string
    {
        return match (true) {
            $session->status === Session::FAILED => 'failed',
            $session->status === Session::WORKING => 'working',
            $session->source !== null => $session->appliedAt ? 'changed' : 'editing',
            $entry !== null && !$entry->getIsUnpublishedDraft() => $entry->getStatus() === Entry::STATUS_LIVE ? 'published' : 'saved',
            $session->appliedAt !== null => 'in_form',
            $session->draft !== null => 'draft',
            default => 'interview',
        };
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
        $type = Plugin::getInstance()->types->find($session->type);
        $problem = null;
        $words = 0;
        $preview = [];

        if ($session->draft !== null) {
            try {
                $draft = Draft::parse($session->draft);
                $words = $draft->wordCount();

                if ($entryType = $type?->forSession($session)->craftEntryType()) {
                    $preview = (new DraftPreview())->render($draft->data, (new SchemaReader())->read($entryType));
                }
            } catch (InvalidArgumentException $exception) {
                $problem = $exception->getMessage();
            }
        }

        $last = $session->messages === [] ? null : $session->messages[array_key_last($session->messages)];

        return [
            'id' => $session->id,
            'editing' => $session->source !== null,
            // Whether the writer has asked something and is waiting for an answer.
            'waitingOnYou' => $session->status === Session::IDLE
                && $last !== null
                && $last['role'] === 'assistant'
                && ($last['asks'] ?? ($session->draft === null && $session->source === null)),
            'type' => $type?->forQuestionnaire(),
            'title' => $session->title(),
            'status' => $session->status,
            'error' => $session->error,
            // Replies use lists and bold, so they are shown as markdown, with
            // any HTML in them escaped. What the person typed stays as typed.
            'messages' => array_map(fn(array $message) => $message['role'] === 'assistant'
                ? $message + ['html' => $this->markdown((string) ($message['content'] ?? ''))]
                : $message, $session->messages),
            'draft' => $session->draft,
            'draftProblem' => $problem,
            'preview' => $preview,
            'words' => $words,
            'usage' => $session->usage,
            'appliedAt' => $session->appliedAt,
            'images' => [],
        ];
    }
}
