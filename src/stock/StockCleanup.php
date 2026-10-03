<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\elements\Asset;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Housekeeping for stock photos (§7.4), run with Craft's garbage
 * collection and by `php craft ghostwriter/stock/cleanup`:
 *
 * - a comp's bytes are deleted when the library's comp period ends
 *   (Getty and iStock 30 days after it was downloaded), whether or not the
 *   preview is still in use; the stand-in stays, and the badge says
 *   "Preview expired";
 * - a preview no entry has used for `stockUnusedDays` (30) is removed, its
 *   stand-in asset deleted; one still in use is never deleted;
 * - a licence whose outcome wasn't known is settled with reconcile() once
 *   it is ten minutes old, from the library's own licences, never by
 *   buying again; one found is put in place.
 */
class StockCleanup extends Component
{
    /**
     * @return array{expired: int, removed: int, reconciled: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $done = ['expired' => 0, 'removed' => 0, 'reconciled' => 0];

        if (Plugin::getInstance()->stockUsages->ledgerIsEmpty()) {
            return $done;
        }

        foreach (['expired' => fn() => $this->expireComps($now), 'removed' => fn() => $this->removeUnused($now), 'reconciled' => fn() => $this->reconcileAll($now)] as $what => $step) {
            try {
                $done[$what] = $step();
            } catch (Throwable $exception) {
                Craft::error("Ghostwriter's stock cleanup ({$what}) failed: {$exception->getMessage()}", 'ghostwriter');
            }
        }

        Plugin::getInstance()->stockComps->reset();

        return $done;
    }

    /**
     * Comps whose period has ended: their bytes go.
     */
    public function expireComps(DateTimeImmutable $now): int
    {
        $plugin = Plugin::getInstance();
        $stock = $plugin->domain->stock();
        $count = 0;

        foreach ($stock->all(new StockImageQuery([StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED])) as $image) {
            $until = $image->compKeepUntil();

            if ($image->comp() !== null && $until !== null && $until <= $now) {
                $comp = $image->comp();
                $stock->compExpired($image->id);
                StockFiles::forget($comp);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Previews unchanged for `stockUnusedDays` that nothing uses: removed,
     * stand-in and all. One still in use is kept.
     */
    public function removeUnused(DateTimeImmutable $now): int
    {
        $plugin = Plugin::getInstance();
        $stock = $plugin->domain->stock();
        $days = max(1, (int) $plugin->getSettings()->stockUnusedDays);
        $count = 0;

        // Usages first, in case an entry changed outside the save hook.
        foreach ($plugin->stockImages->previewsBefore($now->modify("-{$days} days")) as $image) {
            if ($image->usages() !== []) {
                continue;
            }

            $comp = $image->comp();
            $stock->removed($image->id);
            StockFiles::forget($comp);

            $asset = is_numeric($image->asset->id) ? Asset::find()->id((int) $image->asset->id)->status(null)->one() : null;

            if ($asset instanceof Asset) {
                Craft::$app->getElements()->deleteElement($asset);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Licences of unknown outcome, ten minutes on: settled from the
     * library's own licences.
     */
    public function reconcileAll(?DateTimeImmutable $now = null): int
    {
        $count = 0;
        $clock = $now !== null ? fn() => $now : null;

        foreach (Plugin::getInstance()->domain->stock($clock)->dueForReconcile() as $image) {
            if ($this->reconcile($image->id, $now)->state() !== StockImage::LICENSING) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * One licence of unknown outcome, settled if the library can say: a
     * licence found is put in place (never bought again); none, after ten
     * minutes, fails it so it may be tried again.
     */
    public function reconcile(string $id, ?DateTimeImmutable $now = null): StockImage
    {
        $plugin = Plugin::getInstance();
        $stock = $plugin->domain->stock($now !== null ? fn() => $now : null);
        $image = $stock->get($id);
        $library = $plugin->stockLibraries->paid()[$image->library] ?? null;

        if (!$library instanceof LicensableLibrary) {
            return $image;
        }

        $comp = $image->comp();
        $image = $stock->reconcile($id, fn(StockImage $image) => $library->findLicences($image->externalId));

        if ($image->is(StockImage::LICENSED)) {
            StockFiles::forget($comp);
            $image = $stock->replaceAgain($id, $library, new CraftAssetReplacer());
        }

        return $image;
    }
}
