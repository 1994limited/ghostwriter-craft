<?php

namespace nineteenninetyfour\ghostwriter\ai\providers;

use nineteenninetyfour\ghostwriter\ai\Image;
use nineteenninetyfour\ghostwriter\ai\ImageProvider;
use nineteenninetyfour\ghostwriter\ai\ImageRequest;
use nineteenninetyfour\ghostwriter\ai\ProviderException;
use nineteenninetyfour\ghostwriter\ai\TextProvider;
use nineteenninetyfour\ghostwriter\ai\TextRequest;
use nineteenninetyfour\ghostwriter\ai\TextResponse;

/**
 * Gemini over the generateContent API, which also makes images when given
 * an image model.
 */
class Gemini extends HttpProvider implements TextProvider, ImageProvider
{
    public const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const RATIOS = ['landscape' => '3:2', 'portrait' => '2:3', 'square' => '1:1'];

    public function handle(): string
    {
        return 'gemini';
    }

    public function defaultModel(): string
    {
        return 'gemini-3.8-flash';
    }

    public function defaultImageModel(): string
    {
        return 'gemini-3.1-flash-image';
    }

    public function text(TextRequest $request): TextResponse
    {
        $data = $this->generate($request->model ?: $this->defaultModel(), [
            'systemInstruction' => ['parts' => [['text' => $request->instructions]]],
            'contents' => [
                ...array_map(fn($message) => ['role' => $message->role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $message->content]]], $request->history),
                ['role' => 'user', 'parts' => [...$this->inline($request->images), ['text' => $request->prompt]]],
            ],
            'generationConfig' => ['maxOutputTokens' => $request->maxTokens],
        ], $request->timeout);

        // Parts marked as thought are the model's working, not its answer.
        $text = implode('', array_map(
            fn(array $part) => (string) ($part['text'] ?? ''),
            array_filter((array) ($data['candidates'][0]['content']['parts'] ?? []), fn($part) => is_array($part) && empty($part['thought'])),
        ));

        return new TextResponse(
            $text,
            (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            ($data['candidates'][0]['finishReason'] ?? null) === 'MAX_TOKENS',
        );
    }

    public function image(ImageRequest $request): Image
    {
        $data = $this->generate($request->model ?: $this->defaultImageModel(), [
            'contents' => [['role' => 'user', 'parts' => [...$this->inline($request->references), ['text' => $request->prompt]]]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['aspectRatio' => self::RATIOS[$request->shape] ?? self::RATIOS['landscape']],
            ],
        ], $request->timeout);

        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;

            if (is_array($inline) && !empty($inline['data'])) {
                return Image::fromString((string) base64_decode((string) $inline['data']));
            }
        }

        throw new ProviderException('Gemini did not send back an image.');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function generate(string $model, array $body, int $timeout): array
    {
        $data = $this->send('POST', self::BASE . '/' . rawurlencode($model) . ':generateContent', [
            'headers' => ['x-goog-api-key' => $this->apiKey],
            'json' => $body,
        ], $timeout);

        if ($reason = $data['promptFeedback']['blockReason'] ?? null) {
            throw new ProviderException("Gemini declined this request ({$reason}). Try rewording it.");
        }

        if (in_array($data['candidates'][0]['finishReason'] ?? null, ['SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY'], true)) {
            throw new ProviderException('Gemini declined this request. Try rewording it.');
        }

        return $data;
    }

    /**
     * @param Image[] $images
     * @return array<int, array<string, mixed>>
     */
    private function inline(array $images): array
    {
        return array_map(fn(Image $image) => ['inline_data' => ['mime_type' => $image->mime, 'data' => $image->base64()]], $images);
    }
}
