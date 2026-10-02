<?php

namespace nineteenninetyfour\ghostwriter\drafts;

/**
 * Turns entry data (core EntryData's shape) into the values Craft's fields take
 * when a form is posted: a Matrix as `entries` and `sortOrder`, a Neo field
 * as a flat list of `blocks` with levels, a Table as rows keyed by column ID.
 *
 * A block keeps its ID only when it is one of `$existing`, the blocks the
 * entry being changed already has; everything else is created new.
 */
class FieldValues
{
    private int $new = 0;

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $schema
     * @param array<int, int> $existing IDs of blocks the target entry already has.
     * @return array<string, mixed> Field values by handle. The title is not among them.
     */
    public function forCraft(array $data, array $schema, array $existing = []): array
    {
        $this->new = 0;

        return $this->fields($data, $schema, array_flip(array_map('intval', $existing)));
    }

    /**
     * @param array<int|string, int> $existing
     * @return array<string, mixed>
     */
    private function fields(array $data, array $schema, array $existing): array
    {
        $values = [];

        foreach ($schema as $spec) {
            $handle = $spec['handle'];

            if ($handle === 'title' || $spec['type'] === 'neo-children' || !array_key_exists($handle, $data)) {
                continue;
            }

            $value = $data[$handle];

            $values[$handle] = match (true) {
                ($spec['engine'] ?? null) === 'matrix' => $this->matrix((array) $value, $spec, $existing),
                ($spec['engine'] ?? null) === 'neo' => $this->neo((array) $value, $spec, $existing),
                $spec['kind'] === 'rows' => $this->rows((array) $value, $spec),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<int|string, int> $existing
     * @return array{entries: array<string, array<string, mixed>>, sortOrder: array<int, string>}
     */
    private function matrix(array $blocks, array $spec, array $existing): array
    {
        $entries = [];

        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($spec['sets'][$block['type'] ?? ''])) {
                continue;
            }

            $fields = $spec['sets'][$block['type']]['fields'];
            $entry = ['type' => $block['type'], 'enabled' => (bool) ($block['enabled'] ?? true), 'fields' => $this->fields($block, $fields, $existing)];

            if (array_key_exists('title', $block)) {
                $entry['title'] = (string) $block['title'];
            }

            $entries[$this->key($block, $existing)] = $entry;
        }

        return ['entries' => $entries, 'sortOrder' => array_map('strval', array_keys($entries))];
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<int|string, int> $existing
     * @return array{blocks: array<string, array<string, mixed>>, sortOrder: array<int, string>}
     */
    private function neo(array $blocks, array $spec, array $existing): array
    {
        $flat = [];

        $this->flatten($blocks, $spec['sets'] ?? [], 1, $existing, $flat);

        return ['blocks' => $flat, 'sortOrder' => array_map('strval', array_keys($flat))];
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<string, array<string, mixed>> $sets Block types allowed at this level.
     * @param array<int|string, int> $existing
     * @param array<string, array<string, mixed>> $flat
     */
    private function flatten(array $blocks, array $sets, int $level, array $existing, array &$flat): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($sets[$block['type'] ?? ''])) {
                continue;
            }

            $fields = $sets[$block['type']]['fields'];

            $flat[$this->key($block, $existing)] = [
                'type' => $block['type'],
                'enabled' => (bool) ($block['enabled'] ?? true),
                'level' => $level,
                'fields' => $this->fields($block, $fields, $existing),
            ];

            foreach ($fields as $field) {
                if ($field['type'] === 'neo-children' && !empty($block['children'])) {
                    $this->flatten((array) $block['children'], $field['sets'] ?? [], $level + 1, $existing, $flat);
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $rows, array $spec): array
    {
        $columns = $spec['columns'] ?? [];
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = [];

            foreach ($columns as $handle => $id) {
                $cells[$id] = $row[$handle] ?? null;
            }

            $out[] = $cells;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int|string, int> $existing
     */
    private function key(array $block, array $existing): string
    {
        $id = $block['id'] ?? null;

        if (is_numeric($id) && isset($existing[(int) $id])) {
            return (string) (int) $id;
        }

        return 'new' . ++$this->new;
    }
}
