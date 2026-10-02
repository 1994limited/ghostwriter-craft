<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\StockImageStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the stock image ledger, against the plugin's own
 * tables.
 */
class StockImageStoreTest extends TestCase
{
    use StockImageStoreContract;

    protected function stockImageStore(): StockImageStore
    {
        return $this->plugin->stockImages;
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
