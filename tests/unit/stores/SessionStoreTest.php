<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SessionStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the session store, against the plugin's own.
 */
class SessionStoreTest extends TestCase
{
    use SessionStoreContract;

    /** @var array<int, int> */
    private array $users = [];

    protected function sessionStore(): SessionStore
    {
        return $this->plugin->sessions;
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }

    /**
     * Real users, for the sessions table's foreign key.
     */
    protected function contractUser(int $n): int
    {
        return $this->users[$n] ??= (int) $this->signIn()->id;
    }
}
