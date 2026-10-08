<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use craft\ckeditor\Field as Ckeditor;
use craft\fields\Assets;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\AssetRefsContract;
use nineteenninetyfour\ghostwriter\gaps\CraftAssetRefs;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for finding the assets in a value: an Assets field's
 * IDs, and an image inline in CKEditor, stored as a reference tag, which
 * Craft's relations don't hold.
 */
class AssetRefsTest extends TestCase
{
    use AssetRefsContract;

    private ?Schema $schema = null;

    protected function assetRefs(): AssetRefs
    {
        return new CraftAssetRefs();
    }

    protected function assetInAField(): array
    {
        $field = $this->schema()->field('cover');
        $asset = $this->makeAsset(\Craft::$app->getVolumes()->getVolumeByHandle('images'), 'hero.png');

        return [$field, [(int) $asset->id], AssetRef::craft((int) $asset->id)];
    }

    protected function assetInlineInRichText(): array
    {
        $field = $this->schema()->field('body');
        $asset = $this->makeAsset(\Craft::$app->getVolumes()->getVolumeByHandle('images'), 'inline.png');
        $html = "<p>Before the picture</p><figure class=\"image\"><img src=\"{asset:{$asset->id}:transform:wide||https://example.test/inline.png}\" alt=\"\"></figure><p>and after, with <a href=\"{asset:999:url}\">a link to a file</a>.</p>";

        return [$field, $html, AssetRef::craft((int) $asset->id)];
    }

    public function testARefNamesItsFileForTheReviewer(): void
    {
        $field = $this->schema()->field('cover');
        $asset = $this->makeAsset(\Craft::$app->getVolumes()->getVolumeByHandle('images'), 'hero.png');
        $html = "<p><img src=\"{asset:{$asset->id}:url||https://example.test/hero.png}\"></p>";

        $this->assertSame([(string) $asset->filename], array_map(fn(AssetRef $ref) => $ref->filename(), (new CraftAssetRefs())->in([(int) $asset->id], $field)));
        $this->assertSame([(string) $asset->filename], array_map(fn(AssetRef $ref) => $ref->filename(), (new CraftAssetRefs())->in($html, $this->schema()->field('body'))));
    }

    public function testEveryInlineImageIsFoundInOrderButNotLinksToFiles(): void
    {
        $html = '<p><img src="{asset:12:url||https://x.test/a.jpg}"> <a href="{asset:13:url}">file</a> <img alt="" src=\'{asset:14@1:url}\'></p>';

        $this->assertSame([12, 14], array_map(fn(AssetRef $ref) => $ref->id, (new CraftAssetRefs())->in($html, $this->schema()->field('body'))));
    }

    private function schema(): Schema
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $volume = $this->makeVolume();
        $cover = $this->makeField(Assets::class, 'cover', ['sources' => ['volume:' . $volume->uid]]);
        $section = $this->makeSection('stories', [$this->makeEntryType('story', [$cover, $this->makeField(Ckeditor::class, 'body')])]);

        return $this->schema = (new SchemaReader())->schema($section->getEntryTypes()[0]);
    }
}
