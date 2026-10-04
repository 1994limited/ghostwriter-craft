<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\helpers\FileHelper;
use craft\models\Section;
use craft\models\Volume;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\images\ImageSampler;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\migrations\LedgerExport;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The stock image ledger in Craft: a free photo used is recorded as
 * licensed, where each ledger image is used is read from Craft's
 * relations, a deleted asset's record is removed with its licence kept,
 * Getty and iStock images never reach a model, and uninstalling keeps the
 * ledger in a file.
 */
class StockLedgerTest extends TestCase
{
    private Section $stories;

    private Volume $volume;

    private Assets $cover;

    private Assets $picture;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->openverse = true;
        $this->volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $this->volume->uid], 'defaultUploadLocationSource' => 'volume:' . $this->volume->uid];

        $this->cover = $this->makeField(Assets::class, 'cover', $sources + ['maxRelations' => 1]);
        $this->picture = $this->makeField(Assets::class, 'picture', $sources + ['maxRelations' => 1]);
        $feature = $this->makeEntryType('feature', [$this->makeField(PlainText::class, 'heading'), $this->picture], hasTitle: false);

        $this->stories = $this->makeSection('stories', [$this->makeEntryType('story', [$this->cover, $this->makeMatrix('blocks', [$feature])])]);
        $this->plugin->stockUsages->forget();
    }

    public function testUsingAFreePhotoRecordsItAsLicensedWhereItIsUsed(): void
    {
        $user = $this->signIn();
        $draft = $this->newDraft($this->stories);
        $slot = ImageSlot::for($this->cover, $draft, $user);

        $this->http->append(
            new Response(200, [], json_encode(['id' => 'c1', 'url' => 'https://example.com/full.png', 'thumbnail' => 'https://example.com/c1.jpg', 'title' => 'Potter mending a bowl', 'creator' => 'Ann', 'license' => 'cc0', 'foreign_landing_url' => 'https://example.com/c1'])),
            new Response(200, ['Content-Type' => 'image/png'], $this->png()),
        );

        $asset = $this->plugin->imagePicker->keepPhoto($slot, 'openverse', 'c1');
        $image = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $asset->id));

        $this->assertNotNull($image);
        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertSame(['openverse', 'c1'], [$image->library, $image->externalId]);
        $this->assertTrue($image->isReplaced());
        $this->assertFalse($image->noModelInput);
        $this->assertSame('Potter mending a bowl', $image->title);
        $this->assertSame((string) $user->id, (string) $image->insertedBy?->id);

        $this->assertCount(1, $image->usages());
        $usage = $image->usages()[0];
        $this->assertSame(['entry', (string) $draft->id, 'cover', 'Cover', false], [$usage->ownerType, (string) $usage->ownerId, $usage->field, $usage->label, $usage->live]);
    }

    public function testUsagesAreReadFromRelationsOnSaveForTheCanonicalEntry(): void
    {
        $this->signIn();
        $top = $this->makeAsset($this->volume, 'top.png');
        $inBlock = $this->makeAsset($this->volume, 'in-block.png');
        $plain = $this->makeAsset($this->volume, 'plain.png');
        $topRecord = $this->record($top);
        $blockRecord = $this->record($inBlock, 'getty');

        $entry = $this->makeEntry($this->stories, 'Rocks at dusk', [
            'cover' => [$top->id],
            'blocks' => ['entries' => ['new1' => ['type' => 'feature', 'enabled' => true, 'fields' => ['heading' => 'Rocks', 'picture' => [$inBlock->id]]]], 'sortOrder' => ['new1']],
        ]);

        $top = $this->stock()->get($topRecord->id);
        $this->assertSame([['cover', 'Cover', true]], array_map(fn($usage) => [$usage->field, $usage->label, $usage->live], $top->usages()));
        $this->assertSame((string) $entry->id, (string) $top->usages()[0]->ownerId, 'Kept against the canonical entry.');

        $block = $this->stock()->get($blockRecord->id);
        $this->assertSame([['blocks.feature.picture', 'Feature: Picture']], array_map(fn($usage) => [$usage->field, $usage->label], $block->usages()));
        $this->assertSame((string) $entry->id, (string) $block->usages()[0]->ownerId, 'A block\'s image is used on the entry it is in.');

        // A ledger filter by entry finds both; an asset not in the ledger is ignored.
        $this->assertCount(2, $this->plugin->stockImages->query(new StockImageQuery(ownerType: 'entry', ownerId: $entry->id))->images);
        $entry->setFieldValue('cover', [$plain->id]);
        Craft::$app->getElements()->saveElement($entry);

        $this->assertSame([], $this->stock()->get($topRecord->id)->usages(), 'Taken out of the field, it is no longer used there.');
        $this->assertNull($this->plugin->stockImages->forAsset(AssetRef::craft((int) $plain->id)));
    }

    public function testAnImageOnlyInADraftIsUsedOnItsCanonicalEntry(): void
    {
        $user = $this->signIn();
        $entry = $this->makeEntry($this->stories, 'Rain gardens');
        $asset = $this->makeAsset($this->volume, 'draft-only.png');
        $record = $this->record($asset);

        $draft = Craft::$app->getDrafts()->createDraft($entry, $user->id, null, null, [], true);
        $draft->setFieldValue('cover', [$asset->id]);
        $draft->setScenario(\craft\base\Element::SCENARIO_ESSENTIALS);
        Craft::$app->getElements()->saveElement($draft);

        $usages = $this->stock()->get($record->id)->usages();
        $this->assertCount(1, $usages);
        $this->assertSame([(string) $entry->id, 'cover'], [(string) $usages[0]->ownerId, $usages[0]->field]);

        // Discarding the draft takes the usage away.
        Craft::$app->getElements()->deleteElement($draft, true);
        $this->assertSame([], $this->stock()->get($record->id)->usages());
    }

    public function testADeletedAssetsRecordIsRemovedAndItsLicenceKept(): void
    {
        $this->signIn();
        $asset = $this->makeAsset($this->volume, 'gone.png');
        $record = $this->record($asset);

        Craft::$app->getElements()->deleteElement($asset, true);

        $removed = $this->stock()->get($record->id);
        $this->assertSame(StockImage::REMOVED, $removed->state());
        $this->assertTrue($removed->wasLicensed(), 'A licence stays on file.');
        $this->assertNull($this->plugin->stockImages->forAsset(AssetRef::craft((int) $asset->id)));
    }

    public function testGettyImagesNeverReachAModel(): void
    {
        $user = $this->signIn();
        $ledgered = $this->makeAsset($this->volume, 'rocks.png', 600, 400);
        $named = $this->makeAsset($this->volume, 'GettyImages-123456.png', 600, 400);
        $own = $this->makeAsset($this->volume, 'own.png', 600, 400);
        $this->record($ledgered, 'getty', paid: true);

        $sampler = new ImageSampler();
        $this->assertNull($sampler->small($ledgered), 'A Getty preview in the ledger is not sampled.');
        $this->assertNull($sampler->small($named), 'Nor is a file named as Getty names its downloads.');
        $this->assertNotNull($sampler->small($own));

        // Nor is either shown to the photo picker as a reference image.
        foreach (['Ledgered' => $ledgered, 'Named' => $named, 'Own' => $own] as $title => $asset) {
            $this->makeEntry($this->stories, $title, ['cover' => [$asset->id]]);
        }

        $slot = ImageSlot::for($this->cover, $this->newDraft($this->stories), $user);
        $this->assertCount(3, $slot->references());

        $this->fake->respond('photo-picker', '1: a fine one');
        $this->http->append(new Response(200, [], json_encode(['results' => [['id' => 'p1', 'thumbnail' => 'https://example.com/p1.jpg', 'creator' => 'Ann', 'license' => 'cc0']]])));
        $this->http->append(new Response(200, ['Content-Type' => 'image/png'], $this->png()));

        $this->plugin->imagePicker->find($slot, ['rocks']);

        $this->assertCount(1 + 1, $this->fake->prompted('photo-picker')[0]->images, 'Only the site\'s own image and the one candidate.');
    }

    public function testUninstallingKeepsTheLedgerInAFile(): void
    {
        $this->signIn();
        $this->record($this->makeAsset($this->volume, 'kept.png'), 'getty', paid: true);

        $path = LedgerExport::beforeUninstall(Craft::$app->getDb());

        try {
            $this->assertNotNull($path);
            $this->assertStringStartsWith(Craft::getAlias('@storage') . '/ghostwriter-stock-ledger-', $path);
            $saved = json_decode((string) file_get_contents($path), true);
            $this->assertCount(1, $saved['records']);
            $this->assertSame('getty', $saved['records'][0]['library']);
        } finally {
            FileHelper::unlink((string) $path);
        }
    }

    public function testTheLedgerTablesAreInstalled(): void
    {
        $this->assertSame('1.3.0', $this->plugin->schemaVersion);
        $this->assertTrue(Craft::$app->getDb()->tableExists(Store::STOCK_IMAGES));
        $this->assertTrue(Craft::$app->getDb()->tableExists(Store::STOCK_USAGES));
    }

    private function stock(): \NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages
    {
        return $this->plugin->domain->stock();
    }

    /**
     * A ledger record for an asset: a free photo, or a paid library's
     * preview.
     */
    private function record(Asset $asset, string $library = 'pixabay', bool $paid = false): StockImage
    {
        $photo = new Photo($library, 'x' . $asset->id, 'https://example.com/t.jpg', 'Kim Lee', null, 'Royalty-free', title: 'A photo', offer: $paid ? Offer::paid(Cost::units(1, Cost::DOWNLOAD)) : null);
        $stock = $this->stock();
        $image = $paid
            ? $stock->recordPreview($photo, ImagePicker::ref($asset), 'comp-1', new \DateTimeImmutable('+30 days'))
            : $stock->recordFree($photo, ImagePicker::ref($asset));
        $this->plugin->stockUsages->forget();

        return $image;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
