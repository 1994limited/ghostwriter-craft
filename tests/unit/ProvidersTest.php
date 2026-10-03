<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\web\View;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Effort;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Gemini;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenAi;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * How Craft is wired into ghostwriter-core's providers: keys from the
 * environment, choices from the settings, requests through Craft's Guzzle
 * client, and Studio's handling of a cut-off answer. What each provider
 * sends and reads back is tested in core.
 */
class ProvidersTest extends TestCase
{
    public function testTheProviderIsChosenFromTheSettingsAndTheKeysInTheEnvironment(): void
    {
        $this->unfake();
        $providers = $this->plugin->providers;
        $settings = $this->plugin->getSettings();

        $this->assertInstanceOf(Anthropic::class, $providers->text());
        $this->assertTrue($providers->configured());
        $this->assertNull($providers->image(), 'Claude does not make images.');

        $settings->provider = 'openai';
        $this->assertFalse($providers->configured());

        try {
            $providers->text();
            $this->fail('Expected NotConfigured.');
        } catch (NotConfigured $exception) {
            $this->assertStringContainsString('OPENAI_API_KEY', $exception->getMessage());
        }

        // Images go to whichever image provider has a key, unless one is chosen.
        $providers->keys['gemini'] = 'g';
        $this->assertInstanceOf(Gemini::class, $providers->image());

        $providers->keys['openai'] = 'o';
        $this->assertInstanceOf(OpenAi::class, $providers->image());

        $settings->imageProvider = 'gemini';
        $this->assertInstanceOf(Gemini::class, $providers->image());

        // The settings screen is told which keys exist, never what they are.
        $status = $providers->keyStatus();
        $this->assertTrue($status['GEMINI_API_KEY']);
        $this->assertFalse($status['PEXELS_API_KEY']);
        $this->assertNotContains('g', $status);
    }

