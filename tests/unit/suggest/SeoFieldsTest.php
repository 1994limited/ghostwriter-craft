<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoFieldsContract;
use nineteenninetyfour\ghostwriter\suggest\CraftSeoFields;
use nineteenninetyfour\ghostwriter\suggest\SeomaticBundles;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's SeoFieldsContract against CraftSeoFields, for SEOmatic 5.1 as the
 * Northfold test site has it (captured 2026-10-04; SEOmatic needn't be
 * installed here): the SEO Settings field's value as EntryReader
 * serializes it, never saved (every value empty, every source
 * `fromCustom`) or saved with its override switches, and the rows of
 * `seomatic_metabundles` above it: the Journal's bundle with title and
 * description `fromField` (and the Twig SEOmatic writes for them), the
 * Pages' with the title only, and the global one ("Northfold", `before`,
 * robots `all`). Plain fields too.
 */
class SeoFieldsTest extends TestCase
{
    use SeoFieldsContract;

    private const EXCERPT = 'Northfold is taking on its first apprentice in January. Two years on site and in the studio, no degree needed, and applications close on 30 November.';

    /** The section and global bundles, as their rows hold them (the settings that matter here). */
    private const BUNDLES = [
        'journal' => [
            'metaGlobalVars' => ['seoTitle' => '{{ seomatic.helper.extractTextFromField(entry.title) }}', 'seoDescription' => '{{ seomatic.helper.extractTextFromField(entry.excerpt) }}', 'siteNamePosition' => '', 'robots' => 'all', 'inherited' => [], 'overrides' => []],
            'metaSiteVars' => [],
            'metaBundleSettings' => ['seoTitleSource' => 'fromField', 'seoTitleField' => 'title', 'siteNamePositionSource' => 'sameAsGlobal', 'seoDescriptionSource' => 'fromField', 'seoDescriptionField' => 'excerpt'],
        ],
        'pages' => [
            'metaGlobalVars' => ['seoTitle' => '{{ seomatic.helper.extractTextFromField(entry.title) }}', 'seoDescription' => '', 'siteNamePosition' => '', 'robots' => 'all', 'inherited' => [], 'overrides' => []],
            'metaSiteVars' => [],
            'metaBundleSettings' => ['seoTitleSource' => 'fromField', 'seoTitleField' => 'title', 'siteNamePositionSource' => 'sameAsGlobal', 'seoDescriptionSource' => 'fromCustom', 'seoDescriptionField' => ''],
        ],
        SeomaticBundles::GLOBAL => [
            'metaGlobalVars' => ['seoTitle' => '', 'seoDescription' => '', 'siteNamePosition' => 'before', 'robots' => 'all', 'inherited' => [], 'overrides' => []],
            'metaSiteVars' => ['siteName' => 'Northfold'],
            'metaBundleSettings' => ['seoTitleSource' => 'fromCustom', 'seoTitleField' => '', 'siteNamePositionSource' => 'fromCustom', 'seoDescriptionSource' => 'fromCustom', 'seoDescriptionField' => ''],
        ],
    ];

    /** The field's settings: its general tab. */
    private const ENABLED = ['seoTitle', 'seoDescription', 'seoKeywords', 'seoImage', 'seoImageDescription', 'robots', 'canonicalUrl'];

    protected function seoFields(): SeoFields
    {
        return new CraftSeoFields(new class(self::BUNDLES, self::ENABLED) extends SeomaticBundles {
            /**
             * @param array<string, array<string, array<string, mixed>>> $rows
             * @param list<string> $enabled
             */
            public function __construct(private array $rows, private array $enabled)
            {
            }

            public function section(string $handle, int $siteId): ?array
            {
                return $this->rows[$handle] ?? null;
            }

            public function global(int $siteId): ?array
            {
                return $this->rows[self::GLOBAL];
            }

            public function installed(): bool
            {
                return true;
            }

            public function separator(): string
            {
                return '|';
            }

            public function enabledFields(string $fieldHandle): ?array
            {
                return $this->enabled;
            }
        });
    }

