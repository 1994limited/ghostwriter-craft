<?php

namespace nineteenninetyfour\ghostwriter\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\console\ExitCode;

/**
 * Stock photo housekeeping from the command line, as Craft's garbage
 * collection also runs it:
 *
 *     php craft ghostwriter/stock/cleanup
 *     php craft ghostwriter/stock/usages
 */
class StockController extends Controller
{
    /**
     * Deletes comps whose period has ended, removes previews no entry has
     * used for `stockUnusedDays`, and settles licences whose outcome wasn't
     * known.
     */
    public function actionCleanup(): int
    {
        $done = Plugin::getInstance()->stockCleanup->run();

        $this->stdout(sprintf("Comps expired: %d\nUnused previews removed: %d\nLicences settled: %d\n", $done['expired'], $done['removed'], $done['reconciled']), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Looks again at every entry holding a stock image and updates where
     * each is used.
     */
    public function actionUsages(): int
    {
        $changed = Plugin::getInstance()->stockUsages->resync();
        $this->stdout("Ledger records updated: {$changed}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
