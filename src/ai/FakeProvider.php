<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Closure;

/**
 * Stands in for every model in tests, so no call leaves the machine and no
 * key is needed. Answers are queued per agent (writer, voice-analyst and so
 * on) and handed out in order; every request is kept for inspection.
 *
 *     $fake = Plugin::getInstance()->providers->fake();
 *     $fake->respond('writer', '<reply>Here it is.</reply><draft>...</draft>');
 *     // ...
 *     $fake->prompted('writer')[0]->prompt;
 */
class FakeProvider implements TextProvider, ImageProvider
{
    /** @var array<string, array<int, string|Closure>> */
    private array $answers = [];

    /** @var TextRequest[] */
    private array $requests = [];

    /** @var ImageRequest[] */
    public array $imageRequests = [];

    private Image|Closure|null $image = null;

    /**
     * Queue answers for one agent. A closure is called with the request and
     * may throw, to stand for a failed call.
     */
    public function respond(string $agent, string|Closure|TextResponse ...$answers): static
    {
        $this->answers[$agent] = [...($this->answers[$agent] ?? []), ...$answers];

        return $this;
    }

    public function respondWithImage(Image|Closure $image): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @return TextRequest[] Every request one agent was sent, in order.
     */
    public function prompted(string $agent): array
    {
        return array_values(array_filter($this->requests, fn(TextRequest $request) => $request->agent === $agent));
    }

    public function handle(): string
    {
        return 'fake';
    }

    public function defaultModel(): string
    {
        return 'fake';
    }

    public function defaultImageModel(): string
    {
        return 'fake';
    }

    public function text(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;

        $queue = $this->answers[$request->agent] ?? [];

        if ($queue === []) {
            throw new ProviderException("The fake has no answer queued for \"{$request->agent}\".");
        }

        // The last answer keeps being given once the others are used up.
        $answer = count($queue) > 1 ? array_shift($this->answers[$request->agent]) : $queue[0];
        $text = $answer instanceof Closure ? $answer($request) : $answer;

        return $text instanceof TextResponse ? $text : new TextResponse((string) $text, 100, 50);
    }

    public function image(ImageRequest $request): Image
    {
        $this->imageRequests[] = $request;

        $image = $this->image instanceof Closure ? ($this->image)($request) : $this->image;

        // A 1x1 PNG unless told otherwise.
        return $image ?? Image::fromString((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }
}
