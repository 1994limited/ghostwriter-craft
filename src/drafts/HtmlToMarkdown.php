<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Rich text as the markdown a writer would have typed. CKEditor and
 * Redactor store HTML; this is how real entries from the site are shown to
 * the model in the same form it is asked to write in.
 *
 * Headings, paragraphs, lists, block quotes and tables are kept, with bold,
 * italic and links. Images, embedded entries and other furniture are
 * dropped: the point is the writing.
 */
class HtmlToMarkdown
{
    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        $body = $document->getElementsByTagName('body')->item(0);

        return $body ? $this->blocks($body) : '';
    }

    private function blocks(DOMNode $parent, int $indent = 0): string
    {
        $blocks = [];
        $loose = '';

        foreach ($parent->childNodes as $child) {
            // Inline content sitting straight in a block container reads as a paragraph.
            if ($child instanceof DOMText || ($child instanceof DOMElement && $this->isInline($child))) {
                $loose .= $this->inline($child);

                continue;
            }

            if (trim($loose) !== '') {
                $blocks[] = $this->normalise($loose);
            }

            $loose = '';

            if ($child instanceof DOMElement && ($block = $this->block($child, $indent)) !== '') {
                $blocks[] = $block;
            }
        }

        if (trim($loose) !== '') {
            $blocks[] = $this->normalise($loose);
        }

        return implode("\n\n", $blocks);
    }

    private function block(DOMElement $node, int $indent): string
    {
        $tag = strtolower($node->nodeName);

        return match (true) {
            (bool) preg_match('/^h([1-6])$/', $tag, $m) => str_repeat('#', (int) $m[1]) . ' ' . $this->inlineChildren($node),
            $tag === 'p' => $this->inlineChildren($node),
            $tag === 'ul' => $this->list($node, fn(int $i) => '- ', $indent),
            $tag === 'ol' => $this->list($node, fn(int $i) => ($i + 1) . '. ', $indent),
            $tag === 'blockquote' => implode("\n", array_map(fn(string $line) => rtrim('> ' . $line), explode("\n", $this->blocks($node)))),
            $tag === 'table' => $this->table($node),
            $tag === 'hr' => '---',
            $tag === 'pre' => "```\n" . rtrim($node->textContent) . "\n```",
            in_array($tag, ['img', 'picture', 'video', 'audio', 'iframe', 'script', 'style', 'figcaption', 'craft-entry'], true) => '',
            default => $this->blocks($node, $indent),
        };
    }

    private function list(DOMElement $list, callable $marker, int $indent): string
    {
        $lines = [];
        $i = 0;

        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $text = [];
            $nested = [];

            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                    $nested[] = $this->block($child, $indent + 1);
                } elseif ($child instanceof DOMElement && strtolower($child->nodeName) === 'p') {
                    $text[] = $this->inlineChildren($child);
                } else {
                    $text[] = $this->inline($child);
                }
            }

            $lines[] = str_repeat('  ', $indent) . $marker($i++) . $this->normalise(implode(' ', $text));

            foreach (array_filter($nested) as $sublist) {
                $lines[] = $sublist;
            }
        }

        return implode("\n", $lines);
    }

    private function table(DOMElement $table): string
    {
        $lines = [];

        foreach ($table->getElementsByTagName('tr') as $i => $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\\|', $this->inlineChildren($cell));
                }
            }

            $lines[] = '| ' . implode(' | ', $cells) . ' |';

            if ($i === 0) {
                $lines[] = '|' . str_repeat(' --- |', count($cells));
            }
        }

        return implode("\n", $lines);
    }

    private function inlineChildren(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $this->inline($child);
        }

        return $this->normalise($out);
    }

    /**
     * Line breaks (marked by inline() with a NUL) are kept as markdown hard
     * breaks; any other run of whitespace is only HTML formatting.
     */
    private function normalise(string $inline): string
    {
        return trim(implode("  \n", array_map(fn(string $line) => trim(preg_replace('/[ \t\r\n]+/', ' ', $line) ?? ''), explode("\u{0}", $inline))));
    }

    private function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'br') {
            return "\u{0}";
        }

        if (in_array($tag, ['img', 'script', 'style', 'craft-entry'], true)) {
            return '';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= $this->inline($child);
        }

        if (trim($text) === '') {
            return $text;
        }

        return match ($tag) {
            'strong', 'b' => $this->wrap($text, '**'),
            'em', 'i' => $this->wrap($text, '*'),
            'code' => '`' . $text . '`',
            'a' => $node->getAttribute('href') !== '' ? '[' . trim($text) . '](' . $node->getAttribute('href') . ')' : $text,
            default => $text,
        };
    }

    /**
     * Marks go inside any surrounding space, as markdown needs.
     */
    private function wrap(string $text, string $mark): string
    {
        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);

        return $m[1] . $mark . $m[2] . $mark . $m[3];
    }

    private function isInline(DOMElement $node): bool
    {
        return in_array(strtolower($node->nodeName), ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'code', 'br', 'sup', 'sub', 'mark', 'small'], true);
    }
}
