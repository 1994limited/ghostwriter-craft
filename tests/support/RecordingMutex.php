<?php

namespace nineteenninetyfour\ghostwriter\tests\support;

use yii\mutex\Mutex;

/**
 * A mutex that notes every lock taken, refuses those named in $held at once
 * (as if someone else had them), and can run something just as a lock is
 * taken, to stand in for another request that saved first.
 */
class RecordingMutex extends Mutex
{
    /** @var array<int, string> */
    public array $taken = [];

    /** @var array<int, string> */
    public array $held = [];

    /** @var (callable(string): void)|null */
    public $onAcquire = null;

    protected function acquireLock($name, $timeout = 0): bool
    {
        if (in_array($name, $this->held, true)) {
            return false;
        }

        $this->taken[] = $name;

        if ($this->onAcquire) {
            ($this->onAcquire)($name);
        }

        return true;
    }

    protected function releaseLock($name): bool
    {
        return true;
    }
}
