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
        self::createSuggestTables($this);
        self::createLinkIndex($this);
        self::createCredentials($this);
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

        foreach ([Store::CREDENTIALS, Store::INDEX_STEMS, Store::REVISIT_LINKS, Store::REVISIT, Store::ENTRY_INDEX, Store::EDIT_REVIEWS, Store::STOCK_USAGES, Store::STOCK_IMAGES, Store::SESSIONS, Store::FILES, Store::STATE, Store::DOCUMENTS] as $table) {
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

    /**
     * Suggest edits and Content to revisit. A review's record is the JSON
     * in `data`; a revisit row's too, beside the columns the list is
     * sorted and filtered by. `seq` keeps the order reviews were made in.
     */
    public static function createSuggestTables(Migration $migration): void
    {
        if (!$migration->db->tableExists(Store::EDIT_REVIEWS)) {
            $migration->createTable(Store::EDIT_REVIEWS, [
                'seq' => $migration->primaryKey(),
                'id' => $migration->string(26)->notNull(),
                'entryKey' => $migration->string(255)->notNull(),
                'status' => $migration->string(16)->notNull(),
                'version' => $migration->integer()->notNull()->defaultValue(0),
                'expiresAt' => $migration->dateTime(),
                'data' => $migration->mediumText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::EDIT_REVIEWS, ['id'], true);
            $migration->createIndex(null, Store::EDIT_REVIEWS, ['entryKey']);
            $migration->createIndex(null, Store::EDIT_REVIEWS, ['status', 'expiresAt']);
        }

        if (!$migration->db->tableExists(Store::REVISIT)) {
            $migration->createTable(Store::REVISIT, [
                'id' => $migration->primaryKey(),
                'entryKey' => $migration->string(255)->notNull(),
                'site' => $migration->string(64)->notNull()->defaultValue(''),
                'groupHandle' => $migration->string(191)->notNull(),
                'title' => $migration->string(255)->notNull()->defaultValue(''),
                'score' => $migration->integer()->notNull()->defaultValue(0),
                // ",past-year,broken-link,": the reason kinds, for the tiles' filters.
                'kinds' => $migration->string(500)->notNull()->defaultValue(''),
                'snoozedUntil' => $migration->dateTime(),
                'data' => $migration->mediumText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::REVISIT, ['entryKey'], true);
            $migration->createIndex(null, Store::REVISIT, ['site', 'score']);
            $migration->createIndex(null, Store::REVISIT, ['site', 'groupHandle']);
        }

        if (!$migration->db->tableExists(Store::REVISIT_LINKS)) {
            $migration->createTable(Store::REVISIT_LINKS, [
                'id' => $migration->primaryKey(),
                'entryKey' => $migration->string(255)->notNull(),
                'site' => $migration->string(64)->notNull()->defaultValue(''),
                // The link as stored, hashed; and the element it holds ("entry:41"), when it holds one.
                'target' => $migration->char(40)->notNull(),
                'element' => $migration->string(64),
            ]);
            $migration->createIndex(null, Store::REVISIT_LINKS, ['target']);
            $migration->createIndex(null, Store::REVISIT_LINKS, ['element']);
            $migration->createIndex(null, Store::REVISIT_LINKS, ['entryKey']);
        }

        if (!$migration->db->tableExists(Store::ENTRY_INDEX)) {
            $migration->createTable(Store::ENTRY_INDEX, [
                'id' => $migration->primaryKey(),
                'entryKey' => $migration->string(255)->notNull(),
                'site' => $migration->string(64)->notNull()->defaultValue(''),
                'groupHandle' => $migration->string(191)->notNull(),
                'data' => $migration->mediumText()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::ENTRY_INDEX, ['entryKey'], true);
            $migration->createIndex(null, Store::ENTRY_INDEX, ['site']);
        }
    }

    /**
     * The link index (SEO layer §7.1): the entry index keeps link rows
     * (pages of every routable section and category group, as link targets
     * only) beside its full rows, saying which each is, with the columns
     * the daily pass and related() query by; the rest of the row is in
     * `data`. And each row's stems, for narrowing a big site's candidates.
     */
    public static function createLinkIndex(Migration $migration): void
    {
        $columns = [
            // full: one of Ghostwriter's sections (shingles, a revisit row); link: a link target only.
            'scope' => $migration->string(8)->notNull()->defaultValue('full'),
            // entry or category.
            'kind' => $migration->string(16)->notNull()->defaultValue('entry'),
            'liveFrom' => $migration->dateTime(),
            'liveUntil' => $migration->dateTime(),
            'noindex' => $migration->boolean()->notNull()->defaultValue(false),
            'keyPage' => $migration->boolean()->notNull()->defaultValue(false),
            // When the page was last changed, and when its row was written.
            'pageUpdated' => $migration->dateTime(),
            'indexed' => $migration->dateTime(),
        ];

        $table = $migration->db->getTableSchema(Store::ENTRY_INDEX, true);

        foreach ($columns as $name => $type) {
            if ($table !== null && $table->getColumn($name) === null) {
                $migration->addColumn(Store::ENTRY_INDEX, $name, $type);
            }
        }

        if ($table !== null && $table->getColumn('scope') === null) {
            $migration->createIndex(null, Store::ENTRY_INDEX, ['site', 'scope']);
            $migration->createIndex(null, Store::ENTRY_INDEX, ['site', 'groupHandle']);
        }

        if (!$migration->db->tableExists(Store::INDEX_STEMS)) {
            $migration->createTable(Store::INDEX_STEMS, [
                'id' => $migration->bigPrimaryKey(),
                'stem' => $migration->string(64)->notNull(),
                'entryKey' => $migration->string(255)->notNull(),
                'site' => $migration->string(64)->notNull()->defaultValue(''),
            ]);
            $migration->createIndex(null, Store::INDEX_STEMS, ['site', 'stem']);
            $migration->createIndex(null, Store::INDEX_STEMS, ['entryKey']);
        }
    }

    /**
     * Settings → Connections: each service's key set up there, a note
     * when one stopped working, and paid libraries' account tokens, by
     * name, each value encrypted with the security key. Never project
     * config.
     */
    public static function createCredentials(Migration $migration): void
    {
        if (!$migration->db->tableExists(Store::CREDENTIALS)) {
            $migration->createTable(Store::CREDENTIALS, [
                'id' => $migration->primaryKey(),
                'name' => $migration->string(191)->notNull(),
                'value' => $migration->text()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Store::CREDENTIALS, ['name'], true);
        }
    }
}
