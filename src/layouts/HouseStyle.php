<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * What the model entries agree on, place by place, that the pattern finder's
 * "same on every block of this type" cannot see:
 *
 *   positions  settings and links by where a block sits. The first spacer on
 *              a page is 45/65, the last 60/100; the first two breadcrumbs
 *              are always Home and Studio. Agreed values are copied into the
 *              same place in a new entry.
 *   sequences  how many items, of which types, a builder nested in a block
 *              usually holds, so three breadcrumbs are made where pages
 *              have three.
 *   markup     how rich text is dressed in each place. A hero heading that is
 *              always centred, white and uppercase gets the same wrapping;
 *              the writer only writes words.
 *
 * A link from a page to itself, such as a breadcrumb's last step, is
 * recognised as one: it is stored as "this page" and becomes a link to the
 * new entry, with its title. Where two or more model pages link to
 * themselves in the same place and none links anywhere else, the new page
 * does too, however many leave it empty.
 *
 * A link the pages usually have, or that is required, but that nothing
 * settles, points at https://example.com so the page works and the gap is
 * plain to see; it is listed with the places still to fill.
 *
 * Places are paths through the page builder: "pageBuilder/spacer#1" is the
 * second spacer, "pageBuilder/hero#0/children/contentBuilderText#0" the text
 * inside the first hero. Markup goes by the path without the counts.
 */
class HouseStyle
{
    /**
     * Share of the model entries that must agree for a link or other
     * structured value to be copied: a wrong link is worse than none.
     */
    private const AGREED = 0.8;

    /**
     * For a setting (a number, a choice, a word) or a piece of markup, the
     * commonest is taken once more than half agree: a spacer needs some
     * height, and the usual one is the best guess.
     */
    private const MAJORITY = 0.5;

    private const BOOKKEEPING = ['id', 'type', 'enabled'];

