<?php

namespace nineteenninetyfour\ghostwriter\seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Seo\PlainSeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;

/**
 * Writes the draft's search title and description into a Craft entry's
 * values (SEO layer §9.3), in the shape "Use this draft" saves into the
 * provisional draft (DraftValues::for(), then FieldValues and
 * setFieldValues(), as a posted form would):
 *
 * - **SEOmatic** (`seoSettings.metaGlobalVars.seoDescription`, as
 *   CraftSeoFields finds it): the text in `metaGlobalVars`, its source
 *   `fromCustom` in `metaBundleSettings`, and the field's override switch
 *   on, both as the form posts it (`override-seoDescription`) and as the
 *   field stores it (`overrides`, out of `inherited`). Posted with the
 *   switch off, SEOmatic would blank the value and keep the section's.
 *   The rest of the field's bundle is kept; a field with no value yet
 *   gets only these, and SEOmatic fills in its defaults.
 * - **Plain fields** (`seoDescription`, `metaDescription`…): the text, as
 *   it is (core's PlainSeoWriter).
 */
class CraftSeoWriter implements SeoWriter
{
    /** The SEOmatic settings Ghostwriter writes, by the role's variable. */
    private const SOURCES = ['seoTitle' => 'seoTitleSource', 'seoDescription' => 'seoDescriptionSource'];

    public function write(array $values, SeoField $field, string $text): array
    {
        $segments = $field->path->segments;

        if (count($segments) !== 3 || !is_string($segments[0]) || $segments[1] !== 'metaGlobalVars' || !isset(self::SOURCES[$segments[2]])) {
            return (new PlainSeoWriter())->write($values, $field, $text);
        }

        [$handle, , $key] = $segments;
        $bundle = $values[$handle] ?? null;

        if (is_string($bundle)) {
            $bundle = json_decode($bundle, true);
        }

        $bundle = is_array($bundle) ? $bundle : [];
        $vars = is_array($bundle['metaGlobalVars'] ?? null) ? $bundle['metaGlobalVars'] : [];
        $settings = is_array($bundle['metaBundleSettings'] ?? null) ? $bundle['metaBundleSettings'] : [];

        $vars[$key] = $text;
        $inherited = is_array($vars['inherited'] ?? null) ? $vars['inherited'] : [];
        unset($inherited[$key]);
        $vars['inherited'] = $inherited;
        $vars['overrides'] = [...(is_array($vars['overrides'] ?? null) ? $vars['overrides'] : []), $key => true];
        // The switch as the form posts it, last, so nothing after it turns it off again.
        unset($vars["override-{$key}"]);
        $vars["override-{$key}"] = '1';
        $settings[self::SOURCES[$key]] = 'fromCustom';

        $bundle['metaGlobalVars'] = $vars;
        $bundle['metaBundleSettings'] = $settings;
        $values[$handle] = $bundle;

        return $values;
    }
}
