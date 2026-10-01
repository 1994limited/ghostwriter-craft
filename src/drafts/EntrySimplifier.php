<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use nineteenninetyfour\ghostwriter\layouts\SchemaReader;

/**
 * Reduces a stored entry to the form drafts are written in: only the fields
 * the writer fills, rich text as markdown, and none of the IDs or references
 * the control panel adds. It is how existing entries are shown to the model
 * as examples, in exactly the shape it is asked to produce.
 */
class EntrySimplifier
{
    public function __construct(private HtmlToMarkdown $markdown = new HtmlToMarkdown())
    {
    }

    /**
     * @param array<string, mixed> $data As read by EntryData.
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public function simplify(array $data, array $schema): array
    {
        $out = [];

        foreach ($schema as $spec) {
            if (!SchemaReader::writable($spec) || !array_key_exists($spec['handle'], $data)) {
                continue;
            }

            $value = $this->value($data[$spec['handle']], $spec);

            if ($value !== null && $value !== '' && $value !== []) {
                $out[$spec['handle']] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function value(mixed $value, array $spec): mixed
    {
        return match ($spec['kind']) {
            'richtext' => is_string($value) ? trim($this->markdown->convert($value)) : null,
            'blocks' => $this->blocks((array) $value, $spec),
            'rows' => array_values(array_filter(array_map(
                fn($row) => is_array($row) ? $this->simplify($row, $spec['fields'] ?? []) : null,
                (array) $value,
            ))),
            'text', 'longtext' => is_string($value) ? trim($value) : null,
            default => is_scalar($value) || is_array($value) ? $value : null,
        };
    }

    /**
     * @param array<int, mixed> $sets
     * @param array<string, mixed> $spec
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $sets, array $spec): array
    {
        $out = [];

        foreach ($sets as $set) {
            // A block switched off in the control panel is not part of the page.
            if (!is_array($set) || ($set['enabled'] ?? true) === false || !isset($set['type'])) {
                continue;
            }

            $fields = $spec['sets'][$set['type']]['fields'] ?? null;

            if ($fields === null) {
                continue;
            }

            $out[] = ['type' => $set['type']] + $this->simplify($set, $fields);
        }

        return $out;
    }
}
