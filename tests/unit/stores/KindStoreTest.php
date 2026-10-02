<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\KindStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the kind store, against the plugin's own.
 */
class KindStoreTest extends TestCase
{
    use KindStoreContract;

    protected function kindStore(): KindStore
    {
        return $this->plugin->kindStore;
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
