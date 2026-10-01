<?php

namespace nineteenninetyfour\ghostwriter\ai\providers;

use GuzzleHttp\Exception\RequestException;
use nineteenninetyfour\ghostwriter\ai\ProviderException;
use nineteenninetyfour\ghostwriter\ai\TextProvider;
use nineteenninetyfour\ghostwriter\ai\TextRequest;
use nineteenninetyfour\ghostwriter\ai\TextResponse;

/**
 * Claude, over the Messages API.
 */
class Anthropic extends HttpProvider implements TextProvider
{
    public const URL = 'https://api.anthropic.com/v1/messages';

    public function handle(): string
    {
        return 'anthropic';
    }

    public function defaultModel(): string
    {
        return 'claude-opus-5-5';
    }

    public function text(TextRequest $request): TextResponse
    {
        $model = $request->model ?: $this->defaultModel();

        $content = [];

        foreach ($request->images as $image) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image->mime, 'data' => $image->base64()]];
        }

        $content[] = ['type' => 'text', 'text' => $request->prompt];

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->instructions,
            'messages' => [
                ...array_map(fn($message) => ['role' => $message->role, 'content' => $message->content], $request->history),
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];

        if ($this->isCurrent($model)) {
            if ($request->effort) {
                $body['output_config'] = ['effort' => $request->effort];
            }

            // A request a safety classifier declines is run again on the
            // model Anthropic recommends for that kind of refusal, rather
            // than coming back empty.
            $body['fallbacks'] = 'default';
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }

        try {
            $data = $this->send('POST', self::URL, ['headers' => $headers, 'json' => $body], $request->timeout);
        } catch (ProviderException $exception) {
            // Should the fallback beta be retired or renamed, the API refuses
            // the request outright. Send it once more without it, rather than
            // every call failing.
            if (!isset($headers['anthropic-beta']) || !$this->refusedTheBeta($exception)) {
                throw $exception;
            }

            unset($headers['anthropic-beta'], $body['fallbacks']);
            $data = $this->send('POST', self::URL, ['headers' => $headers, 'json' => $body], $request->timeout);
        }

        $text = implode('', array_map(
            fn(array $block) => (string) $block['text'],
            array_filter((array) ($data['content'] ?? []), fn($block) => is_array($block) && ($block['type'] ?? null) === 'text'),
        ));

        if (($data['stop_reason'] ?? null) === 'refusal' && trim($text) === '') {
            throw new ProviderException('Claude declined this request. Try rewording it.');
        }

        return new TextResponse(
            $text,
            (int) ($data['usage']['input_tokens'] ?? 0) + (int) ($data['usage']['cache_read_input_tokens'] ?? 0),
            (int) ($data['usage']['output_tokens'] ?? 0),
            ($data['stop_reason'] ?? null) === 'max_tokens',
        );
    }

    private function refusedTheBeta(ProviderException $exception): bool
    {
        $previous = $exception->getPrevious();
        $response = $previous instanceof RequestException ? $previous->getResponse() : null;

        if ($response === null || $response->getStatusCode() !== 400) {
            return false;
        }

        $body = strtolower((string) $response->getBody());

        return str_contains($body, 'fallback') || str_contains($body, 'beta');
    }

    /**
     * Effort and server-side fallbacks are only understood by the current
     * generation of models; an older one chosen in the settings gets the
     * plain request.
     */
    private function isCurrent(string $model): bool
    {
        return (bool) preg_match('/^claude-(opus-5|sonnet-5-5|fable-5-1)/', $model);
    }
}
