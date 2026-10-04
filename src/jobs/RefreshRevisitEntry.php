<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * An entry was saved: its Content to revisit row, its paragraphs for
 * Suggest edits' duplicate check, and its latest review's Done and Stale.
 * No model. Queued once however often it's saved while it waits
 * (Revisit::queue()); it reads the entry as it is when it runs.
 */
class RefreshRevisitEntry extends BaseJob
{
    public int $entryId = 0;

    public int $siteId = 0;

    public static function start(array $config = []): void
    {
        Craft::$app->getQueue()->push(new static($config));
    }

    /** Held while the job waits, so a second save doesn't queue it twice. */
    public static function cacheKey(int $entryId, int $siteId): string
    {
        return "ghostwriter:revisit-entry:{$entryId}@{$siteId}";
    }

    public function execute($queue): void
    {
        // From here a save queues it again: this run may read too early for it.
        Craft::$app->getCache()->delete(self::cacheKey($this->entryId, $this->siteId));

        $entry = Entry::find()->id($this->entryId)->siteId($this->siteId)->status(null)->one();

        if (!$entry instanceof Entry) {
            return;
        }

        try {
            Plugin::getInstance()->revisit->saved($entry);
        } catch (Throwable $exception) {
            // Never in the queue's way: the daily pass reads it again.
            Craft::warning("Ghostwriter couldn't refresh Content to revisit for entry {$this->entryId}: {$exception->getMessage()}", 'ghostwriter');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Checking a page for Content to revisit');
    }
}
