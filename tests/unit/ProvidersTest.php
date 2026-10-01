<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use GuzzleHttp\Psr7\Response;
use nineteenninetyfour\ghostwriter\ai\Image;
use nineteenninetyfour\ghostwriter\ai\ImageRequest;
use nineteenninetyfour\ghostwriter\ai\Message;
use nineteenninetyfour\ghostwriter\ai\ProviderException;
use nineteenninetyfour\ghostwriter\ai\providers\Anthropic;
use nineteenninetyfour\ghostwriter\ai\providers\Gemini;
use nineteenninetyfour\ghostwriter\ai\providers\OpenAi;
use nineteenninetyfour\ghostwriter\ai\TextRequest;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The provider layer, over a mocked network: what each provider is sent, and
 * how its answer is read back.
 */
class ProvidersTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testClaudeIsSentTheConversationImagesAndAFallback(): void
    {
        $this->http->append(new Response(200, [], json_encode([
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '<reply>Hi.</reply>']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'cache_read_input_tokens' => 30, 'output_tokens' => 40],
        ])));

        $response = (new Anthropic('secret', $this->plugin->providers->http()))->text($this->request(images: [new Image(base64_decode(self::PNG), 'image/png')], effort: 'low'));

        $this->assertSame('<reply>Hi.</reply>', $response->text);
        $this->assertSame([150, 40], [$response->inputTokens, $response->outputTokens]);

        $request = $this->sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));
        $this->assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame('Be brief.', $body['system']);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame(['image', 'text'], array_column($body['messages'][2]['content'], 'type'));
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG], $body['messages'][2]['content'][0]['source']);
    }

    public function testEveryModelWithRefusalClassifiersGetsTheFallback(): void
    {
        foreach (['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'] as $model) {
            $this->http->append(new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'OK']]])));
        }

        foreach (['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'] as $i => $model) {
            (new Anthropic('secret', $this->plugin->providers->http()))->text($this->request(model: $model));

            $body = json_decode((string) $this->sent[$i]['request']->getBody(), true);

            $this->assertSame('default', $body['fallbacks'] ?? null, $model);
        }
    }

    public function testAnOlderClaudeModelGetsThePlainRequest(): void
    {
        $this->http->append(new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'OK']]])));

        (new Anthropic('secret', $this->plugin->providers->http()))->text($this->request(model: 'claude-haiku-4-5', effort: 'low'));

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);

        $this->assertArrayNotHasKey('fallbacks', $body);
        $this->assertArrayNotHasKey('output_config', $body);
        $this->assertFalse($this->sent[0]['request']->hasHeader('anthropic-beta'));
    }

    public function testARefusalAndAnErrorAreExplainedInPlainWords(): void
    {
        $this->http->append(
            new Response(200, [], json_encode(['content' => [], 'stop_reason' => 'refusal'])),
            new Response(529, [], json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])),
        );

        $claude = new Anthropic('secret', $this->plugin->providers->http());

        foreach (['Claude declined this request', 'Anthropic said no (529): Overloaded'] as $expected) {
            try {
                $claude->text($this->request());
                $this->fail('Expected a ProviderException.');
            } catch (ProviderException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage());
            }
        }
    }

    public function testChatGptWritesAndMakesImagesFromReferences(): void
    {
        $this->http->append(
            new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'Written.']]], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3]])),
            new Response(200, [], json_encode(['data' => [['b64_json' => self::PNG]]])),
            new Response(200, [], json_encode(['data' => [['b64_json' => self::PNG]]])),
        );

        $openai = new OpenAi('secret', $this->plugin->providers->http());
        $response = $openai->text($this->request(images: [new Image(base64_decode(self::PNG), 'image/png')]));

        $this->assertSame(['Written.', 9, 3], [$response->text, $response->inputTokens, $response->outputTokens]);

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('Bearer secret', $this->sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('data:image/png;base64,' . self::PNG, $body['messages'][3]['content'][1]['image_url']['url']);

        // With nothing to match, a plain generation; with references, an edit of them.
        $made = $openai->image(new ImageRequest('A lighthouse', shape: 'portrait'));
        $this->assertSame('image/png', $made->mime);
        $this->assertStringEndsWith('/images/generations', (string) $this->sent[1]['request']->getUri());
        $this->assertSame('1024x1536', json_decode((string) $this->sent[1]['request']->getBody(), true)['size']);

        $openai->image(new ImageRequest('A lighthouse', [new Image(base64_decode(self::PNG), 'image/png')]));
        $this->assertStringEndsWith('/images/edits', (string) $this->sent[2]['request']->getUri());
        $this->assertStringContainsString('name="image[]"; filename="reference-0.png"', (string) $this->sent[2]['request']->getBody());
    }

    public function testGeminiWritesAndMakesImages(): void
    {
        $this->http->append(
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Thinking…', 'thought' => true], ['text' => 'Written.']]]]],
                'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 2],
            ])),
            new Response(200, [], json_encode(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => self::PNG]]]]]]])),
            new Response(200, [], json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']])),
        );

        $gemini = new Gemini('secret', $this->plugin->providers->http());

        $this->assertSame('Written.', $gemini->text($this->request())->text);

        $request = $this->sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringEndsWith('/models/gemini-3.8-flash:generateContent', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('x-goog-api-key'));
        $this->assertSame(['user', 'model', 'user'], array_column($body['contents'], 'role'));
        $this->assertSame('Be brief.', $body['systemInstruction']['parts'][0]['text']);

        $this->assertSame('image/png', $gemini->image(new ImageRequest('A lighthouse', shape: 'square'))->mime);
        $this->assertSame('1:1', json_decode((string) $this->sent[1]['request']->getBody(), true)['generationConfig']['imageConfig']['aspectRatio']);

        $this->expectExceptionMessage('Gemini declined this request (SAFETY)');
        $gemini->text($this->request());
    }

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
            $this->fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
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

    /**
     * @param Image[] $images
     */
    private function request(array $images = [], ?string $model = null, ?string $effort = null): TextRequest
    {
        return new TextRequest(
            agent: 'writer',
            instructions: 'Be brief.',
            prompt: 'Say hello.',
            history: [new Message('user', 'Earlier.'), new Message('assistant', 'Noted.')],
            images: $images,
            model: $model,
            effort: $effort,
        );
    }
}
