<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use nineteenninetyfour\ghostwriter\comments\DraftComments;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * **Apply N comments**, in the queue: one call to the reviser for the
 * comments in the editor's message (core's Comments::revise()), checked and
 * applied under the piece's lock, and Ghostwriter's answer in the
 * conversation. A provider failure is core's to report; anything else that
 * stops the run is answered the same way here (Comments::fail()), so the
 * piece is never left working.
 */
class ApplyComments extends Job
{
    public string $sessionId = '';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $session = $plugin->sessions->find($this->sessionId);

        if (!$session) {
            return;
        }

        $drafts = new DraftComments();
        $comments = $drafts->comments();

        try {
            $type = $plugin->types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");
            $type = $type->forSession($session);
            $site = (new DraftLayouts())->context($session, $type)
                ?? throw new InvalidArgumentException('The entry type this was written for no longer exists.');

            [$conversation, $writer] = $plugin->studio->writerInputs($session, $type, $plugin->domain->guide(Guide::VOICE)->body);

            $comments->revise($this->sessionId, $conversation, $writer, $site, self::names($session));
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $comments->fail($this->sessionId, $exception->getMessage());
        }
    }

    /**
     * Who wrote each comment, for "By Priya" in the prompt.
     *
     * @return array<string, string>
     */
    private static function names(\NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session $session): array
    {
        $run = Comments::unanswered($session);
        $names = [];

        foreach ($run === null ? [] : Comments::itemsOf($session->messages[$run]) as $comment) {
            if (is_numeric($comment->by)) {
                $names[(string) $comment->by] = Presenter::name((int) $comment->by);
            }
        }

        return $names;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Applying comments with Ghostwriter');
    }
}
