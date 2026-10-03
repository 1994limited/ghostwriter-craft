<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use nineteenninetyfour\ghostwriter\events\RegisterStockLibrariesEvent;
use nineteenninetyfour\ghostwriter\stock\StockLibraries;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use yii\base\Event;

/**
 * Which photo libraries there are beyond the free ones: the demo library
 * only where it may be, paid libraries when set up and switched on, and
 * the settings' Stock photos section.
 */
class StockLibrariesTest extends TestCase
{
    private StockLibraries $libraries;

    protected function _before(): void
    {
        parent::_before();

        $this->libraries = $this->plugin->stockLibraries;
        $this->libraries->reset();
        $settings = $this->plugin->getSettings();
        $settings->stockLibraries = [];
        $settings->stockDemo = false;
        $settings->stockDefaultSource = 'free';
    }

    protected function _after(): void
    {
        Craft::$app->getConfig()->getGeneral()->devMode = true;
        Event::off(StockLibraries::class, StockLibraries::EVENT_REGISTER_LIBRARIES);
        putenv('CRAFT_ENVIRONMENT');
        $this->libraries->reset();

        parent::_after();
    }

    public function testTheDemoLibraryIsOfferedInDevModeOrWhenAskedForButNeverInProduction(): void
    {
        $general = Craft::$app->getConfig()->getGeneral();

        $general->devMode = true;
        $this->assertTrue($this->libraries->demoAllowed());
        $this->assertSame(['demo'], array_keys($this->libraries->paid()));
        $this->assertSame('Demo stock (no charge)', $this->libraries->get('demo')?->label());

        $general->devMode = false;
        $this->libraries->reset();
        $this->assertFalse($this->libraries->demoAllowed());
        $this->assertSame([], $this->libraries->paid());

        $this->plugin->getSettings()->stockDemo = true;
        $this->assertTrue($this->libraries->demoAllowed(), 'An explicit config flag offers it outside dev mode.');

        putenv('CRAFT_ENVIRONMENT=production');
        $general->devMode = true;
        $this->libraries->reset();
        $this->assertFalse($this->libraries->demoAllowed(), 'Never in production, whatever the flag or dev mode say.');
        $this->assertSame([], $this->libraries->all());
    }

    public function testTheDemoLibrarySellsPreviewsAndKeepsThemFromModels(): void
    {
        $demo = $this->libraries->demo();
        $capabilities = $demo->capabilities();

        $this->assertFalse($capabilities->free);
        $this->assertFalse($capabilities->mayRank);
        $this->assertTrue($capabilities->noModelInput);
        $this->assertSame(Capabilities::STORAGE_PRIVATE, $capabilities->previewStorage);
        $this->assertSame(30, $capabilities->previewKeepDays);

        $photos = $demo->search(new \NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery('roof garden', perPage: 50));
        $this->assertCount(12, $photos);
        $this->assertSame(2, count(array_filter($photos, fn($photo) => $photo->editorial)));
        $this->assertSame('1 download', $photos[0]->offer()->label());
        $this->assertSame(['demo-standard', 'demo-extended'], array_map(fn($quote) => $quote->option, $demo->quotes('demo-01')));
    }

    public function testASwitchedOffOrUnconfiguredLibraryIsNotSearched(): void
    {
        $this->plugin->getSettings()->stockLibraries = ['demo' => false];
        $this->assertSame([], $this->libraries->paid());
        $this->assertArrayHasKey('demo', $this->libraries->all(), 'Still listed in the settings, to switch back on.');
        $this->assertSame([['value' => 'free', 'label' => 'Free libraries']], $this->libraries->sourceOptions(true));

        // A registered library without its keys: managers are told where to set it up.
        $this->plugin->getSettings()->stockLibraries = [];
        $unset = new FakeLibrary('getty', 'Getty Images');
        $unset->available = false;
        Event::on(StockLibraries::class, StockLibraries::EVENT_REGISTER_LIBRARIES, fn(RegisterStockLibrariesEvent $event) => $event->libraries[] = $unset);
        $this->libraries->reset();

        $this->assertSame(['demo'], array_keys($this->libraries->paid()));
        $this->assertSame(
            ['free' => 'Free libraries', 'demo' => 'Demo stock (no charge)', 'getty' => 'Getty Images (connect in Settings)', 'everything' => 'Everything'],
            array_column($this->libraries->sourceOptions(true), 'label', 'value'),
        );
        $this->assertSame(['free', 'demo', 'everything'], array_column($this->libraries->sourceOptions(false), 'value'), 'Hidden from everyone else.');
        $this->assertSame('free', $this->libraries->normaliseSource('getty'));
        $this->assertSame('demo', $this->libraries->normaliseSource('demo'));
    }

