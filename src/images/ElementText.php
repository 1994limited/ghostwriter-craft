<?php

namespace nineteenninetyfour\ghostwriter\images;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Matrix;
use craft\fields\PlainText;
use Illuminate\Support\Collection;
use nineteenninetyfour\ghostwriter\drafts\HtmlToMarkdown;
use Throwable;

/**
 * The words on an element, as plain markdown: its title, its plain and rich
 * text fields, and those of the blocks inside it, in order. Enough for a
 * model to know what a picture is beside.
 */
class ElementText
{
    private const RICH_TEXT = ['craft\ckeditor\Field', 'craft\redactor\Field', 'craft\htmlfield\HtmlField'];

    private const NEO_FIELD = 'benf\neo\Field';

    private const MAX_DEPTH = 5;

    public function of(ElementInterface $element, int $depth = 0): string
    {
        $parts = [];

        // A block whose type has no title field gets one made up by Craft.
        $titled = !$element instanceof \craft\elements\Entry || $element->getType()->hasTitleField;

        if ($depth === 0 && $titled && trim((string) $element->title) !== '') {
            $parts[] = '# ' . trim((string) $element->title);
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            try {
                $value = $element->getFieldValue($field->handle);
            } catch (Throwable) {
                continue;
            }

            if ($field instanceof PlainText) {
                $parts[] = trim((string) $value);
            } elseif ($this->isRich($field)) {
                $parts[] = (new HtmlToMarkdown())->convert((string) $value);
            } elseif (($field instanceof Matrix || is_a($field, self::NEO_FIELD)) && $depth < self::MAX_DEPTH) {
                // Neo hands back every level at once; the top level is
                // enough, as each block brings its own children.
                if (is_a($field, self::NEO_FIELD) && $value instanceof ElementQueryInterface) {
                    $value = (clone $value)->level(1);
                }

                foreach ($this->elements($value) as $child) {
                    $parts[] = $this->of($child, $depth + 1);
                }
            }
        }

        // A Neo block's child blocks are not one of its fields.
        if ($depth > 0 && method_exists($element, 'getChildren') && is_a($element, 'benf\neo\elements\Block') && $depth < self::MAX_DEPTH) {
            foreach ($this->elements($element->getChildren()) as $child) {
                $parts[] = $this->of($child, $depth + 1);
            }
        }

        return implode("\n\n", array_filter(array_map('trim', $parts), fn(string $part) => $part !== ''));
    }

    private function isRich(object $field): bool
    {
        foreach (self::RICH_TEXT as $class) {
            if (is_a($field, $class)) {
                return true;
            }
        }

        return false;
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
}
