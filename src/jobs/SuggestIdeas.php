<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use nineteenninetyfour\ghostwriter\planning\PlanState;
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

        try {
            $suggested = $plugin->studio->suggestIdeas($this->sections, array_values($plugin->ideas->all()), $plugin->voiceGuide->get(), $this->steer);

            // Held for the person to look over; nothing joins the plan until
            // they say which.
            $plugin->planState->update(['status' => PlanState::IDLE, 'error' => null, 'task' => null, 'pending' => array_values($suggested)]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $plugin->planState->update(['status' => PlanState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Looking for what the site is missing');
    }
}
