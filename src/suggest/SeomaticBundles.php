<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use Throwable;

/**
 * SEOmatic's settings above an entry's own SEO Settings field, where
 * SEOmatic keeps them: a section's meta bundle and the site's global one
 * (rows of `seomatic_metabundles`, one per section and site, with
 * `metaGlobalVars`, `metaSiteVars` and `metaBundleSettings` as JSON), the
 * plugin's `separatorChar`, and which settings an SEO Settings field lets
 * the entry set (its `generalEnabledFields` and the other tabs').
 *
 * Read straight from the table, so nothing here needs SEOmatic's classes
 * and nothing is saved. Without the table (SEOmatic not installed), there
 * are no bundles.
 */
class SeomaticBundles
{
    public const TABLE = '{{%seomatic_metabundles}}';

    public const GLOBAL = '__GLOBAL_BUNDLE__';

    /** SEOmatic's default separator (Settings::$separatorChar). */
    public const SEPARATOR = '|';

    /** @var array<string, array<string, array<string, mixed>>|null> */
    private array $bundles = [];

    private ?bool $installed = null;

    /**
     * A section's bundle for a site: `metaGlobalVars`, `metaSiteVars`,
     * `metaBundleSettings`; null when there is none.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function section(string $handle, int $siteId): ?array
    {
        return $this->bundle('section', $handle, $siteId);
    }

    /**
     * The site's global bundle.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function global(int $siteId): ?array
    {
        return $this->bundle(self::GLOBAL, self::GLOBAL, $siteId);
    }

    /** Whether SEOmatic keeps bundles on this install. */
    public function installed(): bool
    {
        if ($this->installed === null) {
            try {
                $this->installed = Craft::$app->getDb()->tableExists(self::TABLE);
            } catch (Throwable) {
                $this->installed = false;
            }
        }

        return $this->installed;
    }

    /** The plugin's `separatorChar` (its settings, `config/seomatic.php`, or the default). */
    public function separator(): string
    {
        try {
            $settings = Craft::$app->getPlugins()->getPlugin('seomatic')?->getSettings();
            $separator = $settings !== null && isset($settings->separatorChar) ? $settings->separatorChar : Craft::$app->getProjectConfig()->get('plugins.seomatic.settings.separatorChar');
        } catch (Throwable) {
            $separator = null;
        }

        return is_string($separator) && trim($separator) !== '' ? (string) App::parseEnv($separator) : self::SEPARATOR;
    }

    /**
     * The settings an SEO Settings field lets the entry set, across its
     * tabs; null when it can't be told (every setting counts as enabled).
     *
     * @return list<string>|null
     */
    public function enabledFields(string $fieldHandle): ?array
    {
        try {
            $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        } catch (Throwable) {
            return null;
        }

        if ($field === null) {
            return null;
        }

        // SEOmatic's field, or a MissingField keeping its settings while the plugin is off.
        $settings = property_exists($field, 'generalEnabledFields') ? (array) get_object_vars($field) : (property_exists($field, 'settings') && is_array($field->settings) ? $field->settings : null);

        if ($settings === null) {
            return null;
        }

        $enabled = [];

        foreach (['generalEnabledFields', 'twitterEnabledFields', 'facebookEnabledFields', 'sitemapEnabledFields'] as $tab) {
            foreach ((array) ($settings[$tab] ?? []) as $setting) {
                if (is_string($setting)) {
                    $enabled[] = $setting;
                }
            }
        }

        return array_values(array_unique($enabled));
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function bundle(string $type, string $handle, int $siteId): ?array
    {
        $key = "{$type}:{$handle}:{$siteId}";

        if (array_key_exists($key, $this->bundles)) {
            return $this->bundles[$key];
        }

        if (!$this->installed()) {
            return $this->bundles[$key] = null;
        }

        try {
            $rows = (new Query())
                ->select(['typeId', 'metaGlobalVars', 'metaSiteVars', 'metaBundleSettings'])
                ->from(self::TABLE)
                ->where(['sourceBundleType' => $type, 'sourceHandle' => $handle, 'sourceSiteId' => $siteId])
                ->all();
        } catch (Throwable) {
            $rows = [];
        }

        // The section's own bundle before any per-entry-type one.
        usort($rows, fn(array $a, array $b) => ($a['typeId'] === null ? 0 : 1) <=> ($b['typeId'] === null ? 0 : 1));
        $row = $rows[0] ?? null;

        if (!is_array($row)) {
            return $this->bundles[$key] = null;
        }

        $bundle = [];

        foreach (['metaGlobalVars', 'metaSiteVars', 'metaBundleSettings'] as $column) {
            $decoded = is_string($row[$column] ?? null) ? json_decode($row[$column], true) : null;
            $bundle[$column] = is_array($decoded) ? $decoded : [];
        }

        return $this->bundles[$key] = $bundle;
    }
}
