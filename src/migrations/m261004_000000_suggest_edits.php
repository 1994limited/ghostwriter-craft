<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use craft\db\Migration;
use nineteenninetyfour\ghostwriter\Store;

/**
 * Suggest edits' reviews, Content to revisit's rows and the links they
 * hold, and the entry index the duplicate check reads.
 */
class m261004_000000_suggest_edits extends Migration
{
    public function safeUp(): bool
    {
        Install::createSuggestTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        foreach ([Store::REVISIT_LINKS, Store::REVISIT, Store::ENTRY_INDEX, Store::EDIT_REVIEWS] as $table) {
            $this->dropTableIfExists($table);
        }

        return true;
    }
}
