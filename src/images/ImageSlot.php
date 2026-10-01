<?php

namespace nineteenninetyfour\ghostwriter\images;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * One Assets field on one element, as the image button sees it: the field,
 * the element it is on (an entry, or a Matrix or Neo block inside one), the
 * entry at the top, and what the pictures already in that place on other
 * entries look like.
 *
 * Words come first from the block the field sits in, since a picture
 * belongs to what is beside it, and from the whole page around it.
 */
class ImageSlot
{
    /** Pictures shown to a model as the style to match. */
    public const REFERENCES = 3;

    /** Entries looked through for those pictures, newest first, until three are found. */
    private const ENTRIES = 150;

    private const MAX_CHARS = 6000;

    private const NEO = 'benf\neo\elements\Block';

    /** @var Asset[]|null */
    private ?array $references = null;

    private function __construct(
        public readonly Assets $field,
        public readonly ElementInterface $element,
        public readonly Entry $root,
        public readonly ?ElementInterface $block,
    ) {
    }

    /**
     * The slot for a field on an element, or null where the image button
     * has no business: not an Assets field, not inside an entry of a section
     * Ghostwriter writes for, or an entry the user may not save.
     */
    public static function find(int $fieldId, int $elementId, int $siteId, ?User $user = null): ?self
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        return $field instanceof Assets && $element ? self::for($field, $element, $user) : null;
    }

    public static function for(Assets $field, ElementInterface $element, ?User $user = null): ?self
    {
        if (!self::takesImages($field)) {
            return null;
        }

        [$root, $block] = self::rootOf($element);

        if (!$root instanceof Entry || !($section = $root->getSection()) || !Plugin::getInstance()->types->enabled($section->handle)) {
            return null;
        }

        if ($user && (!$user->can(Plugin::PERMISSION) || !Craft::$app->getElements()->canSave($root, $user))) {
            return null;
        }

        return new self($field, $element, $root, $block);
    }

    /**
     * Whether the field can hold an image at all: a field kept to PDFs or
     * videos gets no button.
     */
    public static function takesImages(Assets $field): bool
    {
        return !$field->restrictFiles || in_array('image', (array) $field->allowedKinds, true);
    }

    /**
     * The entry at the top, and the outermost block on the way to it.
     *
     * @return array{0: ?ElementInterface, 1: ?ElementInterface}
     */
    private static function rootOf(ElementInterface $element): array
    {
        $current = $element;
        $outermost = null;

        for ($depth = 0; $depth < 8; $depth++) {
            $owner = method_exists($current, 'getOwner') ? $current->getOwner() : null;

            if (!$owner) {
                return [$current, $outermost];
            }

            $outermost = $current;
            $current = $owner;
        }

        return [null, null];
    }

    /**
     * "Block: Field" inside a page builder, as the image sampler labels it,
     * or the field's name at the top of the entry.
     */
    public function label(): string
    {
        $type = $this->block && method_exists($this->block, 'getType') ? $this->block->getType() : null;

        return ($type ? "{$type->name}: " : '') . $this->field->name;
    }

    public function sectionName(): string
    {
        return Craft::t('site', (string) $this->root->getSection()?->name);
    }

    public function title(): string
    {
        return trim((string) $this->root->title);
    }

    /** The words in the block the field sits in; empty at the top of the entry. */
    public function blockText(): string
    {
        return $this->element === $this->root ? '' : $this->trim((new ElementText())->of($this->element));
    }

    public function pageText(): string
    {
        return $this->trim((new ElementText())->of($this->root));
    }

    /**
     * Pictures in the same place on the section's other entries: the same
     * field, in the same kind of block.
     *
     * @return Asset[]
     */
    public function references(): array
    {
        if ($this->references !== null) {
            return $this->references;
        }

        $sampler = new ImageSampler();
        $label = $this->label();
        $found = [];

        // Failing the same place, the same field in a block of the same
        // family: "Link Grid - Bottom Text: Image" for "Link Grid - Center
        // Text: Image".
        $family = $this->block ? preg_replace('/\s*[-–:(].*$/u', '', $label) . ' ' : null;
        $kin = [];

        $entries = Entry::find()
            ->section($this->root->getSection()->handle)
            ->status('live')
            ->id(['not', (int) $this->root->getCanonicalId()])
            ->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])
            ->limit(self::ENTRIES)
            ->all();

        foreach ($entries as $entry) {
            foreach ($sampler->find($entry) as [$where, $asset]) {
                if ($asset->filename === Placeholders::FILENAME) {
                    continue;
                }

                if ($where === $label) {
                    $found[$asset->id] ??= $asset;
                } elseif ($family && str_starts_with($where, $family) && str_ends_with($where, ': ' . $this->field->name)) {
                    $kin[$asset->id] ??= $asset;
                }
            }

            if (count($found) >= self::REFERENCES) {
                break;
            }
        }

        return $this->references = array_slice(array_values($found ?: $kin), 0, self::REFERENCES);
    }

    /**
     * landscape, portrait or square, going by the pictures already there.
     */
    public function shape(): string
    {
        $reference = $this->references()[0] ?? null;
        $width = (int) $reference?->getWidth();
        $height = (int) $reference?->getHeight();

        if (!$width || !$height) {
            return 'landscape';
        }

        $ratio = $width / $height;

        return $ratio > 1.2 ? 'landscape' : ($ratio < 0.83 ? 'portrait' : 'square');
    }

    /**
     * The folder the field uploads to, for this element.
     */
    public function folderId(): int
    {
        try {
            return $this->field->resolveDynamicPathToFolderId($this->element);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException("This field's upload location could not be worked out: {$exception->getMessage()}");
        }
    }

    private function trim(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . '…' : $text;
    }
}
