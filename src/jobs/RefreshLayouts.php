<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * "Refresh layouts": the layout planner asked again for the draft as it is
 * now (one call). The writer's layout stays first; the others are
 * replaced. The piece is "working" meanwhile, claimed by whoever clicked,
 * so nothing changes under it; a planner that fails leaves the writer's
 * layout, never an error.
 */
class RefreshLayouts extends Job
{
    public string $sessionId = '';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $session = $plugin->sessions->find($this->sessionId);

        if (!$session) {
            return;
        }

        $layouts = new DraftLayouts();
        $copy = clone $session;
        $usage = null;

        try {
            $site = $layouts->context($copy);

            if ($site !== null) {
                $usage = $layouts->core()->refresh($copy, $site);
            }
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't refresh the layouts: {$exception->getMessage()}", 'ghostwriter');
        }

        $plugin->domain->sessions()->change($this->sessionId, function(Session $session) use ($copy, $usage): void {
            if ($usage !== null && $session->draft === $copy->draft) {
                $session->units = $copy->units;
                $session->plans = $copy->plans;
                $session->plan = $copy->plan;
                $session->usage = $copy->usage;
            }

            if ($session->status === Session::WORKING) {
                $session->status = Session::IDLE;
            }
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Finding other layouts with Ghostwriter');
    }
}
