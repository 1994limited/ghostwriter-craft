<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * "Try again" in the Text tab's Search section (SEO layer §9.5): one
 * `seo-editor` call for another search title and description, written
 * differently from the ones there now (core's SeoPass::retryMeta()). The
 * piece is "working" meanwhile, claimed by whoever clicked, and the panel
 * says "Writing another…"; a call that fails leaves the texts as they
 * were, and the panel says so (DraftLayouts::hasSearchFailed()).
 */
class RetrySearch extends Job
{
    public string $sessionId = '';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $session = $plugin->sessions->find($this->sessionId);

        if (!$session) {
            DraftLayouts::searching($this->sessionId, false);

            return;
        }

        $copy = clone $session;
        $done = false;

        try {
            (new DraftLayouts())->retrySearch($copy);
            $done = true;
        } catch (ProviderException $exception) {
            Craft::warning("Ghostwriter couldn't write another search title and description: {$exception->getMessage()}", 'ghostwriter');
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');
        }

        try {
            $plugin->domain->sessions()->change($this->sessionId, function(Session $session) use ($copy, $done): void {
                if ($done) {
                    // Only the search texts: everything else on the piece is as it is now.
                    SeoState::of($session)->withMeta(SeoState::of($copy)->meta)->saveTo($session);
                    $session->usage = $copy->usage;
                }

                if ($session->status === Session::WORKING) {
                    $session->status = Session::IDLE;
                }
            });
        } finally {
            DraftLayouts::searchFailed($this->sessionId, !$done);
            DraftLayouts::searching($this->sessionId, false);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing another search title and description with Ghostwriter');
    }
}
