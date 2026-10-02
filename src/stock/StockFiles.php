<?php

namespace nineteenninetyfour\ghostwriter\stock;

use nineteenninetyfour\ghostwriter\Plugin;

/**
 * A preview's comp, where Ghostwriter keeps it: a row in its files table
 * (`stock-comp-…`), never an asset. Let go of once the photo is licensed,
 * removed, refreshed, or its comp period ends.
 */
class StockFiles
{
    public const PREFIX = 'stock-comp-';

    /**
     * Delete a comp's bytes, if it is one Ghostwriter keeps (not a
     * library's own preview address).
     */
    public static function forget(?string $comp): void
    {
        if ($comp !== null && str_starts_with($comp, self::PREFIX)) {
            Plugin::getInstance()->store->deleteFile($comp);
        }
    }
}
