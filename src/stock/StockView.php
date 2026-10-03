<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\elements\User;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * A ledger record as the control panel shows it: the badge on an image
 * field, the asset sidebar panel, the ledger screen's rows. One shape for
 * all three, so they say the same thing.
 */
class StockView
{
    /**
     * Whether someone may license stock images (it spends money): the
     * `ghostwriter:license` permission, which admins have.
     */
    public static function mayLicense(?User $user): bool
    {
        return $user !== null && $user->can(Plugin::PERMISSION) && $user->can(Plugin::LICENSE_PERMISSION);
    }

    /**
     * The records needing someone's attention among these assets: a
     * preview, a licence in flight or failed, or a licence whose file isn't
     * in place yet.
     *
     * @param array<int, int> $assetIds
     * @return array<int, array<string, mixed>>
     */
    public static function badges(array $assetIds, ?User $user = null): array
    {
        $plugin = Plugin::getInstance();

        if ($assetIds === [] || $plugin->stockUsages->ledgerIsEmpty()) {
            return [];
        }

        $images = [];

        foreach (array_unique(array_map('intval', $assetIds)) as $assetId) {
            $image = $plugin->stockImages->forAsset(AssetRef::craft($assetId));

            if ($image !== null && ($image->isUnlicensed() || ($image->is(StockImage::LICENSED) && !$image->isReplaced()))) {
                $images[] = $image;
            }
        }

        return self::many($images, $user);
    }

    /**
     * @param array<int, StockImage> $images
     * @return array<int, array<string, mixed>>
     */
    public static function many(array $images, ?User $user = null): array
    {
        $requested = Plugin::getInstance()->stockImages->requested(array_map(fn(StockImage $image) => $image->id, $images));

        return array_map(fn(StockImage $image) => self::one($image, $user, $requested[$image->id] ?? null), $images);
    }

    /**
     * @param array{at: \DateTimeImmutable, userId: ?int, name: ?string}|null $requested
     * @return array<string, mixed>
     */
    public static function one(StockImage $image, ?User $user = null, ?array $requested = null): array
    {
        $plugin = Plugin::getInstance();
        $user ??= Craft::$app->getUser()->getIdentity();
        $libraries = $plugin->stockLibraries;
        $library = $libraries->get($image->library);
        $licence = $image->licence();
        $formatter = Craft::$app->getFormatter();
        $mayLicense = self::mayLicense($user);
        $licensable = $library instanceof LicensableLibrary && $library->available() && $libraries->enabled($image->library);
        $expired = $image->is(StockImage::PREVIEW) && $image->comp() === null;

        return [
            'id' => $image->id,
            'assetId' => is_numeric($image->asset->id) ? (int) $image->asset->id : null,
            'state' => $image->state(),
            'status' => self::status($image),
            'library' => $libraries->standInName($image->library),
            'short' => $libraries->shortLabel($image->library),
            'externalId' => $image->externalId,
            'title' => $image->title,
            'comp' => $image->comp() !== null ? StockComps::url($image->id) : null,
            'expired' => $expired,
            'mayRefresh' => $expired && $image->mayRefreshComp() && $library instanceof \NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PreviewableLibrary && $library->available() && (bool) $user?->can(Plugin::PERMISSION),
            'mayLicense' => $mayLicense && $licensable && in_array($image->state(), [StockImage::PREVIEW, StockImage::FAILED], true),
            'mayReplace' => $mayLicense && $licensable && $image->is(StockImage::LICENSED) && !$image->isReplaced(),
            'mayReconcile' => $mayLicense && $licensable && $image->is(StockImage::LICENSING),
            'mayRemove' => $mayLicense && in_array($image->state(), [StockImage::PREVIEW, StockImage::FAILED], true),
            'mayRequest' => !$mayLicense && $image->isUnlicensed() && $requested === null,
            'requested' => $requested !== null ? ['name' => $requested['name'], 'at' => $formatter->asDatetime($requested['at'], 'short')] : null,
            'free' => in_array($image->library, \NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch::SOURCES, true),
            'replaced' => $image->isReplaced(),
            'error' => $image->error(),
            'editorial' => $image->editorial,
            'restrictions' => $image->restrictions,
            'credit' => $image->creditLine,
            'licenceType' => $image->licenceType,
            'productType' => $image->productType,
            'termEndsAt' => $image->termEndsAt !== null ? $formatter->asDate($image->termEndsAt, 'medium') : null,
            'insertedBy' => $image->insertedBy?->name,
            'insertedAt' => $formatter->asDatetime($image->insertedAt, 'short'),
            'licence' => $licence !== null ? [
                'orderId' => $licence->orderId,
                'cost' => $licence->cost?->label(),
                'estimated' => $licence->estimated,
                'by' => $licence->licensedBy,
                'at' => $formatter->asDatetime($licence->licensedAt, 'short'),
            ] : null,
            'usages' => array_map(fn($usage) => [
                'label' => $usage->label ?? $usage->field,
                'ownerId' => $usage->ownerId,
                'site' => $usage->site,
                'live' => $usage->live,
                'title' => self::ownerTitle($usage->ownerId, $usage->site),
                'url' => \craft\helpers\UrlHelper::cpUrl('entries/' . $usage->ownerId . ($usage->site ? '?site=' . (Craft::$app->getSites()->getSiteById((int) $usage->site)?->handle ?? '') : '')),
            ], $image->usages()),
        ];
    }

    /**
     * For the Overview tile and the widget (§7.3): how many stock images
     * aren't licensed yet, and how many of those are on live entries.
     *
     * @return array{previews: int, live: int}
     */
    public static function summary(): array
    {
        $plugin = Plugin::getInstance();

        if ($plugin->stockUsages->ledgerIsEmpty()) {
            return ['previews' => 0, 'live' => 0];
        }

        $unlicensed = $plugin->domain->stock()->all(new \NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery([StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED]));
        $live = array_filter($unlicensed, function(StockImage $image): bool {
            foreach ($image->usages() as $usage) {
                if ($usage->live) {
                    return true;
                }
            }

            return false;
        });

        return ['previews' => count($unlicensed), 'live' => count($live)];
    }

    /**
     * The badge's words for a record's state.
     */
    public static function status(StockImage $image): string
    {
        return match (true) {
            $image->is(StockImage::PREVIEW) && $image->comp() === null => Craft::t('ghostwriter', 'Preview expired'),
            $image->is(StockImage::PREVIEW) => Craft::t('ghostwriter', 'Preview · not licensed'),
            $image->is(StockImage::LICENSING) => Craft::t('ghostwriter', 'Licensing…'),
            $image->is(StockImage::FAILED) => Craft::t('ghostwriter', 'Licence failed'),
            $image->is(StockImage::LICENSED) && !$image->isReplaced() => Craft::t('ghostwriter', 'Licensed · file not in place'),
            $image->is(StockImage::LICENSED) => Craft::t('ghostwriter', 'Licensed'),
            default => Craft::t('ghostwriter', 'Removed'),
        };
    }

    /** @var array<string, string|null> */
    private static array $titles = [];

    private static function ownerTitle(int|string $id, ?string $site): ?string
    {
        $key = $id . '|' . $site;

        if (!array_key_exists($key, self::$titles)) {
            $entry = \craft\elements\Entry::find()->id((int) $id)->siteId(is_numeric($site) ? (int) $site : null)->status(null)->drafts(null)->one();
            self::$titles[$key] = $entry?->title;
        }

        return self::$titles[$key];
    }
}
