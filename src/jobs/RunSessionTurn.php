<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\drafts\Draft;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;
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
            $type = $plugin->types->find($session->type)
                ?? throw new InvalidArgumentException("The content type \"{$session->type}\" no longer exists.");

            $response = $plugin->studio->write($session, $type->forSession($session), $plugin->voiceGuide->get());
            $reply = $response->reply;

            if ($response->document !== null) {
                // A draft that does not parse is still kept, so nothing the
                // model wrote is lost; the problem is reported alongside it.
                try {
                    Draft::parse($response->document);
                } catch (InvalidArgumentException $exception) {
                    $reply = trim($reply . "\n\n(" . $exception->getMessage() . ' Ask me to fix it.)');
                }
            }

            // The reply is laid over the session as it is now, not as it was
            // when the turn began: the panel may have changed it meanwhile.
            $apply = function(Session $session) use ($response, $reply): void {
                $before = $session->draft;

                if ($response->document !== null) {
                    $session->draft = $response->document;
                }

                $session->addMessage('assistant', $reply !== '' ? $reply : 'I have updated the draft.');

                $last = array_key_last($session->messages);

                // A turn that hands nothing back and ends on a question is the
                // writer waiting on its colleague, which the panel makes plain.
                $session->messages[$last]['asks'] = $response->document === null && str_contains($reply, '?');

                // What this turn did to the draft, for the conversation's log.
                if ($response->document !== null && $response->document !== $before) {
                    $session->messages[$last]['draft'] = [
                        'change' => $before === null ? 'written' : 'updated',
                        'words' => str_word_count($response->document),
                        'was' => $before === null ? null : str_word_count($before),
                    ];
                }

                $session->usage['input'] += $response->inputTokens;
                $session->usage['output'] += $response->outputTokens;
                $session->status = Session::IDLE;
                $session->error = null;
            };
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $apply = function(Session $session) use ($exception): void {
                $session->status = Session::FAILED;
                $session->error = $exception->getMessage();
            };
        }

        $plugin->sessions->change($this->sessionId, $apply);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing with Ghostwriter');
    }
}
