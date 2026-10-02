<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\stores;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\ImageRequestStoreContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the image request store, against the plugin's own.
 */
class ImageRequestStoreTest extends TestCase
{
    use ImageRequestStoreContract;

    protected function imageRequestStore(): ImageRequestStore
    {
        return $this->plugin->imageStore;
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
