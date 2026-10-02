<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PlanStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the content plan store, against the plugin's own.
 */
class PlanStoreTest extends TestCase
{
    use PlanStoreContract;

    protected function planStore(): PlanStore
    {
        return $this->plugin->plans;
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
