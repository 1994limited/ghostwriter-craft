<?php

namespace nineteenninetyfour\ghostwriter\events;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use yii\base\Event;

/**
 * Paid photo libraries for the image dialog, beside the free ones: add a
 * core `PhotoLibrary` (a `LicensableLibrary` to license from it) to
 * `libraries`. Ghostwriter adds its own as core gains their adapters.
 */
class RegisterStockLibrariesEvent extends Event
{
    /** @var array<int, PhotoLibrary> */
    public array $libraries = [];
}
