<?php

namespace nineteenninetyfour\ghostwriter\content;

use craft\base\ElementInterface;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use yii\base\Component;

/**
 * Pulls the words an editor wrote out of an entry, whatever shape its field
 * layout gives them: CKEditor and Redactor HTML, Matrix blocks, tables,
 * plain text. IDs, asset references, links and other machine values are
 * left behind, because the point is to read the prose, not the plumbing.
 */
class ProseExtractor extends Component
{
    /** Keys whose values are never prose. */
    private const SKIP_KEYS = [
        'id', 'type', 'enabled', 'collapsed', 'title', 'slug', 'uri', 'template', 'layout', 'author',
        'link', 'url', 'href', 'image', 'avatar', 'icon', 'logo', 'logos', 'tint', 'fill', 'ground',
        'gradient', 'width', 'style', 'anchor', 'colour', 'color',
    ];

    public function __construct(private HtmlToMarkdown $markdown = new HtmlToMarkdown(), array $config = [])
    {
        parent::__construct($config);
    }

    public function fromEntry(ElementInterface $entry): string
    {
        $lines = [];

        $this->walk($entry->getSerializedFieldValues(), $lines);

        return trim(implode("\n\n", array_values(array_unique($lines))));
    }

    /**
     * @param array<int, string> $lines
     */
    private function walk(mixed $value, array &$lines): void
    {
        if (is_array($value)) {
            // A Matrix block switched off in the control panel is not part of the page.
            if (($value['enabled'] ?? true) === false && isset($value['type'])) {
                return;
            }

            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }

                $this->walk($child, $lines);
            }

            return;
        }

        if (!is_string($value)) {
            return;
        }

        if ($this->isHtml($value)) {
            $text = trim($this->markdown->convert($value));

            if ($text !== '') {
                $lines[] = $text;
            }

            return;
        }

        if ($this->looksLikeProse($value)) {
            $lines[] = trim($value);
        }
    }

    private function isHtml(string $value): bool
    {
        return (bool) preg_match('/<(p|h[1-6]|ul|ol|li|blockquote|br|strong|em|a|div|table|figure)[\s>\/]/i', $value);
    }

    /**
     * A sentence or a headline, not a handle, a path or a reference.
     */
    private function looksLikeProse(string $value): bool
    {
        $value = trim($value);

        if (mb_strlen($value) < 12 || !str_contains($value, ' ')) {
            return false;
        }

        return !preg_match('/^(\{[a-z]+:|https?:\/\/|#|\/|@)/', $value);
    }
}
