<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Volume;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\StockMarkers;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * No page goes live holding a preview (§7.1), the Overview tile and the
 * ledger screen (§7.3), and cleanup and reconciling (§7.4).
 */
class StockPublishTest extends TestCase
{
    private Section $stories;

    private Volume $volume;

    private Assets $cover;

    private FakeLibrary $demo;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $settings = $this->plugin->getSettings();
        $settings->stockLibraries = [];
        $settings->stockOnPublish = 'block';
        $this->plugin->stockLibraries->reset();
        $this->plugin->stockComps->reset();
        StockMarkers::reset();
        $this->demo = $this->plugin->stockLibraries->demo();

        $this->volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $this->volume->uid], 'defaultUploadLocationSource' => 'volume:' . $this->volume->uid];
        $this->cover = $this->makeField(Assets::class, 'cover', $sources + ['maxRelations' => 1]);
        $picture = $this->makeField(Assets::class, 'picture', $sources + ['maxRelations' => 1]);
        $feature = $this->makeEntryType('feature', [$this->makeField(PlainText::class, 'heading'), $picture], hasTitle: false);
        $this->stories = $this->makeSection('stories', [$this->makeEntryType('story', [$this->cover, $this->makeMatrix('blocks', [$feature])])]);
    }

    public function testALiveSaveHoldingAPreviewIsRefusedOnTheField(): void
    {
        $this->signIn();
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $asset = $this->previewFor($entry);

        $entry->setFieldValue('cover', [$asset->id]);
        $entry->setScenario(Element::SCENARIO_LIVE);

        $this->assertFalse(Craft::$app->getElements()->saveElement($entry));
        $this->assertSame('The cover is a Demo stock preview, not licensed yet. License it, or choose another image, before publishing.', $entry->getFirstError('cover'));
    }

    public function testDraftsAndDisabledEntriesSaveFreely(): void
    {
        $user = $this->signIn();
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $asset = $this->previewFor($entry);

        // The person's own draft of the live entry ("edited, not saved").
        $draft = Craft::$app->getDrafts()->createDraft($entry, $user->id, null, null, [], true);
        $draft->setFieldValue('cover', [$asset->id]);
        $draft->setScenario(Element::SCENARIO_LIVE);
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft), json_encode($draft->getErrors()));

        // A disabled entry, however saved.
        $disabled = $this->makeEntry($this->stories, 'Not yet', live: false);
        $disabled->setFieldValue('cover', [$asset->id]);
        $disabled->setScenario(Element::SCENARIO_LIVE);
        $this->assertTrue(Craft::$app->getElements()->saveElement($disabled), json_encode($disabled->getErrors()));
    }

    public function testApplyingADraftHoldingAPreviewIsRefused(): void
    {
        $user = $this->signIn();
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $asset = $this->previewFor($entry);

        $draft = Craft::$app->getDrafts()->createDraft($entry, $user->id, null, null, [], true);
        $draft->setFieldValue('cover', [$asset->id]);
        $draft->setScenario(Element::SCENARIO_ESSENTIALS);
        Craft::$app->getElements()->saveElement($draft);

        // As Craft's Save button does with a provisional draft.
        $draft = Entry::find()->id($draft->id)->drafts(null)->provisionalDrafts(null)->status(null)->one();
        $draft->setScenario(Element::SCENARIO_LIVE);

        try {
            Craft::$app->getDrafts()->applyDraft($draft);
            $this->fail('Applying the draft should be refused.');
        } catch (\Throwable $refused) {
            $this->assertStringContainsString('cover', $refused->getMessage() . json_encode($draft->getErrors()));
        }

        $this->assertNotContains((int) $asset->id, Entry::find()->id($entry->id)->one()->getFieldValue('cover')->ids(), 'The live entry still has no preview.');
    }

    public function testAPreviewInABlockIsFoundToo(): void
    {
        $this->signIn();
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $asset = $this->previewFor($entry);

        $entry->setFieldValue('blocks', ['entries' => ['new1' => ['type' => 'feature', 'enabled' => true, 'fields' => ['heading' => 'Rocks', 'picture' => [$asset->id]]]], 'sortOrder' => ['new1']]);
        $entry->setScenario(Element::SCENARIO_LIVE);

        $this->assertFalse(Craft::$app->getElements()->saveElement($entry));
        $this->assertSame('The “Feature: Picture” image is a Demo stock preview, not licensed yet. License it, or choose another image, before publishing.', $entry->getFirstError('blocks'));
    }

    public function testWarnSavesAndSaysSo(): void
    {
        $this->signIn();
        $this->plugin->getSettings()->stockOnPublish = 'warn';
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $asset = $this->previewFor($entry);

        $entry->setFieldValue('cover', [$asset->id]);
        $entry->setScenario(Element::SCENARIO_LIVE);

        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));
        $this->assertSame([], $entry->getErrors());
    }

    public function testALicensedImageIsNoBar(): void
    {
        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $asset = $this->previewFor($entry);
        $image = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $asset->id));

        $this->assertSame(200, $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard'])['status']);

        $entry->setFieldValue('cover', [$asset->id]);
        $entry->setScenario(Element::SCENARIO_LIVE);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), json_encode($entry->getErrors()));
    }

    public function testTheOverviewAndTheLedgerScreenListThem(): void
    {
        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $first = $this->previewFor($entry);
        $second = $this->previewFor($entry, 'demo-04');
        $licensed = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $second->id));
        $this->action('ghostwriter/stock/license', ['id' => $licensed->id, 'option' => 'demo-standard']);

        $overview = $this->action('ghostwriter/dashboard/index', [], 'GET', false)['data'];
        $this->assertSame(['previews' => 1, 'live' => 0], $overview['variables']['stock']);

        $screen = $this->action('ghostwriter/stock/index', ['tab' => 'previews'], 'GET', false)['data'];
        $this->assertSame('ghostwriter/stock', $screen['template']);
        $this->assertSame(['previews' => 1, 'licensed' => 1, 'failed' => 0, 'all' => 2], $screen['variables']['tabCounts']);
        $this->assertSame([(int) $first->id], array_column($screen['variables']['rows'], 'assetId'));
        $this->assertTrue($screen['variables']['rows'][0]['mayLicense']);
        $this->assertStringContainsString('ghostwriter/stock/', (string) $screen['variables']['rows'][0]['thumb']);

        $html = Craft::$app->getView()->renderTemplate($screen['template'], $screen['variables'], \craft\web\View::TEMPLATE_MODE_CP);
        $this->assertStringContainsString('Preview · not licensed', $html);
        $this->assertStringContainsString('Export CSV', $html);

        // The CSV: one row per record, with its licence and where it's used.
        $csv = $this->sent($this->raw('ghostwriter/stock/export', ['tab' => 'all']));
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('Date,State,Library,ID,Title,"Licence or order ID"', $lines[0]);
        $this->assertStringContainsString('demo-order-', $csv);
    }

    public function testRequestedLicencesComeFirstAndPreviewsCanBeRemoved(): void
    {
        $this->signIn();
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $older = $this->previewFor($entry);
        $newer = $this->previewFor($entry, 'demo-04');
        $olderImage = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $older->id));
        $this->action('ghostwriter/stock/request', ['id' => $olderImage->id]);

        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        $rows = $this->action('ghostwriter/stock/index', [], 'GET', false)['data']['variables']['rows'];
        $this->assertSame([(int) $older->id, (int) $newer->id], array_column($rows, 'assetId'), 'A requested licence at the top.');

        $removed = $this->action('ghostwriter/stock/remove', ['id' => $olderImage->id]);
        $this->assertSame(200, $removed['status']);
        $this->assertSame(StockImage::REMOVED, $this->plugin->stockImages->find($olderImage->id)?->state());
        $this->assertNull(Asset::find()->id($older->id)->one(), 'The stand-in has gone.');
    }

    public function testCleanupExpiresCompsRemovesUnusedPreviewsAndSettlesUncertainLicences(): void
    {
        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        $entry = $this->makeEntry($this->stories, 'Rocks');
        $used = $this->previewFor($entry);
        $unused = $this->previewFor($entry, 'demo-04');
        $uncertain = $this->previewFor($entry, 'demo-05');
        $usedImage = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $used->id));
        $unusedImage = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $unused->id));
        $uncertainImage = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $uncertain->id));

        // In use on a draft of the entry; the other two nowhere.
        $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId(), null, null, [], true);
        $draft->setFieldValue('cover', [$used->id]);
        $draft->setScenario(Element::SCENARIO_ESSENTIALS);
        Craft::$app->getElements()->saveElement($draft);
        $this->plugin->domain->stock()->syncUsages('entry', $entry->id, (string) $entry->siteId, []);
        $this->plugin->stockUsages->sync((int) $entry->id);
        Craft::$app->getDb()->createCommand()->update(Store::STOCK_USAGES, ['ownerId' => '0'], ['stockImageId' => [$unusedImage->id, $uncertainImage->id]])->execute();
        $this->forgetUsages([$unusedImage->id, $uncertainImage->id]);

        // A licence bought, but the answer lost.
        $this->demo->licenceOutcomes(FakeLibrary::UNCERTAIN_CHARGED);
        $this->action('ghostwriter/stock/license', ['id' => $uncertainImage->id, 'option' => 'demo-standard']);
        $this->assertSame(StockImage::LICENSING, $this->plugin->stockImages->find($uncertainImage->id)?->state());

        $done = $this->plugin->stockCleanup->run(new \DateTimeImmutable('+31 days'));

        // Comps past their 30 days go; the stand-in in use stays.
        $this->assertNull($this->plugin->stockImages->find($usedImage->id)?->comp());
        $this->assertSame(StockImage::PREVIEW, $this->plugin->stockImages->find($usedImage->id)?->state());
        $this->assertNotNull(Asset::find()->id($used->id)->one());

        // A preview nothing uses, for 30 days: removed.
        $this->assertSame(StockImage::REMOVED, $this->plugin->stockImages->find($unusedImage->id)?->state());
        $this->assertNull(Asset::find()->id($unused->id)->one());

        // The lost answer settled from the library's licences: licensed and in place, bought once.
        $settled = $this->plugin->stockImages->find($uncertainImage->id);
        $this->assertSame(StockImage::LICENSED, $settled?->state());
        $this->assertTrue($settled?->isReplaced());
        $this->assertSame(1, $this->demo->licenceCalls('demo-05'));
        $this->assertSame(1, $done['reconciled']);
        $this->assertGreaterThanOrEqual(1, $done['expired']);
    }

    /**
     * A demo photo inserted as a preview into the entry's cover.
     */
    private function previewFor(Entry $entry, string $id = 'demo-03'): Asset
    {
        $slot = ImageSlot::for($this->cover, $entry);
        $asset = $this->plugin->imagePicker->insertPreview($slot, $this->demo, $id);
        $this->plugin->stockComps->reset();

        return $asset;
    }

    /**
     * Take the records' usages away, as if no entry had them.
     *
     * @param array<int, string> $ids
     */
    private function forgetUsages(array $ids): void
    {
        foreach ($ids as $id) {
            $image = $this->plugin->stockImages->find($id);

            foreach ($image->usages() as $usage) {
                $this->plugin->domain->stock()->syncUsages($usage->ownerType, $usage->ownerId, $usage->site, []);
            }
        }
    }

    private function raw(string $route, array $params): \craft\web\Response
    {
        $this->action($route, $params, 'GET');

        return Craft::$app->getResponse();
    }

    private function sent(\craft\web\Response $response): string
    {
        return (string) $response->content;
    }
}
