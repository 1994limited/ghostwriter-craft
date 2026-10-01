<?php

namespace nineteenninetyfour\ghostwriter\layouts;

/**
 * Writes a field layout out as the brief the model works to: which keys a
 * draft may contain, what each one is for, and how this section's pages are
 * usually assembled.
 */
class SchemaDescriber
{
    private const KIND_LABELS = [
        'text' => 'short text',
        'longtext' => 'plain text',
        'richtext' => 'markdown',
        'choice' => 'one of',
        'choices' => 'any of',
        'toggle' => 'true or false',
        'number' => 'number',
        'list' => 'list of short strings',
        'blocks' => 'list of blocks',
        'rows' => 'list of rows',
        'group' => 'group',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $pattern
     */
    public function describe(array $schema, array $pattern): string
    {
        $lines = $this->fields($schema, 0, $pattern);

        foreach ($schema as $spec) {
            $blocks = $pattern['blocks'][$spec['handle']] ?? null;

            if ($spec['kind'] !== 'blocks' || ! $blocks) {
                continue;
            }

            if ($blocks['sequence']) {
                $lines[] = '';
                $lines[] = "Entries in this section usually build `{$spec['handle']}` from these blocks, in this order: ".implode(', ', $blocks['sequence']).'. Follow that order unless the brief gives a reason not to.';
            }

            $boilerplate = $blocks['boilerplate'] ?? [];

            if ($boilerplate) {
                $lines[] = 'These blocks are the same on every entry. Include each with its `type` alone, in its usual place, and its content is copied in for you. Never ask for their text: '.implode(', ', $boilerplate).'.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $pattern
     * @param  array<string, mixed>  $hints  House defaults for these fields, shown as "usually ...".
     * @return array<int, string>
     */
    private function fields(array $schema, int $depth, array $pattern = [], array $hints = []): array
    {
        $lines = [];
        $pad = str_repeat('  ', $depth);

        foreach ($schema as $spec) {
            if (! SchemaReader::writable($spec)) {
                continue;
            }

            $label = self::KIND_LABELS[$spec['kind']];

            if (in_array($spec['kind'], ['choice', 'choices'], true)) {
                $label .= ' '.implode(', ', array_keys($spec['options'] ?? []));
            }

            $line = "{$pad}- `{$spec['handle']}` ({$label}".($spec['required'] ? ', required' : '').')';

            if ($spec['display'] !== '' && strcasecmp(str_replace('_', ' ', $spec['handle']), $spec['display']) !== 0) {
                $line .= ': '.$spec['display'];
            }

            if ($spec['instructions'] !== '') {
                $line .= '. '.rtrim($spec['instructions'], '.');
            }

            if (isset($hints[$spec['handle']]) && is_string($hints[$spec['handle']])) {
                $line .= '. Usually "'.$hints[$spec['handle']].'"';
            }

            $lines[] = $line;

            if ($spec['kind'] === 'blocks') {
                array_push($lines, ...$this->blocks($spec, $depth + 1, $pattern['blocks'][$spec['handle']] ?? null));
            } elseif (in_array($spec['kind'], ['rows', 'group'], true)) {
                array_push($lines, ...$this->fields($spec['fields'] ?? [], $depth + 1));
            }
        }

        return $lines;
    }

    /**
     * Blocks the section actually uses are described in full; the rest of
     * a large page builder is only named, to keep the brief readable.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>|null  $pattern
     * @return array<int, string>
     */
    private function blocks(array $spec, int $depth, ?array $pattern): array
    {
        $lines = [];
        $pad = str_repeat('  ', $depth);
        $used = array_keys($pattern['usage'] ?? []);
        $sets = $spec['sets'] ?? [];

        // With nothing published yet there is no pattern, so describe them all.
        $full = $used ? array_intersect_key($sets, array_flip($used)) : $sets;
        $rest = array_diff_key($sets, $full);

        $lines[] = "{$pad}Each block is written as `type: <block>` followed by that block's fields"
            .($this->nests($sets) ? '; a block that holds other blocks lists them under `children`' : '').'. Blocks:';

        foreach ($full as $handle => $set) {
            $share = isset($pattern['usage'][$handle]) ? ', on '.round($pattern['usage'][$handle] * 100).'% of entries' : '';

            $lines[] = "{$pad}- `{$handle}`: {$set['display']}".($set['instructions'] !== '' ? '. '.rtrim($set['instructions'], '.') : '').$share;

            if (in_array($handle, $pattern['boilerplate'] ?? [], true)) {
                $lines[] = "{$pad}    (the same on every entry; write `type: {$handle}` and nothing else)";

                continue;
            }

            array_push($lines, ...$this->fields(
                $this->worthDescribing($set['fields'], $pattern['fixed'][$handle] ?? [], $pattern['used'][$handle] ?? null),
                $depth + 2,
                hints: $pattern['fixed'][$handle] ?? [],
            ));
        }

        if ($rest) {
            $lines[] = "{$pad}Also available but not normally used here: ".implode(', ', array_map(
                fn (string $handle, array $set) => "`{$handle}` ({$set['display']})",
                array_keys($rest),
                $rest,
            )).'.';
        }

        return $lines;
    }

    /**
     * Whether any of these blocks can hold blocks of its own (Neo).
     *
     * @param  array<string, array<string, mixed>>  $sets
     */
    private function nests(array $sets): bool
    {
        foreach ($sets as $set) {
            foreach ($set['fields'] as $field) {
                if (($field['engine'] ?? null) === 'neo-children' && SchemaReader::writable($field)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Within a block the section already uses, leave out the fields nobody
     * fills in and the settings that are always the same. They are applied
     * when the entry is built, so the writer need not think about them.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $fixed
     * @param  array<int, string>|null  $used  Null when the block has never been used.
     * @return array<int, array<string, mixed>>
     */
    private function worthDescribing(array $fields, array $fixed, ?array $used): array
    {
        if ($used === null) {
            return $fields;
        }

        return array_values(array_filter($fields, function (array $field) use ($fixed, $used) {
            if (! in_array($field['handle'], $used, true)) {
                return false;
            }

            return ! (array_key_exists($field['handle'], $fixed) && in_array($field['kind'], [...SchemaReader::SETTING_KINDS, ...PatternFinder::COPIED_KINDS], true));
        }));
    }
}
