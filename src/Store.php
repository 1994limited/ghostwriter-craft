<?php

namespace nineteenninetyfour\ghostwriter;

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use yii\base\Component;

/**
 * Where Ghostwriter keeps everything it writes: in the database, so it
 * survives deploys, works on read-only and load-balanced hosts, and is the
 * same on every server.
 *
 *   documents  the voice and image style guides, kinds of content and
 *              ideas on the content plan: content, by kind and handle
 *   state      working state: suggestions, jobs in hand, image requests
 *   files      pictures made or uploaded, while they wait to be used
 *
 * Sessions have a table of their own, and so does the stock image ledger
 * (`stock_images`, with `stock_usages` beside it), which is never cleared.
 * Suggest edits' reviews and Content to revisit have theirs (`edit_reviews`,
 * `revisit` with `revisit_links`, and `entry_index`). Core's stores (see domain/) read and
 * write their records here; its Lock is Craft's mutex (domain/CraftLock).
 */
class Store extends Component
{
    public const DOCUMENTS = '{{%ghostwriter_documents}}';

    public const STATE = '{{%ghostwriter_state}}';

    public const FILES = '{{%ghostwriter_files}}';

    public const SESSIONS = '{{%ghostwriter_sessions}}';

    /** The stock image ledger: one row per stock image put into the site. Never deleted. */
    public const STOCK_IMAGES = '{{%ghostwriter_stock_images}}';

    /** Where each ledger image is used, from Craft's relations. */
    public const STOCK_USAGES = '{{%ghostwriter_stock_usages}}';

    /** Suggest edits' reviews, one row each: the page's history. Never deleted but with the entry. */
    public const EDIT_REVIEWS = '{{%ghostwriter_edit_reviews}}';

    /** Content to revisit: one row per entry and site, found without a model. */
    public const REVISIT = '{{%ghostwriter_revisit}}';

    /** What each revisit row's links point at, indexed on the target, for a deleted entry's linkers. */
    public const REVISIT_LINKS = '{{%ghostwriter_revisit_links}}';

    /** Each entry's title, address, summary and paragraph shingles, for Suggest edits' duplicate check and digest. */
    public const ENTRY_INDEX = '{{%ghostwriter_entry_index}}';

    /** Each index row's stems (SEO layer §7.1), so a big site's link candidates are narrowed before they're scored. */
    public const INDEX_STEMS = '{{%ghostwriter_index_stems}}';

    public function document(string $kind, string $handle): ?string
    {
        $body = (new Query())->select('body')->from(self::DOCUMENTS)->where(['kind' => $kind, 'handle' => $handle])->scalar();

        return $body === false ? null : (string) $body;
    }

    /**
     * @return array<string, string> Bodies by handle, oldest first.
     */
    public function documents(string $kind): array
    {
        return (new Query())->select('body')->from(self::DOCUMENTS)->where(['kind' => $kind])->indexBy('handle')->orderBy(['id' => SORT_ASC])->column() ?: [];
    }

    public function documentUpdatedAt(string $kind, string $handle): ?DateTime
    {
        $date = (new Query())->select('dateUpdated')->from(self::DOCUMENTS)->where(['kind' => $kind, 'handle' => $handle])->scalar();

        return $date ? DateTimeHelper::toDateTime($date) ?: null : null;
    }

    public function putDocument(string $kind, string $handle, string $body): void
    {
        Db::upsert(self::DOCUMENTS, ['kind' => $kind, 'handle' => $handle, 'body' => $body]);
    }

    public function deleteDocument(string $kind, string $handle): void
    {
        Db::delete(self::DOCUMENTS, ['kind' => $kind, 'handle' => $handle]);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $key): array
    {
        $value = (new Query())->select('value')->from(self::STATE)->where(['name' => $key])->scalar();

        return $value === false || $value === null ? [] : (array) Json::decodeIfJson((string) $value);
    }

    public function stateUpdatedAt(string $key): ?DateTime
    {
        $date = (new Query())->select('dateUpdated')->from(self::STATE)->where(['name' => $key])->scalar();

        return $date ? DateTimeHelper::toDateTime($date) ?: null : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function putState(string $key, array $value): void
    {
        Db::upsert(self::STATE, ['name' => $key, 'value' => Json::encode($value)]);
    }

    public function deleteState(string $key): void
    {
        Db::delete(self::STATE, ['name' => $key]);
    }

    public function putFile(string $id, string $content, string $mime, string $extension): void
    {
        Db::upsert(self::FILES, ['id' => $id, 'mime' => $mime, 'extension' => $extension, 'data' => base64_encode($content)]);
    }

    /**
     * @return array{content: string, mime: string, extension: string}|null
     */
    public function file(string $id): ?array
    {
        $row = (new Query())->select(['mime', 'extension', 'data'])->from(self::FILES)->where(['id' => $id])->one();

        return $row ? ['content' => (string) base64_decode((string) $row['data']), 'mime' => (string) $row['mime'], 'extension' => (string) $row['extension']] : null;
    }

    public function deleteFile(string $id): void
    {
        Db::delete(self::FILES, ['id' => $id]);
    }
}
