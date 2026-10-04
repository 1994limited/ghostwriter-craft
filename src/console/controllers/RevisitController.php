<?php

namespace nineteenninetyfour\ghostwriter\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\console\ExitCode;

/**
 * Content to revisit and the link index from the command line. No model,
 * ever.
 *
 *     php craft ghostwriter/revisit/refresh          daily, from cron
 *     php craft ghostwriter/revisit/refresh --full   every page read again
 *     php craft ghostwriter/revisit/check-links      weekly, from cron
 *
 * A daily cron line:  0 3 * * * php /path/to/craft ghostwriter/revisit/refresh
 */
class RevisitController extends Controller
{
    /** Read every live entry again, not only those saved since the last pass. */
    public bool $full = false;

    /** One site's ID; every site when not given. */
    public ?int $site = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), ...($actionID === 'refresh' ? ['full', 'site'] : [])];
    }

    /**
     * Brings the list up to date: entries saved since the last pass, rows
     * whose day has come, a full pass once a week; and expires Suggest
     * edits' suggestions nobody acted on for 14 days.
     */
    public function actionRefresh(): int
    {
        $read = Plugin::getInstance()->revisit->daily(full: $this->full, site: $this->site);

        $this->stdout($read === 1 ? "Content to revisit: 1 entry read.\n" : "Content to revisit: {$read} entries read.\n", Console::FG_GREEN);

        foreach (Plugin::getInstance()->revisit->linkResults as $site => $result) {
            $this->stdout("Link index, site {$site}: {$result['written']} written, {$result['forgotten']} forgotten, {$result['promoted']} made full, {$result['pending']} left for the next run.\n");
        }

        return ExitCode::OK;
    }

    /**
     * Checks links to other sites, at most once a week each. Does nothing
     * unless "Check links to other sites once a week" is on in the
     * settings.
     */
    public function actionCheckLinks(): int
    {
        if (!Plugin::getInstance()->getSettings()->checksExternalLinks()) {
            $this->stdout("Checking links to other sites is off in Ghostwriter's settings.\n");

            return ExitCode::OK;
        }

        $checked = Plugin::getInstance()->revisit->links();
        $this->stdout($checked === 1 ? "1 link checked.\n" : "{$checked} links checked.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
