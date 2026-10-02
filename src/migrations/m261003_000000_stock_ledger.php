<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use craft\db\Migration;

/**
 * The stock image ledger: one row per stock image Ghostwriter puts into
 * the site, free or paid, and where each is used.
 */
class m261003_000000_stock_ledger extends Migration
{
    public function safeUp(): bool
    {
        Install::createStockTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261003_000000_stock_ledger cannot be reverted: licence records are permanent.\n";

        return false;
    }
}
