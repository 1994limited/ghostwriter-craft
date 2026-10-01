<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\SingleOptionFieldData;
use Illuminate\Support\Collection;

/**
 * An entry's content as plain data, in one shape whatever builds it: the
 * title, then each field by handle. Page builders become a list of blocks,
 * each with its `id`, `type`, `enabled` and its own fields; a Neo block's
 * children sit under `children`. Rich text stays HTML, tables are rows
 * keyed by column handle, and references are their element IDs.
 *
 * It is the shape the pattern finder, the simplifier and the merger read,
 * so none of them need to know whether a site uses Matrix or Neo.
 */
class EntryData
{
    /**
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public function read(ElementInterface $element, array $schema): array
    {
        $data = [];

        foreach ($schema as $spec) {
            if ($spec['handle'] === 'title') {
                $data['title'] = (string) $element->title;

                continue;
            }

            if ($spec['type'] === 'neo-children') {
                continue;
            }

            $data[$spec['handle']] = $this->value($element, $spec);
        }

        return $data;
    }

    private function value(ElementInterface $element, array $spec): mixed
    {
        $handle = $spec['handle'];

        try {
            $value = $element->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }

        return match (true) {
            ($spec['engine'] ?? null) === 'matrix' => $this->matrix($value, $spec),
            ($spec['engine'] ?? null) === 'neo' => $this->neo($value, $spec),
            $spec['kind'] === 'rows' => $this->rows($value, $spec),
            $value instanceof SingleOptionFieldData => $value->value,
            $value instanceof MultiOptionsFieldData => array_values(array_map(fn($option) => $option->value, iterator_to_array($value))),
            $value instanceof ElementQueryInterface => $value->status(null)->ids(),
            $value instanceof Collection => $value->map(fn($item) => $item instanceof ElementInterface ? $item->id : $item)->all(),
            default => $this->serialized($element, $handle, $value),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matrix(mixed $value, array $spec): array
    {
        $blocks = [];

        foreach ($this->elements($value) as $entry) {
            $type = $entry->getType()->handle;
            $fields = $spec['sets'][$type]['fields'] ?? [];

            $blocks[] = ['id' => $entry->id, 'type' => $type, 'enabled' => (bool) $entry->enabled] + $this->read($entry, $fields);
        }

        return $blocks;
    }

    /**
     * Neo keeps its blocks as one flat list with levels; they are read
     * back into the tree an editor sees.
     *
     * @return array<int, array<string, mixed>>
     */
    private function neo(mixed $value, array $spec): array
    {
        $flat = [];

        foreach ($this->elements($value) as $block) {
            $type = $block->getType()->handle;

            $flat[] = [max(1, (int) $block->level), ['id' => $block->id, 'type' => $type, 'enabled' => (bool) $block->enabled] + $this->read($block, $this->neoFields($spec, $type))];
        }

        $i = 0;

        return $this->nest($flat, $i, 1);
    }

    /**
     * Gather blocks at one level, taking any deeper blocks that follow one
     * as its children.
     *
     * @param array<int, array{0: int, 1: array<string, mixed>}> $flat
     * @return array<int, array<string, mixed>>
     */
    private function nest(array $flat, int &$i, int $level): array
    {
        $out = [];

        while ($i < count($flat)) {
            [$depth, $block] = $flat[$i];

            if ($depth < $level) {
                break;
            }

            if ($depth > $level && $out !== []) {
                $last = array_key_last($out);
                $out[$last]['children'] = [...($out[$last]['children'] ?? []), ...$this->nest($flat, $i, $level + 1)];

                continue;
            }

            // A block deeper than anything before it is taken as it stands.
            $out[] = $block;
            $i++;
        }

        return $out;
    }

    /**
     * A Neo block type's fields, found wherever it is allowed in the tree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function neoFields(array $spec, string $type, int $depth = 0): array
    {
        if (isset($spec['sets'][$type])) {
            return $spec['sets'][$type]['fields'];
        }

        if ($depth > 6) {
            return [];
        }

        foreach ($spec['sets'] ?? [] as $set) {
            foreach ($set['fields'] as $field) {
                if ($field['handle'] === 'children' && ($fields = $this->neoFields($field, $type, $depth + 1)) !== []) {
                    return $fields;
                }
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(mixed $value, array $spec): array
    {
        $columns = array_flip($spec['columns'] ?? []);
        $rows = [];

        foreach ((array) $value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out = [];

            foreach ($columns as $id => $handle) {
                $out[$handle] = $row[$handle] ?? $row[$id] ?? null;
            }

            $rows[] = $out;
        }

        return $rows;
    }

    /**
     * @return ElementInterface[]
     */
    private function elements(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            return (clone $value)->status(null)->all();
        }

        if ($value instanceof Collection) {
            return $value->all();
        }

        return is_array($value) ? array_filter($value, fn($item) => $item instanceof ElementInterface) : [];
    }

    private function serialized(ElementInterface $element, string $handle, mixed $value): mixed
    {
        $field = $element->getFieldLayout()?->getFieldByHandle($handle);

        $serialized = $field ? $field->serializeValue($value, $element) : $value;

        return is_object($serialized) ? (string) $serialized : $serialized;
    }
}
