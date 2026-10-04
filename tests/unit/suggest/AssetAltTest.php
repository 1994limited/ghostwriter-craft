<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\elements\Asset;
use craft\fieldlayoutelements\assets\AltField;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\AssetAltContract;
use nineteenninetyfour\ghostwriter\suggest\CraftAssetAlt;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's AssetAltContract against Craft 5's own alt text: `Asset::$alt`,
 * where the volume's field layout shows it, and nowhere to keep it in a
 * volume whose layout leaves it out.
 */
class AssetAltTest extends TestCase
{
    use AssetAltContract;

    /** @var array<string, Asset> */
    private array $assets = [];

    protected function _before(): void
    {
        parent::_before();

        $photos = $this->volumeWith('photos', alt: true);
        $logos = $this->volumeWith('logos', alt: false);

        $this->assets['with'] = $this->makeAsset($photos, 'with.png');
        $this->assets['without'] = $this->makeAsset($photos, 'without.png');
        $this->assets['logo'] = $this->makeAsset($logos, 'logo.png');

        $with = Asset::find()->id($this->assets['with']->id)->one();
        $with->alt = 'A gravel path between box hedges';
        Craft::$app->getElements()->saveElement($with);
    }

    private function volumeWith(string $handle, bool $alt): \craft\models\Volume
    {
        $volume = $this->makeVolume($handle);
        $layout = new FieldLayout(['type' => Asset::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new AssetTitleField(), ...($alt ? [new AltField()] : [])]);
        $layout->setTabs([$tab]);
        $volume->setFieldLayout($layout);
        Craft::$app->getVolumes()->saveVolume($volume);

        return $volume;
    }

    protected function assetAlt(): AssetAlt
    {
        return new CraftAssetAlt();
    }

    protected function assetWithAlt(): AssetRef
    {
        return AssetRef::craft((int) $this->assets['with']->id);
    }

    protected function assetWithoutAlt(): AssetRef
    {
        return AssetRef::craft((int) $this->assets['without']->id);
    }

    protected function assetWithNoAltField(): AssetRef
    {
        return AssetRef::craft((int) $this->assets['logo']->id);
    }

    public function test_save_to_the_image_writes_it_and_gives_what_it_was(): void
    {
        $alt = new CraftAssetAlt();
        $before = $alt->save($alt->asset($this->assetWithoutAlt()), 'Stone samples laid out for a terrace');

        $this->assertSame('', $before);
        $this->assertSame('Stone samples laid out for a terrace', (new CraftAssetAlt())->altFor($this->assetWithoutAlt()));
        $this->assertNull($alt->altFor(AssetRef::craft(999999)));
    }
}
