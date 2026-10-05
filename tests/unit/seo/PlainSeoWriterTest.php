<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoWriterContract;
use nineteenninetyfour\ghostwriter\seo\CraftSeoWriter;
use nineteenninetyfour\ghostwriter\suggest\CraftSeoFields;
use nineteenninetyfour\ghostwriter\suggest\SeomaticBundles;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's SeoWriterContract against CraftSeoWriter for plain fields (no
 * SEOmatic): an SEO title and a meta description kept as Plain Text
 * fields, with the description's character limit. A plain field can't
 * inherit, be a template or be switched off.
 */
class PlainSeoWriterTest extends TestCase
{
    use SeoWriterContract;

    protected function seoWriterFields(): SeoFields
    {
        return new CraftSeoFields(new class() extends SeomaticBundles {
            public function __construct()
            {
            }

            public function installed(): bool
            {
                return false;
            }
        });
    }

    protected function seoWriter(): SeoWriter
    {
        return new CraftSeoWriter();
    }

    protected function seoWriterEntry(string $state): ?array
    {
        $values = match ($state) {
            'empty' => ['seoTitle' => '', 'metaDescription' => ''],
            'custom' => ['seoTitle' => 'Our first apprentice starts in January', 'metaDescription' => 'Two years on site and in the studio, no degree needed: our first apprenticeship starts in January, and applications close soon.'],
            default => null,
        };

        if ($values === null) {
            return null;
        }

        $schema = Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'title', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'seoTitle', 'type' => 'craft\fields\PlainText', 'kind' => 'text', 'display' => 'SEO title'],
            ['handle' => 'metaDescription', 'type' => 'craft\fields\PlainText', 'kind' => 'longtext', 'display' => 'Meta description', 'maxLength' => 160],
        ]);

        return [$schema, new EntryData(['title' => 'Our first apprentice'] + $values, 109, 'Our first apprentice', group: 'pages', site: 'default')];
    }
}
