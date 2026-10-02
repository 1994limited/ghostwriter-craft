<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\queue\BaseJob;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * A model call can take a minute or more, longer than a web request should
 * be held open, so each one runs as a queued job and the screen polls for
 * the result. Craft runs the queue from control panel requests by default,
 * so this works with no worker set up; a site with a queue worker gets it
 * there instead.
 *
 * Jobs never fail in the queue's eyes: a failure is written to the state
 * the screen reads, where the person who asked will see it. Retrying would
 * only pay for the same call twice.
 */
abstract class Job extends BaseJob implements RetryableJobInterface
{
    public static function start(array $config = []): void
    {
        Craft::$app->getQueue()->push(new static($config));
    }

    /**
     * Core tries a busy or rate-limited call up to three times, so one job
     * can take three timeouts plus the waits between them.
     */
    public function getTtr(): int
    {
        return Plugin::getInstance()->getSettings()->timeout * 3 + 60;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
