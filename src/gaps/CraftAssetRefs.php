<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use nineteenninetyfour\ghostwriter\images\ImagePicker;

/**
 * The assets in a field's value, as EntryReader reads them: an Assets
 * field's asset IDs, and the images inline in CKEditor or Redactor HTML,
 * which Craft stores as reference tags (`<img src="{asset:12:url||…}">`,
 * `{asset:12:transform:wide||…}`). Craft's relations table doesn't hold
 * inline images, so they are read from the HTML.
 *
 * Each ref carries the asset's volume and path as well as its ID, so its
 * file name reaches the reviewer (`<image file="…">`) and the checks on
 * names, as on the other CMSs.
 */
class CraftAssetRefs implements AssetRefs
{
    /** An image's source pointing at an asset by reference tag. */
    private const INLINE = '/<img\b[^>]*?\bsrc\s*=\s*(["\'])\{asset:(\d+)(?:@\d+)?[:}]/i';

    public function in(mixed $value, Field $field): array
    {
        if ($field->kind === Kind::RichText) {
            return is_string($value) && $value !== '' ? $this->inline($value) : [];
        }

        if (!$field->files) {
            return [];
        }

        return $this->refs($this->ids($value), $this->loaded($value));
    }

    /**
     * @return list<AssetRef>
     */
    private function inline(string $html): array
    {
        if (!str_contains($html, '{asset:') || preg_match_all(self::INLINE, $html, $matches) === 0) {
            return [];
        }

        return $this->refs(array_map('intval', $matches[2]));
    }

    /**
     * Refs for the IDs, in order, with each asset's volume and path; an ID
     * whose asset is gone keeps just its ID.
     *
     * @param list<int> $ids
     * @param array<int, Asset> $loaded Assets already in hand, by ID.
     * @return list<AssetRef>
     */
    private function refs(array $ids, array $loaded = []): array
    {
        $missing = array_values(array_diff(array_unique($ids), array_keys($loaded)));

        if ($missing !== []) {
            foreach (Asset::find()->id($missing)->status(null)->all() as $asset) {
                $loaded[(int) $asset->id] = $asset;
            }
        }

        return array_map(fn(int $id) => isset($loaded[$id]) ? ImagePicker::ref($loaded[$id]) : AssetRef::craft($id), $ids);
    }

    /**
     * The assets a value already holds as elements (an eager-loaded or
     * cached query, a collection or a list of them), by ID.
     *
     * @return array<int, Asset>
     */
    private function loaded(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $value = method_exists($value, 'getCachedResult') ? $value->getCachedResult() : null;
        } elseif ($value instanceof Collection) {
            $value = $value->all();
        }

        $assets = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if ($item instanceof Asset) {
                $assets[(int) $item->id] = $item;
            }
        }

        return $assets;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $cached = method_exists($value, 'getCachedResult') ? $value->getCachedResult() : null;
            $value = $cached ?? (clone $value)->status(null)->ids();
        } elseif ($value instanceof Collection) {
            $value = $value->all();
        }

        if (!is_array($value)) {
            return is_numeric($value) ? [(int) $value] : [];
        }

        $ids = [];

        foreach ($value as $item) {
            if ($item instanceof ElementInterface) {
                $item = $item->id;
            }

            if (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }
}
