<?php

namespace nineteenninetyfour\ghostwriter\preview;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use DateTime;

/**
 * CKEditor nested entries a draft adds, rendered without being saved
 * (page preview design §7.3, spike C4).
 *
 * CKEditor renders a nested entry by loading it by ID. One the draft adds
 * has no ID, so the preview's HTML gives it a preview-only negative one
 * (`<craft-entry data-entry-id="-101">`). Here each CKEditor value, on the
 * entry and inside its Matrix blocks, is parsed and loaded (those IDs find
 * nothing), then each placeholder is handed an entry built in memory.
 * It renders through its entry type's partial template, or as a plain
 * stand-in box where there is none.
 */
class NestedEntries
{
    private const CKEDITOR_FIELD = 'craft\ckeditor\Field';

    private const FIELD_DATA = 'craft\ckeditor\data\FieldData';

    private const ENTRY_CHUNK = 'craft\ckeditor\data\Entry';

    /**
     * @param array<int, array{type: string, title?: ?string, fields: array<string, mixed>}> $nested By negative ID.
     */
    public function swapIn(Entry $owner, array $nested): void
    {
        if (!class_exists(self::FIELD_DATA)) {
            return;
        }

        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $this->patch($owner, $field, $nested);

            if ($field instanceof Matrix) {
                foreach ($owner->getFieldValue($field->handle)->all() as $block) {
                    foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $inner) {
                        $this->patch($block, $inner, $nested);
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array{type: string, title?: ?string, fields: array<string, mixed>}> $nested
     */
    private function patch(ElementInterface $element, FieldInterface $field, array $nested): void
    {
        if (!is_a($field, self::CKEDITOR_FIELD)) {
            return;
        }

        $value = $element->getFieldValue($field->handle);

        if (!is_a($value, self::FIELD_DATA)) {
            return;
        }

        // Parse, and load the saved nested entries (one query; the
        // placeholders find nothing), then hand each placeholder its own.
        $chunks = $value->getChunks(false);
        $value->loadEntries();

        foreach ($chunks as $chunk) {
            if (!is_a($chunk, self::ENTRY_CHUNK) || !isset($nested[(int) $chunk->entryId])) {
                continue;
            }

            $entry = $this->entry($nested[(int) $chunk->entryId], $field, $element);

            if ($entry) {
                $chunk->setEntry($entry);
            }
        }
    }

    /**
     * @param array{type: string, title?: ?string, fields: array<string, mixed>} $spec
     */
    private function entry(array $spec, FieldInterface $field, ElementInterface $owner): ?Entry
    {
        $type = Craft::$app->getEntries()->getEntryTypeByHandle($spec['type']);

        if (!$type) {
            return null;
        }

        // Live, as CKEditor shows only live nested entries.
        $entry = new PreviewNestedEntry([
            'typeId' => $type->id,
            'fieldId' => $field->id,
            'ownerId' => $owner->id,
            'siteId' => $owner->siteId,
            'enabled' => true,
            'postDate' => new DateTime(),
        ]);

        if ($spec['title'] ?? null) {
            $entry->title = $spec['title'];
        }

        $entry->setFieldValues($spec['fields']);
        $entry->previewing = true;

        return $entry;
    }
}
