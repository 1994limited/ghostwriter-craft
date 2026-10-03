<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * The assets in a field's value, as EntryReader reads them: an Assets
 * field's asset IDs, and the images inline in CKEditor or Redactor HTML,
 * which Craft stores as reference tags (`<img src="{asset:12:url||…}">`,
 * `{asset:12:transform:wide||…}`). Craft's relations table doesn't hold
 * inline images, so they are read from the HTML.
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

        return array_map(fn(int $id) => AssetRef::craft($id), $this->ids($value));
    }

    /**
     * @return list<AssetRef>
     */
    private function inline(string $html): array
    {
        if (!str_contains($html, '{asset:') || preg_match_all(self::INLINE, $html, $matches) === 0) {
            return [];
        }

        return array_map(fn(string $id) => AssetRef::craft((int) $id), $matches[2]);
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
