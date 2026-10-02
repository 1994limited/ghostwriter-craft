<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\Store;

/**
 * Licence records are permanent: before uninstalling drops the stock image
 * ledger, every record is written to
 * `storage/ghostwriter-stock-ledger-<date>.json`, and the person
 * uninstalling is told where it went.
 */
class LedgerExport
{
    /**
     * @return string|null Where the ledger was written; null when there was nothing to keep.
     */
    public static function beforeUninstall(Connection $db): ?string
    {
        $records = array_values(array_filter(array_map(
            fn($json) => Json::decodeIfJson((string) $json),
            (new Query())->select('data')->from(Store::STOCK_IMAGES)->orderBy(['insertedAt' => SORT_ASC])->column($db),
        ), 'is_array'));

        if ($records === []) {
            return null;
        }

        $path = Craft::getAlias('@storage') . '/ghostwriter-stock-ledger-' . date('Y-m-d-His') . '.json';
        FileHelper::writeToFile($path, Json::encode(['exported' => date(DATE_ATOM), 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $message = Craft::t('ghostwriter', 'Ghostwriter’s stock image ledger ({count} records) was saved to {path} before uninstalling.', ['count' => count($records), 'path' => $path]);
        Craft::warning($message, 'ghostwriter');

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            echo $message . "\n";
        } elseif (Craft::$app->has('session', true)) {
            Craft::$app->getSession()->setNotice($message);
        }

        return $path;
    }
}
