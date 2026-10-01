<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use RuntimeException;
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
 * Sessions have a table of their own (see SessionRepository).
 */
class Store extends Component
{
    public const DOCUMENTS = '{{%ghostwriter_documents}}';

    public const STATE = '{{%ghostwriter_state}}';

    public const FILES = '{{%ghostwriter_files}}';

    public const SESSIONS = '{{%ghostwriter_sessions}}';

    /** Seconds to wait for another request to finish changing the same thing. */
    private const LOCK_WAIT = 15;

    /** What is said of work that stopped without finishing. */
    public const STOPPED = 'This stopped before it finished, probably cut off by a time limit on the server. Try again.';

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
     * Whether work marked as in hand since then has been going on for far
     * longer than a job is allowed: its process was stopped (a time limit,
     * a restart) before it could say so, and nothing else ever will.
     */
    public static function isStale(DateTime|string|null $since): bool
    {
        if ($since === null || $since === '') {
            return false;
        }

        $since = $since instanceof DateTime ? $since : DateTimeHelper::toDateTime($since);
        $allowed = (Plugin::getInstance()->getSettings()->timeout + 120) * 2;

        return $since !== false && $since->getTimestamp() < time() - $allowed;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function putState(string $key, array $value): void
    {
        Db::upsert(self::STATE, ['name' => $key, 'value' => Json::encode($value)]);
    }

    /**
     * Change a piece of state from what it is now, with nobody else
     * changing it in between: a job and a request can both be at it.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed> The state as saved.
     */
    public function changeState(string $key, callable $change): array
    {
        return $this->locked("state:{$key}", function() use ($key, $change) {
            $value = $change($this->state($key));
            $this->putState($key, $value);

            return $value;
        });
    }

    public function deleteState(string $key): void
    {
        Db::delete(self::STATE, ['name' => $key]);
    }

    /**
     * Remove state under a prefix not touched for a while, such as old
     * image requests.
     */
    public function clearStateOlderThan(string $prefix, int $seconds): void
    {
        Db::delete(self::STATE, ['and', ['like', 'name', $prefix . '%', false], ['<', 'dateUpdated', Db::prepareDateForDb(new DateTime("-{$seconds} seconds"))]]);
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

    public function clearFilesOlderThan(int $seconds): void
    {
        Db::delete(self::FILES, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$seconds} seconds"))]);
    }

    /**
     * Run something while holding a named lock, across every server.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function locked(string $name, callable $work): mixed
    {
        $mutex = Craft::$app->getMutex();
        $name = 'ghostwriter:' . $name;

        if (!$mutex->acquire($name, self::LOCK_WAIT)) {
            throw new RuntimeException('Ghostwriter is busy saving that. Try again in a moment.');
        }

        try {
            return $work();
        } finally {
            $mutex->release($name);
        }
    }
}
