<?php

namespace nineteenninetyfour\ghostwriter\domain;

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use nineteenninetyfour\ghostwriter\Store;

/**
 * Sessions, one row each in `ghostwriter_sessions`: the whole session as
 * JSON in `data`, beside the columns it is looked up by (who started it,
 * the entry it is for).
 */
class DbSessionStore implements SessionStore
{
    public function all(): array
    {
        return $this->rows((new Query())->from(Store::SESSIONS));
    }

    public function startedBy(int|string $userId): array
    {
        return is_numeric($userId) ? $this->rows((new Query())->from(Store::SESSIONS)->where(['userId' => (int) $userId])) : [];
    }

    /**
     * The sessions for an entry (by its canonical ID), the most recently
     * changed first.
     *
     * @return array<int, Session>
     */
    public function forElement(int $elementId): array
    {
        return $this->rows((new Query())->from(Store::SESSIONS)->where(['elementId' => $elementId]));
    }

    public function find(string $id): ?Session
    {
        // IDs are ours; anything else is not ours to look up.
        if (!Format::Craft->isSessionId($id)) {
            return null;
        }

        $data = (new Query())->select('data')->from(Store::SESSIONS)->where(['id' => $id])->scalar();

        return $data === false ? null : $this->session((string) $data);
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = Format::Craft->stamp(new DateTimeImmutable());

        Db::upsert(Store::SESSIONS, [
            'id' => $session->id,
            'userId' => is_numeric($session->startedBy) ? (int) $session->startedBy : null,
            'elementId' => is_numeric($session->recordId) ? (int) $session->recordId : null,
            'data' => Json::encode($session->toArray()),
        ]);

        return $session;
    }

    public function delete(string $id): void
    {
        if (Format::Craft->isSessionId($id)) {
            Db::delete(Store::SESSIONS, ['id' => $id]);
        }
    }

    /**
     * @return array<int, Session> The most recently changed first.
     */
    private function rows(Query $query): array
    {
        return array_values(array_filter(array_map(
            fn($data) => $this->session((string) $data),
            $query->select('data')->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])->column(),
        )));
    }

    private function session(string $json): ?Session
    {
        $data = Json::decodeIfJson($json);

        if (!is_array($data) || !isset($data['id'], $data['type'])) {
            return null;
        }

        return Session::fromArray($data, Format::Craft);
    }
}
