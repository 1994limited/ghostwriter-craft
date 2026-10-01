<?php

namespace nineteenninetyfour\ghostwriter\ai;

final class TextResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }
}
