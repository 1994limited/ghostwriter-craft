<?php

namespace nineteenninetyfour\ghostwriter\domain;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * Queue-waiting marks as `queued:<subject>` state, for core's Waiting.
 * Craft runs its own queue from control panel requests, so Waiting is
 * built with `runsItself` (Domain::waiting()) and never says work is
 * waiting for a worker; nothing marks work today.
 */
class DbWaitingStore implements WaitingStore
{
    public function mark(string $subject, int $at): void
    {
        Plugin::getInstance()->store->putState("queued:{$subject}", ['at' => $at]);
    }

    public function unmark(string $subject): void
    {
        Plugin::getInstance()->store->deleteState("queued:{$subject}");
    }

    public function markedAt(string $subject): ?int
    {
        $at = Plugin::getInstance()->store->state("queued:{$subject}")['at'] ?? null;

        return is_numeric($at) ? (int) $at : null;
    }
}
