<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\GuideStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the guide store, against the plugin's own.
 */
class GuideStoreTest extends TestCase
{
    use GuideStoreContract;

    protected function guideStore(): GuideStore
    {
        return $this->plugin->guides;
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
