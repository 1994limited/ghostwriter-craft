<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use craft\db\Migration;
use nineteenninetyfour\ghostwriter\Store;

/**
 * Ghostwriter's tables. Everything it writes lives here, so it works the
 * same on every server and survives deploys.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        self::createTables($this);
        (new FileImport($this))->run();

        return true;
    }

    public function safeDown(): bool
    {
        foreach ([Store::SESSIONS, Store::FILES, Store::STATE, Store::DOCUMENTS] as $table) {
            $this->dropTableIfExists($table);
        }

        return true;
    }

    public static function createTables(Migration $migration): void
    {
        if (!$migration->db->tableExists(Store::DOCUMENTS)) {
            $migration->createTable(Store::DOCUMENTS, [
                'id' => $migration->primaryKey(),
                'kind' => $migration->string(32)->notNull(),
                'handle' => $migration->string(191)->notNull(),
                'body' => $migration->mediumText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::DOCUMENTS, ['kind', 'handle'], true);
        }

        if (!$migration->db->tableExists(Store::STATE)) {
            $migration->createTable(Store::STATE, [
                'id' => $migration->primaryKey(),
                'name' => $migration->string(191)->notNull(),
                'value' => $migration->mediumText(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::STATE, ['name'], true);
        }

        if (!$migration->db->tableExists(Store::FILES)) {
            $migration->createTable(Store::FILES, [
                'id' => $migration->string(64)->notNull(),
                'mime' => $migration->string(64)->notNull(),
                'extension' => $migration->string(8)->notNull(),
                'data' => $migration->longText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
                'PRIMARY KEY([[id]])',
            ]);
        }

        if (!$migration->db->tableExists(Store::SESSIONS)) {
            $migration->createTable(Store::SESSIONS, [
                'id' => $migration->string(26)->notNull(),
                'userId' => $migration->integer(),
                'elementId' => $migration->integer(),
                'data' => $migration->mediumText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
                'PRIMARY KEY([[id]])',
            ]);
            $migration->createIndex(null, Store::SESSIONS, ['userId']);
            $migration->createIndex(null, Store::SESSIONS, ['elementId']);
            $migration->createIndex(null, Store::SESSIONS, ['dateUpdated']);
            $migration->addForeignKey(null, Store::SESSIONS, ['userId'], '{{%users}}', ['id'], 'CASCADE');
        }
    }
}
