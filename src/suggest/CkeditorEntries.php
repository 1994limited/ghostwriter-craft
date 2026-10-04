<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;

/**
 * The entries nested in CKEditor fields, as Suggest edits and Content to
 * revisit read them: blocks, like a Matrix field's.
 *
 * CKEditor keeps a nested entry in its HTML as
 * `<craft-entry data-entry-id="…">`, which core's rich text reading drops,
 * so the entry's own words would never be checked. Here each CKEditor
 * field that holds nested entries gets a page builder beside it, under
 * `{handle}~entries` (no Craft handle has a `~`), whose blocks are those
 * entries in the field's order, each read as a Matrix entry is and known
 * by its ID. Core then walks, checks, reviews, indexes and reconciles
 * their text as it does a Matrix entry's; EntryChecks::canonicalBlocks()
 * gives each its canonical ID, and the guide finds it by its card in the
 * editor (SuggestEdits::display(), suggest.js).
 *
 * Only the checks' context has them: Finish this page and the writer read
 * the CKEditor field as it is.
 */
final class CkeditorEntries
{
    /** Added to a CKEditor field's handle for the builder of its nested entries. */
    public const SUFFIX = '~entries';

    private const CKEDITOR = 'craft\ckeditor\Field';

    /** Entries in entries in CKEditor fields go this deep, and no deeper. */
    private const MAX_DEPTH = 3;

    /** @var array<string, array<string, mixed>> Each entry type's set, by ID. */
    private array $sets = [];

    public function __construct(private readonly int $siteId) {}

    /**
     * The schema and values with each CKEditor field's nested entries
     * beside it; the same when there are none.
     *
     * @return array{0: Schema, 1: EntryData}
     */
    public function expand(Schema $schema, EntryData $entry): array
    {
        $rows = [$entry->values];
        $fields = $this->fields($schema->fields, $rows, 0);

        if ($rows[0] === $entry->values) {
            return [$schema, $entry];
        }

        return [new Schema($fields), $entry->withValues($rows[0])];
    }

    /**
     * The fields, each CKEditor field followed by the builder of its nested
     * entries, and each page builder's sets the same way inside it.
     *
     * @param list<Field> $fields
     * @param list<array<mixed>> $rows The values of everything these fields are in: the entry, or every block of one set. Updated in place.
     * @return list<Field>
     */
    private function fields(array $fields, array &$rows, int $depth): array
    {
        $out = [];

        foreach ($fields as $field) {
            if ($field->isBuilder()) {
                $out[] = $this->builder($field, $rows, $depth);

                continue;
            }

            $out[] = $field;

            if ($field->type === self::CKEDITOR && $depth < self::MAX_DEPTH && ($nested = $this->nested($field, $rows)) !== null) {
                $out[] = $this->builder($nested, $rows, $depth + 1);
            }
        }

        return $out;
    }

    /**
     * A page builder with its sets' fields expanded over every block of
     * each set.
     *
     * @param list<array<mixed>> $rows
     */
    private function builder(Field $field, array &$rows, int $depth): Field
    {
        $sets = [];

        foreach ($field->sets as $handle => $set) {
            $at = [];
            $blocks = [];

            foreach ($rows as $r => $row) {
                foreach (is_array($row[$field->handle] ?? null) ? $row[$field->handle] : [] as $i => $block) {
                    if (is_array($block) && ($block['type'] ?? null) === $handle) {
                        $at[] = [$r, $i];
                        $blocks[] = $block;
                    }
                }
            }

            $inner = $this->fields($set->fields, $blocks, $depth);

            foreach ($at as $n => [$r, $i]) {
                $rows[$r][$field->handle][$i] = $blocks[$n];
            }

            $sets[$handle] = new Set($set->label, $set->instructions, $inner);
        }

        return new Field(
            handle: $field->handle,
            kind: $field->kind,
            label: $field->label,
            instructions: $field->instructions,
            required: $field->required,
            options: $field->options,
            sets: $sets,
            fields: $field->fields,
            type: $field->type,
            engine: $field->engine,
            files: $field->files,
            path: $field->path,
            meta: $field->meta,
        );
    }

    /**
     * The builder of a CKEditor field's nested entries, with each row's
     * entries put beside the field's value; null when no row has any.
     *
     * @param list<array<mixed>> $rows Updated in place.
     */
    private function nested(Field $field, array &$rows): ?Field
    {
        $ids = [];

        foreach ($rows as $r => $row) {
            $ids[$r] = self::ids($row[$field->handle] ?? null);
        }

        $all = array_values(array_unique(array_merge(...array_values($ids))));

        if ($all === []) {
            return null;
        }

        /** @var array<int, Entry> $entries */
        $entries = Entry::find()->id($all)->siteId($this->siteId)->status(null)->drafts(null)->revisions(null)->indexBy('id')->all();
        $sets = [];
        $found = [];
        $reader = new EntryReader();

        foreach ($ids as $r => $list) {
            $blocks = [];

            foreach ($list as $id) {
                $entry = $entries[$id] ?? null;

                if (!$entry instanceof Entry) {
                    continue;
                }

                $type = $entry->getType();
                $set = $this->sets[(string) $type->id] ??= (new SchemaReader())->entryTypeSet($type);
                $sets[$type->handle] = $set;
                $blocks[] = ['id' => (int) $entry->id, 'type' => $type->handle, 'enabled' => (bool) $entry->enabled && $entry->getEnabledForSite() !== false] + $reader->read($entry, $set['fields']);
            }

            $found[$r] = $blocks;
        }

        if ($sets === []) {
            return null;
        }

        foreach ($found as $r => $blocks) {
            $rows[$r][$field->handle . self::SUFFIX] = $blocks;
        }

        return Field::fromSpec([
            'handle' => $field->handle . self::SUFFIX,
            'type' => 'ckeditor-entries',
            'kind' => Kind::Blocks->value,
            'engine' => 'ckeditor',
            'display' => $field->label,
            'instructions' => '',
            'required' => false,
            'sets' => $sets,
        ]);
    }

    /**
     * The nested entries' IDs in a CKEditor value, in order.
     *
     * @return list<int>
     */
    public static function ids(mixed $html): array
    {
        if (!is_string($html) || stripos($html, '<craft-entry') === false) {
            return [];
        }

        preg_match_all('/<craft-entry\b[^>]*\bdata-entry-id\s*=\s*["\']?(\d+)/i', $html, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }
}
