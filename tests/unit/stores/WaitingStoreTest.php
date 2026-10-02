<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\WaitingStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the queue-waiting store, against the plugin's own.
 */
class WaitingStoreTest extends TestCase
{
    use WaitingStoreContract;

    protected function waitingStore(): WaitingStore
    {
        return $this->plugin->waitingStore;
    }
}
