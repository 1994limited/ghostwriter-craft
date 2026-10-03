<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use craft\elements\Asset;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Ghostwriter's striped placeholder, recognised as VolumeAssetSink saves
 * it and the Applier keeps it out: an asset whose file is named
 * Placeholders::FILENAME, in any volume. Never by its title, which an
 * editor may give any image.
 */
class CraftPlaceholderAssets implements PlaceholderAssets
{
    /** @var array<int, bool> Whether each asset looked up is the placeholder, by ID. */
    private array $known = [];

    public function isPlaceholder(AssetRef $asset, Field $field): bool
    {
        if (!is_numeric($asset->id)) {
            return $asset->filename() === Placeholders::FILENAME;
        }

        $id = (int) $asset->id;

        return $this->known[$id] ??= Asset::find()->id($id)->status(null)->site('*')->unique()->filename(Placeholders::FILENAME)->exists();
    }
}
