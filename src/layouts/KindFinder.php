<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use craft\elements\Entry;

/**
 * Finds the kinds of entry a section already holds by grouping entries that
 * are built the same way. A Pages section might turn out to hold landing
 * pages, service pages and a few one-offs; nobody has to say so.
 *
 * It is what makes the options different on every site: they come from that
 * site's own content, with no configuration and no model call.
 */
class KindFinder
{
    /** Two entries are the same kind when this share of their blocks match. */
    private const SIMILARITY = 0.6;

    /** Example entries offered per kind. */
    private const EXAMPLES = 6;

    /** Entries looked at; enough to see the kinds, few enough to stay quick. */
    private const SAMPLE = 120;

    public function __construct(private EntryData $data = new EntryData())
    {
    }

    /**
     * @param array<int, array<string, mixed>> $schema
     * @return array<int, array{label: string, count: int, examples: array<int, int>, titles: array<int, string>, blocks: array<int, string>}>
     */
    public function find(string $section, array $schema, ?string $entryType = null): array
    {
        $spec = null;

        foreach ($schema as $candidate) {
            if ($candidate['kind'] === 'blocks') {
                $spec = $candidate;
                break;
            }
        }

        // Without a page builder every entry is built the same way.
        if ($spec === null) {
            return [];
        }

        $entries = PatternFinder::published($section, $entryType, self::SAMPLE);
        $groups = [];

        foreach ($entries as $entry) {
            $blocks = $this->blocks($this->data->read($entry, [$spec])[$spec['handle']] ?? []);

            if ($blocks === []) {
                continue;
            }

            foreach ($groups as &$group) {
                if ($this->similarity($blocks, $group['blocks']) >= self::SIMILARITY) {
                    $group['entries'][] = $entry;

                    continue 2;
                }
            }

            unset($group);

            $groups[] = ['blocks' => $blocks, 'entries' => [$entry]];
        }

        unset($group);

        $kinds = array_values(array_filter($groups, fn(array $group) => count($group['entries']) >= 2));
        usort($kinds, fn(array $a, array $b) => count($b['entries']) <=> count($a['entries']));

        // One group covering nearly everything is just "the section".
        if (count($kinds) < 2 && array_sum(array_map(fn(array $group) => count($group['entries']), $kinds)) >= count($entries) - 1) {
            return [];
        }

        return array_map(fn(array $group) => [
            'label' => $this->label($group['entries']),
            'count' => count($group['entries']),
            'examples' => array_map(fn(Entry $entry) => (int) $entry->id, array_slice($group['entries'], 0, self::EXAMPLES)),
            'titles' => array_map(fn(Entry $entry) => (string) $entry->title, $group['entries']),
            'blocks' => $group['blocks'],
        ], $kinds);
    }

    /**
     * The types of the top-level blocks that are switched on.
     *
     * @param array<int, mixed> $value
     * @return array<int, string>
     */
    private function blocks(array $value): array
    {
        $blocks = [];

        foreach ($value as $set) {
            if (is_array($set) && isset($set['type']) && ($set['enabled'] ?? true) !== false) {
                $blocks[] = (string) $set['type'];
            }
        }

        return $blocks;
    }

    /**
     * Share of block types the two have in common.
     *
     * @param array<int, string> $a
     * @param array<int, string> $b
     */
    private function similarity(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);

        return count(array_intersect($a, $b)) / max(count(array_unique([...$a, ...$b])), 1);
    }

    /**
     * Named after the page the entries sit under when they share one,
     * otherwise after the entries themselves.
     *
     * @param Entry[] $entries
     */
    private function label(array $entries): string
    {
        $parents = array_unique(array_map(fn(Entry $entry) => $entry->getParent()?->id, $entries), SORT_REGULAR);
        $parent = count($parents) === 1 && reset($parents) ? $entries[0]->getParent() : null;

        if ($parent) {
            return 'Like the pages under ' . $parent->title;
        }

        $titles = array_map(fn(Entry $entry) => (string) $entry->title, array_slice($entries, 0, 2));
        $more = count($entries) - 2;

        return 'Like ' . ($more > 0 ? implode(', ', $titles) . ' and ' . $more . ' more' : implode(' and ', $titles));
    }
}