    private const TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'ul', 'ol'];

    /** Stands for the entry itself, and for its title, in a learned value. */
    public const SELF = '@self';

    public const TITLE = '@title';

    /** Keys that hold where a link goes, in Hyper's and Craft's link fields. */
    private const TARGETS = ['linkValue', 'value'];

    /** Keys that hold what a link says. */
    private const TEXTS = ['linkText', 'label'];

    /** Where a link that should be there but cannot be decided points. */
    public const PLACEHOLDER_URL = 'https://example.com';

    /** What it says, where a template shows a link's own words. */
    public const PLACEHOLDER_TEXT = 'Link to choose';

    private const HYPER = 'verbb\\hyper\\fields\\HyperField';

    private const LINK = 'craft\\fields\\Link';

    /**
     * @param array<int, array<string, mixed>> $entries Entry data in EntryData's shape.
     * @param array<int, array<string, mixed>> $schema
     * @param array<int, int|null> $ids Each entry's ID, in the same order, to find links to itself.
     * @return array{positions: array<string, array<string, mixed>>, sequences: array<string, array<int, string>>, markup: array<string, array<string, array<string, mixed>>>, links: array<string, float>}
     */
    public function learn(array $entries, array $schema, array $ids = []): array
    {
        $items = [];
        $lists = [];
        $html = [];
        $links = [];

        foreach ($entries as $i => $entry) {
            $own = [];
            $this->collect($entry, $schema, '', '', $own, $lists, $html, $links);

            // A link to the entry itself reads the same on every entry once
            // it is written as "this page".
            foreach ($own as $path => $found) {
                foreach ($found as $item) {
                    $items[$path][] = isset($ids[$i]) ? $this->generalise($item, (int) $ids[$i], (string) ($entry['title'] ?? '')) : $item;
                }
            }
        }

        $positions = [];

        foreach ($items as $path => $found) {
            if (count($found) >= 2 && ($agreed = $this->agreed($found, count($entries))) !== []) {
                $positions[$path] = $agreed;
            }
        }

        $sequences = [];

        foreach ($lists as $path => $found) {
            $counts = [];

            foreach ($found as $sequence) {
                $counts[implode('>', $sequence)] = ($counts[implode('>', $sequence)] ?? 0) + 1;
            }

            arsort($counts);
            $best = (string) array_key_first($counts);

            if (count($found) >= 2 && $counts[$best] / count($found) >= self::AGREED && $best !== '') {
                $sequences[$path] = explode('>', $best);
            }
        }

        $markup = [];

        foreach ($html as $path => $samples) {
            if (count($samples) >= 2 && ($shapes = $this->shapes($samples)) !== []) {
                $markup[$path] = $shapes;
            }
        }

        // How often each kind of block has its link set, wherever it sits.
        $linked = array_map(fn(array $found) => array_sum($found) / count($found), $links);

        return ['positions' => $positions, 'sequences' => $sequences, 'markup' => $markup, 'links' => $linked];
    }

    /**
     * Fill a new entry's data from the house style: agreed values where the
     * draft left a place empty, nested items where the draft has none, and
     * the house markup around the writer's rich text.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $style From learn().
     * @param array<int, string> $toFill Places still for a person, by label.
     * @param array{id: ?int, title: string} $self The new entry, for links to itself.
     * @return array<string, mixed>
     */
    public function apply(array $data, array $schema, array $style, array &$toFill = [], array $self = ['id' => null, 'title' => ''], string $path = '', string $shape = '', string $label = ''): array
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];
            $value = $data[$handle] ?? null;

            if ($spec['kind'] === 'richtext' && is_string($value) && $value !== '') {
                $data[$handle] = $this->dress($value, $style['markup']["{$shape}.{$handle}"] ?? []);

                continue;
            }

            if (!isset($spec['engine'])) {
                continue;
            }

            $base = $path === '' ? $handle : "{$path}/{$handle}";
            $shapeBase = $shape === '' ? $handle : "{$shape}/{$handle}";

            // A builder the draft left empty, such as breadcrumbs, made as
            // the model entries have it.
            if ((!is_array($value) || $value === []) && isset($style['sequences'][$base])) {
                $value = array_map(fn(string $type) => ['type' => $type, 'enabled' => true], $style['sequences'][$base]);
            }

            if (!is_array($value)) {
                continue;
            }

            $seen = [];

            foreach ($value as $i => $block) {
                $type = is_array($block) ? ($block['type'] ?? null) : null;
                $set = $type !== null ? ($spec['sets'][$type] ?? null) : null;

                if ($set === null) {
                    continue;
                }

                $n = $seen[$type] = ($seen[$type] ?? -1) + 1;
                $here = "{$base}/{$type}#{$n}";
                $name = ($label === '' ? '' : "{$label}: ") . $set['display'] . (count(array_filter($value, fn($other) => ($other['type'] ?? null) === $type)) > 1 ? ' ' . ($n + 1) : '');

                foreach ($style['positions'][$here] ?? [] as $key => $agreed) {
                    if (!isset($block[$key]) || $block[$key] === '' || $block[$key] === []) {
                        $filled = $this->specific($this->withoutIds($agreed), $self);

                        // A link to "this page" waits until there is a page to link to.
                        if (!$this->mentionsSelf($filled)) {
                            $block[$key] = $filled;
                        }
                    }
                }

                foreach ($set['fields'] as $field) {
                    if (SchemaReader::writable($field) || !empty($block[$field['handle']]) || isset($field['engine'])) {
                        continue;
                    }

                    // A link the block should have that nothing settles goes
                    // to example.com for now, so the page works and the gap shows.
                    $expected = ($field['required'] ?? false) || ($style['links']["{$shapeBase}/{$type}.{$field['handle']}"] ?? 0) >= self::MAJORITY;

                    if ($expected && ($placeholder = $this->placeholderLink($field)) !== null) {
                        $block[$field['handle']] = $placeholder;
                        $toFill[] = "{$name} (links to example.com for now)";
                    } elseif ($path !== '' && $field['type'] !== \craft\fields\Assets::class) {
                        // A link the model entries do not agree on is a
                        // person's to choose; name it so it is not missed.
                        // Images are marked by the placeholders instead.
                        $toFill[] = $name;
                    }
                }

                $value[$i] = $this->apply($block, $set['fields'], $style, $toFill, $self, $here, "{$shapeBase}/{$type}", $name);
            }

            $data[$handle] = $value;
        }

        return $data;
    }

    /**
     * @param array<string, array<string, array<int, array<string, mixed>>>> $items
     * @param array<string, array<int, array<int, string>>> $lists
     * @param array<string, array<int, string>> $html
     * @param array<string, array<int, int>> $links Per kind of block and link field, 1 where set and 0 where not.
     */
    private function collect(array $data, array $schema, string $path, string $shape, array &$items, array &$lists, array &$html, array &$links = []): void
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];
            $value = $data[$handle] ?? null;

            if ($spec['kind'] === 'richtext' && is_string($value) && trim($value) !== '') {
                $html["{$shape}.{$handle}"][] = $value;

                continue;
            }

            if (!isset($spec['engine']) || !is_array($value)) {
                continue;
            }

            $base = $path === '' ? $handle : "{$path}/{$handle}";
            $shapeBase = $shape === '' ? $handle : "{$shape}/{$handle}";
            $seen = [];
            $sequence = [];

            foreach ($value as $block) {
                if (!is_array($block) || !isset($block['type']) || ($block['enabled'] ?? true) === false) {
                    continue;
                }

                $set = $spec['sets'][$block['type']] ?? null;

                if ($set === null) {
                    continue;
                }

                $n = $seen[$block['type']] = ($seen[$block['type']] ?? -1) + 1;
                $here = "{$base}/{$block['type']}#{$n}";
                $sequence[] = $block['type'];

                // Only the block's own plain values; builders inside it are
                // taken place by place, below.
                $own = [];

                foreach ($set['fields'] as $field) {
                    if (!isset($field['engine']) && array_key_exists($field['handle'], $block)) {
                        $own[$field['handle']] = $block[$field['handle']];
                    }

                    if (in_array($field['type'], [self::HYPER, self::LINK], true)) {
                        $links["{$shapeBase}/{$block['type']}.{$field['handle']}"][] = $this->hasLink($block[$field['handle']] ?? null) ? 1 : 0;
                    }
                }

                $items[$here][] = $own;

                $this->collect($block, $set['fields'], $here, "{$shapeBase}/{$block['type']}", $items, $lists, $html, $links);
            }

            // Only builders inside blocks: the page builder's own order is
            // the pattern finder's to say.
            if ($path !== '') {
                $lists[$base][] = $sequence;
            }
        }
    }

    /**
     * Values that most entries agree on in one place. Empty is not a value.
     *
     * @param array<int, array<string, mixed>> $found
     * @return array<string, mixed>
     */
    private function agreed(array $found, int $entries): array
    {
        $seen = [];
        $samples = [];

        foreach ($found as $item) {
            foreach ($item as $key => $value) {
                if (in_array($key, self::BOOKKEEPING, true) || $value === null || $value === '' || $value === []) {
                    continue;
                }

                $encoded = (string) json_encode($this->withoutIds($value));
                $seen[$key][$encoded] = ($seen[$key][$encoded] ?? 0) + 1;
                $samples[$key][$encoded] ??= $value;
            }
        }

        $agreed = [];

        foreach ($seen as $key => $values) {
            arsort($values);
            $encoded = array_key_first($values);

            // Counted against every model entry, so a value only some pages
            // have is not taken for the house's.
            $share = $values[$encoded] / max($entries, count($found));
            $needed = is_array($samples[$key][$encoded]) ? self::AGREED : self::MAJORITY;

            // A link is copied when nearly every entry has it, or when more
            // than half do and none has anything different: a page that
            // leaves it empty is not a page that disagrees.
            $unopposed = count($values) === 1 && $share > self::MAJORITY;

            // A link to the page itself is copied when two or more pages
            // have one there and every link there is to the page itself,
            // whatever each calls it.
            $selfLinks = array_filter(array_keys($values), fn(string $value) => $this->mentionsSelf(json_decode($value, true)));
            $toSelf = count($selfLinks) === count($values) && array_sum(array_intersect_key($values, array_flip($selfLinks))) >= 2;

            if ($share > $needed || $toSelf || ($needed === self::AGREED && ($share >= $needed || $unopposed))) {
                $agreed[$key] = $samples[$key][$encoded];
            }
        }

        return $agreed;
    }

    /**
     * How each kind of text element is dressed in these samples, where they
     * agree: its attributes, and the inline wrappers around its words.
     *
     * @param array<int, string> $samples
     * @return array<string, array<string, mixed>>
     */
    private function shapes(array $samples): array
    {
        $byTag = [];

        foreach ($samples as $html) {
            $seenHere = [];

            foreach ($this->elements($html) as $element) {
                $tag = strtolower($element->nodeName);

                // One vote per sample per tag: the first such element.
                if (!in_array($tag, self::TEXT_TAGS, true) || isset($seenHere[$tag])) {
                    continue;
                }

                $seenHere[$tag] = true;
                $shape = $this->shapeOf($element);
                $byTag[$tag][(string) json_encode($shape)][] = $shape;
            }
        }

        $shapes = [];

        foreach ($byTag as $tag => $variants) {
            uasort($variants, fn(array $a, array $b) => count($b) <=> count($a));
            $best = reset($variants);
            $total = array_sum(array_map('count', $variants));

            // A plain element is the default and needs nothing.
            if ($total >= 2 && count($best) / $total > self::MAJORITY && ($best[0]['attributes'] !== [] || $best[0]['wrappers'] !== [])) {
                $shapes[$tag] = $best[0];
            }
        }

        return $shapes;
    }

    /**
     * @return array{attributes: array<string, string>, wrappers: array<int, array{0: string, 1: array<string, string>}>}
     */
    private function shapeOf(DOMElement $element): array
    {
        $wrappers = [];
        $node = $element;

        // Inline elements wrapping all of the content, one inside another.
        while (($only = $this->onlyChild($node)) && in_array(strtolower($only->nodeName), ['span', 'strong', 'em', 'b', 'i', 'mark', 'small'], true)) {
            $wrappers[] = [strtolower($only->nodeName), $this->attributes($only)];
            $node = $only;
        }

        return ['attributes' => $this->attributes($element), 'wrappers' => $wrappers];
    }

    /**
     * Put the house markup on the writer's plain elements.
     *
     * @param array<string, array<string, mixed>> $shapes
     */
    private function dress(string $html, array $shapes): string
    {
        if ($shapes === []) {
            return $html;
        }

        $document = $this->document($html);
        $body = $document->getElementsByTagName('body')->item(0);
        $changed = false;

        foreach (iterator_to_array($body?->childNodes ?? []) as $element) {
            $shape = $element instanceof DOMElement ? ($shapes[strtolower($element->nodeName)] ?? null) : null;

            // Only plain elements: anything the writer dressed is left as it is.
            if ($shape === null || $element->attributes->length > 0) {
                continue;
            }

            foreach ($shape['attributes'] as $name => $value) {
                $element->setAttribute($name, $value);
            }

            $inner = $element;

            foreach ($shape['wrappers'] as [$tag, $attributes]) {
                $wrapper = $document->createElement($tag);

                foreach ($attributes as $name => $value) {
                    $wrapper->setAttribute($name, $value);
                }

                while ($inner->firstChild) {
                    $wrapper->appendChild($inner->firstChild);
                }

                $inner->appendChild($wrapper);
                $inner = $wrapper;
            }

            $changed = true;
        }

        if (!$changed || !$body) {
            return $html;
        }

        $out = '';

        foreach ($body->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function elements(string $html): array
    {
        $body = $this->document($html)->getElementsByTagName('body')->item(0);

        return $body ? array_values(array_filter(iterator_to_array($body->childNodes), fn($node) => $node instanceof DOMElement)) : [];
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        return $document;
    }

    private function onlyChild(DOMNode $node): ?DOMElement
    {
        $elements = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            } elseif (trim($child->textContent) !== '') {
                return null;
            }
        }

        return count($elements) === 1 ? $elements[0] : null;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        ksort($attributes);

        return $attributes;
    }

    /**
     * A link to its own entry, written as "this page" and its title.
     */
    private function generalise(mixed $value, int $id, string $title): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (in_array($key, self::TARGETS, true) && ($item === $id || $item === [$id] || $item === (string) $id)) {
                $value[$key] = self::SELF;
            } elseif (in_array($key, self::TEXTS, true) && $title !== '' && $item === $title) {
                $value[$key] = self::TITLE;
            } else {
                $value[$key] = $this->generalise($item, $id, $title);
            }
        }

        return $value;
    }

    /**
     * "This page" made into the new entry and its title.
     *
     * @param array{id: ?int, title: string} $self
     */
    private function specific(mixed $value, array $self): mixed
    {
        if ($value === self::SELF) {
            return $self['id'] !== null ? [$self['id']] : self::SELF;
        }

        if ($value === self::TITLE) {
            return $self['title'];
        }

        return is_array($value) ? array_map(fn($item) => $this->specific($item, $self), $value) : $value;
    }

    /**
     * A link to example.com, in the shape the field stores, or null for a
     * field that cannot hold a web address.
     *
     * @param array<string, mixed> $field
     * @return array<int|string, mixed>|null
     */
    private function placeholderLink(array $field): ?array
    {
        return match ($field['type']) {
            self::HYPER => [['type' => 'verbb\\hyper\\links\\Url', 'linkValue' => self::PLACEHOLDER_URL, 'linkText' => self::PLACEHOLDER_TEXT]],
            self::LINK => ['type' => 'url', 'value' => self::PLACEHOLDER_URL, 'label' => self::PLACEHOLDER_TEXT],
            default => null,
        };
    }

    private function hasLink(mixed $value): bool
    {
        if (!is_array($value)) {
            return is_string($value) && trim($value) !== '';
        }

        foreach ($value as $key => $item) {
            $found = in_array($key, self::TARGETS, true) ? $item !== null && $item !== '' && $item !== [] : is_array($item) && $this->hasLink($item);

            if ($found) {
                return true;
            }
        }

        return false;
    }

    private function mentionsSelf(mixed $value): bool
    {
        if ($value === self::SELF) {
            return true;
        }

        return is_array($value) && array_filter($value, fn($item) => $this->mentionsSelf($item)) !== [];
    }

    private function withoutIds(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        unset($value['id']);

        return array_map(fn($item) => $this->withoutIds($item), $value);
    }
}
