<?php

namespace nineteenninetyfour\ghostwriter\drafts;

/**
 * Lays a rewritten draft over the entry it was made from. The writer only
 * deals in words, so everything else an existing entry holds (images, links,
 * chosen entries, settings, the IDs of its blocks) is carried over from the
 * entry and only the writing changes.
 */
class EntryMerger
{
    /**
     * @param array<string, mixed> $built Entry data built from the revised draft.
     * @param array<string, mixed> $original The entry's data as EntryData reads it.
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public function merge(array $built, array $original, array $schema): array
    {
        $specs = array_column($schema, null, 'handle');

        foreach ($built as $handle => $value) {
            $spec = $specs[$handle] ?? null;
            $was = $original[$handle] ?? null;

            if (!$spec || !is_array($value) || !is_array($was)) {
                continue;
            }

            $built[$handle] = match ($spec['kind']) {
                'blocks' => $this->blocks($value, $was, $spec),
                default => $value,
            };
        }

        // What the draft does not hold (references, settings it left out)
        // stays as the entry had it.
        foreach ($specs as $handle => $spec) {
            if (!array_key_exists($handle, $built) && array_key_exists($handle, $original) && $handle !== 'title') {
                $built[$handle] = $original[$handle];
            }
        }

        return $built;
    }

    /**
     * Blocks are matched by type, in order: the second text block of the
     * draft is the entry's second text block, wherever either now sits.
     *
     * @param array<int, mixed> $built
     * @param array<int, mixed> $original
     * @param array<string, mixed> $spec
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $built, array $original, array $spec): array
    {
        $waiting = [];
        $hidden = [];

        foreach ($original as $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }

            // The draft never saw blocks that are switched off; they are
            // kept as they were, after the rest.
            if (($block['enabled'] ?? true) === false) {
                $hidden[] = $block;
            } else {
                $waiting[$block['type']][] = $block;
            }
        }

        $merged = [];

        foreach ($built as $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }

            $was = isset($waiting[$block['type']]) ? array_shift($waiting[$block['type']]) : null;

            if ($was === null) {
                $merged[] = $block;

                continue;
            }

            $fields = $spec['sets'][$block['type']]['fields'] ?? [];

            $merged[] = ['id' => $was['id'] ?? null] + $this->merge($block, $was, $fields) + $was;
        }

        return [...$merged, ...$hidden];
    }
}
