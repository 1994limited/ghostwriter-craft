<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\db\Query;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\OpenRouterConnection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeOpenRouter;
use nineteenninetyfour\ghostwriter\controllers\ProvidersController;
use nineteenninetyfour\ghostwriter\domain\DbProviderKeys;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * OpenRouter as a provider, and "Connect with OpenRouter" as core's
 * docs/connecting-accounts.md sets out: the state and PKCE verifier kept
 * together in the session and used once, the key kept encrypted, .env
 * always winning, Disconnect and Check connection, admins only. Nothing
 * here calls OpenRouter: the connection is core's FakeOpenRouter, and
 * provider calls go to the mock HTTP handler.
 */
class OpenRouterTest extends TestCase
{
    private FakeOpenRouter $connection;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);

        // The site's own encrypted store, as in production.
        $providers = $this->plugin->providers;
        $providers->providerKeys = $this->plugin->providerKeys;
        $this->connection = new FakeOpenRouter($this->plugin->providerKeys, $providers->environment());
        $providers->connection = $this->connection;
    }

    protected function _after(): void
    {
        $this->plugin->providerKeys->forget('openrouter');

        parent::_after();
    }

    public function testConnectingKeepsTheKeyEncryptedAfterTheStateIsChecked(): void
    {
        $this->signIn(admin: true);

        $redirect = $this->redirectFrom('ghostwriter/providers/connect');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        // The fake goes straight back to the callback, as OpenRouter would after sign-in.
        $this->assertStringStartsWith(ProvidersController::callbackUrl('openrouter'), $redirect);
        $this->assertSame(32, strlen($query['state']));

        // The state and the verifier are kept together, for this one callback.
        $kept = Craft::$app->getSession()->get('ghostwriter.connect.openrouter');
        $this->assertSame($query['state'], $kept['state']);
        $this->assertNotEmpty($kept['verifier']);

        $this->redirectFrom('ghostwriter/providers/callback', ['code' => $query['code'], 'state' => $query['state']]);

        $this->assertTrue($this->connection->connected());
        $this->assertSame('connected', $this->plugin->providers->source('openrouter'));
        $this->assertSame('Connected to OpenRouter (sk-or-v1-f…000).', $this->flash('notice'));
        $this->assertNull(Craft::$app->getSession()->get('ghostwriter.connect.openrouter'));

        // Kept encrypted: no key in the database as it is.
        $stored = (string) (new Query())->select('value')->from(Store::STATE)->where(['name' => DbProviderKeys::name('openrouter')])->scalar();
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString(FakeOpenRouter::KEY, $stored);
        $this->assertSame(FakeOpenRouter::KEY, $this->plugin->providerKeys->get('openrouter'));

        // The state was single use.
        $again = $this->action('ghostwriter/providers/callback', ['code' => $query['code'], 'state' => $query['state']], 'GET', params: ['id' => 'openrouter']);
        $this->assertSame(403, $again['status']);
    }

    public function testAWrongStateIsRefusedAndNothingIsKept(): void
    {
        $this->signIn(admin: true);
        parse_str((string) parse_url($this->redirectFrom('ghostwriter/providers/connect'), PHP_URL_QUERY), $query);

        $refused = $this->action('ghostwriter/providers/callback', ['code' => $query['code'], 'state' => str_repeat('0', 32)], 'GET', params: ['id' => 'openrouter']);

        $this->assertSame(403, $refused['status']);
        $this->assertFalse($this->connection->connected());
        $this->assertNull($this->plugin->providerKeys->get('openrouter'));
    }

    public function testARefusedSignInSaysSoAndGoingBackConnectsNothing(): void
    {
        $this->signIn(admin: true);
        $this->connection->refuseCode = true;
        parse_str((string) parse_url($this->redirectFrom('ghostwriter/providers/connect'), PHP_URL_QUERY), $query);

        $this->redirectFrom('ghostwriter/providers/callback', ['code' => $query['code'], 'state' => $query['state']]);
        $this->assertFalse($this->connection->connected());
        $this->assertSame(OpenRouterConnection::SIGN_IN_REFUSED, $this->flash('error'));

        // Back from OpenRouter without allowing: no code.
        parse_str((string) parse_url($this->redirectFrom('ghostwriter/providers/connect'), PHP_URL_QUERY), $query);
        $this->redirectFrom('ghostwriter/providers/callback', ['state' => $query['state']]);
        $this->assertFalse($this->connection->connected());
        $this->assertSame('Not connected: OpenRouter wasn’t given access.', $this->flash('error'));
    }

    public function testDisconnectForgetsTheKeyAndCheckConnectionSaysWhatIsLeft(): void
    {
        $this->signIn(admin: true);
        $this->plugin->providerKeys->put('openrouter', FakeOpenRouter::KEY);

        $checked = $this->action('ghostwriter/providers/check', [], 'POST', params: ['id' => 'openrouter']);
        $this->assertSame(200, $checked['status']);
        $this->assertSame('Connected. $7.50 of $10.00 left (resets monthly).', $checked['data']['message']);

        $this->connection->refuseKey = true;
        $refused = $this->action('ghostwriter/providers/check', [], 'POST', params: ['id' => 'openrouter']);
        $this->assertSame(422, $refused['status']);
        $this->assertStringContainsString('Connect with OpenRouter again', $refused['data']['message']);

        $done = $this->action('ghostwriter/providers/disconnect', [], 'POST', params: ['id' => 'openrouter']);
        $this->assertSame(200, $done['status'], json_encode($done['data']));
        $this->assertStringContainsString('openrouter.ai/settings/keys', $done['data']['message']);
        $this->assertNull($this->plugin->providerKeys->get('openrouter'));
        $this->assertNull($this->plugin->providers->source('openrouter'));
    }

    public function testAKeyInTheEnvironmentAlwaysWins(): void
    {
        $this->signIn(admin: true);
        $this->plugin->providerKeys->put('openrouter', 'sk-or-v1-connected-key-0000000000');
        $this->plugin->providers->keys['openrouter'] = 'sk-or-v1-environment-key-000000000';

        $this->assertSame('env', $this->plugin->providers->source('openrouter'));
        $this->assertSame('sk-or-v1-environment-key-000000000', $this->plugin->providers->key('openrouter'));

        // Connect and Disconnect are refused, and the connected key is left alone.
        $this->redirectFrom('ghostwriter/providers/connect');
        $this->assertSame(OpenRouterConnection::ENV_KEY_SET, $this->flash('error'));
        $this->assertSame(422, $this->action('ghostwriter/providers/disconnect', [], 'POST', params: ['id' => 'openrouter'])['status']);
        $this->assertNotNull($this->plugin->providerKeys->get('openrouter'));

        $html = $this->settingsHtml();
        $this->assertStringContainsString('Using OPENROUTER_API_KEY from .env', $html);
        $this->assertStringNotContainsString('Connect with OpenRouter</a>', $html);
    }

    public function testOnlyAdminsMayConnectDisconnectOrCheck(): void
    {
        $this->signIn();

        foreach (['connect' => 'GET', 'callback' => 'GET', 'disconnect' => 'POST', 'check' => 'POST'] as $route => $method) {
            $this->assertSame(403, $this->action("ghostwriter/providers/{$route}", [], $method, params: ['id' => 'openrouter'])['status'], $route);
        }
    }

    public function testConnectSendsThePersonToOpenRouterWithTheCallbackAndAChallenge(): void
    {
        // Core's real connection: only its address is built, nothing is sent.
        $this->plugin->providers->connection = null;
        $this->signIn(admin: true);

        $redirect = $this->redirectFrom('ghostwriter/providers/connect');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $kept = Craft::$app->getSession()->get('ghostwriter.connect.openrouter');

        $this->assertStringStartsWith('https://openrouter.ai/auth?', $redirect);
        $this->assertSame(ProvidersController::callbackUrl('openrouter'), $query['callback_url']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(\NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\Pkce::challenge($kept['verifier']), $query['code_challenge']);
        $this->assertSame($kept['state'], $query['state']);
        $this->assertStringNotContainsString($kept['verifier'], $redirect);
        $this->assertSame([], $this->sent);
    }

    public function testAConnectedKeyWritesWithTheModelChosenForEachTier(): void
    {
        $this->unfake();
        $settings = $this->plugin->getSettings();

        $settings->provider = 'openrouter';
        $this->assertFalse($this->plugin->providers->configured(), 'Not without a key.');

        $this->plugin->providerKeys->put('openrouter', 'sk-or-v1-connected-key-0000000000');
        $settings->openrouterModels = ['writing' => 'openai/gpt-6.1-sol', 'quick' => ''];

        $this->assertTrue($this->plugin->providers->configured());
        $this->assertInstanceOf(OpenRouter::class, $this->plugin->providers->text());

        $reply = fn() => new Response(200, [], json_encode(['model' => 'x', 'choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]]));
        $this->http->append($reply(), $reply());

        $this->plugin->studio->core()->ask('writer', 'Say hello.', instructions: 'Be brief.');
        $this->plugin->studio->core()->ask('photo-researcher', 'Say hello.', instructions: 'Be brief.');

        $writer = $this->sent[0]['request'];
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', (string) $writer->getUri());
        $this->assertSame('Bearer sk-or-v1-connected-key-0000000000', $writer->getHeaderLine('Authorization'));
        $this->assertSame('openai/gpt-6.1-sol', json_decode((string) $writer->getBody(), true)['model']);
        $this->assertSame('anthropic/claude-sonnet-5.5', json_decode((string) $this->sent[1]['request']->getBody(), true)['model'], 'The quick tier keeps its default.');
    }

    public function testTheSettingsTakeOpenRouterAndCheckItsModels(): void
    {
        $plugins = Craft::$app->getPlugins();

        $this->assertTrue($plugins->savePluginSettings($this->plugin, ['provider' => 'openrouter', 'imageProvider' => 'openrouter', 'openrouterModels' => ['writing' => 'anthropic/claude-opus-5.5', 'quick' => '']]));
        $this->assertSame('anthropic/claude-opus-5.5', $this->plugin->getSettings()->openrouterModel('writing'));
        $this->assertNull($this->plugin->getSettings()->openrouterModel('quick'));

        $this->assertFalse($plugins->savePluginSettings($this->plugin, ['openrouterModels' => ['writing' => 'claude opus', 'quick' => '']]));
        $this->assertNotEmpty($this->plugin->getSettings()->getErrors('openrouterModels.writing'));

        $this->assertFalse($plugins->savePluginSettings($this->plugin, ['provider' => 'mistral']));

        $settings = $this->plugin->getSettings();
        $settings->provider = 'openrouter';
        $settings->model = 'claude-opus-5-5';
        $this->assertStringContainsString('company/model', (string) $settings->modelWarning());
        $settings->model = 'anthropic/claude-opus-5.5';
        $this->assertNull($settings->modelWarning());
    }

    public function testTheSettingsRowOffersConnectThenShowsTheMaskedKeyAndThePrivacyNote(): void
    {
        $this->signIn(admin: true);

        $html = $this->settingsHtml();
        $this->assertStringContainsString('Connect with OpenRouter', $html);
        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringNotContainsString('pass through OpenRouter', $html);

        $this->plugin->providerKeys->put('openrouter', FakeOpenRouter::KEY);
        $this->plugin->getSettings()->provider = 'openrouter';

        $html = $this->settingsHtml();
        $this->assertStringContainsString('Connected to OpenRouter (sk-or-v1-f…000)', $html);
        $this->assertStringNotContainsString(FakeOpenRouter::KEY, $html);
        $this->assertStringContainsString('Disconnect', $html);
        $this->assertStringContainsString('Requests, including images, pass through OpenRouter on their way to the model’s company.', $html);
        $this->assertStringContainsString('Default (anthropic/claude-sonnet-5.5)', $html);
    }

    private function settingsHtml(): string
    {
        $method = new \ReflectionMethod($this->plugin, 'settingsHtml');

        return (string) $method->invoke($this->plugin);
    }

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
    private function redirectFrom(string $route, array $params = []): string
    {
        $result = $this->action($route, $params, 'GET', params: ['id' => 'openrouter']);
        $this->assertSame(302, $result['status'], json_encode($result['data']));

        return (string) Craft::$app->getResponse()->getHeaders()->get('location');
    }
}
