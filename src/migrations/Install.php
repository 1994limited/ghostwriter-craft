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
        self::createStockTables($this);
        (new FileImport($this))->run();

        return true;
    }

    /**
     * Licence records are permanent, so the stock image ledger is written
     * to a JSON file in storage before its tables go, and the person
     * uninstalling is told where.
     */
    public function safeDown(): bool
    {
        if ($this->db->tableExists(Store::STOCK_IMAGES)) {
            LedgerExport::beforeUninstall($this->db);
        }

        foreach ([Store::STOCK_USAGES, Store::STOCK_IMAGES, Store::SESSIONS, Store::FILES, Store::STATE, Store::DOCUMENTS] as $table) {
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

    /**
     * The stock image ledger. `assetId` has no foreign key on purpose:
     * deleting an asset must not delete its licence. The record itself is
     * the JSON in `data`; the other columns are what it is looked up by.
     */
    public static function createStockTables(Migration $migration): void
    {
        if (!$migration->db->tableExists(Store::STOCK_IMAGES)) {
            $migration->createTable(Store::STOCK_IMAGES, [
                'id' => $migration->string(26)->notNull(),
                'state' => $migration->string(16)->notNull(),
                'library' => $migration->string(64)->notNull(),
                'externalId' => $migration->string(191)->notNull(),
                'assetId' => $migration->integer(),
                'assetKey' => $migration->string(255)->notNull(),
                'insertedAt' => $migration->dateTime()->notNull(),
                'stateChangedAt' => $migration->dateTime()->notNull(),
                // "Request licence": asked for by someone who may not license.
                'requestedAt' => $migration->dateTime(),
                'requestedById' => $migration->integer(),
                'requestedByName' => $migration->string(255),
                'data' => $migration->mediumText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
                'PRIMARY KEY([[id]])',
            ]);
            $migration->createIndex(null, Store::STOCK_IMAGES, ['state']);
            $migration->createIndex(null, Store::STOCK_IMAGES, ['library', 'externalId']);
            $migration->createIndex(null, Store::STOCK_IMAGES, ['assetId']);
            $migration->createIndex(null, Store::STOCK_IMAGES, ['assetKey']);
            $migration->createIndex(null, Store::STOCK_IMAGES, ['insertedAt']);
            $migration->createIndex(null, Store::STOCK_IMAGES, ['stateChangedAt']);
        }

        if (!$migration->db->tableExists(Store::STOCK_USAGES)) {
            $migration->createTable(Store::STOCK_USAGES, [
                'id' => $migration->primaryKey(),
                'stockImageId' => $migration->string(26)->notNull(),
                'ownerType' => $migration->string(32)->notNull(),
                'ownerId' => $migration->string(64)->notNull(),
                'elementId' => $migration->integer(),
                'site' => $migration->string(64),
                'siteId' => $migration->integer(),
                'fieldId' => $migration->integer(),
                'fieldPath' => $migration->string(255)->notNull(),
                'live' => $migration->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::STOCK_USAGES, ['stockImageId']);
            $migration->createIndex(null, Store::STOCK_USAGES, ['ownerType', 'ownerId']);
            $migration->createIndex(null, Store::STOCK_USAGES, ['elementId']);
            $migration->addForeignKey(null, Store::STOCK_USAGES, ['stockImageId'], Store::STOCK_IMAGES, ['id'], 'CASCADE');
        }
    }
}
