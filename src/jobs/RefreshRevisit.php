<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\queue\BaseJob;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * Content to revisit's daily pass, queued where there's no cron: by
 * Craft's garbage collection or by opening the list, once it's over a
 * day old. Entries saved since the last pass, rows whose day has come, a
 * full pass once a week (and the first time), and Suggest edits'
 * suggestions nobody acted on for 14 days expired. No model, ever.
 */
class RefreshRevisit extends BaseJob
{
    /** Held while the job waits, so it's queued once. */
    public const CACHE_KEY = 'ghostwriter:revisit-daily';

    public bool $full = false;

    public static function start(array $config = []): void
    {
        Craft::$app->getQueue()->push(new static($config));
    }

    public function execute($queue): void
    {
        try {
            Plugin::getInstance()->revisit->daily(full: $this->full);
        } finally {
            Craft::$app->getCache()->delete(self::CACHE_KEY);
        }
    }

    public function getTtr(): int
    {
        return 3600;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Bringing Content to revisit up to date');
    }
}
