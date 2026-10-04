<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Where a Craft entry's SEO title and description are:
 *
 * - **SEOmatic:** an SEO Settings field, whose value (as EntryReader
 *   serializes it) has the text in `metaGlobalVars` and where it comes
 *   from in `metaBundleSettings`. `fromCustom` is the page's own text,
 *   written through the field's input; `fromField` is another field's
 *   text, checked and shown as inherited, with nothing to write here
 *   (SEOmatic's setting says where it comes from); anything else (the
 *   section's defaults, a template, an asset) isn't checked.
 * - **Plain fields:** `seoTitle`, `metaTitle`, `seoDescription`,
 *   `metaDescription` (core's PlainSeoFields), with a Plain Text field's
 *   character limit as the limit.
 */
class CraftSeoFields implements SeoFields
{
    /** SEOmatic's SEO Settings field, by class, so it needn't be installed. */
    public const SEOMATIC = 'nystudio107\seomatic\fields\SeoSettings';

    private const ROLES = [
        SeoField::TITLE => ['seoTitle', 'seoTitleSource', 'seoTitleField'],
        SeoField::DESCRIPTION => ['seoDescription', 'seoDescriptionSource', 'seoDescriptionField'],
    ];

    public function in(Schema $schema, EntryData $entry): array
    {
        $found = [];

        foreach ($schema->fields as $field) {
            if ($field->type === self::SEOMATIC) {
                array_push($found, ...$this->seomatic($field, $schema, $entry));
            }
        }

        return [...$found, ...(new PlainSeoFields())->in($schema, $entry)];
    }

    /**
     * @return list<SeoField>
     */
    private function seomatic(Field $field, Schema $schema, EntryData $entry): array
    {
        $value = $entry->get($field->handle);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return [];
        }

        $vars = is_array($value['metaGlobalVars'] ?? null) ? $value['metaGlobalVars'] : [];
        $settings = is_array($value['metaBundleSettings'] ?? null) ? $value['metaBundleSettings'] : [];
        $found = [];

        foreach (self::ROLES as $role => [$key, $sourceKey, $fieldKey]) {
            $source = (string) ($settings[$sourceKey] ?? '');
            $path = FieldPath::of($field->handle)->with('metaGlobalVars')->with($key);
            $label = Craft::t('ghostwriter', $role === SeoField::TITLE ? 'SEO title' : 'SEO description');

            // Switched off for this entry, or not kept by this field.
            if (in_array($source, ['none', 'disabled'], true) || ($source === '' && !array_key_exists($key, $vars))) {
                continue;
            }

            if ($source === 'fromCustom') {
                $text = (string) ($vars[$key] ?? '');

                // A Twig template in a custom value isn't text to check.
                $found[] = str_contains($text, '{{') || str_contains($text, '{%')
                    ? new SeoField($path, $role, $label, SeoField::LIMITS[$role], null, false)
                    : new SeoField($path, $role, $label, SeoField::LIMITS[$role], $text);

                continue;
            }

            if ($source === 'fromField' && is_string($settings[$fieldKey] ?? null) && $settings[$fieldKey] !== '') {
                $handle = $settings[$fieldKey];
                $text = $entry->get($handle);
                $text = is_scalar($text) ? trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5)) : '';
                $found[] = new SeoField($path, $role, $label, SeoField::LIMITS[$role], $text, false, self::labelOf($schema, $handle));

                continue;
            }

            // The section's defaults, an asset or a template: not checked.
            $found[] = new SeoField($path, $role, $label, SeoField::LIMITS[$role], null, false);
        }

        return $found;
    }

    private static function labelOf(Schema $schema, string $handle): string
    {
        foreach ($schema->fields as $field) {
            if ($field->handle === $handle) {
                return $field->label !== '' ? $field->label : $handle;
            }
        }

        return $handle;
    }
}