    public function testTheSettingsListEachLibrarysKeysWithoutTheirValues(): void
    {
        $this->signIn(admin: true);
        putenv('SHUTTERSTOCK_API_KEY=sk-test-never-shown');
        putenv('SHUTTERSTOCK_API_SECRET');

        try {
            $html = Craft::$app->getView()->renderTemplate('ghostwriter/_settings', [
                'settings' => $this->plugin->getSettings(),
                'sections' => [],
                'keys' => [],
                'overrides' => [],
                'modelDefaults' => [],
                'stock' => (fn() => $this->stockSettings())->call($this->plugin),
            ], \craft\web\View::TEMPLATE_MODE_CP);
        } finally {
            putenv('SHUTTERSTOCK_API_KEY');
        }

        $this->assertStringContainsString('Stock photos', $html);
        $this->assertStringContainsString('Demo stock (no charge)', $html);
        $this->assertStringContainsString('Getty Images and iStock', $html);
        $this->assertMatchesRegularExpression('#<code>SHUTTERSTOCK_API_KEY</code>\s*<span class="status green"#', $html);
        $this->assertMatchesRegularExpression('#<code>SHUTTERSTOCK_API_SECRET</code>\s*<span class="status"#', $html);
        $this->assertStringNotContainsString('sk-test-never-shown', $html);
        $this->assertStringContainsString('Connect account', $html);
        $this->assertStringContainsString('name="stockLibraries[demo]"', $html);
        $this->assertStringContainsString('name="onUnfinishedPublish"', $html);
        $this->assertStringContainsString('name="stockIncludeEditorial"', $html);
    }

    public function testCheckConnectionShowsTheAccountForManagersOnly(): void
    {
        $this->signIn(admin: true);
        $checked = $this->action('ghostwriter/stock/check', ['library' => 'demo']);

        $this->assertSame(200, $checked['status'], json_encode($checked['data']));
        $this->assertSame('Connected as Demo account', $checked['data']['account']);
        $this->assertSame(['Demo pack, 100 downloads left'], $checked['data']['products']);

        $this->assertSame(404, $this->action('ghostwriter/stock/check', ['library' => 'shutterstock'])['status'], 'Not until core has its adapter.');

        $this->signIn();
        $this->assertSame(403, $this->action('ghostwriter/stock/check', ['library' => 'demo'])['status']);
    }

    public function testStockSettingsAreValidated(): void
    {
        $settings = $this->plugin->getSettings();
        $settings->setAttributes(['stockLibraries' => ['demo' => '', 'getty' => '1', '../x' => '1'], 'stockOnPublish' => 'warn'], false);

        $this->assertSame(['demo' => false, 'getty' => true], $settings->stockLibraries);
        $this->assertFalse($settings->blocksPreviewsOnPublish());

        $settings->stockOnPublish = 'publish anyway';
        $this->assertFalse($settings->validate(['stockOnPublish']));
        $settings->stockOnPublish = 'block';
        $this->assertTrue($settings->blocksPreviewsOnPublish());

        // "Finish this page"'s setting wins; the stock one is read when it isn't set.
        $settings->onUnfinishedPublish = 'warn';
        $this->assertFalse($settings->blocksPreviewsOnPublish());
        $this->assertSame(\NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish::Warn, $settings->onPublish());
        $settings->onUnfinishedPublish = 'sometimes';
        $this->assertFalse($settings->validate(['onUnfinishedPublish']));
        $settings->onUnfinishedPublish = null;
        $this->assertTrue($settings->validate(['onUnfinishedPublish']));
        $this->assertTrue($settings->blocksPreviewsOnPublish());
    }
}
