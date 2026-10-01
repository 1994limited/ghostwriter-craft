<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use craft\base\FieldInterface;
use craft\fields\Assets;
use craft\fields\BaseOptionsField;
use craft\fields\ButtonGroup;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\models\EntryType;
use craft\models\FieldLayout;

/**
 * Reads a field layout into the plain description of its fields that the
 * rest of the plugin works from. Every site's entry types are different: a
 * single CKEditor body on one, a Matrix or Neo page builder on the next.
 * Reducing each field to a "kind" is what lets one writer serve them all.
 *
 * Kinds:
 *   text, longtext   plain strings (Plain Text, Color)
 *   richtext         written as markdown, stored as HTML (CKEditor, Redactor)
 *   choice, choices  one or several of a fixed set of options (Dropdown,
 *                    Radio Buttons, Button Group; Checkboxes, Multi-select)
 *   toggle, number   Lightswitch; Number, Money, Range
 *   blocks           a page builder: Matrix, whose "sets" are its entry
 *                    types, or Neo, whose sets are its block types. A Neo
 *                    block that takes child blocks gets a `children` field
 *                    of its own, holding the blocks allowed inside it.
 *   rows             a Table: a list of rows sharing the same columns
 *   reference        assets, entries, categories, users, links, dates and
 *                    anything else the writer leaves for a person. A page
 *                    builder with nothing in it to write, such as a Matrix
 *                    of images, is a reference too.
 */
class SchemaReader
{
    private const MAX_DEPTH = 5;

    /** Kinds that configure how something looks rather than say anything. */
    public const SETTING_KINDS = ['choice', 'choices', 'toggle', 'number'];

    /** Rich text fields, by class, so neither plugin needs to be installed. */
    private const RICH_TEXT = ['craft\ckeditor\Field', 'craft\redactor\Field', 'craft\htmlfield\HtmlField'];

    private const NEO = 'benf\neo\Field';

    /**
     * The fields of an entry type, with the title first where it has one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(EntryType $type): array
    {
        return $this->layout($type->getFieldLayout(), $type->hasTitleField, 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function layout(FieldLayout $layout, bool $title, int $depth): array
    {
        $specs = [];

        // The slug is derived from the title, not written.
        if ($title) {
            $specs[] = ['handle' => 'title', 'type' => 'title', 'kind' => 'text', 'display' => 'Title', 'instructions' => '', 'required' => true];
        }

        foreach ($layout->getCustomFields() as $field) {
            $specs[] = $this->field($field, $depth);
        }

        return $specs;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(FieldInterface $field, int $depth): array
    {
        $kind = $this->kind($field);

        $spec = [
            'handle' => (string) $field->handle,
            'type' => $field::class,
            'kind' => $kind,
            'display' => (string) ($field->name ?? $field->handle),
            'instructions' => trim((string) ($field->instructions ?? '')),
            'required' => (bool) ($field->layoutElement?->required ?? $field->required ?? false),
        ];

        if (in_array($kind, ['choice', 'choices'], true) && $field instanceof BaseOptionsField) {
            $spec['options'] = $this->options($field);
        }

        if ($field instanceof Assets) {
            $spec['max_files'] = $field->maxRelations ? (int) $field->maxRelations : null;
            $spec['sources'] = $field->sources;
            $spec['source'] = $field->restrictLocation ? $field->restrictedLocationSource : $field->defaultUploadLocationSource;
            $spec['images'] = !$field->restrictFiles || in_array('image', (array) $field->allowedKinds, true);
        }

        if ($field instanceof Table) {
            $spec['fields'] = $this->columns($field);
            $spec['columns'] = array_column(array_map(fn(array $column) => $column + ['id' => ''], $spec['fields']), 'id', 'handle');
        }

        if ($kind === 'blocks' && $depth < self::MAX_DEPTH) {
            if ($field instanceof Matrix) {
                $spec['engine'] = 'matrix';
                $spec['sets'] = $this->matrixSets($field, $depth);
            } elseif (is_a($field, self::NEO)) {
                $spec['engine'] = 'neo';
                $spec['sets'] = $this->neoSets($field, null, $depth);
            }

            // A page builder with nothing in it to write, such as a Matrix
            // of images, is left for a person like any other reference.
            if (!$this->hasWriting($spec['sets'] ?? [])) {
                $spec['kind'] = 'reference';
            }
        } elseif ($kind === 'blocks') {
            $spec['kind'] = 'reference';
        }

        return $spec;
    }

    private function kind(FieldInterface $field): string
    {
        foreach (self::RICH_TEXT as $class) {
            if (is_a($field, $class)) {
                return 'richtext';
            }
        }

        return match (true) {
            $field instanceof PlainText => $field->multiline ? 'longtext' : 'text',
            $field instanceof Color => 'text',
            $field instanceof Checkboxes, $field instanceof MultiSelect => 'choices',
            $field instanceof Dropdown, $field instanceof RadioButtons, $field instanceof ButtonGroup => 'choice',
            $field instanceof Lightswitch => 'toggle',
            $field instanceof Number, $field instanceof Money, $field instanceof Range => 'number',
            $field instanceof Matrix, is_a($field, self::NEO) => 'blocks',
            $field instanceof Table => 'rows',
            default => 'reference',
        };
    }

    /**
     * @return array<string, array{display: string, instructions: string, fields: array<int, array<string, mixed>>}>
     */
    private function matrixSets(Matrix $field, int $depth): array
    {
        $sets = [];

        foreach ($field->getEntryTypes() as $type) {
            $sets[$type->handle] = [
                'display' => (string) $type->name,
                'instructions' => trim((string) ($type->description ?? '')),
                'fields' => $this->layout($type->getFieldLayout(), (bool) $type->hasTitleField, $depth + 1),
            ];
        }

        return $sets;
    }

