<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Sends a session's latest message to the writer and stores what comes back:
 * its reply, and the draft if it wrote or changed one.
 */
class RunSessionTurn extends Job
{
    public string $sessionId = '';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $session = $plugin->sessions->find($this->sessionId);

        if (!$session) {
            return;
        }

        try {
            $type = $plugin->types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            $response = $plugin->studio->write($session, $type->forSession($session), $plugin->domain->guide(Guide::VOICE)->body);

            // The answer is laid over the session as it is now, not as it was
            // when the turn began: the panel may have changed it meanwhile.
            // A draft that does not parse is still kept, with the problem
            // said alongside it.
            $apply = fn(Session $session) => $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $apply = fn(Session $session) => $session->fail($exception->getMessage());
        }

        $plugin->domain->sessions()->change($this->sessionId, $apply);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing with Ghostwriter');
    }
}
