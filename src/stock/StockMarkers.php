<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * Where a stock image is marked on the asset itself (§7.2): a panel in
 * the asset's sidebar with its state, library, ID and licence, and License
 * or Request licence; and a "Stock licence" column in the asset index.
 */
class StockMarkers
{
    public const COLUMN = 'ghostwriterStock';

    /** @var array<int, array{state: string, library: string, replaced: bool}>|null By asset ID, this request. */
    private static ?array $states = null;

    public static function sidebarHtml(Asset $asset): string
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();

        if (!$asset->id || !$user?->can(Plugin::PERMISSION) || $plugin->stockUsages->ledgerIsEmpty()) {
            return '';
        }

        $image = $plugin->stockImages->forAsset(AssetRef::craft((int) $asset->id));

        if ($image === null) {
            return '';
        }

        Craft::$app->getView()->registerAssetBundle(GhostwriterAsset::class);
        $view = StockView::many([$image], $user)[0];

        return Html::tag('fieldset', Html::tag('legend', Craft::t('ghostwriter', 'Stock licence'), ['class' => 'h6']) . Html::tag('div', '', [
            'class' => 'meta gw-stock-panel',
            'data' => ['ghostwriter-stock' => Json::encode($view)],
        ]), ['class' => 'gw-stock-sidebar']);
    }

    /**
     * The index column: the state, and the library.
     */
    public static function columnHtml(Asset $asset): string
    {
        $state = self::states()[(int) $asset->id] ?? null;

        if ($state === null) {
            return '';
        }

        $libraries = Plugin::getInstance()->stockLibraries;
        [$label, $colour] = match (true) {
            $state['state'] === StockImage::PREVIEW => [Craft::t('ghostwriter', 'Preview · not licensed'), 'orange'],
            $state['state'] === StockImage::LICENSING => [Craft::t('ghostwriter', 'Licensing…'), 'orange'],
            $state['state'] === StockImage::FAILED => [Craft::t('ghostwriter', 'Licence failed'), 'red'],
            $state['state'] === StockImage::LICENSED && !$state['replaced'] => [Craft::t('ghostwriter', 'Licensed · file not in place'), 'red'],
            $state['state'] === StockImage::LICENSED => [Craft::t('ghostwriter', 'Licensed'), 'green'],
            default => [Craft::t('ghostwriter', 'Removed'), ''],
        };

        return Html::tag('span', '', ['class' => ['status', $colour], 'aria-hidden' => 'true'])
            . Html::encode($label) . Html::tag('span', ' · ' . Html::encode($libraries->shortLabel($state['library'])), ['class' => 'light']);
    }

    /**
     * Each asset's newest record, read once per request.
     *
     * @return array<int, array{state: string, library: string, replaced: bool}>
     */
    private static function states(): array
    {
        if (self::$states !== null) {
            return self::$states;
        }

        self::$states = [];

        if (Plugin::getInstance()->stockUsages->ledgerIsEmpty()) {
            return self::$states;
        }

        $rows = (new Query())
            ->select(['assetId', 'state', 'library', 'data'])
            ->from(Store::STOCK_IMAGES)
            ->where(['not', ['assetId' => null]])
            ->orderBy(['insertedAt' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            $current = self::$states[(int) $row['assetId']] ?? null;

            // The newest that isn't removed wins; a removed one only if there is nothing else.
            if ($current === null || $row['state'] !== StockImage::REMOVED || $current['state'] === StockImage::REMOVED) {
                $data = Json::decodeIfJson((string) $row['data']);
                self::$states[(int) $row['assetId']] = ['state' => (string) $row['state'], 'library' => (string) $row['library'], 'replaced' => (bool) (is_array($data) ? ($data['replaced'] ?? false) : false)];
            }
        }

        return self::$states;
    }

    public static function reset(): void
    {
        self::$states = null;
    }
}
