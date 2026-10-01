<?php

namespace nineteenninetyfour\ghostwriter\ai;

/**
 * One image to be made. The references are pictures the site already uses
 * in the same place, handed over as the style to match; an editor's own
 * image (a logo, a product) comes last among them when there is one.
 */
final class ImageRequest
{
    /**
     * @param Image[] $references
     * @param string $shape landscape, portrait or square
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $references = [],
        public readonly string $shape = 'landscape',
        public readonly ?string $model = null,
        public readonly int $timeout = 300,
    ) {
    }
}
