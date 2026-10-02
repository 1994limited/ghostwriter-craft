<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;

/**
 * Core's Lock over Craft's mutex, so a lock holds across every server the
 * site runs on. Names are kept under `ghostwriter:`. A lock taken again
 * while held in the same request is refused at once (Craft's mutex never
 * waits on itself), never deadlocked.
 */
class CraftLock implements Lock
{
    public function run(string $key, callable $work, int $waitSeconds = 15): mixed
    {
        $mutex = Craft::$app->getMutex();
        $name = 'ghostwriter:' . $key;

        if (!$mutex->acquire($name, $waitSeconds)) {
            throw new LockTimeout();
        }

        try {
            return $work();
        } finally {
            $mutex->release($name);
        }
    }
}
