<?php

namespace nineteenninetyfour\ghostwriter\preview;

use RuntimeException;

/**
 * This entry can't be shown as a page: its section has no URLs or no
 * template for the site. The panel says so, and Blocks still works.
 */
class CannotPreview extends RuntimeException
{
    public const NO_URLS = 'no-urls';

    public const NO_TEMPLATE = 'no-template';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
