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
 * ChatGPT over Chat Completions, and its image model over the Images API.
 */
class OpenAi extends HttpProvider implements TextProvider, ImageProvider
{
    public const BASE = 'https://api.openai.com/v1';

    private const SIZES = ['landscape' => '1536x1024', 'portrait' => '1024x1536', 'square' => '1024x1024'];

    public function handle(): string
    {
        return 'openai';
    }

    public function defaultModel(): string
    {
        return 'gpt-5';
    }

    public function defaultImageModel(): string
    {
        return 'gpt-image-1';
    }

    public function text(TextRequest $request): TextResponse
    {
        $content = [['type' => 'text', 'text' => $request->prompt]];

        foreach ($request->images as $image) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$image->mime};base64,{$image->base64()}"]];
        }

        $data = $this->send('POST', self::BASE . '/chat/completions', [
            'headers' => ['Authorization' => "Bearer {$this->apiKey}"],
            'json' => [
                'model' => $request->model ?: $this->defaultModel(),
                'max_completion_tokens' => $request->maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $request->instructions],
                    ...array_map(fn($message) => ['role' => $message->role, 'content' => $message->content], $request->history),
                    ['role' => 'user', 'content' => $content],
                ],
            ],
        ], $request->timeout);

        $message = $data['choices'][0]['message'] ?? [];

        if (!empty($message['refusal']) && empty($message['content'])) {
            throw new ProviderException('ChatGPT declined this request: ' . $message['refusal']);
        }

        return new TextResponse(
            (string) ($message['content'] ?? ''),
            (int) ($data['usage']['prompt_tokens'] ?? 0),
            (int) ($data['usage']['completion_tokens'] ?? 0),
        );
    }

    /**
     * With reference images the picture is made as an edit of them, which
     * is how the Images API takes images to work from.
     */
    public function image(ImageRequest $request): Image
    {
        $model = $request->model ?: $this->defaultImageModel();
        $size = self::SIZES[$request->shape] ?? self::SIZES['landscape'];
        $headers = ['Authorization' => "Bearer {$this->apiKey}"];

        if ($request->references === []) {
            $data = $this->send('POST', self::BASE . '/images/generations', [
                'headers' => $headers,
                'json' => ['model' => $model, 'prompt' => $request->prompt, 'size' => $size, 'n' => 1],
            ], $request->timeout);
        } else {
            $parts = [
                ['name' => 'model', 'contents' => $model],
                ['name' => 'prompt', 'contents' => $request->prompt],
                ['name' => 'size', 'contents' => $size],
            ];

            foreach ($request->references as $i => $reference) {
                $parts[] = ['name' => 'image[]', 'contents' => $reference->data, 'filename' => "reference-{$i}.{$reference->extension()}", 'headers' => ['Content-Type' => $reference->mime]];
            }

            $data = $this->send('POST', self::BASE . '/images/edits', ['headers' => $headers, 'multipart' => $parts], $request->timeout);
        }

        $encoded = $data['data'][0]['b64_json'] ?? null;

        if (!is_string($encoded) || $encoded === '') {
            throw new ProviderException('OpenAI did not send back an image.');
        }

        return Image::fromString((string) base64_decode($encoded));
    }
}