    /**
     * Neo's block types. At the top of the field only those allowed at the
     * top level; inside a block, only those it allows as children.
     *
     * @param array<int, string>|string|null $allowed Child block type handles, '*' for all, null for the top level.
     * @return array<string, array<string, mixed>>
     */
    private function neoSets(FieldInterface $field, array|string|null $allowed, int $depth): array
    {
        $sets = [];

        foreach ($field->getBlockTypes() as $type) {
            $permitted = match (true) {
                $allowed === null => (bool) $type->topLevel,
                $allowed === '*' => true,
                default => in_array($type->handle, (array) $allowed, true),
            };

            if (!$permitted || ($type->enabled ?? true) === false) {
                continue;
            }

            $fields = $this->layout($type->getFieldLayout(), false, $depth + 1);

            if (!empty($type->childBlocks) && $depth + 1 < self::MAX_DEPTH) {
                $children = [
                    'handle' => 'children',
                    'type' => 'neo-children',
                    'kind' => 'blocks',
                    'engine' => 'neo-children',
                    'display' => 'Blocks inside',
                    'instructions' => '',
                    'required' => false,
                    'sets' => $this->neoSets($field, $type->childBlocks, $depth + 1),
                ];

                if (!$this->hasWriting($children['sets'])) {
                    $children['kind'] = 'reference';
                }

                $fields[] = $children;
            }

            $sets[$type->handle] = [
                'display' => (string) $type->name,
                'instructions' => trim((string) ($type->description ?? '')),
                'fields' => $fields,
            ];
        }

        return $sets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function columns(Table $field): array
    {
        $fields = [];

        foreach ($field->columns as $id => $column) {
            if (($column['type'] ?? '') === 'heading') {
                continue;
            }

            $handle = (string) (($column['handle'] ?? '') ?: $id);

            $fields[] = [
                'handle' => $handle,
                'id' => (string) $id,
                'type' => 'table:' . ($column['type'] ?? 'singleline'),
                'kind' => match ($column['type'] ?? 'singleline') {
                    'singleline', 'color' => 'text',
                    'multiline' => 'longtext',
                    'number' => 'number',
                    'checkbox', 'lightswitch' => 'toggle',
                    'select' => 'choice',
                    default => 'reference',
                },
                'display' => (string) ($column['heading'] ?? $handle),
                'instructions' => '',
                'required' => false,
                'options' => array_column(array_filter((array) ($column['options'] ?? []), fn($option) => is_array($option) && isset($option['value'])), 'label', 'value'),
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function options(BaseOptionsField $field): array
    {
        $options = [];

        foreach ($field->options as $option) {
            if (is_array($option) && array_key_exists('value', $option) && !isset($option['optgroup'])) {
                $options[(string) $option['value']] = (string) ($option['label'] ?? $option['value']);
            }
        }

        return $options;
    }

    /**
     * @param array<string, array<string, mixed>> $sets
     */
    private function hasWriting(array $sets): bool
    {
        foreach ($sets as $set) {
            foreach ($set['fields'] as $field) {
                // A nested entry's title alone is a label, not writing.
                if ($field['handle'] !== 'title' && self::writable($field)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the writer fills this field in, as opposed to leaving it for a
     * person or for the site's usual defaults.
     *
     * @param array<string, mixed> $spec
     */
    public static function writable(array $spec): bool
    {
        return $spec['kind'] !== 'reference';
    }
}
