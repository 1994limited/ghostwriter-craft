<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use craft\fields\Assets;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PlaceholderAssetsContract;
use nineteenninetyfour\ghostwriter\domain\VolumeAssetSink;
use nineteenninetyfour\ghostwriter\gaps\CraftAssetRefs;
use nineteenninetyfour\ghostwriter\gaps\CraftPlaceholderAssets;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for recognising the striped placeholder: the one
 * VolumeAssetSink saves is, an ordinary image titled like it isn't.
 */
class PlaceholderAssetsTest extends TestCase
{
    use PlaceholderAssetsContract;

    private ?Field $field = null;

    protected function placeholderSink(): AssetSink
    {
        return new VolumeAssetSink();
    }

    protected function placeholderAssets(): PlaceholderAssets
    {
        return new CraftPlaceholderAssets();
    }

    protected function placeholderAssetRefs(): AssetRefs
    {
        return new CraftAssetRefs();
    }

    protected function placeholderImageField(): Field
    {
        if ($this->field !== null) {
            return $this->field;
        }

        $volume = $this->makeVolume();
        $cover = $this->makeField(Assets::class, 'cover', ['sources' => ['volume:' . $volume->uid], 'defaultUploadLocationSource' => 'volume:' . $volume->uid]);
        $section = $this->makeSection('stories', [$this->makeEntryType('story', [$cover])]);
        $schema = (new SchemaReader())->schema($section->getEntryTypes()[0]);

        return $this->field = $schema->field('cover');
    }

    protected function ordinaryImageTitledLikeThePlaceholder(Field $field): mixed
    {
        $volume = \Craft::$app->getVolumes()->getVolumeByHandle('images');
        $asset = $this->makeAsset($volume, 'striped.png');
        $asset->title = Placeholders::TITLE;
        \Craft::$app->getElements()->saveElement($asset);

        return [(int) $asset->id];
    }
}
