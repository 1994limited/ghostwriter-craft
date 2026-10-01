<?php

namespace nineteenninetyfour\ghostwriter\ai;

final class TextResponse
{
    /**
     * @param bool $truncated The model stopped at the length limit, not because it had finished.
     */
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly bool $truncated = false,
    ) {
    }
}
