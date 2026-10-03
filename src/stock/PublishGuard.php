<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\fields\Assets;
use craft\helpers\ElementHelper;
use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * No page goes live holding a stock photo preview (§7.1).
 *
 * On `Entry::EVENT_BEFORE_SAVE`, for a canonical entry (not a draft or
 * revision) that is enabled for its site and saved in the live scenario,
 * or updated from a draft (applying a draft, which Craft saves in the
 * essentials scenario): if any image in it, at any depth in its
 * blocks, is a preview not licensed yet, the save is refused with a
 * message on the field (`stockOnPublish` = block, the default), or saved
 * with a warning (warn). Drafts, provisional drafts and disabled entries
 * always save. Other elements (global sets, categories) are only warned.
 */
class PublishGuard
{
    /** How deep blocks are followed for images. */
    private const DEPTH = 6;

    public static function beforeSave(ModelEvent $event): void
    {
        $element = $event->sender;

        if (!$element instanceof ElementInterface || !$event->isValid || Plugin::getInstance()->stockUsages->ledgerIsEmpty()) {
            return;
        }

        // A block is checked with the entry it is in, never on its own.
        if (method_exists($element, 'getOwner') && $element->getOwner() !== null) {
            return;
        }

        try {
            $previews = self::previewsIn($element);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't check for stock photo previews: {$exception->getMessage()}", 'ghostwriter');

            return;
        }

        if ($previews === []) {
            return;
        }

        // Going live: a canonical entry, enabled for its site, saved in the
        // live scenario (Save), or updated from a draft (applying a draft,
        // which Craft saves through duplicateElement() in the essentials
        // scenario, so it is told by `updatingFromDerivative`).
        $live = $element instanceof Entry
            && !ElementHelper::isDraftOrRevision($element)
            && !$element->propagating
            && ($element->getScenario() === Element::SCENARIO_LIVE || $element->updatingFromDerivative)
            && $element->enabled
            && $element->getEnabledForSite();

        $blocks = $live && Plugin::getInstance()->getSettings()->blocksPreviewsOnPublish();

        foreach ($previews as [$handle, $label, $image]) {
            $message = self::message($label, $image);

            if ($blocks) {
                $element->addError($handle, $message);
            } elseif ($live || !$element instanceof Entry) {
                self::warn($message);
            }
        }

        if ($blocks) {
            $event->isValid = false;
        }
    }

    /**
     * "The hero image is a Getty preview, not licensed yet. License it, or
     * choose another image, before publishing."
     */
    public static function message(string $label, StockImage $image): string
    {
        return Craft::t('ghostwriter', 'The {field} is a {library} preview, not licensed yet. License it, or choose another image, before publishing.', [
            'field' => $label,
            'library' => Plugin::getInstance()->stockLibraries->standInName($image->library),
        ]);
    }

    /**
     * The previews in an element's fields, each with the top-level field
     * that holds it (for the error) and how to name it.
     *
     * @return array<int, array{0: string, 1: string, 2: StockImage}>
     */
    public static function previewsIn(ElementInterface $element): array
    {
        $found = [];
        self::collect($element, null, $found, 0);

        $plugin = Plugin::getInstance();
        $previews = [];

        foreach ($found as $assetId => [$handle, $label]) {
            $image = $plugin->stockImages->forAsset(AssetRef::craft($assetId));

            if ($image !== null && $image->isUnlicensed()) {
                $previews[] = [$handle, $label, $image];
            }
        }

        return $previews;
    }

    /**
     * Asset IDs in an element's field values as they are now (posted, not
     * yet saved), by the top-level field they are in.
     *
     * @param array{0: string, 1: string}|null $top The top-level field's handle and its name, inside a block.
     * @param array<int, array{0: string, 1: string}> $found
     */
    private static function collect(ElementInterface $element, ?array $top, array &$found, int $depth): void
    {
        if ($depth > self::DEPTH) {
            return;
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $element->getFieldValue($field->handle);
            $place = $top ?? [(string) $field->handle, self::name($field)];

            if ($field instanceof Assets) {
                foreach (self::ids($value) as $id) {
                    $found[$id] ??= $top === null ? $place : [$top[0], self::nestedName($element, $field)];
                }

                continue;
            }

            foreach (self::nested($value) as $nested) {
                self::collect($nested, $place, $found, $depth + 1);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private static function ids(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $cached = method_exists($value, 'getCachedResult') ? $value->getCachedResult() : null;

            return array_map('intval', $cached !== null ? array_map(fn($asset) => $asset->id, $cached) : (clone $value)->status(null)->ids());
        }

        return $value instanceof Collection ? array_map(fn($asset) => (int) $asset->id, $value->all()) : [];
    }

    /**
     * Nested elements (Matrix entries, Neo blocks) in a field's value.
     *
     * @return array<int, ElementInterface>
     */
    private static function nested(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $cached = method_exists($value, 'getCachedResult') ? $value->getCachedResult() : null;
            $elements = $cached ?? (clone $value)->status(null)->all();
        } elseif ($value instanceof Collection) {
            $elements = $value->all();
        } else {
            return [];
        }

        return array_values(array_filter($elements, fn($nested) => $nested instanceof ElementInterface && !$nested instanceof \craft\elements\Asset && $nested->getFieldLayout() !== null && method_exists($nested, 'getOwner')));
    }

    /** "hero image", from the field's name "Hero image". */
    private static function name(FieldInterface $field): string
    {
        $name = Craft::t('site', (string) $field->name);

        return mb_strtolower(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /** "“Hero: Image” image", inside a block. */
    private static function nestedName(ElementInterface $element, FieldInterface $field): string
    {
        $type = method_exists($element, 'getType') ? $element->getType() : null;
        $label = ($type ? Craft::t('site', $type->name) . ': ' : '') . Craft::t('site', (string) $field->name);

        return Craft::t('ghostwriter', '“{label}” image', ['label' => $label]);
    }

    private static function warn(string $message): void
    {
        if (Craft::$app instanceof \craft\web\Application) {
            Craft::$app->getSession()->setNotice($message);
        }

        Craft::warning($message, 'ghostwriter');
    }
}
