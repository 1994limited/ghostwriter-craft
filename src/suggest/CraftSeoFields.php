<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use Throwable;

/**
 * Where a Craft entry's SEO title and description are, and what the page
 * prints for them:
 *
 * - **SEOmatic:** an SEO Settings field, whose value (as EntryReader
 *   serializes it) is a whole meta bundle: the text in `metaGlobalVars`,
 *   where it comes from in `metaBundleSettings` (`fromCustom`,
 *   `fromField` + the field's handle). The page prints the field's value
 *   only while it overrides the section's: the setting is one the field
 *   lets the entry set, isn't listed in `metaGlobalVars.inherited`, and
 *   isn't empty (its override switch is on); otherwise it inherits the
 *   section's bundle (EntryData's group and site), then the global one
 *   (SeomaticBundles). A `fromField` source, or the Twig SEOmatic writes
 *   for one (`{{ seomatic.helper.extractTextFromField(entry.excerpt) }}`),
 *   is that field's text; other Twig is a template; `none` is switched
 *   off. Ghostwriter can give the page a value of its own wherever the
 *   field offers the setting (writable): it writes the text with the
 *   field's override switch on (CraftSeoWriter), as SEOmatic blanks a
 *   value posted with it off. The switch as the form posts it
 *   (`override-seoDescription`) is read as SEOmatic reads it.
 *   Robots (`metaGlobalVars.robots`) and the site name position resolve
 *   the same way, the site name is the global `metaSiteVars.siteName`,
 *   and the separator the plugin's `separatorChar`. The setting is read,
 *   never the rendered tag (outside the `live` environment SEOmatic
 *   prints `none`).
 * - **Plain fields:** `seoTitle`, `metaTitle`, `seoDescription`,
 *   `metaDescription` (core's PlainSeoFields), with a Plain Text field's
 *   character limit as the limit, and a `noindex` lightswitch.
 */
class CraftSeoFields implements SeoFields
{
    /** SEOmatic's SEO Settings field, by class, so it needn't be installed. */
    public const SEOMATIC = 'nystudio107\seomatic\fields\SeoSettings';

    private const ROLES = [
        SeoField::TITLE => ['seoTitle', 'seoTitleSource', 'seoTitleField'],
        SeoField::DESCRIPTION => ['seoDescription', 'seoDescriptionSource', 'seoDescriptionField'],
    ];

    /** The Twig SEOmatic writes for a `fromField` source (PullFieldHelper::parseTextSources()). */
    private const PULL_FIELD = '/^\{\{\s*seomatic\.helper\.extractTextFromField\(\s*(?:entry|object|element)\.([A-Za-z0-9_]+)\s*\)\s*\}\}$/';

    private const SWITCHED_OFF = ['none', 'disabled'];

    public function __construct(private readonly SeomaticBundles $bundles = new SeomaticBundles()) {}

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

    public function noindex(Schema $schema, EntryData $entry): ?bool
    {
        $field = $this->seomaticField($schema);

        if ($field === null && !$this->bundles->installed()) {
            return (new PlainSeoFields())->noindex($schema, $entry);
        }

        $robots = $this->setting('robots', $field, $entry, fn(array $bundle) => true);

        if ($robots === null) {
            return $field === null ? (new PlainSeoFields())->noindex($schema, $entry) : false;
        }

        if (str_contains($robots, '{{') || str_contains($robots, '{%')) {
            return null;
        }

        $directives = array_map('trim', explode(',', strtolower($robots)));

        return in_array('noindex', $directives, true) || in_array('none', $directives, true);
    }

    public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat
    {
        if (!$this->bundles->installed()) {
            return null;
        }

        $global = $this->bundles->global($this->siteId($entry));

        if ($global === null) {
            return null;
        }

        // A section's own position only with siteNamePositionSource fromCustom; else the global one.
        $position = $this->setting('siteNamePosition', $this->seomaticField($schema), $entry, fn(array $bundle) => ($bundle['metaBundleSettings']['siteNamePositionSource'] ?? 'fromCustom') === 'fromCustom');
        $name = $global['metaSiteVars']['siteName'] ?? '';

        return TitleFormat::of($this->parsed(is_string($name) ? $name : ''), $this->bundles->separator(), $position ?? 'before');
    }

