<?php

namespace nineteenninetyfour\ghostwriter\sessions;

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use yii\base\Component;

/**
 * Sessions, one row each, with the whole session as JSON beside the columns
 * it is looked up by.
 */
class SessionRepository extends Component
{
    /**
     * @return Session[] Newest first.
     */
    public function all(): array
    {
        return $this->rows((new Query())->from(Store::SESSIONS));
    }

    /**
     * Someone's own sessions, newest first.
     *
     * @return Session[]
     */
    public function forUser(?int $userId): array
    {
        return $userId === null ? [] : $this->rows((new Query())->from(Store::SESSIONS)->where(['userId' => $userId]));
    }

    /**
     * Whether conversations are shared with everyone who may use
     * Ghostwriter (the sharedConversations setting), rather than kept to
     * the person who started each.
     */
    public function shared(): bool
    {
        return (bool) Plugin::getInstance()->getSettings()->sharedConversations;
    }

    /**
     * The sessions someone may see, newest first: everyone's when
     * conversations are shared, otherwise their own.
     *
     * @return Session[]
     */
    public function visibleTo(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return $this->shared() ? $this->all() : $this->forUser($userId);
    }

    /**
     * Whether someone may open, carry on or remove a session. Anyone who may
     * use Ghostwriter when conversations are shared; otherwise only the
     * person who started it.
     */
    public function canSee(Session $session, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->shared() || ($session->userId !== null && $session->userId === $userId);
    }

    public function find(string $id): ?Session
    {
        // IDs are ours; anything else is not ours to look up.
        if (!preg_match('/^[0-9a-f]{26}$/', $id)) {
            return null;
        }

        $data = (new Query())->select('data')->from(Store::SESSIONS)->where(['id' => $id])->scalar();

        return $data === false ? null : $this->session((string) $data);
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = Session::now();

        Db::upsert(Store::SESSIONS, [
            'id' => $session->id,
            'userId' => $session->userId,
            'elementId' => $session->elementId,
            'data' => Json::encode($session->toArray()),
        ]);

        return $session;
    }

    public function delete(Session $session): void
    {
        Db::delete(Store::SESSIONS, ['id' => $session->id]);
    }

    /**
     * Change a session with nobody else changing it in between: the job
     * writing a reply and a request from the panel can overlap.
     *
     * @param callable(Session): void $change
     */
    public function change(string $id, callable $change): ?Session
    {
        return Plugin::getInstance()->store->locked("session:{$id}", function() use ($id, $change) {
            $session = $this->find($id);

            if ($session === null) {
                return null;
            }

            $change($session);

            return $this->save($session);
        });
    }

    /**
     * @return Session[]
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

        $session = Session::fromArray($data);

        // A turn whose job was stopped before it could answer.
        if ($session->status === Session::WORKING && Store::isStale($session->updatedAt)) {
            $session->status = Session::FAILED;
            $session->error = Store::STOPPED;
        }

        return $session;
    }
}