    private function schema(): Schema
    {
        // The Journal's article type, as SchemaReader reads it.
        return Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'title', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'excerpt', 'type' => 'craft\fields\PlainText', 'kind' => 'longtext', 'display' => 'Excerpt'],
            ['handle' => 'body', 'type' => 'craft\ckeditor\Field', 'kind' => 'richtext', 'display' => 'Body'],
            ['handle' => 'seoSettings', 'type' => CraftSeoFields::SEOMATIC, 'kind' => 'reference', 'display' => 'SEO'],
        ]);
    }

    /**
     * The SEO Settings field's value: never saved, with these changes.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function field(array $vars = [], array $settings = []): array
    {
        return [
            'bundleVersion' => '1.0.62',
            'sourceBundleType' => 'field',
            'sourceId' => null,
            'sourceHandle' => null,
            'typeId' => null,
            'sourceSiteId' => null,
            'metaGlobalVars' => $vars + ['seoTitle' => '', 'siteNamePosition' => '', 'seoDescription' => '', 'robots' => '', 'inherited' => [], 'overrides' => []],
            'metaBundleSettings' => $settings + ['seoTitleSource' => 'fromCustom', 'seoTitleField' => '', 'siteNamePositionSource' => '', 'seoDescriptionSource' => 'fromCustom', 'seoDescriptionField' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $seo
     */
    private function articlePost(array $seo, string $section = 'journal', string $excerpt = self::EXCERPT): EntryData
    {
        return new EntryData(['title' => 'Our first apprentice', 'excerpt' => $excerpt, 'body' => '<p>Northfold is taking on its first apprentice.</p>', 'seoSettings' => $seo], 109, 'Our first apprentice', group: $section, site: 'default');
    }

    protected function seoEntry(string $state): ?array
    {
        $seo = match ($state) {
            // Typed in the field with its switch on, as the form posts it.
            'custom' => self::field(['seoDescription' => 'Two years on site and in the studio, no degree needed: our first apprenticeship starts in January.', 'overrides' => ['seoDescription' => true]]),
            'field' => self::field(['seoDescription' => '{{ seomatic.helper.extractTextFromField(entry.excerpt) }}', 'overrides' => ['seoDescription' => true]], ['seoDescriptionSource' => 'fromField', 'seoDescriptionField' => 'excerpt']),
            'template' => self::field(['seoDescription' => '{{ entry.excerpt|slice(0, 150) }}', 'overrides' => ['seoDescription' => true]]),
            'disabled' => self::field(['overrides' => ['seoDescription' => true]], ['seoDescriptionSource' => 'none']),
            'section' => self::field(),
            'noindex' => self::field(['robots' => 'noindex', 'overrides' => ['robots' => true]]),
        };

        return [$this->schema(), $this->articlePost($seo)];
    }

    protected function seoTitle(): ?array
    {
        return ['Our first apprentice', 'Northfold | Our first apprentice'];
    }

    private function find(EntryData $entry, string $role = SeoField::DESCRIPTION): SeoField
    {
        return array_values(array_filter($this->seoFields()->in($this->schema(), $entry), fn(SeoField $field) => $field->role === $role))[0];
    }

    public function test_seomatic_values_are_found_by_their_place_in_the_field(): void
    {
        [, $entry] = $this->seoEntry('custom');
        $description = $this->find($entry);

        $this->assertSame('seoSettings.metaGlobalVars.seoDescription', $description->path->dotted());
        $this->assertTrue($description->writable);
    }

    public function test_a_field_never_saved_prints_the_sections_excerpt(): void
    {
        $description = $this->find($this->articlePost(self::field()));
        $title = $this->find($this->articlePost(self::field()), SeoField::TITLE);

        $this->assertSame(SeoSource::Field, $description->source, 'Not an empty value of the page\'s own.');
        $this->assertSame('Excerpt', $description->inheritsFrom);
        $this->assertSame(self::EXCERPT, $description->text);
        $this->assertFalse($description->writable, 'With the switch off, SEOmatic would blank a value written in place.');
        $this->assertSame('Our first apprentice', $title->text);
    }

    public function test_text_typed_with_the_switch_off_is_still_the_sections(): void
    {
        // As the form posts it with override-seoDescription off.
        $description = $this->find($this->articlePost(self::field(['seoDescription' => 'Typed text', 'inherited' => ['seoDescription' => true]])));

        $this->assertSame(SeoSource::Field, $description->source);
        $this->assertSame(self::EXCERPT, $description->text);
    }

    public function test_a_setting_the_field_doesnt_offer_is_inherited(): void
    {
        $fields = new CraftSeoFields(new class(self::BUNDLES) extends SeomaticBundles {
            /** @param array<string, array<string, array<string, mixed>>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function section(string $handle, int $siteId): ?array
            {
                return $this->rows[$handle] ?? null;
            }

            public function global(int $siteId): ?array
            {
                return $this->rows[self::GLOBAL];
            }

            public function installed(): bool
            {
                return true;
            }

            public function enabledFields(string $fieldHandle): ?array
            {
                return ['seoTitle'];
            }
        });
        $found = $fields->in($this->schema(), $this->articlePost(self::field(['seoDescription' => 'Old text', 'overrides' => ['seoDescription' => true]])));

        $this->assertSame(self::EXCERPT, $found[1]->text, 'SEOmatic blanks a value its field doesn\'t offer.');
    }

    public function test_an_empty_excerpt_is_inherited_and_empty(): void
    {
        $description = $this->find($this->articlePost(self::field(), 'journal', ''));

        $this->assertSame(SeoSource::Field, $description->source);
        $this->assertTrue($description->isEmpty(), 'Worth a description of its own.');
    }

    public function test_a_section_with_no_description_and_none_above_prints_none(): void
    {
        $description = $this->find($this->articlePost(self::field(), 'pages'));

        $this->assertSame(SeoSource::Custom, $description->source);
        $this->assertTrue($description->isEmpty());
    }

    public function test_robots_from_the_field_the_section_or_the_global_bundle(): void
    {
        $schema = $this->schema();

        $this->assertFalse($this->seoFields()->noindex($schema, $this->articlePost(self::field())), 'The section\'s "all".');
        $this->assertFalse($this->seoFields()->noindex($schema, $this->articlePost(self::field(['robots' => 'noindex', 'inherited' => ['robots' => true]]))), 'Switched off: the section\'s.');
        $this->assertTrue($this->seoFields()->noindex($schema, $this->articlePost(self::field(['robots' => 'none', 'overrides' => ['robots' => true]]))));
    }

    public function test_plain_fields_keep_their_character_limit(): void
    {
        $schema = Schema::fromSpecs([['handle' => 'metaDescription', 'type' => 'craft\fields\PlainText', 'kind' => 'longtext', 'display' => 'Meta description', 'maxLength' => 150]]);
        $found = $this->seoFields()->in($schema, new EntryData(['metaDescription' => str_repeat('word ', 40)]));

        $this->assertCount(1, $found);
        $this->assertSame(150, $found[0]->limit);
        $this->assertTrue($found[0]->tooLong());
    }
}
