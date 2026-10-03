<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Sends a session's latest message to the writer and stores what comes back:
 * its reply, the draft if it wrote or changed one, and the extras it
 * prepared with it. On the first draft, the layout planner then proposes
 * other layouts of the same words (page preview design §6.2): the draft is
 * shown as soon as it is in, while the piece is still working, and the
 * layouts follow.
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

        $before = null;
        $planning = false;
        $response = $conversation = $context = null;

        try {
            $type = $plugin->types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            [$conversation, $context] = $plugin->studio->writerInputs($session, $type->forSession($session), $plugin->domain->guide(Guide::VOICE)->body);
            $response = $plugin->studio->core()->write($conversation, $context);

            // The answer is laid over the session as it is now, not as it was
            // when the turn began: the panel may have changed it meanwhile.
            // A draft that does not parse is still kept, with the problem
            // said alongside it. A first draft stays "working" while the
            // planner runs, so nothing is changed under it, but it shows.
            $apply = function(Session $session) use ($response, &$planning, &$before): void {
                $before = $session->draft;
                $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
                $planning = ($before === null || trim($before) === '') && $response->document !== null;

                if ($planning) {
                    $session->status = Session::WORKING;
                    $session->startedWorkingAt = Format::Craft->stamp(new DateTimeImmutable());
                }
            };
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $apply = fn(Session $session) => $session->fail($exception->getMessage());
        }

        $session = $plugin->domain->sessions()->change($this->sessionId, $apply);

        if ($session !== null && $response !== null) {
            $this->arrange($session, $before, $response, $conversation, $context, $planning);
        }
    }

    /**
     * Extras, unit ids and layouts after the turn. A later turn needs no
     * model and is done under the session's lock; the first draft calls
     * the planner outside it, and what it gives is laid over the session.
     */
    private function arrange(Session $session, ?string $before, TaggedResponse $response, Conversation $conversation, WriterContext $context, bool $planning): void
    {
        $sessions = Plugin::getInstance()->domain->sessions();
        $layouts = new DraftLayouts();

        if (!$planning) {
            $sessions->change($this->sessionId, function(Session $session) use ($layouts, $before, $response, $conversation, $context) {
                try {
                    $layouts->afterWriter($session, $before, $response, $conversation, $context);
                } catch (Throwable $exception) {
                    Craft::warning("Ghostwriter couldn't lay out the draft: {$exception->getMessage()}", 'ghostwriter');

                    return false;
                }

                return null;
            });

            return;
        }

        $copy = clone $session;

        try {
            $usage = $layouts->afterWriter($copy, $before, $response, $conversation, $context);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't lay out the draft: {$exception->getMessage()}", 'ghostwriter');
            $usage = null;
        }

        $sessions->change($this->sessionId, function(Session $session) use ($copy, $usage): void {
            if ($usage !== null && $session->draft === $copy->draft) {
                $session->units = $copy->units;
                $session->extras = $copy->extras;
                $session->plans = $copy->plans;
                $session->plan = $copy->plan;
                $session->usage = [
                    'input' => (int) ($session->usage['input'] ?? 0) + $usage->input,
                    'output' => (int) ($session->usage['output'] ?? 0) + $usage->output,
                ] + $session->usage;
            }

            if ($session->status === Session::WORKING) {
                $session->status = Session::IDLE;
            }
        });
    }

    /**
     * The writer and, on a first draft, the layout planner.
     */
    public function getTtr(): int
    {
        return parent::getTtr() * 2;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing with Ghostwriter');
    }
}
