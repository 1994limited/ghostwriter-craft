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
 * Sessions have a table of their own. Core's stores (see domain/) read and
 * write their records here; its Lock is Craft's mutex (domain/CraftLock).
 */
class Store extends Component
{
    public const DOCUMENTS = '{{%ghostwriter_documents}}';

    public const STATE = '{{%ghostwriter_state}}';

    public const FILES = '{{%ghostwriter_files}}';

    public const SESSIONS = '{{%ghostwriter_sessions}}';

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
