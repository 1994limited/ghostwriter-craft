<?php

namespace nineteenninetyfour\ghostwriter\sessions;

use craft\helpers\FileHelper;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Sessions are stored one JSON file each, so the plugin needs no tables of
 * its own and a session can be read, or thrown away, by hand.
 */
class SessionRepository extends Component
{
    /**
     * @return Session[] Newest first.
     */
    public function all(): array
    {
        $sessions = array_filter(array_map(fn(string $path) => $this->read($path), glob($this->directory() . '/*.json') ?: []));

        usort($sessions, fn(Session $a, Session $b) => strcmp((string) $b->updatedAt, (string) $a->updatedAt));

        return $sessions;
    }

    public function find(string $id): ?Session
    {
        // IDs are ours; anything else is not ours to look up.
        if (!preg_match('/^[0-9a-f]{26}$/', $id)) {
            return null;
        }

        return $this->read($this->path($id));
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = Session::now();

        Plugin::getInstance()->paths->write($this->path($session->id), (string) json_encode($session->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $session;
    }

    public function delete(Session $session): void
    {
        FileHelper::unlink($this->path($session->id));
    }

    private function read(string $path): ?Session
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['id'], $data['type']) ? Session::fromArray($data) : null;
    }

    private function directory(): string
    {
        return Plugin::getInstance()->paths->storage('sessions');
    }

    private function path(string $id): string
    {
        return $this->directory() . '/' . $id . '.json';
    }
}
