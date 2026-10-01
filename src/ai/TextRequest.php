<?php

namespace nineteenninetyfour\ghostwriter\ai;

/**
 * One call to a model that writes: instructions, the conversation so far,
 * the new prompt and any images to look at.
 *
 * `agent` names the job being done (writer, voice-analyst and so on). The
 * providers ignore it; it is how tests fake and inspect one kind of call.
 */
final class TextRequest
{
    /**
     * @param Message[] $history Earlier turns, oldest first.
     * @param Image[] $images Attached to the new prompt.
     * @param string|null $effort low, medium or high, for providers that take it. Null leaves it to the provider.
     */
    public function __construct(
        public readonly string $agent,
        public readonly string $instructions,
        public readonly string $prompt,
        public readonly array $history = [],
        public readonly array $images = [],
        public readonly int $maxTokens = 16000,
        public readonly ?string $model = null,
        public readonly int $timeout = 300,
        public readonly ?string $effort = null,
    ) {
    }
}
