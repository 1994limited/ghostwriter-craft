<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\Asset;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use Throwable;
use yii\base\Component;

/**
 * Where each ledger image is used, read exactly from Craft's relations
 * table: an asset ID as a relation's target gives the element holding it,
 * the field and the site. Drafts and nested entries (Matrix, Neo) are
 * followed up to the entry at the top, and kept against its canonical
 * entry: a usage on a draft is still a usage, since it is where a preview
 * is being looked at.
 *
 * Usages are kept up to date each time an entry is saved, and on demand
 * from the ledger screen (resync()).
 */
class StockUsages extends Component
{
    /** What a usage's owner is, in the ledger. */
    public const OWNER = 'entry';

    /** How deep nested entries are followed. */
    private const DEPTH = 8;

    private ?bool $empty = null;

    /**
     * After any entry is saved or deleted: its canonical entry's usages,
     * for every site it is on. Revisions and propagation are skipped, and
     * nothing is done while the ledger is empty.
     */
    public function afterSave(ElementInterface $element): void
    {
        if (!$element instanceof Entry || $element->propagating || $element->getIsRevision() || $this->ledgerIsEmpty()) {
            return;
        }

        try {
            $root = self::root($element);

            if ($root instanceof Entry && !$root->getIsRevision()) {
                $canonicalId = (int) $root->getCanonicalId();

                if ($canonicalId) {
                    $this->sync($canonicalId);
                }
            }
        } catch (Throwable $exception) {
            // Keeping the ledger's usages up to date never stops a save.
            Craft::warning("Ghostwriter couldn't update where stock images are used: {$exception->getMessage()}", 'ghostwriter');
        }
    }

    /**
     * After an element is deleted: a deleted entry no longer uses anything
     * (a deleted draft is looked at through its canonical entry), and a
     * deleted asset's ledger record is removed. A licence stays on file.
     */
    public function afterDelete(ElementInterface $element): void
    {
        if ($this->ledgerIsEmpty()) {
            return;
        }

        try {
            if ($element instanceof Asset) {
                $image = Plugin::getInstance()->stockImages->forAsset(AssetRef::craft((int) $element->id));

                if ($image !== null && !$image->is(StockImage::LICENSING)) {
                    Plugin::getInstance()->domain->stock()->removed($image->id, Plugin::getInstance()->domain->person());
                }

                return;
            }

            if (!$element instanceof Entry || $element->getIsRevision()) {
                return;
            }

            $root = self::root($element);

            if (!$root instanceof Entry) {
                return;
            }

            if ($root->getIsDraft() && !$root->getIsUnpublishedDraft()) {
                $this->sync((int) $root->getCanonicalId());

                return;
            }

            $stock = Plugin::getInstance()->domain->stock();
            $sites = (new Query())->select('site')->distinct()->from(Store::STOCK_USAGES)->where(['ownerType' => self::OWNER, 'ownerId' => (string) $root->id])->column();

            foreach ($sites as $site) {
                $stock->syncUsages(self::OWNER, (int) $root->id, $site !== null ? (string) $site : null, []);
            }
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't update where stock images are used: {$exception->getMessage()}", 'ghostwriter');
        }
    }

    /**
     * Usages of ledger images on one canonical entry (with its drafts and
     * nested entries), on every site it is on.
     *
     * @return int How many ledger records changed.
     */
    public function sync(int $entryId): int
    {
        $ids = $this->tree($entryId);
        $relations = (new Query())
            ->select(['sourceId', 'sourceSiteId', 'fieldId', 'targetId'])
            ->from(Table::RELATIONS)
            ->where(['sourceId' => array_keys($ids)])
            ->all();
        $ledger = $this->ledgerAssets(array_map(fn(array $row) => (int) $row['targetId'], $relations));

        $known = (new Query())->select('site')->distinct()->from(Store::STOCK_USAGES)->where(['ownerType' => self::OWNER, 'ownerId' => (string) $entryId])->column();
        $sites = array_values(array_unique(array_map('strval', [
            ...(new Query())->select('siteId')->from(Table::ELEMENTS_SITES)->where(['elementId' => $entryId])->column(),
            ...array_filter($known, fn($site) => $site !== null),
        ])));

        if ($ledger === [] && $known === []) {
            return 0;
        }

        $changed = 0;
        $stock = Plugin::getInstance()->domain->stock();

        foreach ($sites as $site) {
            $found = [];

            foreach ($relations as $row) {
                $assetId = (int) $row['targetId'];

                if (!isset($ledger[$assetId]) || ($row['sourceSiteId'] !== null && (string) $row['sourceSiteId'] !== $site)) {
                    continue;
                }

                $place = $this->placeOf((int) $row['sourceId'], (int) $row['fieldId'], (int) $site);

                if ($place !== null) {
                    $found[] = ['asset' => AssetRef::craft($assetId), 'field' => $place['path'], 'label' => $place['label']];
                }
            }

            $entry = Entry::find()->id($entryId)->siteId((int) $site)->status(null)->drafts(null)->one();
            $live = $entry instanceof Entry && !$entry->getIsDraft() && $entry->getStatus() === Entry::STATUS_LIVE;

            $changed += count($stock->syncUsages(self::OWNER, $entryId, $site, $found, $live));
        }

        return $changed;
    }