    public function testACallGoesThroughCraftsClientWithTheKeyModelAndTimeoutFromTheSettings(): void
    {
        $this->unfake();
        $settings = $this->plugin->getSettings();
        $settings->model = 'claude-sonnet-5-5';
        $settings->timeout = 120;

        $this->http->append(new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'Hello.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
        ])));

        $response = $this->plugin->studio->core()->ask('photo-researcher', 'Say hello.', [['role' => 'user', 'content' => 'Earlier.'], ['role' => 'assistant', 'content' => 'Noted.']], instructions: 'Be brief.');

        $this->assertSame('Hello.', $response->text);
        $this->assertSame([12, 3], [$response->usage->input, $response->usage->output]);

        $sent = $this->sent[0];
        $body = json_decode((string) $sent['request']->getBody(), true);

        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $sent['request']->getUri());
        $this->assertSame('test-key', $sent['request']->getHeaderLine('x-api-key'));
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame([120, 15], [$sent['options']['timeout'], $sent['options']['connect_timeout']]);

        // The agent's own limit and effort, from core.
        $this->assertSame(2000, $body['max_tokens']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
    }

    public function testAGatewayFromTheSettingsIsCalledInsteadOfTheProvidersOwnAddress(): void
    {
        $this->unfake();
        $settings = $this->plugin->getSettings();

        $_SERVER['GHOSTWRITER_ANTHROPIC_BASE_URL'] = 'https://gateway.example.com/anthropic/';
        $settings->setAttributes(['baseUrls' => ['anthropic' => '$GHOSTWRITER_ANTHROPIC_BASE_URL']], false);

        try {
            // Unknown and missing providers are tidied into the known ones.
            $this->assertSame(['anthropic' => '$GHOSTWRITER_ANTHROPIC_BASE_URL', 'openai' => '', 'gemini' => '', 'openrouter' => ''], $settings->baseUrls);
            $this->assertSame('https://gateway.example.com/anthropic/', $settings->baseUrl('anthropic'));
            $this->assertNull($settings->baseUrl('openai'));

            $this->http->append(new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'Hello.']]])));
            $this->plugin->studio->core()->ask('writer', 'Say hello.', instructions: 'Be brief.');

            $this->assertSame('https://gateway.example.com/anthropic/v1/messages', (string) $this->sent[0]['request']->getUri());
        } finally {
            unset($_SERVER['GHOSTWRITER_ANTHROPIC_BASE_URL']);
        }

        // An unset variable means the provider's own address.
        $this->assertNull($settings->baseUrl('anthropic'));
    }

    public function testAGatewayMustBeHttpsExceptOnThisMachine(): void
    {
        $plugins = Craft::$app->getPlugins();

        $this->assertTrue($plugins->savePluginSettings($this->plugin, ['baseUrls' => ['anthropic' => 'https://gateway.example.com', 'openai' => 'http://localhost:8080', 'gemini' => 'http://[::1]:9000']]));
        $this->assertSame('http://localhost:8080', $this->plugin->getSettings()->baseUrl('openai'));

        foreach (['http://gateway.example.com', 'https://user:secret@gateway.example.com', 'https://gateway.example.com/?key=1', 'gateway.example.com'] as $address) {
            $this->assertFalse($plugins->savePluginSettings($this->plugin, ['baseUrls' => ['gemini' => $address]]), $address);
            $this->assertNotEmpty($this->plugin->getSettings()->getErrors('baseUrls.gemini'), $address);
        }

        // A variable is checked for what it holds.
        $_SERVER['GHOSTWRITER_OPENAI_BASE_URL'] = 'http://gateway.example.com';

        try {
            $this->assertFalse($plugins->savePluginSettings($this->plugin, ['baseUrls' => ['openai' => '$GHOSTWRITER_OPENAI_BASE_URL']]));
        } finally {
            unset($_SERVER['GHOSTWRITER_OPENAI_BASE_URL']);
        }
    }

    public function testTheGatewaysAreOnTheSettingsScreenAndLockedWhenSetInConfig(): void
    {
        $variables = [
            'settings' => $this->plugin->getSettings(),
            'sections' => [],
            'keys' => [],
            'modelDefaults' => [],
        ];

        $html = Craft::$app->getView()->renderTemplate('ghostwriter/_settings', $variables + ['overrides' => []], View::TEMPLATE_MODE_CP);
        $this->assertStringContainsString('Claude (Anthropic) base URL', $html);
        $this->assertStringContainsString('id="baseUrls-gemini-field"', $html);
        $this->assertStringNotContainsString('Set by <code>baseUrls</code>', $html);

        $html = Craft::$app->getView()->renderTemplate('ghostwriter/_settings', $variables + ['overrides' => ['baseUrls']], View::TEMPLATE_MODE_CP);
        $this->assertStringContainsString('Set by <code>baseUrls</code> in config/ghostwriter.php', $html);
    }

    public function testABusyProviderIsTriedAgainThenExplainedInPlainWords(): void
    {
        $this->unfake();

        $this->http->append(
            new Response(429, ['retry-after' => '7'], json_encode(['error' => ['message' => 'Rate limited']])),
            new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'Hello.']]])),
            // Overloaded every time it is tried.
            ...array_fill(0, 3, new Response(529, [], json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]))),
        );

        $this->assertSame('Hello.', $this->plugin->studio->core()->ask('writer', 'Say hello.', instructions: 'Be brief.')->text);
        // As long as the provider asked, and recorded rather than waited.
        $this->assertSame(7.0, $this->sleeper->waits[0]);

        try {
            $this->plugin->studio->core()->ask('writer', 'Say hello.', instructions: 'Be brief.');
            $this->fail('Expected Overloaded.');
        } catch (Overloaded $exception) {
            $this->assertSame('Anthropic is busy right now. Try again shortly.', $exception->getMessage());
        }

        $this->assertCount(5, $this->sent);
        $this->assertCount(3, $this->sleeper->waits);
    }

    public function testACutOffAnswerIsAskedForAgainWithMoreRoomThenRefusedOrKept(): void
    {
        $cut = new TextResponse('title: Half', StopReason::MaxTokens, new Usage(100, 16000));
        $this->fake->respond('writer', $cut, new TextResponse('title: Whole', StopReason::End, new Usage(100, 20000)));

        $response = $this->plugin->studio->core()->ask('writer', 'Go.', instructions: 'Write.');
        $this->assertSame('title: Whole', $response->text);
        $this->assertSame([16000, 32000], array_map(fn($request) => $request->resolvedMaxTokens(), $this->fake->prompted('writer')));
        // Both calls are counted.
        $this->assertSame(36000, $response->usage->output);

        // Anything but a draft or guide keeps what came back.
        $this->fake->respond('brief-writer', $cut);
        $this->assertSame('title: Half', $this->plugin->studio->core()->ask('brief-writer', 'Go.', instructions: 'Write.')->text);

        $this->fake->reset('writer')->respond('writer', $cut);

        $this->expectException(Truncated::class);
        $this->expectExceptionMessage('cut off before it finished');

        $this->plugin->studio->core()->ask('writer', 'Go.', instructions: 'Write.');
    }

    public function testAnAgentsLimitAndEffortComeFromCore(): void
    {
        $this->fake->respond('photo-picker', '1, 2');

        $this->plugin->studio->core()->ask('photo-picker', 'Go.', instructions: 'Pick.');

        $sent = $this->fake->prompted('photo-picker');

        $this->assertSame([2000, Effort::Low], [$sent[0]->resolvedMaxTokens(), $sent[0]->resolvedEffort()]);
    }
}
