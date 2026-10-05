<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Sends a session's latest message to the writer and stores what comes back:
 * its reply, the draft if it wrote or changed one, and the extras it
 * prepared with it. On the first draft, the SEO pass then links it to the
 * site's other pages (SEO layer §7: two calls, "Checking headings and
 * links…"), and the layout planner proposes other layouts of the same
 * words (page preview design §6.2): the draft is shown as soon as it is
 * in, while the piece is still working, its links once they are in, and
 * the layouts follow.
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
                $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens, questions: $response->questions);
                $planning = ($before === null || trim($before) === '') && $response->document !== null;

                if ($planning) {
                    $session->status = Session::WORKING;
                    $session->startedWorkingAt = Format::Craft->stamp(new DateTimeImmutable());
                    // Marked now, so the panel says "Checking headings and
                    // links…" from the moment the draft shows.
                    DraftLayouts::checking($session->id);
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
        $written = $copy->draft;

        try {
            $usage = $layouts->afterWriter($copy, $before, $response, $conversation, $context, function(string $stage) use ($sessions, $copy, $written): void {
                if ($stage === SeoPass::CHECKING) {
                    DraftLayouts::checking($this->sessionId);

                    return;
                }

                // The links are in: the draft shows with them while the
                // planner looks for other layouts.
                try {
                    $sessions->change($this->sessionId, function(Session $session) use ($copy, $written): ?bool {
                        if ($session->draft !== $written) {
                            return false;
                        }

                        $session->draft = $copy->draft;
                        SeoState::of($copy)->withWritten(SeoState::of($session)->written)->saveTo($session);

                        return null;
                    });
                } catch (Throwable $exception) {
                    Craft::warning("Ghostwriter couldn't show the draft's links yet: {$exception->getMessage()}", 'ghostwriter');
                }

                DraftLayouts::checked($this->sessionId);
            });
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't lay out the draft: {$exception->getMessage()}", 'ghostwriter');
            $usage = null;
        } finally {
            DraftLayouts::checked($this->sessionId);
        }

        $sessions->change($this->sessionId, function(Session $session) use ($copy, $usage, $written): void {
            // The SEO pass's links are the draft's own, unless it was
            // edited meanwhile.
            if ($usage !== null && ($session->draft === $written || $session->draft === $copy->draft)) {
                $session->draft = $copy->draft;
                SeoState::of($copy)->withWritten(SeoState::of($session)->written)->saveTo($session);
                $session->units = $copy->units;
                $session->extras = $copy->extras;
                $session->plans = $copy->plans;
                $session->plan = $copy->plan;
                $session->usage = [
                    'input' => (int) ($session->usage['input'] ?? 0) + $usage->input,
                    'output' => (int) ($session->usage['output'] ?? 0) + $usage->output,
                ] + $session->usage;
            } elseif ($copy->seo !== []) {
                // Not its links, but the pass has been: it isn't run again.
                // The search title, description and address it wrote are
                // kept (unless the piece has its own by now), and what
                // Ghostwriter wrote into the entry before is never lost.
                $now = SeoState::of($session);
                $theirs = SeoState::of($copy);
                $session->seo = (new SeoState(
                    removed: $now->removed,
                    checked: $theirs->checked,
                    meta: $now->meta->isEmpty() ? $theirs->meta : $now->meta,
                    written: $now->written->merge($theirs->written),
                ))->toArray();
            }

            if ($session->status === Session::WORKING) {
                $session->status = Session::IDLE;
            }
        });
    }

    /**
     * The writer and, on a first draft, the SEO pass's two calls and the
     * layout planner.
     */
    public function getTtr(): int
    {
        return parent::getTtr() * 4;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing with Ghostwriter');
    }
}
