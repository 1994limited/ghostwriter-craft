<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestOptions;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * What Suggest edits' free checks read on a Craft site: an entry as it
 * stands (the canonical entry, or someone's draft or provisional draft) in
 * core's CheckContext, with Finish this page's ports plus alt text and SEO
 * fields, the entry's age, its site's language, and the three settings.
 *
 * Nothing here calls a model or saves anything.
 */
class EntryChecks
{
    /**
     * An entry wherever it is edited: its section, its canonical ID (a
     * draft is its entry's), and its site's ID.
     */
    public static function ref(Entry $entry): EntryRef
    {
        return new EntryRef((string) ($entry->getSection()?->handle ?? ''), (int) ($entry->getCanonicalId() ?? $entry->id), (int) $entry->siteId);
    }

    /**
     * The checks' context for an entry as it stands.
     *
     * @param array<string, LinkResult> $external The weekly link check's results for its links.
     */
    public function context(Entry $entry, ?DateTimeImmutable $now = null, ?EntryIndex $index = null, ?Quieted $quieted = null, array $external = []): CheckContext
    {
        return new CheckContext(
            gaps: $this->withPorts(Plugin::getInstance()->gaps->context($entry)),
            now: $now ?? new DateTimeImmutable(),
            updatedAt: self::updatedAt($entry),
            language: self::language((int) $entry->siteId),
            index: $index,
            entry: self::ref($entry),
            age: $this->age(),
            options: $this->options(),
            quieted: $quieted ?? new Quieted(),
            external: $external,
        );
    }

    public function withPorts(GapContext $gaps): GapContext
    {
        return new GapContext(
            schema: $gaps->schema,
            entry: self::canonicalBlocks($gaps->entry),
            richText: $gaps->richText,
            links: $gaps->links,
            placeholders: $gaps->placeholders,
            assets: $gaps->assets,
            targets: $gaps->targets,
            stock: $gaps->stock,
            pattern: $gaps->pattern,
            session: $gaps->session,
            sources: $gaps->sources,
            alt: new CraftAssetAlt(),
            seo: new CraftSeoFields(),
        );
    }

    /**
     * The entry's values with each Matrix entry and Neo block known by its
     * canonical ID. A provisional draft has its own copy of each block it
     * changed, under a new ID; by the canonical one, a review's places are
     * the same in the entry and in anyone's draft of it, and a decision
     * made in one holds in the other. The guide finds each block in the
     * form it's showing (SuggestEdits).
     */
    public static function canonicalBlocks(EntryData $entry): EntryData
    {
        $ids = [];
        $collect = function(mixed $value) use (&$collect, &$ids): void {
            if (!is_array($value)) {
                return;
            }

            if (isset($value['id'], $value['type']) && is_numeric($value['id'])) {
                $ids[] = (int) $value['id'];
            }

            foreach ($value as $item) {
                $collect($item);
            }
        };
        $collect($entry->values);

        if ($ids === []) {
            return $entry;
        }

        $canonical = array_map('intval', array_filter((new \craft\db\Query())->select(['canonicalId', 'id'])->from('{{%elements}}')->where(['id' => array_unique($ids)])->andWhere(['not', ['canonicalId' => null]])->indexBy('id')->column()));

        if ($canonical === []) {
            return $entry;
        }

        $map = function(mixed $value) use (&$map, $canonical): mixed {
            if (!is_array($value)) {
                return $value;
            }

            if (isset($value['id'], $value['type']) && is_numeric($value['id']) && isset($canonical[(int) $value['id']])) {
                $value['id'] = $canonical[(int) $value['id']];
            }

            return array_map($map, $value);
        };

        return new EntryData($map($entry->values), $entry->id, $entry->title(), $entry->parentId, $entry->parentTitle);
    }

    /**
     * Age counts a quarter in sections ordered by date (channels: news, a
     * journal), unless a manager has said it counts in full there.
     */
    public function age(): AgePolicy
    {
        $dated = array_values(array_map(
            fn(Section $section) => $section->handle,
            array_filter(Plugin::getInstance()->types->sections(), fn(Section $section) => $section->type === Section::TYPE_CHANNEL),
        ));

        return AgePolicy::fromGroups($dated, Plugin::getInstance()->getSettings()->ageInFull());
    }

    public function options(): SuggestOptions
    {
        return new SuggestOptions(claims: Plugin::getInstance()->getSettings()->checksClaims());
    }

    /** The language the phrase checks read: the site's own ("en-GB", "de"). */
    public static function language(int $siteId): string
    {
        return (string) (Craft::$app->getSites()->getSiteById($siteId, true)?->language ?? Craft::$app->language ?? 'en');
    }

    /**
     * The site's own hosts, every site's: links to them aren't "other
     * sites".
     *
     * @return list<string>
     */
    public static function ownHosts(): array
    {
        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            try {
                $host = parse_url((string) $site->getBaseUrl(), PHP_URL_HOST);
            } catch (Throwable) {
                $host = null;
            }

            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * When the entry was last saved: a draft's is its entry's, so typing
     * in one doesn't make the page new.
     */
    public static function updatedAt(Entry $entry): ?DateTimeImmutable
    {
        $canonical = $entry->getIsCanonical() ? $entry : ($entry->getCanonical(true) ?? $entry);
        $updated = $canonical->dateUpdated;

        return $updated ? DateTimeImmutable::createFromInterface($updated) : null;
    }
}
