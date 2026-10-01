<?php

namespace nineteenninetyfour\ghostwriter\images;

use craft\helpers\FileHelper;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * One search or picture being made from an image field's button, kept as a
 * JSON file while the queue works on it, with the made picture beside it.
 * They are working state, not content: a day later they are cleared away.
 */
class ImageRequests extends Component
{
    public const WORKING = 'working';

    public const READY = 'ready';

    public const FAILED = 'failed';

    private const KEEP_FOR = 86400;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->clearOld();

        return $this->save($data + [
            'id' => bin2hex(random_bytes(13)),
            'status' => self::WORKING,
            'error' => null,
            'terms' => [],
            'options' => [],
            'file' => null,
            'createdAt' => time(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{26}$/', $id) || !is_file($this->path($id))) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path($id)), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        Plugin::getInstance()->paths->write($this->path($data['id']), (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $data;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        $data = $this->find($id);

        return $data ? $this->save(array_merge($data, $changes)) : null;
    }

    /**
     * Where a made picture for this request is kept.
     */
    public function file(string $id, string $extension): string
    {
        return $this->directory() . '/' . $id . '.' . $extension;
    }

    private function clearOld(): void
    {
        foreach (glob($this->directory() . '/*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - self::KEEP_FOR) {
                FileHelper::unlink($path);
            }
        }
    }

    private function directory(): string
    {
        return Plugin::getInstance()->paths->storage('images');
    }

    private function path(string $id): string
    {
        return $this->directory() . '/' . $id . '.json';
    }
}
