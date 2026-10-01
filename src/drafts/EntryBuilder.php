<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use League\CommonMark\CommonMarkConverter;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;

/**
 * Turns a draft into the data an entry holds, guided by the field layout:
 * markdown becomes HTML for CKEditor or Redactor, choices are checked against
 * their options, blocks are checked against the builder's block types, and
 * any value the draft left out that is a house default in this section is
 * filled in.
 *
 * Anything in the draft that the layout has no place for is dropped and
 * reported, never saved. The result is plain data in EntryData's shape;
 * FieldValues turns it into what Craft's fields accept.
 */
class EntryBuilder
{
    /** @var array<int, string> */
    private array $notes = [];

    /** @var array<int, string> */
    private array $toFill = [];

    /**
     * @param array<string, mixed> $draft
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $pattern
     * @param array<string, mixed> $defaults
     * @return array{data: array<string, mixed>, notes: array<int, string>}
     */
    public function build(array $draft, array $schema, array $pattern = [], array $defaults = []): array
    {
        $this->notes = [];
        $this->toFill = [];

        $data = $this->fields($draft, $schema, $pattern['blocks'] ?? []);

        if ($this->toFill) {
            $this->notes[] = 'Still to choose by hand: ' . implode('; ', array_unique($this->toFill)) . '.';
        }

        // The type's own defaults win over what the section usually does.
        foreach ($defaults + ($pattern['fixed'] ?? []) as $key => $value) {
            $data[$key] ??= $this->withoutIds($value);
        }

        return ['data' => $data, 'notes' => $this->notes];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $blockPatterns
     * @return array<string, mixed>
     */
    private function fields(array $values, array $schema, array $blockPatterns = []): array
    {
        $data = [];
        $known = array_column($schema, 'handle');

        foreach (array_diff(array_keys($values), $known, ['type']) as $stray) {
            $this->notes[] = "\"{$stray}\" is not a field here and was left out.";
        }

        foreach ($schema as $spec) {
            if (!SchemaReader::writable($spec) || !array_key_exists($spec['handle'], $values)) {
                continue;
            }

            $value = $this->value($values[$spec['handle']], $spec, $blockPatterns[$spec['handle']] ?? []);

            if ($value !== null) {
                $data[$spec['handle']] = $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $pattern
     */
    private function value(mixed $value, array $spec, array $pattern): mixed
    {
        return match ($spec['kind']) {
            'text' => is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : null,
            'longtext' => is_scalar($value) ? trim((string) $value) : null,
            'richtext' => is_scalar($value) ? $this->html(trim((string) $value)) : null,
            'choice' => $this->choice($value, $spec),
            'choices' => array_values(array_filter(array_map(fn($v) => $this->choice($v, $spec), (array) $value), fn($v) => $v !== null)),
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : null,
            'blocks' => $this->blocks((array) $value, $spec, $pattern),
            'rows' => array_values(array_map(
                fn(array $row) => $this->fields($row, $spec['fields'] ?? []),
                array_filter((array) $value, 'is_array'),
            )),
            default => null,
        };
    }

    /**
     * Raw HTML in the markdown is escaped: the text came from a model.
     */
    private function html(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        return trim((string) (new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function choice(mixed $value, array $spec): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $options = $spec['options'] ?? [];

        if ($options === [] || array_key_exists($value, $options)) {
            return $value;
        }

        // The model may have written the label rather than the key.
        $key = array_search(strtolower($value), array_map('strtolower', $options), true);

        if ($key !== false) {
            return (string) $key;
        }

        $this->notes[] = "\"{$value}\" is not an option for {$spec['handle']} and was left out.";

        return null;
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $pattern
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $blocks, array $spec, array $pattern): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (!is_string($type) || !isset($spec['sets'][$type])) {
                $this->notes[] = 'A block of type "' . (is_scalar($type) ? $type : '?') . '" cannot go in ' . $spec['handle'] . ' and was left out.';

                continue;
            }

            $fields = $this->fields($block, $spec['sets'][$type]['fields']);

            // House defaults for this block: its usual settings, and for a
            // boilerplate block its usual content too. A boilerplate block
            // is always the copy, whatever the writer put in it: its wording
            // is not the writer's to change.
            $copied = in_array($type, $pattern['boilerplate'] ?? [], true);

            $fixed = $pattern['fixed'][$type] ?? [];

            $changed = array_filter(array_intersect_key($block, $fixed), fn($value, $key) => !is_string($value) || trim($value) !== $fixed[$key], ARRAY_FILTER_USE_BOTH);

            if ($copied && $changed !== []) {
                $this->notes[] = $spec['sets'][$type]['display'] . ' is the same on every entry here, so its usual content was used in place of what was drafted.';
            }

            foreach ($fixed as $key => $value) {
                if ($copied || !isset($fields[$key])) {
                    $fields[$key] = $this->withoutIds($value);
                }
            }

            // Images, links and chosen entries are a person's to pick. Name
            // the ones this kind of entry normally has, so none is missed.
            foreach ($spec['sets'][$type]['fields'] as $field) {
                $expected = in_array($field['handle'], $pattern['used'][$type] ?? [], true);

                if ($expected && !SchemaReader::writable($field) && !isset($fields[$field['handle']])) {
                    $this->toFill[] = $spec['sets'][$type]['display'] . ': ' . ($field['display'] ?: $field['handle']);
                }
            }

            $out[] = ['type' => $type, 'enabled' => true] + $fields;
        }

        return $out;
    }

    /**
     * Copied content keeps its shape but not its IDs: those blocks belong to
     * the entry they were copied from, and these are new ones.
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
