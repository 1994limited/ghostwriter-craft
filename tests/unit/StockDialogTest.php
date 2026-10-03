<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Volume;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use nineteenninetyfour\ghostwriter\controllers\ImagesController;
use nineteenninetyfour\ghostwriter\ImageButton;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The image dialog with stock libraries: "Search in", remembered per
 * person; paid results in their library's order and never shown to a
 * model; the editorial filter; the chips; "Insert preview" for a paid
 * photo (a stand-in asset, the comp kept privately, a ledger preview).
 */
class StockDialogTest extends TestCase
{
    private Section $stories;

    private Volume $volume;

    private Assets $cover;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->openverse = true;
        $this->plugin->getSettings()->stockLibraries = [];
        $this->plugin->stockLibraries->reset();

        $this->volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $this->volume->uid], 'defaultUploadLocationSource' => 'volume:' . $this->volume->uid];
        $this->cover = $this->makeField(Assets::class, 'cover', $sources + ['maxRelations' => 1]);
        $this->stories = $this->makeSection('stories', [$this->makeEntryType('story', [$this->cover, $this->makeField(PlainText::class, 'intro')])]);
    }

    public function testTheButtonOffersWhereToSearchAndRemembersEachPersonsChoice(): void
    {
        $user = $this->signIn();
        $draft = $this->newDraft($this->stories);
        $config = $this->config(ImageButton::htmlFor($this->cover, $draft, false));

        $this->assertSame(['free', 'demo', 'everything'], array_column($config['sources'], 'value'));
        $this->assertSame(['free' => false, 'demo' => true, 'everything' => true], array_column($config['sources'], 'editorial', 'value'), 'Which sources the editorial filter applies to.');
        $this->assertSame('free', $config['source'], 'The site\'s default until the person chooses.');
        $this->assertFalse($config['editorial']);

        $this->search($draft, 'demo', 'roof garden');

        $this->assertSame('demo', Craft::$app->getUsers()->getUserPreferences($user->id)[ImagesController::SOURCE_PREFERENCE] ?? null);
        $this->assertSame('demo', $this->config(ImageButton::htmlFor($this->cover, $draft, false))['source']);

        // A library switched off since is not where the next search goes.
        $this->plugin->getSettings()->stockLibraries = ['demo' => false];
        $this->plugin->stockLibraries->reset();
        $this->assertSame('free', ImagesController::rememberedSource());
    }

    public function testAPaidLibrarysResultsAreInItsOwnOrderAndNeverJudged(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);

        $found = $this->search($draft, 'demo', 'roof garden');

        $this->assertSame('ready', $found['status']);
        $this->assertSame(['roof garden'], $found['terms']);
        // A page of nine, with the editorial-only one among them left out unless asked for.
        $this->assertCount(8, $found['options']);
        $this->assertSame('demo-01', $found['options'][0]['id']);
        $this->assertFalse($found['judged']);
        $this->assertSame(['Demo stock'], $found['paidLibraries']);
        $this->assertSame([], $this->fake->prompted('photo-picker'), 'No model sees a paid library\'s photos.');

        $card = $found['options'][0];
        $this->assertTrue($card['paid']);
        $this->assertSame(['Demo stock', '1 download', false, false], [$card['source_label'], $card['cost'], $card['picked'], $card['editorial']]);
        $this->assertStringContainsString('ghostwriter/stock/demo-thumb', $card['thumb']);

        $editorial = $this->search($draft, 'demo', 'roof garden', editorial: true);
        $this->assertCount(9, $editorial['options']);
        $flower = array_values(array_filter($editorial['options'], fn($option) => $option['editorial']))[0];
        $this->assertSame('Editorial use only. Not for advertising or promotion.', $flower['restrictions']);
    }

    public function testEverythingListsTheJudgedFreePhotosThenThePaidOnes(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);

        $this->fake->respond('photo-picker', '1: just right');
        $this->http->append(new Response(200, [], json_encode(['results' => [['id' => 'p1', 'thumbnail' => 'https://example.com/p1.jpg', 'creator' => 'Ann', 'license' => 'cc0']]])));
        $this->http->append(new Response(200, ['Content-Type' => 'image/png'], $this->png()), new Response(200, ['Content-Type' => 'image/png'], $this->png()));

        $found = $this->search($draft, 'everything', 'roof garden');

        $this->assertSame(['openverse', 'demo'], array_values(array_unique(array_column($found['options'], 'source'))));
        $this->assertSame('p1', $found['options'][0]['id']);
        $this->assertTrue($found['options'][0]['picked']);
        $this->assertSame('Free', $found['options'][0]['cost']);
        $this->assertTrue($found['judged']);
        $this->assertCount(1, $this->fake->prompted('photo-picker'));
        $this->assertCount(1, $this->fake->prompted('photo-picker')[0]->images, 'Only the free photo was shown to the model.');
    }

    public function testInsertPreviewPutsAStandInInTheFieldAndKeepsTheCompPrivately(): void
    {
        $user = $this->signIn();
        $draft = $this->newDraft($this->stories);
        $found = $this->search($draft, 'demo', 'roof garden');

        $used = $this->action('ghostwriter/images/use', ['id' => $found['id'], 'source' => 'demo', 'photo' => 'demo-05']);

        $this->assertSame(200, $used['status'], json_encode($used['data']));
        $this->assertTrue($used['data']['preview']);

        // The asset is the stand-in: a JPEG at the photo's aspect ratio
        // (portrait), named, titled and described from the photo.
        $asset = Asset::find()->id($used['data']['assetId'])->one();
        $this->assertSame('a-gardener-planting-a-hedge-demo-demo-05.jpg', $asset->filename);
        $this->assertSame('A gardener planting a hedge', $asset->title);
        $this->assertSame('A gardener planting a hedge', $asset->alt);
        $this->assertSame([1200, 1600], [(int) $asset->width, (int) $asset->height]);

        // The ledger has a preview, with its comp kept in Ghostwriter's own
        // files (never as an asset) for the library's 30 days.
        $image = $this->plugin->stockImages->forAsset(AssetRef::craft((int) $asset->id));
        $this->assertNotNull($image);
        $this->assertSame(StockImage::PREVIEW, $image->state());
        $this->assertSame(['demo', 'demo-05'], [$image->library, $image->externalId]);
        $this->assertTrue($image->noModelInput);
        $this->assertStringStartsWith('stock-comp-', (string) $image->comp());
        $this->assertSame('image/png', $this->plugin->store->file((string) $image->comp())['mime'] ?? null);
        $this->assertSame((new \DateTimeImmutable('+30 days'))->format('Y-m-d'), $image->compKeepUntil()?->format('Y-m-d'));
        $this->assertSame((string) $user->id, (string) $image->insertedBy?->id);
        $this->assertSame([[(string) $draft->id, 'cover']], array_map(fn($usage) => [(string) $usage->ownerId, $usage->field], $image->usages()));

        // Image requests are cleared after a day; comps are kept for their period.
        $this->plugin->imageStore->clearOlderThan(new \DateTimeImmutable('+2 days'));
        $this->assertNotNull($this->plugin->store->file((string) $image->comp()));
    }

    public function testAPaidPhotoIsLookedUpAgainAndNeverTakenFromTheBrowser(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);
        $found = $this->search($draft, 'demo', 'roof garden');

        $used = $this->action('ghostwriter/images/use', ['id' => $found['id'], 'source' => 'demo', 'photo' => '../../etc/passwd']);

        $this->assertSame(422, $used['status']);
        $this->assertSame('That photograph could not be found.', $used['data']['message']);
        $this->assertSame(0, (int) (new \craft\db\Query())->from(Store::STOCK_IMAGES)->count());
    }

    /**
     * Search, run the queue, and give back what the dialog then shows.
     *
     * @return array<string, mixed>
     */
    private function search(\craft\elements\Entry $draft, string $source, string $words, bool $editorial = false): array
    {
        $started = $this->action('ghostwriter/images/start', ['fieldId' => $this->cover->id, 'elementId' => $draft->id, 'siteId' => $draft->siteId, 'mode' => 'find', 'words' => $words, 'source' => $source, 'editorial' => $editorial ? '1' : '0']);
        $this->assertSame(200, $started['status'], json_encode($started['data']));
        $this->runQueue();

        return $this->action('ghostwriter/images/status', ['id' => $started['data']['id']], 'GET')['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $html): array
    {
        preg_match('/data-ghostwriter-image="([^"]+)"/', $html, $match);

        return json_decode(html_entity_decode($match[1] ?? '{}'), true);
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
