<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\db\Query;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Paid\Shutterstock;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use nineteenninetyfour\ghostwriter\controllers\LibrariesController;
use nineteenninetyfour\ghostwriter\events\RegisterStockLibrariesEvent;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\stock\StockLibraries;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use yii\base\Event;

/**
 * "Connect account" for a library that licenses with a person's own
 * sign-in, as core's docs/connecting-accounts.md sets out: a single-use
 * state checked before the code is exchanged, the same callback address
 * in both calls, tokens kept encrypted, Disconnect, managers only. And
 * Shutterstock, registered from its keys, in its sandbox by default in
 * dev mode.
 */
class ConnectAccountTest extends TestCase
{
    private FakeLibrary $library;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->library = new FakeLibrary('oauthdemo', 'OAuth demo', Capabilities::paid(Capabilities::QUOTES_BALANCE, 30, needsOAuth: true, termsCheckedAt: '2026-10-02'), tokens: $this->plugin->libraryTokens);
        $library = $this->library;
        Event::on(StockLibraries::class, StockLibraries::EVENT_REGISTER_LIBRARIES, function(RegisterStockLibrariesEvent $event) use ($library): void {
            $event->libraries[] = $library;
        });
        $this->plugin->getSettings()->stockLibraries = [];
        $this->plugin->stockLibraries->reset();
    }

    protected function _after(): void
    {
        Event::off(StockLibraries::class, StockLibraries::EVENT_REGISTER_LIBRARIES);
        putenv('SHUTTERSTOCK_API_KEY');
        putenv('SHUTTERSTOCK_API_SECRET');
        $this->plugin->getSettings()->shutterstockSandbox = null;
        $this->plugin->stockLibraries->reset();

        parent::_after();
    }

    public function testConnectingKeepsTheTokensEncryptedAfterTheStateIsChecked(): void
    {
        $this->signIn(admin: true);

        $redirect = $this->redirectFrom('ghostwriter/libraries/connect');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        // The demo goes straight back to the callback, as a provider would after sign-in.
        $this->assertStringStartsWith(LibrariesController::callbackUrl('oauthdemo'), $redirect);
        $this->assertSame(32, strlen($query['state']));

        $this->redirectFrom('ghostwriter/libraries/callback', ['code' => $query['code'], 'state' => $query['state']]);

        $this->assertTrue($this->library->connected());
        $this->assertSame('OAuth demo is connected.', $this->flash('notice'));

        // Kept encrypted: no token in the database as it is.
        $stored = (string) (new Query())->select('value')->from(Store::STATE)->where(['name' => 'library-tokens:oauthdemo'])->scalar();
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($this->plugin->libraryTokens->get('oauthdemo')?->accessToken ?? 'x', $stored);

        // The state was single use.
        $again = $this->action('ghostwriter/libraries/callback', ['code' => $query['code'], 'state' => $query['state']], 'GET', params: ['id' => 'oauthdemo']);
        $this->assertSame(403, $again['status']);
    }

    public function testAWrongStateIsRefusedAndNothingIsKept(): void
    {
        $this->signIn(admin: true);
        $redirect = $this->redirectFrom('ghostwriter/libraries/connect');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $refused = $this->action('ghostwriter/libraries/callback', ['code' => $query['code'], 'state' => str_repeat('0', 32)], 'GET', params: ['id' => 'oauthdemo']);

        $this->assertSame(403, $refused['status']);
        $this->assertFalse($this->library->connected());
    }

    public function testARefusedSignInSaysSoAndAPersonCanSayNo(): void
    {
        $this->signIn(admin: true);
        $this->library->refusesConnect = true;
        parse_str((string) parse_url($this->redirectFrom('ghostwriter/libraries/connect'), PHP_URL_QUERY), $query);

        $this->redirectFrom('ghostwriter/libraries/callback', ['code' => $query['code'], 'state' => $query['state']]);
        $this->assertFalse($this->library->connected());
        $this->assertSame('OAuth demo didn\'t accept the sign-in. Try connecting again.', $this->flash('error'));

        parse_str((string) parse_url($this->redirectFrom('ghostwriter/libraries/connect'), PHP_URL_QUERY), $query);
        $this->redirectFrom('ghostwriter/libraries/callback', ['error' => 'access_denied', 'state' => $query['state']]);
        $this->assertFalse($this->library->connected());
        $this->assertSame('Not connected: OAuth demo wasn’t given access.', $this->flash('error'));
    }

    public function testDisconnectForgetsTheTokens(): void
    {
        $this->signIn(admin: true);
        $this->plugin->libraryTokens->put('oauthdemo', new TokenSet('access', new \DateTimeImmutable('+1 hour'), 'refresh'));
        $this->assertTrue($this->library->connected());

        $done = $this->action('ghostwriter/libraries/disconnect', [], 'POST', params: ['id' => 'oauthdemo']);

        $this->assertSame(200, $done['status'], json_encode($done['data']));
        $this->assertFalse($this->library->connected());
        $this->assertNull($this->plugin->libraryTokens->get('oauthdemo'));
    }

    public function testOnlyManagersMayConnectOrDisconnect(): void
    {
        $this->signIn();

        foreach (['connect' => 'GET', 'callback' => 'GET', 'disconnect' => 'POST'] as $route => $method) {
            $this->assertSame(403, $this->action("ghostwriter/libraries/{$route}", [], $method, params: ['id' => 'oauthdemo'])['status'], $route);
        }
    }

    public function testLicensingWithoutAConnectedAccountSaysToConnectAgain(): void
    {
        $this->signIn(admin: true);
        $this->assertFalse($this->library->connected());

        $refused = $this->action('ghostwriter/stock/check', ['library' => 'oauthdemo']);

        $this->assertSame(422, $refused['status']);
        $this->assertStringContainsString('OAuth demo', $refused['data']['message']);
    }

    public function testShutterstockIsRegisteredFromItsKeysInItsSandboxInDevMode(): void
    {
        $this->signIn(admin: true);
        $this->assertNull($this->plugin->stockLibraries->get('shutterstock'), 'Not without its keys.');

        putenv('SHUTTERSTOCK_API_KEY=test-consumer-key');
        putenv('SHUTTERSTOCK_API_SECRET=test-consumer-secret');
        $this->plugin->stockLibraries->reset();

        $library = $this->plugin->stockLibraries->get('shutterstock');
        $this->assertInstanceOf(Shutterstock::class, $library);
        $this->assertTrue($library->available());
        $this->assertSame('Shutterstock (sandbox)', $library->label(), 'Dev mode uses the sandbox by default.');
        $this->assertTrue($library->capabilities()->needsOAuth);
        $this->assertSame(Capabilities::STORAGE_NONE, $library->capabilities()->previewStorage);

        // Sign-in goes to Shutterstock, back to the callback built from the site's own address.
        $redirect = $this->redirectFrom('ghostwriter/libraries/connect', id: 'shutterstock');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://api.shutterstock.com/v2/oauth/authorize?', $redirect);
        $this->assertSame(LibrariesController::callbackUrl('shutterstock'), $query['redirect_uri']);
        $this->assertSame('user.view licenses.create licenses.view purchases.view', $query['scope']);
        $this->assertStringNotContainsString('test-consumer-secret', $redirect);

        $this->plugin->getSettings()->shutterstockSandbox = false;
        $this->plugin->stockLibraries->reset();
        $this->assertSame('Shutterstock', $this->plugin->stockLibraries->get('shutterstock')?->label());
    }

    /**
     * The notice or error the control panel shows next, as Craft keeps it.
     */
    private function flash(string $type): ?string
    {
        $flash = Craft::$app->getSession()->getFlash("cp-notification-{$type}");

        return is_array($flash) ? $flash[0] : $flash;
    }

    /**
     * Run a route that redirects, and give back where to.
     *
     * @param array<string, string> $params
     */
    private function redirectFrom(string $route, array $params = [], string $id = 'oauthdemo'): string
    {
        $result = $this->action($route, $params, 'GET', params: ['id' => $id]);
        $this->assertSame(302, $result['status'], json_encode($result['data']));

        return (string) Craft::$app->getResponse()->getHeaders()->get('location');
    }
}
