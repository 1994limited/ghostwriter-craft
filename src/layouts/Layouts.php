<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use Craft;
use craft\elements\Entry;
use craft\fields\Link;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Layout\FoundKind;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseResult;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\KindFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts as CoreLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;

/**
 * Core's layout algorithms (describing a field layout, finding how a
 * section's entries are put together and the kinds it holds, the house
 * style, building a draft into entry data), wired for Craft: CKEditor and
 * Redactor HTML, Hyper and Craft's Link field for links. A link the house
 * style can't settle is marked as still to choose with core's sentinel
 * (`https://example.com/#gw-link:<hint>`), which "Finish this page" finds. What stays here is
 * what needs Craft: choosing the entries, and reading them.
 */
class Layouts
{
    /** Hyper's field class, named so Hyper need not be installed. */
    public const HYPER = 'verbb\\hyper\\fields\\HyperField';

    public readonly CoreLayouts $core;

    public function __construct(private EntryReader $reader = new EntryReader())
    {
        $this->core = new CoreLayouts(LayoutOptions::craft()->withLinkSentinels(), new HtmlDialect(), new CraftLinks(hyper: [self::HYPER], link: [Link::class]));
    }

    /**
     * How a section's entries are put together, learned from the entries
     * picked by hand or else its newest live ones.
     *
     * @param string|null $entryType Handle of the entry type to learn from; null for all of the section's.
     * @param array<string, mixed> $where Field => value an entry must have (or contain, for lists).
     * @param array<int, int|string> $examples Entry IDs to learn from instead.
     */
    public function pattern(string $section, Schema $schema, ?string $entryType = null, array $where = [], array $examples = []): Pattern
    {
        // Entries picked by hand are the whole evidence: a section such as
        // Pages holds several kinds of page, and only a person knows which
        // ones a given type should be modelled on.
        $entries = $examples ? Entry::find()->id(array_map('intval', $examples))->status(null)->fixedOrder()->all() : [];

        if ($entries === []) {
            $entries = $this->choose(self::published($section, $entryType), $schema, $where);
        }

        $specs = $schema->toSpecs();
        $pattern = $this->core->patterns()->find($schema, array_map(fn(Entry $entry) => new EntryData($this->reader->read($entry, $specs), (int) $entry->getCanonicalId()), $entries));
        LayoutLog::record('pattern', $pattern);

        return $pattern;
    }

    /**
     * The kinds of entry a section holds, found by grouping the entries
     * built the same way.
     *
     * @return array<int, FoundKind>
     */
    public function kinds(string $section, Schema $schema, ?string $entryType = null): array
    {
        $builder = null;

        foreach ($schema->fields as $field) {
            if ($field->kind === Kind::Blocks) {
                $builder = $field;
                break;
            }
        }

        // Only the page builder is read; without one there is nothing to group.
        $entries = $builder === null ? [] : array_map(function(Entry $entry) use ($builder) {
            $parent = $entry->getParent();

            return new EntryData($this->reader->read($entry, [$builder->toSpec()]), (int) $entry->id, (string) $entry->title, $parent?->id, $parent?->title);
        }, self::published($section, $entryType, KindFinder::SAMPLE));

        $kinds = $this->core->kinds()->find($schema, $entries);
        LayoutLog::record('kinds', $kinds);

        return $kinds;
    }

    /**
     * What the type analyst and the writer are shown of a kind of entry.
     */
    public function layout(Schema $schema, Pattern $pattern): Layout
    {
        $layout = Layout::fromSchema($schema, $pattern, $this->core->describer());
        LayoutLog::record('describe', $layout->fields);

        return $layout;
    }

    /**
     * A draft, as the writer wrote it, made into entry data. Without a
     * pattern (revising an existing entry), only the writing is built.
     *
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $defaults
     */
    public function build(array $draft, Schema $schema, ?Pattern $pattern = null, array $defaults = []): BuiltEntry
    {
        $built = $this->core->builder()->build($draft, $schema, $pattern, $defaults);
        LayoutLog::record('build', $built);

        return $built;
    }

    /**
     * What the model entries agree on, place by place, carried into a new
     * entry's data.
     *
     * @param array<string, mixed> $data
     */
    public function houseStyle(array $data, Schema $schema, HouseRules $house, int|string|null $id = null, string $title = ''): HouseResult
    {
        $result = $this->core->houseStyle()->apply($data, $schema, $house, $id, $title);
        LayoutLog::record('apply', $result);

        return $result;
    }

    /**
     * A section's live entries, newest first, optionally of one entry type.
     *
     * @return Entry[]
     */
    public static function published(string $section, ?string $entryType = null, ?int $limit = null): array
    {
        if (!Craft::$app->getEntries()->getSectionByHandle($section)) {
            return [];
        }

        $query = Entry::find()->section($section)->status('live')->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($entryType) {
            $query->type($entryType);
        }

        return $query->limit($limit)->all();
    }

    /**
     * The entries to learn from, by core's rule (those matching `where`, or
     * all while none do, at most PatternFinder::SAMPLE), reading only the
     * fields `where` names to decide.
     *
     * @param Entry[] $entries
     * @param array<string, mixed> $where
     * @return Entry[]
     */
    private function choose(array $entries, Schema $schema, array $where): array
    {
        $entries = array_values($entries);
        $specs = array_map(fn(Field $field) => $field->toSpec(), array_values(array_filter($schema->fields, fn(Field $field) => array_key_exists($field->handle, $where))));
        $candidates = array_map(fn(int $i) => new EntryData($specs === [] ? [] : $this->reader->read($entries[$i], $specs), $i), array_keys($entries));

        return array_map(fn(EntryData $chosen) => $entries[$chosen->id], PatternFinder::choose($candidates, $where));
    }
}
