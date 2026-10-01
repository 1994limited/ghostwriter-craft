<?php

namespace nineteenninetyfour\ghostwriter\planning;

use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;
use yii\base\Component;

/**
 * The content plan: ideas for things the site does not have yet. Some are
 * suggested by Ghostwriter from what is missing, some added by hand. Each
 * idea is a row in the database.
 */
class IdeaRepository extends Component
{
    public const OPEN = 'open';

    public const DRAFTED = 'drafted';

    public const DISMISSED = 'dismissed';

    /**
     * @return array<string, array{id: string, title: string, section: string, type: ?string, why: string, notes: string, status: string, source: string, session: ?string, createdAt: string}>
     */
    public function all(): array
    {
        $ideas = [];

        foreach (Plugin::getInstance()->store->documents('idea') as $id => $json) {
            $idea = Json::decodeIfJson($json);

            if (!is_array($idea) || empty($idea['title']) || empty($idea['section'])) {
                continue;
            }

            $idea = ['id' => (string) $id] + $idea + ['type' => null, 'why' => '', 'notes' => '', 'status' => self::OPEN, 'source' => 'added', 'session' => null, 'createdAt' => date('Y-m-d')];
            $ideas[(string) $id] = $idea;
        }

        return $ideas;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $idea
     * @return array<string, mixed>
     */
    public function add(array $idea, string $source = 'added'): array
    {
        $idea = [
            'id' => bin2hex(random_bytes(8)),
            'title' => trim((string) $idea['title']),
            'section' => (string) $idea['section'],
            'type' => ($idea['type'] ?? null) ?: null,
            'why' => trim((string) ($idea['why'] ?? '')),
            'notes' => trim((string) ($idea['notes'] ?? '')),
            'status' => self::OPEN,
            'source' => $source,
            'session' => null,
            'createdAt' => substr(Session::now(), 0, 10),
        ];

        $this->put($idea);

        return $idea;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        return Plugin::getInstance()->store->locked("idea:{$id}", function() use ($id, $changes) {
            $idea = $this->find($id);

            if ($idea === null) {
                return null;
            }

            $idea = array_merge($idea, array_intersect_key($changes, array_flip(['title', 'section', 'type', 'why', 'notes', 'status', 'session'])));
            $this->put($idea);

            return $idea;
        });
    }

    /**
     * Remove every idea in one state, such as all the open ones.
     *
     * @return int How many went.
     */
    public function clear(string $status): int
    {
        $gone = array_filter($this->all(), fn(array $idea) => $idea['status'] === $status);

        foreach ($gone as $id => $idea) {
            $this->delete((string) $id);
        }

        return count($gone);
    }

    public function delete(string $id): void
    {
        Plugin::getInstance()->store->deleteDocument('idea', $id);
    }

    /**
     * @param array<string, mixed> $idea
     */
    private function put(array $idea): void
    {
        Plugin::getInstance()->store->putDocument('idea', (string) $idea['id'], Json::encode($idea));
    }
}