    /**
     * @return list<SeoField>
     */
    private function seomatic(Field $field, Schema $schema, EntryData $entry): array
    {
        $own = self::bundleOf($entry->get($field->handle));
        $enabled = $this->bundles->enabledFields($field->handle);
        $section = $entry->group !== null ? $this->bundles->section($entry->group, $this->siteId($entry)) : null;
        $global = $this->bundles->installed() ? $this->bundles->global($this->siteId($entry)) : null;
        $found = [];

        foreach (self::ROLES as $role => [$key, $sourceKey, $fieldKey]) {
            $path = FieldPath::of($field->handle)->with('metaGlobalVars')->with($key);
            $label = Craft::t('ghostwriter', $role === SeoField::TITLE ? 'SEO title' : 'SEO description');
            $limit = SeoField::LIMITS[$role];
            // Whether the entry's field offers the setting at all: only then can the page have its own.
            $offered = $enabled === null || in_array($key, $enabled, true);
            $overrides = $own !== null && $offered && !self::inherits($own, $key);

            // The entry's own value, while its override is on.
            if ($overrides && ($value = $this->value($own, $key, $sourceKey, $fieldKey, $schema, $entry)) !== null) {
                $found[] = $this->field($path, $role, $label, $limit, $value, true, true);

                continue;
            }

            foreach ([$section, $global] as $bundle) {
                if ($bundle !== null && ($value = $this->value($bundle, $key, $sourceKey, $fieldKey, $schema, $entry)) !== null) {
                    $found[] = $this->field($path, $role, $label, $limit, $value, false, $offered);

                    continue 2;
                }
            }

            // Set nowhere: the page prints none.
            $found[] = new SeoField($path, $role, $label, $limit, '', $offered, source: SeoSource::Custom);
        }

        return $found;
    }

    /**
     * @param array{0: SeoSource, 1: ?string, 2: ?string} $value
     */
    private function field(FieldPath $path, string $role, string $label, int $limit, array $value, bool $own, bool $offered): SeoField
    {
        [$source, $text, $from] = $value;

        if ($source === SeoSource::Custom && !$own) {
            $source = SeoSource::Default;
        }

        // Text of the page's own can go in wherever the field offers the
        // setting (its override switch turned on); never over a template
        // (someone's Twig) or a switched-off one.
        $writable = $offered && in_array($source, [SeoSource::Custom, SeoSource::Default, SeoSource::Field], true);

        return new SeoField($path, $role, $label, $limit, $text, $writable, $from, $source);
    }

