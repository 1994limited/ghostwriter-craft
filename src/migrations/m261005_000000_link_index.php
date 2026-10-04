<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use craft\db\Migration;
use nineteenninetyfour\ghostwriter\Store;

/**
 * The link index (SEO layer §7.1): link rows beside the entry index's full
 * rows, and the stem table. The next daily pass builds the link rows.
 */
class m261005_000000_link_index extends Migration
{
    public function safeUp(): bool
    {
        Install::createLinkIndex($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Store::INDEX_STEMS);

        foreach (['scope', 'kind', 'liveFrom', 'liveUntil', 'noindex', 'keyPage', 'pageUpdated', 'indexed'] as $column) {
            if ($this->db->getTableSchema(Store::ENTRY_INDEX, true)?->getColumn($column) !== null) {
                $this->dropColumn(Store::ENTRY_INDEX, $column);
            }
        }

        return true;
    }
}
