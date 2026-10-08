<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Categories;
use craft\fields\Tags;
use craft\helpers\ElementHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RowKind;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * The pages of a Craft site a page can link to (SEO layer §7.1), and each
 * as core's IndexRow:
 *
 * - entries of every section with URLs for the site (a URI format in its
 *   site settings), singles included: Contact and About often are;
 * - categories of every group with URLs for the site, when the category
 *   has text of its own (MIN_WORDS): a bare listing is a poor target.
 *
 * Published is Craft's `live` for the site; a scheduled entry (`pending`)
 * is kept with its post date, and an expiry date is kept, so core's
 * Linkable leaves them out until (and from) their day. Robots come from
 * CraftSeoFields (SEOmatic: the entry's field, the section, the site),
 * else a plain lightswitch such as `noindex`. Key pages are level 1 of a
 * Structure section. A link to a page is a reference, `{entry:12@1:url}`,
 * so a slug changed between passes never breaks one.
 *
 * Internal to the plugin; nothing here calls a model.
 */
class CraftLinkSource
{
    /** A category's own text, in words, before it's a link target. */
    public const MIN_WORDS = 40;

    /** A category group's rows are kept under this prefix and its handle, apart from sections'. */
    public const CATEGORY = 'category.';

    /** The kinds of field (SchemaReader) that hold prose. */
    private const PROSE = ['text', 'longtext', 'richtext', 'blocks', 'rows'];

    /** A first text this long is a summary. */
    private const SUMMARY_WORDS = 8;

    /** @var array<int, array{schema: Schema, specs: array<int, array<string, mixed>>}> Field layouts read, by layout ID. */
    private array $schemas = [];

    /**
     * The routable groups of a site: sections and category groups with a
     * URI format for it.
     *
     * @return list<array{group: string, kind: RowKind, id: int, label: string, structure: bool}>
     */
    public function groups(int $siteId): array
    {
        $groups = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (self::sectionHasUrls($section, $siteId)) {
                $groups[] = ['group' => (string) $section->handle, 'kind' => RowKind::Entry, 'id' => (int) $section->id, 'label' => Craft::t('site', (string) $section->name), 'structure' => $section->type === Section::TYPE_STRUCTURE];
            }
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if (self::categoryGroupHasUrls($group, $siteId)) {
                $groups[] = ['group' => self::CATEGORY . $group->handle, 'kind' => RowKind::Category, 'id' => (int) $group->id, 'label' => Craft::t('site', (string) $group->name), 'structure' => false];
            }
        }

        return $groups;
    }

    /**
     * Ghostwriter's own sections: their pages have full rows.
     *
     * @return list<string>
     */
    public function fullGroups(): array
    {
        return array_values(array_map(fn(Section $section) => (string) $section->handle, Plugin::getInstance()->types->sections()));
    }

    /** What editors call a group: the section's or category group's name. */
    public function label(string $group): string
    {
        if (str_starts_with($group, self::CATEGORY)) {
            $found = Craft::$app->getCategories()->getGroupByHandle(substr($group, strlen(self::CATEGORY)));
        } else {
            $found = Craft::$app->getEntries()->getSectionByHandle($group);
        }

        return $found !== null ? Craft::t('site', (string) $found->name) : $group;
    }

    /**
     * The linkable pages of a site's routable groups outside Ghostwriter's
     * own, as core's LinkPlan reads them: keys, dates and the key-page flag
     * only (entries aren't loaded; categories are, for their text).
     *
     * @return iterable<array{key: string, group: string, updated: ?string, key_page: bool, kind: RowKind, id: int}>
     */
    public function pages(int $siteId): iterable
    {
        $full = array_flip($this->fullGroups());

        foreach ($this->groups($siteId) as $group) {
            if (isset($full[$group['group']])) {
                continue;
            }

            if ($group['kind'] === RowKind::Entry) {
                $columns = ['elements.id', 'elements.dateUpdated'];

                if ($group['structure']) {
                    $columns[] = 'structureelements.level';
                }

                $query = Entry::find()->sectionId($group['id'])->siteId($siteId)->status([Entry::STATUS_LIVE, Entry::STATUS_PENDING])->select($columns)->orderBy(['elements.id' => SORT_ASC])->asArray();

                foreach ($query->each(1000) as $page) {
                    yield [
                        'key' => (new EntryRef($group['group'], (int) $page['id'], $siteId))->key(),
                        'group' => $group['group'],
                        'updated' => self::utc($page['dateUpdated'] ?? null),
                        'key_page' => $group['structure'] && (int) ($page['level'] ?? 0) === 1,
                        'kind' => RowKind::Entry,
                        'id' => (int) $page['id'],
                    ];
                }

                continue;
            }

            foreach (Category::find()->groupId($group['id'])->siteId($siteId)->status(Category::STATUS_ENABLED)->orderBy(['elements.id' => SORT_ASC])->each(200) as $category) {
                if ($category instanceof Category && $this->words($category) >= self::MIN_WORDS) {
                    yield [
                        'key' => (new EntryRef($group['group'], (int) $category->id, $siteId))->key(),
                        'group' => $group['group'],
                        'updated' => self::iso($category->dateUpdated),
                        'key_page' => false,
                        'kind' => RowKind::Category,
                        'id' => (int) $category->id,
                    ];
                }
            }
        }
    }

    /**
     * Rows for pages the daily pass lists, read in one query per kind.
     *
     * @param list<array{kind: RowKind, id: int}> $pages
     * @return list<IndexRow>
     */
    public function rows(array $pages, int $siteId, ?DateTimeImmutable $now = null): array
    {
        $ids = [RowKind::Entry->value => [], RowKind::Category->value => []];

        foreach ($pages as $page) {
            $ids[$page['kind']->value][] = $page['id'];
        }

        $rows = [];
        $elements = [
            ...($ids['entry'] !== [] ? Entry::find()->id($ids['entry'])->siteId($siteId)->status(null)->all() : []),
            ...($ids['category'] !== [] ? Category::find()->id($ids['category'])->siteId($siteId)->status(null)->all() : []),
        ];

        foreach ($elements as $element) {
            if (($row = $this->row($element, IndexScope::Link, $now)) !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * A page as an index row, in the given scope; null for an element that
     * isn't a page (a nested entry, a draft). A row of a page that can't be
     * linked to (not live or scheduled, no URI for the site, a category
     * without text of its own) says so (published false, or no address),
     * so the index forgets it.
     */
    public function row(ElementInterface $element, IndexScope $scope = IndexScope::Link, ?DateTimeImmutable $now = null): ?IndexRow
    {
        if (!($element instanceof Entry || $element instanceof Category) || ElementHelper::isDraftOrRevision($element) || !$element->getIsCanonical()) {
            return null;
        }

        $now ??= new DateTimeImmutable();
        $ref = self::ref($element);

        if ($ref === null) {
            return null;
        }

        $siteId = (int) $element->siteId;

        try {
            [$schema, $data, $specs] = $this->read($element);
            $texts = self::texts($data->values, $specs);
            $summary = $this->seoDescription($schema, $data);
            $summary = $summary !== '' ? $summary : ($element instanceof Entry ? Revisit::summary($element) : '');

            if ($summary === '') {
                foreach ($texts as $text) {
                    if (count(preg_split('/\s+/u', $text) ?: []) >= self::SUMMARY_WORDS) {
                        $summary = $text;

                        break;
                    }
                }
            }

            $noindex = $this->noindex($schema, $data);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't read {$ref->key()} for the link index: {$exception->getMessage()}", 'ghostwriter');
            $texts = [];
            $summary = '';
            $noindex = false;
        }

        if ($element instanceof Entry) {
            $section = $element->getSection();
            $routable = $section !== null && self::sectionHasUrls($section, $siteId);
            $published = in_array($element->getStatus(), [Entry::STATUS_LIVE, Entry::STATUS_PENDING], true);
            $postDate = $element->postDate;
            $liveFrom = $postDate !== null && $postDate > $now ? self::iso($postDate) : null;
            $liveUntil = $element->expiryDate !== null ? self::iso($element->expiryDate) : null;
            $key = $section !== null && $section->type === Section::TYPE_STRUCTURE && (int) $element->level === 1;
            $type = $section !== null ? Craft::t('site', (string) $section->name) : '';
            $kind = RowKind::Entry;
            $link = "{entry:{$element->id}@{$siteId}:url}";
        } else {
            $group = $element->getGroup();
            $routable = self::categoryGroupHasUrls($group, $siteId);
            $published = $element->getStatus() === Category::STATUS_ENABLED && self::wordCount($texts) >= self::MIN_WORDS;
            $liveFrom = null;
            $liveUntil = null;
            $key = false;
            $type = Craft::t('site', (string) $group->name);
            $kind = RowKind::Category;
            $link = "{category:{$element->id}@{$siteId}:url}";
        }

        return IndexRow::make(
            $ref,
            $scope,
            (string) ($element->title ?? ''),
            $routable ? self::path($element->uri) : null,
            mb_substr($summary, 0, DigestEntry::SUMMARY * 2),
            $type,
            $kind,
            $liveFrom,
            $liveUntil,
            $noindex,
            $key,
            $link,
            self::iso($element->dateUpdated) ?? '',
            $published,
            $now->format(DATE_ATOM),
            Craft::$app->getSites()->getSiteById($siteId, true)?->language,
            terms: self::terms($element),
        );
    }

    /**
     * What a page is filed under, for related pages (LinkCandidates): an
     * entry's categories and tags ("category:12", "tag:7"), from its
     * Categories and Tags fields; a category is filed under itself.
     *
     * @return list<string>
     */
    public static function terms(Entry|Category $element): array
    {
        if ($element instanceof Category) {
            return ['category:' . (int) ($element->getCanonicalId() ?? $element->id)];
        }

        $terms = [];

        try {
            foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!($field instanceof Categories || $field instanceof Tags)) {
                    continue;
                }

                $prefix = $field instanceof Categories ? 'category:' : 'tag:';
                $value = $element->getFieldValue((string) $field->handle);
                $ids = $value instanceof ElementQueryInterface ? (clone $value)->status(null)->ids() : (is_object($value) && method_exists($value, 'ids') ? $value->ids() : []);

                foreach ($ids as $id) {
                    $terms[] = $prefix . (int) $id;
                }
            }
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't read what entry {$element->id} is filed under: {$exception->getMessage()}", 'ghostwriter');
        }

        return array_values(array_unique($terms));
    }

    /**
     * Whether a save of this element may change the link index: the
     * element itself (not a draft, a revision or a nested entry) in a
     * section or category group with URLs on some site.
     */
    public static function linkable(ElementInterface $element): bool
    {
        if (ElementHelper::isDraftOrRevision($element) || !$element->getIsCanonical()) {
            return false;
        }

        if ($element instanceof Entry) {
            $section = $element->getSection();

            return $section !== null && array_filter(array_keys($section->getSiteSettings()), fn($siteId) => self::sectionHasUrls($section, (int) $siteId)) !== [];
        }

        if ($element instanceof Category) {
            $group = $element->getGroup();

            return array_filter(array_keys($group->getSiteSettings()), fn($siteId) => self::categoryGroupHasUrls($group, (int) $siteId)) !== [];
        }

        return false;
    }

    /** The index's reference to a page: its section (or `category.` and group), canonical ID and site. */
    public static function ref(ElementInterface $element): ?EntryRef
    {
        if ($element instanceof Entry) {
            $section = $element->getSection();

            return $section !== null ? new EntryRef((string) $section->handle, (int) ($element->getCanonicalId() ?? $element->id), (int) $element->siteId) : null;
        }

        if ($element instanceof Category) {
            return new EntryRef(self::CATEGORY . $element->getGroup()->handle, (int) ($element->getCanonicalId() ?? $element->id), (int) $element->siteId);
        }

        return null;
    }

    /** The section a structure belongs to, or the category group; null for any other structure. */
    public static function groupOfStructure(int $structureId): ?string
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ((int) $section->structureId === $structureId) {
                return (string) $section->handle;
            }
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ((int) $group->structureId === $structureId) {
                return self::CATEGORY . $group->handle;
            }
        }

        return null;
    }

    public static function sectionHasUrls(Section $section, int $siteId): bool
    {
        $settings = $section->getSiteSettings()[$siteId] ?? null;

        return $settings !== null && $settings->hasUrls && trim((string) $settings->uriFormat) !== '';
    }

    public static function categoryGroupHasUrls(CategoryGroup $group, int $siteId): bool
    {
        $settings = $group->getSiteSettings()[$siteId] ?? null;

        return $settings !== null && $settings->hasUrls && trim((string) $settings->uriFormat) !== '';
    }

    /** The site-relative address of a URI: '/garden-services/winter-care'; the home page '/'; null without one. */
    public static function path(?string $uri): ?string
    {
        if ($uri === null || trim($uri) === '') {
            return null;
        }

        if ($uri === Element::HOMEPAGE_URI) {
            return '/';
        }

        return '/' . trim($uri, '/');
    }

    /**
     * Whether the page asks search engines not to index it: SEOmatic's
     * robots setting (the entry's field, the section, the site), or a plain
     * lightswitch such as `noindex`.
     */
    private function noindex(Schema $schema, EntryData $data): bool
    {
        return (new CraftSeoFields())->noindex($schema, $data) === true || (new PlainSeoFields())->noindex($schema, $data) === true;
    }

    /** The page's SEO description where an SEO field gives one. */
    private function seoDescription(Schema $schema, EntryData $data): string
    {
        foreach ((new CraftSeoFields())->in($schema, $data) as $field) {
            if ($field->role === SeoField::DESCRIPTION && trim((string) $field->text) !== '') {
                return trim((string) $field->text);
            }
        }

        return '';
    }

    /**
     * The element's fields and values, as Finish this page reads them.
     *
     * @return array{0: Schema, 1: EntryData, 2: array<int, array<string, mixed>>}
     */
    private function read(Entry|Category $element): array
    {
        $layout = $element->getFieldLayout();
        $id = (int) ($layout?->id ?? 0);

        if (!isset($this->schemas[$id])) {
            $reader = new SchemaReader();
            $specs = $element instanceof Entry ? $reader->read($element->getType()) : ($layout !== null ? $reader->readLayout($layout) : []);
            $this->schemas[$id] = ['schema' => Schema::fromSpecs($specs), 'specs' => $specs];
        }

        ['schema' => $schema, 'specs' => $specs] = $this->schemas[$id];
        $values = (new EntryReader())->read($element, $specs);

        return [$schema, new EntryData(
            $values,
            (int) $element->id,
            (string) ($element->title ?? ''),
            // A category has no section bundle: its field, then the site's.
            group: $element instanceof Entry ? $element->getSection()?->handle : null,
            site: $element->getSite()->handle,
        ), $specs];
    }

    /** How many words of its own text a category has. */
    private function words(Category $category): int
    {
        try {
            [, $data, $specs] = $this->read($category);

            return self::wordCount(self::texts($data->values, $specs));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The prose in an element's values, in order, as plain text: rich text
     * and plain text fields, and those of its blocks. The title, IDs and
     * single words (handles, options) aren't prose.
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $specs
     * @return list<string>
     */
    private static function texts(array $values, array $specs): array
    {
        $texts = [];
        $walk = function(mixed $value, string|int $key) use (&$walk, &$texts): void {
            if (is_array($value)) {
                foreach ($value as $k => $item) {
                    if (!in_array($k, ['id', 'type', 'enabled', 'title'], true)) {
                        $walk($item, $k);
                    }
                }

                return;
            }

            if (!is_string($value)) {
                return;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>', '</h2>', '</h3>', '</h4>'], ' ', $value)), ENT_QUOTES | ENT_HTML5)));

            if ($text !== '' && str_contains($text, ' ') && !str_starts_with($text, '{') && !str_starts_with($text, '[')) {
                $texts[] = $text;
            }
        };

        foreach ($specs as $spec) {
            $handle = (string) ($spec['handle'] ?? '');

            if ($handle !== 'title' && in_array($spec['kind'] ?? '', self::PROSE, true) && array_key_exists($handle, $values)) {
                $walk($values[$handle], $handle);
            }
        }

        return $texts;
    }

    /**
     * @param list<string> $texts
     */
    private static function wordCount(array $texts): int
    {
        return array_sum(array_map(fn(string $text) => count(preg_split('/\s+/u', trim($text)) ?: []), $texts));
    }

    private static function iso(?DateTimeInterface $date): ?string
    {
        return $date !== null ? DateTimeImmutable::createFromInterface($date)->format(DATE_ATOM) : null;
    }

    /** A date column as Craft stores it (UTC) as ISO. */
    private static function utc(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