    /**
     * What one bundle says for a setting: its source, its text and the
     * label of the field it comes from; null when it says nothing (empty),
     * so the next bundle up applies.
     *
     * @param array<string, array<string, mixed>> $bundle
     * @return array{0: SeoSource, 1: ?string, 2: ?string}|null
     */
    private function value(array $bundle, string $key, string $sourceKey, string $fieldKey, Schema $schema, EntryData $entry): ?array
    {
        $vars = $bundle['metaGlobalVars'] ?? [];
        $settings = $bundle['metaBundleSettings'] ?? [];
        $source = is_string($settings[$sourceKey] ?? null) ? $settings[$sourceKey] : '';
        $raw = is_scalar($vars[$key] ?? null) ? trim((string) $vars[$key]) : '';

        if (in_array($source, self::SWITCHED_OFF, true)) {
            return [SeoSource::Disabled, null, null];
        }

        $handle = $source === 'fromField' && is_string($settings[$fieldKey] ?? null) && $settings[$fieldKey] !== ''
            ? $settings[$fieldKey]
            : (preg_match(self::PULL_FIELD, $raw, $match) === 1 ? $match[1] : null);

        if ($handle !== null) {
            return [SeoSource::Field, self::text($entry->get($handle)), self::labelOf($schema, $handle)];
        }

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '{{') || str_contains($raw, '{%')) {
            return [SeoSource::Template, null, null];
        }

        return [SeoSource::Custom, $raw, null];
    }

    /**
     * A plain setting (robots, the site name position) as the page gets
     * it: the field's while it overrides, else the section's (where
     * $sectionHasIt says it keeps its own), else the global one's; null
     * when none has one.
     *
     * @param callable(array<string, array<string, mixed>>): bool $sectionHasIt
     */
    private function setting(string $key, ?Field $field, EntryData $entry, callable $sectionHasIt): ?string
    {
        $siteId = $this->siteId($entry);

        if ($field !== null && ($own = self::bundleOf($entry->get($field->handle))) !== null) {
            $enabled = $this->bundles->enabledFields($field->handle);
            $value = is_scalar($own['metaGlobalVars'][$key] ?? null) ? trim((string) $own['metaGlobalVars'][$key]) : '';

            if ($value !== '' && ($enabled === null || in_array($key, $enabled, true)) && !self::inherits($own, $key)) {
                return $value;
            }
        }

        $section = $entry->group !== null ? $this->bundles->section($entry->group, $siteId) : null;
        $value = $section !== null && $sectionHasIt($section) && is_scalar($section['metaGlobalVars'][$key] ?? null) ? trim((string) $section['metaGlobalVars'][$key]) : '';

        if ($value !== '') {
            return $value;
        }

        $global = $this->bundles->global($siteId);
        $value = $global !== null && is_scalar($global['metaGlobalVars'][$key] ?? null) ? trim((string) $global['metaGlobalVars'][$key]) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * Whether a field bundle leaves a setting to the section: its
     * override switch as the form posts it (`override-seoDescription`,
     * which InheritableSettingsModel::__set() turns into the lists) off,
     * or listed in `inherited`, or, with neither list naming it, empty
     * (Helper::isInherited()).
     *
     * @param array<string, array<string, mixed>> $bundle
     */
    private static function inherits(array $bundle, string $key): bool
    {
        $vars = $bundle['metaGlobalVars'] ?? [];

        if (array_key_exists("override-{$key}", $vars)) {
            return !$vars["override-{$key}"];
        }
        $inherited = is_array($vars['inherited'] ?? null) && array_key_exists($key, $vars['inherited']);
        $overridden = is_array($vars['overrides'] ?? null) && array_key_exists($key, $vars['overrides']);

        if ($inherited || $overridden) {
            return $inherited && !$overridden;
        }

        return !is_scalar($vars[$key] ?? null) || trim((string) $vars[$key]) === '';
    }

    /**
     * The field's value as a bundle, or null when it holds none.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function bundleOf(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return null;
        }

        return [
            'metaGlobalVars' => is_array($value['metaGlobalVars'] ?? null) ? $value['metaGlobalVars'] : [],
            'metaBundleSettings' => is_array($value['metaBundleSettings'] ?? null) ? $value['metaBundleSettings'] : [],
        ];
    }

    /** A field's value as plain text, as extractTextFromField() gives it. */
    private static function text(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));
    }

    /** A site name as SEOmatic prints it: an environment variable or a little Twig. */
    private function parsed(string $value): string
    {
        $value = (string) App::parseEnv(trim($value));

        if (!str_contains($value, '{')) {
            return $value;
        }

        try {
            return trim(Craft::$app->getView()->renderString($value));
        } catch (Throwable) {
            return '';
        }
    }

    private function siteId(EntryData $entry): int
    {
        try {
            $site = $entry->site !== null ? Craft::$app->getSites()->getSiteByHandle($entry->site, true) : null;

            return (int) ($site ?? Craft::$app->getSites()->getPrimarySite())->id;
        } catch (Throwable) {
            return 1;
        }
    }

    private function seomaticField(Schema $schema): ?Field
    {
        foreach ($schema->fields as $field) {
            if ($field->type === self::SEOMATIC) {
                return $field;
            }
        }

        return null;
    }

    private static function labelOf(Schema $schema, string $handle): string
    {
        $field = $schema->field($handle);

        return $field !== null && $field->label !== '' ? $field->label : $handle;
    }
}
