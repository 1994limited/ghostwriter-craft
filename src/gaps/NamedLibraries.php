<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\UnlicensedStock;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * Core's UnlicensedStock, with each preview's library named as editors
 * know it ("Demo stock", "Getty Images") rather than by its ID, so the
 * guide and the publish guard say "a Getty Images preview". The ID stays
 * in `meta.libraryId`.
 */
final class NamedLibraries implements Detector
{
    public function __construct(private readonly Detector $inner = new UnlicensedStock()) {}

    public function kinds(): array
    {
        return $this->inner->kinds();
    }

    public function usesModel(): bool
    {
        return $this->inner->usesModel();
    }

    public function detect(GapContext $context): iterable
    {
        $libraries = Plugin::getInstance()->stockLibraries;

        foreach ($this->inner->detect($context) as $gap) {
            $id = is_string($gap->meta['library'] ?? null) ? $gap->meta['library'] : '';

            yield $id === '' ? $gap : $gap->withMeta(['library' => $libraries->standInName($id), 'libraryId' => $id]);
        }
    }
}
