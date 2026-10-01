<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;

/**
 * Studies the entries a section already has to find out how its pages are
 * really put together, which the field layout alone cannot say: a page
 * builder allows thirty blocks, but the articles only ever use six, in one
 * order.
 *
 * It finds, for each page builder, the usual sequence of blocks and how often
 * each is used; the values that are the same on nearly every entry, which are
 * house defaults rather than writing; and the newest entries as examples.
 */
class PatternFinder
{
    private const SAMPLE = 30;

    /** A value shared by this share of entries is a house default. */
    private const FIXED_SHARE = 0.8;

    /** Structured kinds that, when fixed, are copied whole and never rewritten. */
    public const COPIED_KINDS = ['rows', 'blocks', 'group', 'list', 'reference'];

    /** Keys that are bookkeeping, never content. */
    private const BOOKKEEPING = ['id', 'type', 'enabled'];

    public function __construct(
        private EntryData $data = new EntryData(),
        private EntrySimplifier $simplifier = new EntrySimplifier(),
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $schema
     * @param string|null $entryType Handle of the entry type to learn from; null for all of the section's.
     * @param array<string, mixed> $where Field => value an entry must have (or contain, for lists).
     * @param array<int, int|string> $examples Entry IDs to learn from instead.
     * @return array{entries: int, words: int, blocks: array<string, array<string, mixed>>, fixed: array<string, mixed>, examples: array<int, array<string, mixed>>}
     */
    public function find(string $section, array $schema, ?string $entryType = null, array $where = [], array $examples = []): array
    {
        // Entries picked by hand are the whole evidence: a section such as
        // Pages holds several kinds of page, and only a person knows which
        // ones a given type should be modelled on.
        $picked = $examples ? Entry::find()->id(array_map('intval', $examples))->status(null)->fixedOrder()->all() : [];

        if ($picked !== []) {
            return $this->patternFrom($picked, $schema);
        }

        $all = self::published($section, $entryType);

        // A type can narrow the entries it learns from, such as the guides
        // among a section of articles. Until some exist, the whole section
        // is the best evidence there is.
        $matching = $where ? array_values(array_filter($all, fn(Entry $entry) => $this->matches($entry, $where, $schema))) : $all;

        return $this->patternFrom(array_slice($matching === [] ? $all : $matching, 0, self::SAMPLE), $schema);
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
     * @param Entry[] $entries
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    private function patternFrom(array $entries, array $schema): array
    {
        $data = array_map(fn(Entry $entry) => $this->data->read($entry, $schema), $entries);
        $simplified = array_map(fn(array $entry) => $this->simplifier->simplify($entry, $schema), $data);

        $blocks = [];

        foreach ($schema as $spec) {
            if ($spec['kind'] === 'blocks') {
                $blocks[$spec['handle']] = $this->blockPattern(array_values(array_filter(array_column($data, $spec['handle']))), $spec['sets'] ?? []);
            }
        }

        $words = array_map(fn(array $entry) => str_word_count(json_encode($entry) ?: ''), $simplified);
        sort($words);

        return [
            'entries' => count($entries),
            'words' => $words === [] ? 0 : (int) $words[intdiv(count($words), 2)],
            'blocks' => $blocks,
            'fixed' => $this->fixedValues($data, array_merge(['title', 'slug', 'id'], array_keys($blocks), array_column(array_filter($schema, [SchemaReader::class, 'writable']), 'handle'))),
            'examples' => array_values(array_map(fn(array $example) => $this->withoutDefaults($example, $schema, $blocks), array_slice($simplified, 0, 2))),
            'filled' => $this->fillRates($data, $schema),
            'house' => (new HouseStyle())->learn(array_values($data), $schema, array_values(array_map(fn(Entry $entry) => (int) $entry->getCanonicalId(), $entries))),
        ];
    }

    /**
     * How often each field holds something: on the entry, keyed by handle,
     * and on blocks, keyed "blockType.handle", at any depth. It is how an
     * image that every page has is told from a background few pages use.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, float>
     */
    private function fillRates(array $items, array $schema): array
    {
        $counts = [];
        $totals = [];

        $walk = function(array $items, array $specs, string $prefix) use (&$walk, &$counts, &$totals): void {
            foreach ($items as $item) {
                if (!is_array($item) || ($item['enabled'] ?? true) === false) {
                    continue;
                }

                foreach ($specs as $spec) {
                    $key = $prefix . $spec['handle'];
                    $value = $item[$spec['handle']] ?? null;

                    $totals[$key] = ($totals[$key] ?? 0) + 1;

                    if ($value !== null && $value !== '' && $value !== []) {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }

                    if (isset($spec['engine']) && is_array($value)) {
                        foreach ($value as $block) {
                            $set = is_array($block) ? ($spec['sets'][$block['type'] ?? ''] ?? null) : null;

                            if ($set) {
                                $walk([$block], $set['fields'], $block['type'] . '.');
                            }
                        }
                    }
                }
            }
        };

        $walk($items, $schema, '');

        $rates = [];

        foreach ($totals as $key => $total) {
            $rates[$key] = round(($counts[$key] ?? 0) / $total, 2);
        }

        return $rates;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, array<string, mixed>> $schema
     */
    private function matches(Entry $entry, array $where, array $schema): bool
    {
        $data = $this->data->read($entry, array_values(array_filter($schema, fn(array $spec) => array_key_exists($spec['handle'], $where))));

        foreach ($where as $field => $expected) {
            if (!in_array($expected, (array) ($data[$field] ?? null), false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, mixed> $fields One page builder's value from each entry.
     * @param array<string, array<string, mixed>> $available The builder's sets, as read from the layout.
     * @return array{sequence: array<int, string>, usage: array<string, float>, fixed: array<string, array<string, mixed>>, used: array<string, array<int, string>>, boilerplate: array<int, string>}
     */
    private function blockPattern(array $fields, array $available = []): array
    {
        $sequences = [];
        $byType = [];
        $entriesUsing = [];

        foreach ($fields as $sets) {
            $sequence = [];

            foreach ((array) $sets as $set) {
                if (!is_array($set) || !isset($set['type']) || ($set['enabled'] ?? true) === false) {
                    continue;
                }

                $sequence[] = $set['type'];
                $byType[$set['type']][] = $set;
            }

            $sequences[] = $sequence;

            foreach (array_unique($sequence) as $type) {
                $entriesUsing[$type] = ($entriesUsing[$type] ?? 0) + 1;
            }
        }

        $total = max(count($fields), 1);

        arsort($entriesUsing);

        $fixed = array_filter(array_map(fn(array $items) => $this->fixedValues($items, self::BOOKKEEPING, reused: true), $byType));
        $used = array_map(fn(array $items) => $this->usedKeys($items), $byType);

        return [
            'sequence' => $this->commonest($sequences),
            'usage' => array_map(fn(int $count) => round($count / $total, 2), $entriesUsing),
            'fixed' => $fixed,
            'used' => $used,
            'boilerplate' => array_values(array_filter(
                array_keys($byType),
                fn(string $type) => count($byType[$type]) >= 2 && $this->isBoilerplate($available[$type]['fields'] ?? [], $fixed[$type] ?? [], $used[$type]),
            )),
        ];
    }

    /**
     * A block is boilerplate when nothing in it is written afresh each time:
     * every field of content that gets used holds a house default. Process
     * steps and testimonials are typical; so is a spacer. Such a block is
     * copied, not written. A setting that varies, such as a background,
     * does not make a block's content any less fixed.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $fixed
     * @param array<int, string> $used
     */
    private function isBoilerplate(array $fields, array $fixed, array $used): bool
    {
        foreach ($fields as $field) {
            // Settings and references (images, links) are not writing.
            $isContent = SchemaReader::writable($field) && !in_array($field['kind'], SchemaReader::SETTING_KINDS, true);

            if ($isContent && in_array($field['handle'], $used, true) && !array_key_exists($field['handle'], $fixed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order of blocks used most often. Entries are newest first, so a tie
     * goes to the most recent way of doing it.
     *
     * @param array<int, array<int, string>> $sequences
     * @return array<int, string>
     */
    private function commonest(array $sequences): array
    {
        $counts = [];

        foreach ($sequences as $sequence) {
            $key = implode('>', $sequence);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if ($counts === []) {
            return [];
        }

        $best = array_search(max($counts), $counts, true);

        return $best === '' ? [] : explode('>', (string) $best);
    }

    /**
     * Keys whose value is identical on nearly every item.
     *
     * With `reused`, structured content (rows, blocks) also counts when no
     * item has a version of its own: a page builder's process steps might
     * come in a "website" and an "app" wording, each pasted onto several
     * pages. Nobody writes those afresh, so the commonest version is used.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $ignore
     * @return array<string, mixed>
     */
    private function fixedValues(array $items, array $ignore, bool $reused = false): array
    {
        // A single item proves nothing about a house default.
        if (count($items) < 2) {
            return [];
        }

        $seen = [];
        $samples = [];

        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                if (in_array($key, $ignore, true)) {
                    continue;
                }

                // Blocks and rows carry IDs, so two copies of the same
                // content only compare equal once those are set aside.
                $encoded = json_encode($this->withoutIds($value));

                $seen[$key][$encoded] = ($seen[$key][$encoded] ?? 0) + 1;
                $samples[$key][$encoded] ??= $value;
            }
        }

        $fixed = [];

        foreach ($seen as $key => $values) {
            arsort($values);
            $encoded = array_key_first($values);

            $sample = $samples[$key][$encoded];
            $neverUnique = $reused && is_array($sample) && $sample !== [] && min($values) >= 2 && array_sum($values) === count($items);

            // Empty is not a house default; it is a field nobody uses.
            if ($sample === null || $sample === '' || $sample === []) {
                continue;
            }

            if ($values[$encoded] / count($items) >= self::FIXED_SHARE || $neverUnique) {
                $fixed[$key] = $sample;
            }
        }

        return $fixed;
    }

    /**
     * The keys that hold something on at least one item. A field nobody has
     * ever filled in is not part of how this section is written.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function usedKeys(array $items): array
    {
        $used = [];

        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                if ($value !== null && $value !== '' && $value !== [] && !in_array($key, self::BOOKKEEPING, true)) {
                    $used[$key] = true;
                }
            }
        }

        return array_keys($used);
    }

    /**
     * Examples are shown without the settings that are the same everywhere,
     * so what is left is the writing.
     *
     * @param array<string, mixed> $example
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $blocks
     * @return array<string, mixed>
     */
    private function withoutDefaults(array $example, array $schema, array $blocks): array
    {
        foreach ($schema as $spec) {
            if ($spec['kind'] !== 'blocks' || !isset($example[$spec['handle']])) {
                continue;
            }

            $pattern = $blocks[$spec['handle']];

            $example[$spec['handle']] = array_map(function(array $block) use ($spec, $pattern) {
                // A boilerplate block is copied when the entry is built, so
                // the writer only needs to see where it goes.
                if (in_array($block['type'], $pattern['boilerplate'], true)) {
                    return ['type' => $block['type']];
                }

                $kinds = array_column($spec['sets'][$block['type']]['fields'] ?? [], 'kind', 'handle');

                foreach ($pattern['fixed'][$block['type']] ?? [] as $key => $value) {
                    if (!array_key_exists($key, $block)) {
                        continue;
                    }

                    $kind = $kinds[$key] ?? null;
                    $isSetting = in_array($kind, SchemaReader::SETTING_KINDS, true) && $block[$key] === $value;

                    if ($isSetting || in_array($kind, self::COPIED_KINDS, true)) {
                        unset($block[$key]);
                    }
                }

                return $block;
            }, $example[$spec['handle']]);
        }

        return $example;
    }

    /**
     * @return mixed The value with every `id` key removed, at any depth.
     */
    private function withoutIds(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        unset($value['id']);

        return array_map(fn($item) => $this->withoutIds($item), $value);
    }
}
