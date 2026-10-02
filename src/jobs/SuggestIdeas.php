<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Reads what the site has and finds ideas for what it is missing, for the
 * person to choose from.
 */
class SuggestIdeas extends Job
{
    /** @var array<int, string> */
    public array $sections = [];

    public string $steer = '';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $plan = $plugin->domain->plan();

        try {
            $suggested = $plugin->studio->suggestIdeas($this->sections, $plugin->plans->ideas(), $plugin->domain->guide(Guide::VOICE)->body, $this->steer);

            // Held for the person to look over, alongside any batch still
            // waiting (E3); nothing joins the plan until they say which.
            $plan->receive($suggested);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $plan->failed($exception->getMessage());
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Looking for what the site is missing');
    }
}
