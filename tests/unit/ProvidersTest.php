<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

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

        $response = $this->plugin->studio->ask('photo-researcher', 'Be brief.', 'Say hello.', [['role' => 'user', 'content' => 'Earlier.'], ['role' => 'assistant', 'content' => 'Noted.']]);

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

    public function testABusyProviderIsTriedAgainThenExplainedInPlainWords(): void
    {
        $this->unfake();

        $this->http->append(
            new Response(429, ['retry-after' => '7'], json_encode(['error' => ['message' => 'Rate limited']])),
            new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'Hello.']]])),
            // Overloaded every time it is tried.
            ...array_fill(0, 3, new Response(529, [], json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]))),
        );

        $this->assertSame('Hello.', $this->plugin->studio->ask('writer', 'Be brief.', 'Say hello.')->text);
        // As long as the provider asked, and recorded rather than waited.
        $this->assertSame(7.0, $this->sleeper->waits[0]);

        try {
            $this->plugin->studio->ask('writer', 'Be brief.', 'Say hello.');
            $this->fail('Expected Overloaded.');
        } catch (Overloaded $exception) {
            $this->assertSame('Anthropic is busy right now. Try again shortly.', $exception->getMessage());
        }

        $this->assertCount(5, $this->sent);
        $this->assertCount(3, $this->sleeper->waits);
    }

    public function testACutOffAnswerIsAskedForAgainWithMoreRoomThenRefused(): void
    {
        $cut = new TextResponse('title: Half', StopReason::MaxTokens, new Usage(100, 16000));
        $this->fake->respond('writer', $cut, new TextResponse('title: Whole', StopReason::End, new Usage(100, 20000)));

        $this->assertSame('title: Whole', $this->plugin->studio->ask('writer', 'Write.', 'Go.')->text);
        $this->assertSame([16000, 32000], array_map(fn($request) => $request->maxTokens, $this->fake->prompted('writer')));

        $this->fake->respond('brief-writer', $cut);

        $this->expectException(Truncated::class);
        $this->expectExceptionMessage('cut off before it finished');

        $this->plugin->studio->ask('brief-writer', 'Write.', 'Go.');
    }

    public function testAnAgentsLimitAndEffortComeFromCoreUnlessGiven(): void
    {
        $this->fake->respond('photo-picker', '1, 2');

        $this->plugin->studio->ask('photo-picker', 'Pick.', 'Go.');
        $this->plugin->studio->ask('photo-picker', 'Pick.', 'Go.', maxTokens: 500, effort: 'high');

        $sent = $this->fake->prompted('photo-picker');

        $this->assertSame([2000, Effort::Low], [$sent[0]->maxTokens, $sent[0]->effort]);
        $this->assertSame([500, Effort::High], [$sent[1]->maxTokens, $sent[1]->effort]);
    }
}
