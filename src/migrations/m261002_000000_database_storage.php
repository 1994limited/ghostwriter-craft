<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use craft\db\Migration;

/**
 * Moves Ghostwriter from files to the database: creates its tables, and
 * imports the guides, kinds, ideas, state and sessions earlier versions
 * kept as files.
 */
class m261002_000000_database_storage extends Migration
{
    public function safeUp(): bool
    {
        Install::createTables($this);
        (new FileImport($this))->run();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261002_000000_database_storage cannot be reverted.\n";

        return false;
    }
}
