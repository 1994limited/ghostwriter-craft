<?php

namespace nineteenninetyfour\ghostwriter\planning;

use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;
use Symfony\Component\Yaml\Yaml;
use yii\base\Component;

/**
 * The content plan: ideas for things the site does not have yet. Some are
 * suggested by Ghostwriter from what is missing, some added by hand. It is
 * one YAML file in the project, so the plan is versioned with the site and
 * can be edited there too.
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
        if (!is_file($this->path())) {
            return [];
        }

        $ideas = [];

        foreach ((array) ((array) Yaml::parse((string) file_get_contents($this->path())))['ideas'] ?? [] as $idea) {
            if (!is_array($idea) || empty($idea['title']) || empty($idea['section'])) {
                continue;
            }

            $idea += ['id' => bin2hex(random_bytes(8)), 'type' => null, 'why' => '', 'notes' => '', 'status' => self::OPEN, 'source' => 'added', 'session' => null, 'createdAt' => date('Y-m-d')];
            $ideas[(string) $idea['id']] = $idea;
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

        $ideas = $this->all();
        $ideas[$idea['id']] = $idea;
        $this->write($ideas);

        return $idea;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        $ideas = $this->all();

        if (!isset($ideas[$id])) {
            return null;
        }

        $ideas[$id] = array_merge($ideas[$id], array_intersect_key($changes, array_flip(['title', 'section', 'type', 'why', 'notes', 'status', 'session'])));
        $this->write($ideas);

        return $ideas[$id];
    }

    /**
     * Remove every idea in one state, such as all the open ones.
     *
     * @return int How many went.
     */
    public function clear(string $status): int
    {
        $ideas = $this->all();
        $keep = array_filter($ideas, fn(array $idea) => $idea['status'] !== $status);

        $this->write($keep);

        return count($ideas) - count($keep);
    }

    public function delete(string $id): void
    {
        $ideas = $this->all();
        unset($ideas[$id]);
        $this->write($ideas);
    }

    /**
     * @param array<string, array<string, mixed>> $ideas
     */
    private function write(array $ideas): void
    {
        Plugin::getInstance()->paths->write($this->path(), Yaml::dump(['ideas' => array_values($ideas)], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }

    private function path(): string
    {
        return Plugin::getInstance()->paths->guides('ideas.yaml');
    }
}
