<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoWriterContract;
use nineteenninetyfour\ghostwriter\seo\CraftSeoWriter;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\tests\unit\suggest\SeoFieldsTest;

/**
 * Core's SeoWriterContract against CraftSeoWriter for SEOmatic 5.1, in the
 * shapes the Northfold test site has (SeoFieldsTest's captured field and
 * bundles): an entry never saved in Pages (no description anywhere),
 * one with a description typed with the override switch on, one in the
 * Journal inheriting its Excerpt, a Twig template, and one switched off.
 * What CraftSeoWriter writes reads back as the page's own with the switch
 * on; posted with the switch off, SEOmatic would keep the section's.
 */
class SeoWriterTest extends TestCase
{
    use SeoWriterContract;

    protected function seoWriterFields(): SeoFields
    {
        return SeoFieldsTest::northfold();
    }

    protected function seoWriter(): SeoWriter
    {
        return new CraftSeoWriter();
    }

    protected function seoWriterEntry(string $state): ?array
    {
        [$seo, $section] = match ($state) {
            'empty' => [SeoFieldsTest::field(), 'pages'],
            'custom' => [SeoFieldsTest::field(['seoTitle' => 'Our first apprentice starts in January', 'seoDescription' => 'Two years on site and in the studio, no degree needed: our first apprenticeship starts in January.', 'overrides' => ['seoTitle' => true, 'seoDescription' => true]]), 'journal'],
            'field' => [SeoFieldsTest::field(), 'journal'],
            'template' => [SeoFieldsTest::field(['seoDescription' => '{{ entry.excerpt|slice(0, 150) }}', 'overrides' => ['seoDescription' => true]]), 'journal'],
            'disabled' => [SeoFieldsTest::field(['overrides' => ['seoDescription' => true]], ['seoDescriptionSource' => 'none']), 'journal'],
        };

        return [SeoFieldsTest::articleSchema(), self::article($seo, $section)];
    }

    /**
     * @param array<string, mixed> $seo
     */
    private static function article(mixed $seo, string $section): EntryData
    {
        return new EntryData(['title' => 'Our first apprentice', 'excerpt' => SeoFieldsTest::EXCERPT, 'body' => '<p>Northfold is taking on its first apprentice.</p>', 'seoSettings' => $seo], 109, 'Our first apprentice', group: $section, site: 'default');
    }

    private function description(EntryData $entry): SeoField
    {
        foreach ($this->seoWriterFields()->in(SeoFieldsTest::articleSchema(), $entry) as $field) {
            if ($field->role === SeoField::DESCRIPTION) {
                return $field;
            }
        }

        $this->fail('No SEO description.');
    }

    public function test_the_text_goes_in_with_the_override_switch_on_and_the_source_custom(): void
    {
        [, $entry] = $this->seoWriterEntry('field');
        $values = (new CraftSeoWriter())->write($entry->values, $this->description($entry), $this->seoWriterText());
        $bundle = $values['seoSettings'];

        $this->assertSame($this->seoWriterText(), $bundle['metaGlobalVars']['seoDescription']);
        $this->assertSame('1', $bundle['metaGlobalVars']['override-seoDescription'], 'The switch as the form posts it.');
        $this->assertTrue($bundle['metaGlobalVars']['overrides']['seoDescription'], 'And as the field stores it.');
        $this->assertArrayNotHasKey('seoDescription', $bundle['metaGlobalVars']['inherited']);
        $this->assertSame('fromCustom', $bundle['metaBundleSettings']['seoDescriptionSource']);
        $this->assertSame('', $bundle['metaGlobalVars']['seoTitle'], 'The title is left as it was.');
        $this->assertSame('1.0.62', $bundle['bundleVersion'], 'The rest of the bundle is kept.');
        $this->assertSame(array_keys($bundle['metaGlobalVars'])[count($bundle['metaGlobalVars']) - 1], 'override-seoDescription', 'The switch comes last, so nothing turns it off after.');
    }

    public function test_posted_with_the_switch_off_seomatic_keeps_the_sections(): void
    {
        [, $entry] = $this->seoWriterEntry('field');
        $values = (new CraftSeoWriter())->write($entry->values, $this->description($entry), $this->seoWriterText());
        $values['seoSettings']['metaGlobalVars']['override-seoDescription'] = '';
        $read = $this->description($entry->withValues($values));

        $this->assertSame(SeoSource::Field, $read->source, 'SEOmatic blanks it and keeps the section\'s.');
        $this->assertSame(SeoFieldsTest::EXCERPT, $read->text);
    }

    public function test_a_field_kept_as_json_is_written_as_a_bundle(): void
    {
        [, $entry] = $this->seoWriterEntry('empty');
        $entry = $entry->withValues(['seoSettings' => json_encode(SeoFieldsTest::field())] + $entry->values);
        $values = (new CraftSeoWriter())->write($entry->values, $this->description($entry), $this->seoWriterText());

        $this->assertIsArray($values['seoSettings']);
        $this->assertSame($this->seoWriterText(), $this->description($entry->withValues($values))->text);
    }

    public function test_a_field_with_no_value_yet_gets_only_what_ghostwriter_sets(): void
    {
        [, $entry] = $this->seoWriterEntry('empty');
        $values = $entry->values;
        unset($values['seoSettings']);
        $written = (new CraftSeoWriter())->write($values, $this->description($entry), $this->seoWriterText());

        $this->assertSame(['seoDescription', 'inherited', 'overrides', 'override-seoDescription'], array_keys($written['seoSettings']['metaGlobalVars']));
        $this->assertSame(['seoDescriptionSource' => 'fromCustom'], $written['seoSettings']['metaBundleSettings']);
    }
}
