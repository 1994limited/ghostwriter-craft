<?php

namespace nineteenninetyfour\ghostwriter\seo;

use Craft;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SlugContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\suggest\CraftSeoFields;
use Throwable;

/**
 * What core's SEO pass needs for a piece's search title, description and
 * address on Craft (SEO layer §9, §10: Seo\MetaContext):
 *
 * - the SEO fields (CraftSeoFields: SEOmatic, then plain fields), read
 *   from the entry the piece is for, with its section and site: the entry
 *   being edited, or the unpublished draft Craft made when the create
 *   screen opened;
 * - whether it is new: an unpublished draft, or an entry with no post
 *   date (never live);
 * - what Ghostwriter wrote into it before (every piece for the entry,
 *   their SeoState::$written);
 * - the address (SlugContext): set only on a new entry whose slug is
 *   empty, Craft's temporary one, or the one Craft made from its title,
 *   and only where the section's URI format for the site uses the slug.
 *   Channels (a journal, news) are dated. `taken`: the section's slugs on
 *   the site; `base`: the address before the slug, for the Search section.
 */
class MetaContexts
{
    /** At most this many of a section's slugs are read to keep a new one apart. */
    public const TAKEN_LIMIT = 20000;

    /**
     * The context for a piece; null when it can't be told where the piece
     * is going (its kind or section is gone).
     *
     * - $taken: read the section's slugs, for a new address to keep apart
     *   from (not needed only to show the Search section).
     */
    public function for(Session $session, ?ContentKind $kind = null, ?string $voice = null, ?Schema $schema = null, bool $taken = true): ?MetaContext
    {
        try {
            return $this->make($session, $kind, $voice, $schema, $taken);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't get ready to write the search title and description: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }
    }

    private function make(Session $session, ?ContentKind $kind, ?string $voice, ?Schema $schema, bool $taken): ?MetaContext
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($session->kind)?->forSession($session);
        $entryType = $type ? $plugin->types->entryType($type) : null;

        if ($type === null || $entryType === null) {
            return null;
        }

        $siteId = (int) ($session->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
        $site = Craft::$app->getSites()->getSiteById($siteId, true);
        $entry = self::entry($session, $siteId);
        $specs = (new SchemaReader())->read($entry?->getType() ?? $entryType);
        $schema ??= Schema::fromSpecs($specs);
        $data = $entry !== null
            ? self::entryData($entry, $specs)
            : new EntryData([], null, null, group: $type->group, site: $site?->handle);

        return new MetaContext(
            fields: new CraftSeoFields(),
            schema: $schema,
            entry: $data,
            newEntry: $entry === null || self::isNew($entry),
            provenance: self::provenance($session),
            slug: $this->slug($entry, $type, $siteId, $taken),
            kind: $kind ?? $type->toStudio(),
            voice: $voice ?? self::voice(),
            locale: $site?->language ?? Craft::$app->language,
        );
    }

    /** The entry a piece is for, on its site: the one being edited, or a new entry's unpublished draft. */
    public static function entry(Session $session, ?int $siteId = null): ?Entry
    {
        $id = $session->source ?? $session->recordId;

        if (!is_numeric($id)) {
            return null;
        }

        $siteId ??= $session->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;

        return Entry::find()->id((int) $id)->siteId($siteId)->drafts(null)->status(null)->one();
    }

    /**
     * An entry's values as the SEO fields read them, with its section and
     * site (whose SEOmatic bundles apply).
     *
     * @param array<int, array<string, mixed>> $specs SchemaReader's.
     */
    public static function entryData(Entry $entry, array $specs): EntryData
    {
        return new EntryData((new EntryReader())->read($entry, $specs), (int) $entry->id, (string) $entry->title, group: $entry->getSection()?->handle, site: $entry->getSite()->handle);
    }

    /** A new entry, or one never published: an unpublished draft, or an entry that was never given a post date. */
    public static function isNew(Entry $entry): bool
    {
        if ($entry->getIsUnpublishedDraft()) {
            return true;
        }

        $canonical = $entry->getIsDraft() ? $entry->getCanonical(true) : $entry;

        return $canonical->postDate === null;
    }

    /**
     * Whether Ghostwriter may set this entry's slug (SEO layer §10, Craft):
     * a new or never-published entry whose slug is empty or temporary, or,
     * on an unpublished draft, still the one Craft made from its title;
     * never a published entry or a canonical with a slug; and only where
     * the section's address for the site uses the slug.
     */
    public static function slugSettable(Entry $entry): bool
    {
        if (!self::isNew($entry) || !self::usesSlug($entry->getSection(), (int) $entry->siteId)) {
            return false;
        }

        $slug = trim((string) $entry->slug);

        if ($slug === '' || ElementHelper::isTempSlug($slug)) {
            return true;
        }

        if (!$entry->getIsUnpublishedDraft()) {
            return false;
        }

        try {
            return trim((string) $entry->title) !== '' && $slug === ElementHelper::generateSlug((string) $entry->title, null, $entry->getSite()->language);
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether the section's URI format on this site has the slug in it. */
    public static function usesSlug(?Section $section, int $siteId): bool
    {
        $settings = $section?->getSiteSettings()[$siteId] ?? null;

        return $settings !== null && $settings->hasUrls && preg_match('/\bslug\b/', (string) $settings->uriFormat) === 1;
    }

    /**
     * The SEO text Ghostwriter wrote into this piece's entry before: every
     * piece for the same entry, this one included.
     */
    public static function provenance(Session $session): SeoProvenance
    {
        $written = SeoState::of($session)->written;
        $id = $session->source ?? $session->recordId;

        if (!is_numeric($id)) {
            return $written;
        }

        try {
            foreach (Plugin::getInstance()->sessions->forElement((int) $id) as $other) {
                if ($other->id !== $session->id) {
                    $written = $written->merge(SeoState::of($other)->written);
                }
            }
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't read what it wrote into this entry before: {$exception->getMessage()}", 'ghostwriter');
        }

        return $written;
    }

    /** Where the address goes; null where the section's address doesn't use the slug. */
    private function slug(?Entry $entry, ContentType $type, int $siteId, bool $taken): ?SlugContext
    {
        $section = $entry?->getSection() ?? Craft::$app->getEntries()->getSectionByHandle($type->group);

        if (!self::usesSlug($section, $siteId)) {
            return null;
        }

        $settable = $entry === null || self::slugSettable($entry);
        $current = $entry !== null && !ElementHelper::isTempSlug((string) $entry->slug) && trim((string) $entry->slug) !== '' ? (string) $entry->slug : null;

        return new SlugContext(
            settable: $settable,
            dated: $section->type === Section::TYPE_CHANNEL,
            taken: $settable && $taken ? self::taken($section, $siteId, $entry === null ? null : (int) $entry->getCanonicalId()) : [],
            current: $current,
            base: self::base($section, $siteId, $entry),
        );
    }

    /**
     * The slugs the section's other entries have on the site.
     *
     * @return list<string>
     */
    public static function taken(Section $section, int $siteId, ?int $except = null): array
    {
        $query = Entry::find()->section($section->handle)->siteId($siteId)->status(null)->limit(self::TAKEN_LIMIT);

        if ($except !== null) {
            $query->id(['not', $except]);
        }

        return array_values(array_filter(array_map('strval', $query->select(['elements_sites.slug'])->column()), fn(string $slug) => $slug !== '' && !ElementHelper::isTempSlug($slug)));
    }

    /**
     * The address before the slug, as the Search section shows it:
     * "northfold.test/journal/". Anything in the URI format before the slug
     * that needs the entry (`{parent.uri}`) is filled in from it where it
     * can be, and left out where not.
     */
    public static function base(Section $section, int $siteId, ?Entry $entry = null): string
    {
        $format = (string) ($section->getSiteSettings()[$siteId]->uriFormat ?? '');
        $before = preg_split('/\{[^{}]*\bslug\b[^{}]*\}/', $format, 2)[0] ?? '';

        if (str_contains($before, '{')) {
            try {
                $before = $entry !== null ? Craft::$app->getView()->renderObjectTemplate($before, $entry) : '';
            } catch (Throwable) {
                $before = '';
            }

            $before = (string) preg_replace('/\{[^}]*\}/', '', $before);
        }

        $before = (string) preg_replace('#/{2,}#', '/', ltrim($before, '/'));

        try {
            $host = (string) preg_replace('#^[a-z]+://#i', '', rtrim(UrlHelper::siteUrl('', null, null, $siteId), '/'));
        } catch (Throwable) {
            $host = '';
        }

        return ($host !== '' ? $host . '/' : '/') . $before;
    }

    private static function voice(): string
    {
        try {
            return Plugin::getInstance()->domain->guide(\NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide::VOICE)->body;
        } catch (Throwable) {
            return '';
        }
    }
}
