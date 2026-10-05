<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Testing\FakeKeyCheck;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use nineteenninetyfour\ghostwriter\connections\ConnectionsPage;
use nineteenninetyfour\ghostwriter\domain\DbCredentialStore;
use nineteenninetyfour\ghostwriter\migrations\m261007_000000_connections;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Settings → Connections: a card per service, set up by pasting a key
 * that is checked first and kept encrypted with the security key; .env
 * always wins. Admins only.
 */
class ConnectionsTest extends TestCase
{
    private const KEY = 'pexels-pasted-0123456789wxyz';

    private FakeKeyCheck $check;

    protected function _before(): void
    {
        parent::_before();

        $this->check = new FakeKeyCheck();
        $this->plugin->providers->check = $this->check;
        $this->plugin->providers->keys['pexels'] = null;
        $this->plugin->providers->keys['shutterstock'] = null;
        $this->plugin->providers->keys['shutterstock_secret'] = null;
    }

    protected function _after(): void
    {
        foreach (['pexels', 'health:pexels', 'shutterstock', 'openrouter', 'tokens:shutterstock'] as $name) {
            $this->plugin->credentials->forget($name);
        }

        $this->plugin->providers->check = null;

        parent::_after();
    }

    public function testThePageShowsEveryServiceByGroup(): void
    {
        $this->signIn(admin: true);

        $page = $this->action('ghostwriter/connections/index', method: 'GET', json: false);
        $this->assertSame(200, $page['status']);

        $payload = ConnectionsPage::payload();
        $this->assertSame(['writing', 'images', 'stock'], array_column($payload['groups'], 'id'));
        $this->assertSame(['anthropic', 'openai', 'gemini', 'openrouter'], array_column($payload['groups'][0]['cards'], 'id'));
        $this->assertSame(['unsplash', 'pexels', 'pixabay', 'openverse'], array_column($payload['groups'][1]['cards'], 'id'));
        $this->assertSame('not_set', $payload['groups'][1]['cards'][1]['status']['state']);
        $this->assertSame('Connections', $payload['strings']['title']);
    }

    public function testOnlyAdminsMayOpenIt(): void
    {
        $this->signIn();

        $this->assertSame(403, $this->action('ghostwriter/connections/index', method: 'GET')['status']);
        $this->assertSame(403, $this->action('ghostwriter/connections/save', ['service' => 'pexels', 'fields' => ['key' => self::KEY]])['status']);
        $this->assertNull($this->plugin->providers->credentials()->key('pexels'));
    }

    public function testAKeyIsCheckedKeptEncryptedShownMaskedAndUsed(): void
    {
        $this->signIn(admin: true);

        $response = $this->action('ghostwriter/connections/save', ['service' => 'pexels', 'fields' => ['key' => ' ' . self::KEY . ' ']]);

        $this->assertSame(200, $response['status'], Json::encode($response['data']));
        $this->assertSame('Pexels is connected.', $response['data']['message']);
        $this->assertSame('Connected · key ending ••wxyz', $response['data']['card']['status']['label']);
        $this->assertStringNotContainsString(self::KEY, Json::encode($response['data']));
        $this->assertSame(['pexels'], $this->check->checked);

        $raw = (string) (new Query())->select('value')->from(Store::CREDENTIALS)->where(['name' => 'pexels'])->scalar();
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::KEY, $raw, 'Encrypted at rest.');
        $this->assertStringNotContainsString(self::KEY, (string) base64_decode($raw));

