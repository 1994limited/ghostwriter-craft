<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Fills in a piece's whole brief from what the person said in the
 * conversation (or a plan idea), and puts it in the conversation as the
 * brief card for them to check. One model call.
 */
class FillBrief extends Job
{
    public string $sessionId = '';

    /**
     * The job a piece needs next: filling in its brief while that is what
     * it waits on (BriefThread::fills()), otherwise the writer's turn.
     */
    public static function next(Session $session): void
    {
        BriefThread::fills($session)
            ? self::start(['sessionId' => $session->id])
            : RunSessionTurn::start(['sessionId' => $session->id]);
    }

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $session = $plugin->sessions->find($this->sessionId);

        if (!$session || !BriefThread::fills($session)) {
            return;
        }

        try {
            $type = $plugin->types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            $result = $plugin->studio->fillBrief($type->forSession($session), $session);

            // Laid over the session as it is now; called off if it has moved on.
            $plugin->domain->sessions()->propose(
                $this->sessionId,
                $result->value,
                $result->usage->input,
                $result->usage->output,
                Craft::t('ghostwriter', 'brief.card'),
                Craft::t('ghostwriter', 'brief.card.open'),
            );
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $plugin->domain->sessions()->change($this->sessionId, fn(Session $session) => $session->fail($exception->getMessage()));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Filling in the brief with Ghostwriter');
    }
}
