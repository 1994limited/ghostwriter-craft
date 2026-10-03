<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Volume;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ReplaceMeta;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\ImageButton;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\CraftAssetReplacer;
use nineteenninetyfour\ghostwriter\stock\StockMarkers;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Where editors see a comp (the CP-only route, and stand-in addresses
 * turned into it for the control panel and signed-in previews only), the
 * markers on the field and the asset, and License & replace: the option
 * checked again, bought once, the file swapped byte for byte with the
 * asset's ID, title, alt text and focal point kept; failures said plainly;
 * the licence permission; Request licence.
 */
class StockLicensingTest extends TestCase
{
    private Section $stories;

    private Volume $volume;

    private Assets $cover;

    private FakeLibrary $demo;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setIsLivePreview(false);
        $this->plugin->getSettings()->stockLibraries = [];
        $this->plugin->stockLibraries->reset();
        $this->plugin->stockComps->reset();
        StockMarkers::reset();
        $this->demo = $this->plugin->stockLibraries->demo();

        $this->volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $this->volume->uid], 'defaultUploadLocationSource' => 'volume:' . $this->volume->uid];
        $this->cover = $this->makeField(Assets::class, 'cover', $sources + ['maxRelations' => 1]);
        $volumeLayout = $this->volume->getFieldLayout();
        $tab = new \craft\models\FieldLayoutTab(['name' => 'Content', 'layout' => $volumeLayout]);
        $tab->setElements([new \craft\fieldlayoutelements\CustomField($this->makeField(PlainText::class, 'credit'))]);
        $volumeLayout->setTabs([$tab]);
        Craft::$app->getVolumes()->saveVolume($this->volume);

        $this->stories = $this->makeSection('stories', [$this->makeEntryType('story', [$this->cover])]);
    }

    public function testTheLicencePermissionIsRegisteredAndGivenToNobody(): void
    {
        $permissions = array_merge(...array_map(fn(array $group) => array_keys($group['permissions']), Craft::$app->getUserPermissions()->getAllPermissions()));

        $this->assertContains('ghostwriter:license', $permissions);
        $this->assertFalse($this->signIn()->can(Plugin::LICENSE_PERMISSION));
        $this->assertTrue($this->signIn(admin: true)->can(Plugin::LICENSE_PERMISSION));
    }

    public function testTheCompIsServedOnlyToSignedInEditorsAndNeverCached(): void
    {
        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        [$asset, $image] = $this->preview();

        $response = $this->raw("ghostwriter/stock/comp", ['id' => $image->id]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('private, no-store', $response->getHeaders()->get('Cache-Control'));
        $this->assertSame('noindex', $response->getHeaders()->get('X-Robots-Tag'));
        $this->assertSame($this->plugin->store->file((string) $image->comp())['content'], $this->sent($response));

        $this->signIn(permitted: false);
        $this->assertSame(403, $this->action('ghostwriter/stock/comp', [], 'GET', params: ['id' => $image->id])['status']);
    }

    public function testEditorsSeeTheCompWhereTheStandInIsAndEveryoneElseTheStandIn(): void
    {
        $user = $this->signIn();
        [$asset, $image] = $this->preview();
        $comp = "ghostwriter/stock/{$image->id}/comp";
        $request = Craft::$app->getRequest();
        $url = function() use ($asset): string {
            $this->plugin->stockComps->reset();

            return (string) Asset::find()->id($asset->id)->one()->getUrl();
        };

        // The control panel: the field's thumbnail and the asset editor.
        $this->assertStringContainsString($comp, $url());
        $this->assertStringContainsString($comp, (string) Craft::$app->getAssets()->getThumbUrl(Asset::find()->id($asset->id)->one(), 120), 'A thumbnail (a transform) is the comp at its own size.');

        // A front-end page: the stand-in.
        $request->setIsCpRequest(false);
        $this->assertStringEndsWith('/images/' . $asset->filename, $url());

        // Live Preview, signed in: the comp.
        $request->setIsLivePreview(true);
        $this->assertStringContainsString($comp, $url());

        // A share link opened by someone signed out: the stand-in.
        Craft::$app->getUser()->setIdentity(null);
        $this->assertStringNotContainsString($comp, $url());

        // Signed in, but without Ghostwriter: the stand-in.
        $this->signIn(permitted: false);
        $this->assertStringNotContainsString($comp, $url());
        Craft::$app->getUser()->setIdentity($user);
    }

    public function testTheFieldBadgeSaysWhatToDoForWhoeverIsLooking(): void
    {
        $this->signIn();
        [$asset, $image, $draft] = $this->preview();
        $draft->setFieldValue('cover', [$asset->id]);

        $badges = $this->config(ImageButton::htmlFor($this->cover, $draft, false))['stock'];
        $this->assertCount(1, $badges);
        $this->assertSame(['Preview · not licensed', 'Demo stock', 'demo-03', false, true], [$badges[0]['status'], $badges[0]['library'], $badges[0]['externalId'], $badges[0]['mayLicense'], $badges[0]['mayRequest']]);

        // Request licence: noted beside the record, once.
        $requested = $this->action('ghostwriter/stock/request', ['id' => $image->id]);
        $this->assertSame(200, $requested['status']);
        $this->assertFalse($requested['data']['badge']['mayRequest']);
        $this->assertNotNull($requested['data']['badge']['requested']);
        $this->assertArrayHasKey($image->id, $this->plugin->stockImages->requested());

        // Someone who may license gets License.
        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        $badges = $this->action('ghostwriter/stock/badges', ['assetIds' => [$asset->id]], 'GET')['data']['badges'];
        $this->assertTrue($badges[0]['mayLicense']);
    }

    public function testTheAssetShowsItsLicenceInItsSidebarAndTheIndex(): void
    {
        $this->signIn();
        [$asset] = $this->preview();

        $html = StockMarkers::sidebarHtml($asset);
        $this->assertStringContainsString('Stock licence', $html);
        $this->assertStringContainsString('data-ghostwriter-stock', $html);
        $this->assertStringContainsString('Preview · not licensed', html_entity_decode($html));

        $this->assertStringContainsString('Preview · not licensed', StockMarkers::columnHtml($asset));
        $this->assertStringContainsString('Demo stock', StockMarkers::columnHtml($asset));
        $this->assertSame('', StockMarkers::columnHtml($this->makeAsset($this->volume, 'own.png')));
    }

    public function testLicenseAndReplaceSwapsTheFileAndKeepsTheAsset(): void
    {
        $user = $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        [$asset, $image] = $this->preview();
        $comp = (string) $image->comp();

        // The editor's own alt text and focal point, set on the stand-in.
        $asset = Asset::find()->id($asset->id)->one();
        $asset->alt = 'A sedum roof, from the terrace';
        $asset->setFocalPoint(['x' => 0.3, 'y' => 0.7]);
        Craft::$app->getElements()->saveElement($asset);

        $quote = $this->action('ghostwriter/stock/quote', [], 'GET', params: ['id' => $image->id])['data'];
        $this->assertSame(['demo-standard', 'demo-extended'], array_column($quote['quotes'], 'option'));
        $this->assertSame('Uses 1 of your 100 remaining downloads (Demo pack)', $quote['quotes'][0]['cost']);
        $this->assertSame('Demo photographer/Demo stock', $quote['credit']);

        $licensed = $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-extended']);

        $this->assertSame(200, $licensed['status'], json_encode($licensed['data']));
        $this->assertSame('Licensed. The preview has been replaced with the full image.', $licensed['data']['message']);
        $this->assertSame(1, $this->demo->licenceCalls('demo-03'));

        $after = Asset::find()->id($asset->id)->one();
        $this->assertSame($asset->filename, $after->filename, 'Same asset, same name.');
        $this->assertSame('A sedum roof, from the terrace', $after->alt);
        $this->assertSame((string) $asset->title, (string) $after->title);
        $this->assertEqualsWithDelta(0.3, $after->getFocalPoint()['x'], 0.0001);
        $this->assertEqualsWithDelta(0.7, $after->getFocalPoint()['y'], 0.0001);
        $this->assertSame('Demo photographer/Demo stock (no charge)', $after->getFieldValue('credit'), 'The licence\'s credit line.');

        // The licensed file, exactly as the library sent it.
        $licence = $this->plugin->stockImages->find($image->id)?->licence();
        $this->assertNotNull($licence);
        $this->assertSame(md5($this->demo->download($licence)->content), md5((string) $after->getContents()));

        $record = $this->plugin->stockImages->find($image->id);
        $this->assertSame(StockImage::LICENSED, $record->state());
        $this->assertTrue($record->isReplaced());
        $this->assertSame('demo-extended', $record->quote()?->option);
        $this->assertSame((string) $user->id, (string) $record->history()[count($record->history()) - 1]->by?->id);
        $this->assertNull($this->plugin->store->file($comp), 'The comp goes once the licence is in.');

        // Never bought twice.
        $again = $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard']);
        $this->assertSame(409, $again['status']);
        $this->assertSame(1, $this->demo->licenceCalls('demo-03'));
    }

    public function testTheReplacerKeepsTheLibrarysBytesAndChangesNoExtensionsContent(): void
    {
        $this->signIn();
        $asset = $this->makeAsset($this->volume, 'stand-in.png', 800, 600);
        $jpeg = $this->jpegWithComment('Copyright Demo Images, image 42');

        $ref = (new CraftAssetReplacer())->replace(ImagePicker::ref($asset), new PhotoFile($jpeg, 'image/jpeg', 'jpg', $this->demo->photoFor('x')), new ReplaceMeta('ledger'));

        $after = Asset::find()->id($asset->id)->one();
        $this->assertSame((int) $asset->id, (int) $ref->id);
        $this->assertSame('stand-in.jpg', $after->filename, 'Another type keeps the name, with its own extension; nothing is converted.');
        $this->assertSame($jpeg, (string) $after->getContents(), 'Byte for byte: the embedded credit stays.');
    }

    public function testTheLicensedFilesTypeIsReadFromItsBytesNotTheLibrarysWord(): void
    {
        $this->signIn();
        $asset = $this->makeAsset($this->volume, 'said-jpeg.png', 800, 600);
        $png = $this->image('png');

        (new CraftAssetReplacer())->replace(ImagePicker::ref($asset), new PhotoFile($png, 'image/jpeg', 'jpg', $this->demo->photoFor('x')), new ReplaceMeta('ledger'));

        $after = Asset::find()->id($asset->id)->one();
        $this->assertSame('said-jpeg.png', $after->filename, 'A PNG said to be a JPEG keeps a .png name.');
        $this->assertSame($png, (string) $after->getContents());

        $this->assertSame(['image/jpeg', 'jpg'], CraftAssetReplacer::imageType($this->image('jpeg')));
        $this->assertSame(['image/png', 'png'], CraftAssetReplacer::imageType($png));
        $this->assertSame(['image/webp', 'webp'], CraftAssetReplacer::imageType($this->image('webp')));
    }

    public function testWebpIsPutInPlaceByteForByte(): void
    {
        $this->signIn();
        $asset = $this->makeAsset($this->volume, 'hero.png', 800, 600);
        $webp = $this->image('webp');

        (new CraftAssetReplacer())->replace(ImagePicker::ref($asset), new PhotoFile($webp, 'image/webp', 'webp', $this->demo->photoFor('x')), new ReplaceMeta('ledger'));

        $after = Asset::find()->id($asset->id)->one();
        $this->assertSame('hero.webp', $after->filename);
        $this->assertSame($webp, (string) $after->getContents());
    }

    #[\Codeception\Attribute\DataProvider('notAnImage')]
    public function testAnythingButAJpegPngOrWebpIsRefusedAndTheStandInStays(string $bytes, string $mime, string $extension): void
    {
        $this->signIn();
        $asset = $this->makeAsset($this->volume, 'stand-in.png', 800, 600);
        $before = (string) $asset->getContents();

        try {
            (new CraftAssetReplacer())->replace(ImagePicker::ref($asset), new PhotoFile($bytes, $mime, $extension, $this->demo->photoFor('x')), new ReplaceMeta('ledger'));
            $this->fail('A file that isn\'t a JPEG, PNG or WebP must be refused.');
        } catch (\RuntimeException $refused) {
            $this->assertSame('The file Demo stock sent isn’t a JPEG, PNG or WebP image, so it wasn’t put in place.', $refused->getMessage());
        }

        $after = Asset::find()->id($asset->id)->one();
        $this->assertSame('stand-in.png', $after->filename, 'The stand-in stays.');
        $this->assertSame($before, (string) $after->getContents());
        $this->assertSame([], glob(Craft::$app->getPath()->getTempPath() . '/ghostwriter-licensed-*') ?: [], 'Nothing was written.');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function notAnImage(): array
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><script>alert(document.cookie)</script><rect width="800" height="600"/></svg>';
        $html = "<!DOCTYPE html>\n<html><head><title>Service unavailable</title></head><body><h1>503</h1><script>fetch('/admin')</script></body></html>";

        return [
            'an SVG said to be a JPEG' => [$svg, 'image/jpeg', 'jpg'],
            'an SVG said to be an SVG' => [$svg, 'image/svg+xml', 'svg'],
            'an HTML page said to be a JPEG' => [$html, 'image/jpeg', 'jpg'],
            'an HTML page behind a JPEG signature' => ["\xFF\xD8\xFF" . $html, 'image/jpeg', 'jpg'],
            'a PNG signature with nothing after it' => ["\x89PNG\r\n\x1A\n", 'image/png', 'png'],
            'a GIF' => [base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'image/gif', 'gif'],
            'nothing' => ['', 'image/jpeg', 'jpg'],
        ];
    }

    public function testFailuresAreSaidPlainlyAndNothingIsBoughtTwice(): void
    {
        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        [, $image] = $this->preview();

        $this->assertSame(409, $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'made-up'])['status']);

        $this->demo->licenceOutcomes(new InsufficientBalance('No downloads left.'));
        $empty = $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard']);
        $this->assertSame('Your Demo stock account has nothing left to license this with. Nothing was charged.', $empty['data']['message']);
        $this->assertSame(StockImage::FAILED, $this->plugin->stockImages->find($image->id)?->state());

        $this->demo->licenceOutcomes(new QuoteChanged(quote: new Quote('demo-03', 'demo-standard', 'Standard', Cost::units(2, Cost::DOWNLOAD))));
        $changed = $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard']);
        $this->assertSame(409, $changed['status']);
        $this->assertSame('The price has changed: it is now 2 downloads. Check it and license again.', $changed['data']['message']);

        $this->demo->licenceOutcomes(FakeLibrary::UNCERTAIN_NOT_CHARGED);
        $uncertain = $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard']);
        $this->assertSame(409, $uncertain['status']);
        $this->assertSame('We couldn’t confirm the purchase. Ghostwriter will check with Demo stock in a few minutes; don’t buy it again.', $uncertain['data']['message']);
        $this->assertSame(StockImage::LICENSING, $this->plugin->stockImages->find($image->id)?->state());

        $refused = $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard']);
        $this->assertSame(409, $refused['status'], 'While the outcome is unknown, it is not bought again.');
        $this->assertSame(3, $this->demo->licenceCalls('demo-03'));
    }

    public function testLicensingNeedsThePermissionAndAnEditorialAcknowledgement(): void
    {
        $this->signIn();
        [, $image] = $this->preview();
        $this->assertSame(403, $this->action('ghostwriter/stock/license', ['id' => $image->id, 'option' => 'demo-standard'])['status']);
        $this->assertSame(403, $this->action('ghostwriter/stock/quote', [], 'GET', params: ['id' => $image->id])['status']);

        $this->signIn(extra: [Plugin::LICENSE_PERMISSION]);
        [, $editorial] = $this->preview('demo-09');
        $this->assertTrue($editorial->editorial);

        $refused = $this->action('ghostwriter/stock/license', ['id' => $editorial->id, 'option' => 'demo-standard']);
        $this->assertSame('This image is for editorial use only. Tick the box to confirm, then license it.', $refused['data']['message']);
        $this->assertSame(0, $this->demo->licenceCalls('demo-09'));

        $this->assertSame(200, $this->action('ghostwriter/stock/license', ['id' => $editorial->id, 'option' => 'demo-standard', 'acknowledge' => '1'])['status']);
    }

    public function testAnExpiredPreviewCanBeRefreshedOnce(): void
    {
        $this->signIn();
        [, $image] = $this->preview();
        $this->plugin->domain->stock()->compExpired($image->id);

        $refreshed = $this->action('ghostwriter/stock/refresh', ['id' => $image->id]);
        $this->assertSame(200, $refreshed['status'], json_encode($refreshed['data']));
        $this->assertNotNull($this->plugin->stockImages->find($image->id)?->comp());

        $this->plugin->domain->stock()->compExpired($image->id);
        $this->assertSame(409, $this->action('ghostwriter/stock/refresh', ['id' => $image->id])['status']);
    }

    /**
     * A demo photo inserted as a preview into a new entry's cover.
     *
     * @return array{0: Asset, 1: StockImage, 2: \craft\elements\Entry}
     */
    private function preview(string $id = 'demo-03'): array
    {
        $draft = $this->newDraft($this->stories);
        $slot = ImageSlot::for($this->cover, $draft, Craft::$app->getUser()->getIdentity());
        $asset = $this->plugin->imagePicker->insertPreview($slot, $this->demo, $id);
        $this->plugin->stockComps->reset();
        StockMarkers::reset();

        return [$asset, $this->plugin->stockImages->forAsset(AssetRef::craft((int) $asset->id)), $draft];
    }

    /**
     * Run an action and keep its response, for one that sends a file.
     */
    private function raw(string $route, array $params): \craft\web\Response
    {
        $this->action($route, [], 'GET', params: $params);

        return Craft::$app->getResponse();
    }

    private function sent(\craft\web\Response $response): string
    {
        if ($response->stream === null) {
            return (string) $response->content;
        }

        [$handle, $begin, $end] = $response->stream;
        fseek($handle, $begin);

        return (string) stream_get_contents($handle, $end - $begin + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $html): array
    {
        preg_match('/data-ghostwriter-image="([^"]+)"/', $html, $match);

        return json_decode(html_entity_decode($match[1] ?? '{}'), true);
    }

    private function image(string $type): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 90, 140));
        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($image, null, 80),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 80),
        };

        return (string) ob_get_clean();
    }

    private function jpegWithComment(string $comment): string
    {
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 140, 60));
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();

        // A COM segment straight after the start-of-image marker.
        return substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment . substr($jpeg, 2);
    }
}