        $this->assertSame(self::KEY, $this->plugin->providers->credentials()->key('pexels'));
        $this->assertContains('pexels', $this->plugin->imagePicker->stock()->sources());
    }

    public function testARefusedKeyIsSaidPlainlyAndNotKept(): void
    {
        $this->signIn(admin: true);

        $response = $this->action('ghostwriter/connections/save', ['service' => 'pexels', 'fields' => ['key' => 'a-wrong-key-0123']]);

        $this->assertSame(422, $response['status']);
        $this->assertSame('Pexels didn’t accept that key. Check you copied all of it, with nothing before or after.', $response['data']['message']);
        $this->assertNull($this->plugin->providers->credentials()->key('pexels'));
    }

    public function testAKeyInEnvWinsAndCannotBeReplacedHere(): void
    {
        $this->plugin->providers->keys['pexels'] = 'pexels-from-env-0123';
        $this->signIn(admin: true);

        $card = ConnectionsPage::card($this->plugin->providers->credentials()->services()->get('pexels'));
        $this->assertSame('env', $card['status']['state']);
        $this->assertNull($card['status']['ending']);

        $response = $this->action('ghostwriter/connections/save', ['service' => 'pexels', 'fields' => ['key' => self::KEY]]);
        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('PEXELS_API_KEY', $response['data']['message']);
        $this->assertSame(409, $this->action('ghostwriter/connections/disconnect', ['service' => 'pexels'])['status']);
        $this->assertSame([], $this->check->checked);
    }

    public function testDisconnectForgetsTheKey(): void
    {
        $this->signIn(admin: true);
        $this->action('ghostwriter/connections/save', ['service' => 'pexels', 'fields' => ['key' => self::KEY]]);

        $response = $this->action('ghostwriter/connections/disconnect', ['service' => 'pexels']);

        $this->assertSame(200, $response['status']);
        $this->assertSame('not_set', $response['data']['card']['status']['state']);
        $this->assertNull($this->plugin->providers->credentials()->key('pexels'));
    }

    public function testTheStoreKeepsNothingAsPlainText(): void
    {
        $store = new DbCredentialStore();
        $value = ['fields' => ['key' => 'sk-very-secret-0123456789', 'secret' => 'ünïcode-✓'], 'via' => 'paste'];

        $store->put('shutterstock', $value);
        $store->put('shutterstock', $value);

        $this->assertSame($value, $store->get('shutterstock'));
        $this->assertStringNotContainsString('sk-very-secret', (string) $store->raw('shutterstock'));
        $this->assertSame(1, (int) (new Query())->from(Store::CREDENTIALS)->where(['name' => 'shutterstock'])->count());

        $store->forget('shutterstock');
        $this->assertNull($store->get('shutterstock'));
    }

    public function testTheMigrationMovesKeysAndTokensKeptBeforeConnections(): void
    {
        $security = Craft::$app->getSecurity();
        $this->plugin->store->putState('provider-key:openrouter', ['sealed' => base64_encode($security->encryptByKey('sk-or-v1-kept-earlier-0123'))]);
        $this->plugin->store->putState('library-tokens:shutterstock', ['sealed' => base64_encode($security->encryptByKey(Json::encode((new TokenSet('access-earlier-0123', refreshToken: 'refresh'))->toArray())))]);

        $migration = new m261007_000000_connections();
        $migration->db = Craft::$app->getDb();
        $migration->safeUp();

        $this->assertSame('sk-or-v1-kept-earlier-0123', $this->plugin->providers->credentials()->key('openrouter'));
        $this->assertSame('connect', $this->plugin->providers->credentials()->status('openrouter')->via);
        $this->assertSame('access-earlier-0123', $this->plugin->libraryTokens->get('shutterstock')?->accessToken);
        $this->assertSame([], $this->plugin->store->state('provider-key:openrouter'));
        $this->assertSame([], $this->plugin->store->state('library-tokens:shutterstock'));
    }

    public function testGetStartedLeadsHere(): void
    {
        $this->signIn(admin: true);
        $this->unfake();
        $this->plugin->providers->keys['anthropic'] = null;

        $steps = array_column($this->plugin->onboarding->steps(), null, 'key');

        $this->assertStringEndsWith('ghostwriter/connections', $steps['key']['action']['url']);
    }
}
