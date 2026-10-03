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
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Readiness;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * No page goes live unfinished: the one publish guard for "Finish this
 * page" and stock photos (§5.6 of the finish-this-page design, §7.1 of the
 * stock design), core's PublishReadiness behind it.
 *
 * On `Entry::EVENT_BEFORE_SAVE`, for a canonical entry (not a draft or
 * revision) that is enabled for its site and saved in the live scenario,
 * or updated from a draft (applying a draft, which Craft saves through
 * duplicateElement() in the essentials scenario, so it is told by
 * `updatingFromDerivative`): one finder run looks for every gap that
 * blocks, at any depth in Matrix and Neo blocks. A fact to add, a link to
 * choose or to a page that's gone, an image placeholder, leftover template
 * text, or a stock photo preview not licensed. Then, as
 * `onUnfinishedPublish` says, the save is refused with a message on each
 * field ("block", the default), or saved with one warning listing them
 * ("warn"). Drafts, provisional drafts and disabled entries always save.
 *
 * In sections Ghostwriter doesn't write for, only stock previews are
 * looked for. Global sets and categories are only warned about previews.
 */
class PublishGuard
{
    /** How deep blocks are followed for images. */
    private const DEPTH = 6;

    public static function beforeSave(ModelEvent $event): void
    {
        $element = $event->sender;

        if (!$element instanceof ElementInterface || !$event->isValid) {
            return;
        }

        // A block is checked with the entry it is in, never on its own.
        if (method_exists($element, 'getOwner') && $element->getOwner() !== null) {
            return;
        }

        if ($element instanceof Entry) {
            self::entry($event, $element);

            return;
        }

        if (Plugin::getInstance()->stockUsages->ledgerIsEmpty()) {
            return;
        }

        try {
            $previews = self::previewsIn($element);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't check for stock photo previews: {$exception->getMessage()}", 'ghostwriter');

            return;
        }

        foreach ($previews as [, $label, $image]) {
            self::warn(self::message($label, $image));
        }
    }

    /**
     * Whether saving this entry puts it live: a canonical entry, enabled
     * for its site, saved in the live scenario (Save), or updated from a
     * draft (applying a draft, which Craft saves through duplicateElement()
     * in the essentials scenario, so it is told by `updatingFromDerivative`).
     */
    public static function goesLive(Entry $entry): bool
    {
        return !ElementHelper::isDraftOrRevision($entry)
            && !$entry->propagating
            && ($entry->getScenario() === Element::SCENARIO_LIVE || $entry->updatingFromDerivative)
            && $entry->enabled
            && $entry->getEnabledForSite();
    }

    private static function entry(ModelEvent $event, Entry $entry): void
    {
        $plugin = Plugin::getInstance();

        if (!self::goesLive($entry) || (!Gaps::writesHere($entry) && $plugin->stockUsages->ledgerIsEmpty())) {
            return;
        }

        try {
            $readiness = $plugin->gaps->readiness($entry);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't check whether the entry is finished: {$exception->getMessage()}", 'ghostwriter');

            return;
        }

        if ($readiness->ready()) {
            return;
        }

        $translate = fn(Message $message) => Gaps::translate($message);

        if (!$readiness->blocked()) {
            self::warn(Gaps::translate($readiness->message($translate)));

            return;
        }

        foreach (self::byField($readiness) as $handle => $message) {
            $entry->addError($handle, $message);
        }

        $event->isValid = false;
    }

    /**
     * What each top-level field says, for addError(): core's message for
     * each problem, named by its block when it sits in one ("Feature:
     * Picture: This is a Demo stock preview…"), one sentence each.
     *
     * @return array<string, string>
     */
    public static function byField(Readiness $readiness): array
    {
        $fields = [];

        foreach ($readiness->problems() as $gap) {
            // As core's Readiness words it: "gaps.publish.field.<kind>".
            $text = Gaps::translate(new Message('gaps.publish.field.' . $gap->kind->value, array_filter([
                'label' => $gap->label,
                'hint' => $gap->hint,
                'library' => is_scalar($gap->meta['library'] ?? null) ? $gap->meta['library'] : null,
            ], fn($value) => $value !== null)));

            $fields[$gap->path->handle()][] = count($gap->path->segments) > 1 ? Gaps::translate(new Message('{place}: {text}', ['place' => $gap->label, 'text' => $text])) : $text;
        }

        return array_map(fn(array $lines) => implode(' ', array_unique($lines)), $fields);
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
