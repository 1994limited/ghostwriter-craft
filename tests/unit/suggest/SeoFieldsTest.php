<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoFieldsContract;
use nineteenninetyfour\ghostwriter\suggest\CraftSeoFields;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's SeoFieldsContract against CraftSeoFields, for SEOmatic's SEO
 * Settings field as EntryReader serializes it (SEOmatic needn't be
 * installed): `fromCustom`, `fromField`, the section's defaults, and
 * switched off. Plain fields too.
 */
class SeoFieldsTest extends TestCase
{
    use SeoFieldsContract;

    protected function seoFields(): SeoFields
    {
        return new CraftSeoFields();
    }

    protected function seoEntry(string $state): array
    {
        $schema = Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'title', 'kind' => 'text', 'display' => 'Title'],
            ['handle' => 'summary', 'type' => 'craft\fields\PlainText', 'kind' => 'longtext', 'display' => 'Summary'],
            ['handle' => 'seo', 'type' => CraftSeoFields::SEOMATIC, 'kind' => 'reference', 'display' => 'SEO'],
        ]);

        $source = ['custom' => 'fromCustom', 'field' => 'fromField', 'template' => 'sameAsGlobal', 'disabled' => 'none'][$state];

        return [$schema, new EntryData([
            'title' => 'Services',
            'summary' => 'Garden design and planting plans.',
            'seo' => [
                'metaGlobalVars' => [
                    'seoTitle' => 'Services | Northfold',
                    'seoDescription' => $state === 'custom' ? 'From a single planting plan to a full design and build, across Northumberland, Durham and the Tyne Valley.' : '',
                ],
                'metaBundleSettings' => [
                    'seoTitleSource' => 'fromCustom',
                    'seoDescriptionSource' => $source,
                    'seoDescriptionField' => $state === 'field' ? 'summary' : '',
                ],
            ],
        ])];
    }

    public function test_seomatic_values_are_found_by_their_place_in_the_field(): void
    {
        [$schema, $entry] = $this->seoEntry('custom');
        $description = array_values(array_filter($this->seoFields()->in($schema, $entry), fn(SeoField $field) => $field->role === SeoField::DESCRIPTION))[0];

        $this->assertSame('seo.metaGlobalVars.seoDescription', $description->path->dotted());
        $this->assertTrue($description->writable);
    }

    public function test_one_from_another_field_names_it_and_is_set_in_seomatic(): void
    {
        [$schema, $entry] = $this->seoEntry('field');
        $description = array_values(array_filter($this->seoFields()->in($schema, $entry), fn(SeoField $field) => $field->role === SeoField::DESCRIPTION))[0];

        $this->assertSame('Summary', $description->inheritsFrom);
        $this->assertSame('Garden design and planting plans.', $description->text);
        $this->assertFalse($description->writable, 'Its source is SEOmatic\'s setting, not the form.');
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