    /**
     * Every entry that holds a ledger image, looked at again: for the
     * ledger screen, and after anything that went round the save hook.
     */
    public function resync(): int
    {
        $assetIds = array_keys($this->ledgerAssets(null));
        $owners = array_map('intval', (new Query())->select('ownerId')->distinct()->from(Store::STOCK_USAGES)->where(['ownerType' => self::OWNER])->column());

        if ($assetIds !== []) {
            foreach ((new Query())->select('sourceId')->distinct()->from(Table::RELATIONS)->where(['targetId' => $assetIds])->column() as $sourceId) {
                $element = Craft::$app->getElements()->getElementById((int) $sourceId, null, null, ['status' => null, 'drafts' => null, 'revisions' => false]);

                if ($element instanceof ElementInterface && ($root = self::root($element)) instanceof Entry && !$root->getIsRevision()) {
                    $owners[] = (int) $root->getCanonicalId();
                }
            }
        }

        $changed = 0;

        foreach (array_unique(array_filter($owners)) as $owner) {
            $changed += $this->sync($owner);
        }

        return $changed;
    }

    /**
     * Where a field is on an element, as the ledger names it: its path
     * (`heroImage`, or `pageBuilder.hero.image` inside a block) and a label
     * as the image button gives it ("Hero image", "Hero: Image").
     *
     * @return array{path: string, label: string}
     */
    public static function place(ElementInterface $element, FieldInterface $field): array
    {
        $path = [(string) $field->handle];
        $type = null;
        $current = $element;

        for ($depth = 0; $depth < self::DEPTH; $depth++) {
            $owner = method_exists($current, 'getOwner') ? $current->getOwner() : null;

            if (!$owner) {
                break;
            }

            $kind = method_exists($current, 'getType') ? $current->getType() : null;
            $container = method_exists($current, 'getField') ? $current->getField() : null;
            $type ??= $kind;
            array_unshift($path, (string) ($container?->handle ?? 'field'), (string) ($kind?->handle ?? 'block'));
            $current = $owner;
        }

        return [
            'path' => implode('.', $path),
            'label' => ($type ? Craft::t('site', $type->name) . ': ' : '') . Craft::t('site', (string) $field->name),
        ];
    }

    /**
     * The entry at the top of a nested element (itself for a top-level one).
     */
    public static function root(ElementInterface $element): ?ElementInterface
    {
        $current = $element;

        for ($depth = 0; $depth < self::DEPTH; $depth++) {
            $owner = method_exists($current, 'getOwner') ? $current->getOwner() : null;

            if (!$owner) {
                return $current;
            }

            $current = $owner;
        }

        return null;
    }

    /**
     * A usage for the ledger at the moment a stock image goes in: the
     * canonical entry the field is on, and where on it.
     */
    public static function usageFor(ElementInterface $element, FieldInterface $field): ?Usage
    {
        $root = self::root($element);

        if (!$root instanceof Entry || !$root->getCanonicalId()) {
            return null;
        }

        $place = self::place($element, $field);

        return new Usage(self::OWNER, (int) $root->getCanonicalId(), $place['path'], (string) $root->siteId, $place['label'], live: false);
    }

    /**
     * Whether the ledger has nothing in it, asked once per request.
     */
    public function ledgerIsEmpty(): bool
    {
        return $this->empty ??= Plugin::getInstance()->stockImages->isEmpty();
    }

    /**
     * Forget what was asked this request: after a record is added.
     */
    public function forget(): void
    {
        $this->empty = null;
    }

    /**
     * The canonical entry, its drafts and every element nested in them, by
     * ID.
     *
     * @return array<int, true>
     */
    private function tree(int $entryId): array
    {
        $ids = [$entryId => true];

        foreach ((new Query())->select('id')->from(Table::ELEMENTS)->where(['canonicalId' => $entryId])->andWhere(['not', ['draftId' => null]])->column() as $draft) {
            $ids[(int) $draft] = true;
        }

        $owners = array_keys($ids);

        for ($depth = 0; $depth < self::DEPTH && $owners !== []; $depth++) {
            $nested = array_map('intval', (new Query())->select('elementId')->from(Table::ELEMENTS_OWNERS)->where(['ownerId' => $owners])->column());
            $owners = array_values(array_filter($nested, fn(int $id) => !isset($ids[$id])));

            foreach ($owners as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Of these asset IDs (every ledger asset when null), those with a
     * ledger record that isn't removed.
     *
     * @param array<int, int>|null $assetIds
     * @return array<int, true>
     */
    private function ledgerAssets(?array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $query = (new Query())->select('assetId')->distinct()->from(Store::STOCK_IMAGES)->where(['not', ['state' => StockImage::REMOVED]])->andWhere(['not', ['assetId' => null]]);

        if ($assetIds !== null) {
            $query->andWhere(['assetId' => array_values(array_unique($assetIds))]);
        }

        return array_fill_keys(array_map('intval', $query->column()), true);
    }

    /**
     * Where the relation's source element holds the field, as place() names
     * it.
     *
     * @return array{path: string, label: string}|null
     */
    private function placeOf(int $sourceId, int $fieldId, int $siteId): ?array
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        $element = Craft::$app->getElements()->getElementById($sourceId, null, $siteId, ['status' => null, 'drafts' => null, 'revisions' => false])
            ?? Craft::$app->getElements()->getElementById($sourceId, null, null, ['status' => null, 'drafts' => null, 'revisions' => false]);

        if (!$field || !$element) {
            return null;
        }

        // A field in a block is the block's layout's copy of it: its own
        // handle there, if it was renamed in the layout.
        $layoutField = $element->getFieldLayout()?->getFieldById($fieldId) ?? $field;

        return self::place($element, $layoutField);
    }
}
