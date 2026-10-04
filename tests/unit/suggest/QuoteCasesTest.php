<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use PHPUnit\Framework\TestCase;

/**
 * The guide's port of core's QuoteFinder (suggest.js) is tested in Node
 * against a copy of core's cases. The copy must be core's own, so the two
 * can't drift.
 */
class QuoteCasesTest extends TestCase
{
    public function testTheCopyIsCoresOwn(): void
    {
        $core = (new \ReflectionClass(QuoteFinder::class))->getFileName();
        $cases = dirname((string) $core, 3) . '/resources/anchor/quote-cases.json';

        $this->assertFileExists($cases);
        $this->assertJsonFileEqualsJsonFile($cases, dirname(__DIR__, 2) . '/js/quote-cases.json', 'Copy core\'s resources/anchor/quote-cases.json to tests/js/.');
    }
}
