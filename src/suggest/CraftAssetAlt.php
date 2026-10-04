<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\elements\Asset;
use craft\fieldlayoutelements\assets\AltField;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;
use Throwable;

/**
 * An asset's alt text, where Craft 5 keeps it: the asset's own `alt`,
 * shown only where the volume's field layout includes the Alternative
 * Text element. A volume without it has nowhere to show alt text, so
 * there's nothing to ask for (null).
 *
 * save() is "Save to the image": the one change Suggest edits saves
 * itself, after a confirm, because alt text belongs to the asset and
 * shows wherever it's used.
 */
class CraftAssetAlt implements AssetAlt
{
    /** @var array<int, bool> Whether each volume keeps alt text, by ID. */
    private array $volumes = [];

    public function altFor(AssetRef $asset): ?string
    {
        $found = $this->asset($asset);

        if ($found === null || !$this->keepsAlt($found)) {
            return null;
        }

        return trim((string) ($found->alt ?? ''));
    }

    public function asset(AssetRef $asset): ?Asset
    {
        $id = $asset->id ?? null;

        if (!is_numeric($id)) {
            return null;
        }

        $found = Asset::find()->id((int) $id)->status(null)->one();

        return $found instanceof Asset ? $found : null;
    }

    /** Whether the asset's volume shows alt text in its field layout. */
    public function keepsAlt(Asset $asset): bool
    {
        $volumeId = (int) $asset->volumeId;

        if (isset($this->volumes[$volumeId])) {
            return $this->volumes[$volumeId];
        }

        try {
            $layout = $asset->getVolume()->getFieldLayout();
            $keeps = false;

            foreach ($layout->getTabs() as $tab) {
                foreach ($tab->getElements() as $element) {
                    if ($element instanceof AltField) {
                        $keeps = true;
                    }
                }
            }
        } catch (Throwable) {
            $keeps = false;
        }

        return $this->volumes[$volumeId] = $keeps;
    }

    /**
     * Writes the alt text on the asset, and gives what it was, for Undo.
     *
     * @throws \RuntimeException when Craft won't save it.
     */
    public function save(Asset $asset, string $alt): string
    {
        $before = (string) ($asset->alt ?? '');
        $asset->alt = trim($alt) === '' ? null : trim($alt);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \RuntimeException(implode(' ', $asset->getFirstErrors()) ?: 'The image couldn’t be saved.');
        }

        return $before;
    }

    /**
     * How many of the site's live entries use an asset, from Craft's
     * relations (fields) and its inline references (CKEditor).
     */
    public function uses(Asset $asset): int
    {
        try {
            $related = array_map(
                fn(\craft\elements\Entry $entry) => (int) ($entry->primaryOwnerId ?? $entry->id),
                \craft\elements\Entry::find()->relatedTo(['targetElement' => $asset])->status(null)->site('*')->unique()->all(),
            );
            // Inline in CKEditor: the reference tag in the saved content of
            // entries themselves, not their drafts or revisions.
            $inline = (new \craft\db\Query())
                // A Matrix entry's page is the entry that owns it.
                ->select(new \yii\db\Expression('COALESCE([[en.primaryOwnerId]], [[es.elementId]])'))
                ->distinct()
                ->from(['es' => '{{%elements_sites}}'])
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[es.elementId]]')
                ->leftJoin(['en' => '{{%entries}}'], '[[en.id]] = [[es.elementId]]')
                ->where(['e.draftId' => null, 'e.revisionId' => null, 'e.dateDeleted' => null])
                ->andWhere(['like', 'es.content', '{asset:' . $asset->id . ':'])
                ->column();

            $pages = array_unique([...array_map('intval', $related), ...array_map('intval', $inline)]);

            // Pages themselves: not drafts, revisions or anything trashed.
            return $pages === [] ? 0 : (int) (new \craft\db\Query())
                ->from('{{%elements}}')
                ->where(['id' => $pages, 'draftId' => null, 'revisionId' => null, 'dateDeleted' => null])
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
