<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LockContract;
use nineteenninetyfour\ghostwriter\tests\support\RecordingMutex;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the lock, against Craft's mutex.
 */
class LockTest extends TestCase
{
    use LockContract;

    protected function contractLock(): Lock
    {
        return $this->plugin->lock;
    }

    public function testALockHeldElsewhereIsRefusedWithItsName(): void
    {
        $mutex = new RecordingMutex();
        $mutex->held = ['ghostwriter:session:abc'];
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            $this->plugin->lock->run('session:abc', fn() => $this->fail('The work should not run.'), 1);
            $this->fail('The lock should be refused.');
        } catch (LockTimeout $timeout) {
            $this->assertSame(409, $timeout->status());
        } finally {
            Craft::$app->set('mutex', $original);
        }

        $this->assertSame('ran', $this->plugin->lock->run('session:other', fn() => 'ran'));
    }
}
