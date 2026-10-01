<?php

namespace nineteenninetyfour\ghostwriter\images;

use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * One search or picture being made from an image field's button, kept while
 * the queue works on it, with the made picture (and any picture uploaded to
 * put in it) stored beside it. They are working state, not content: a day
 * later they are cleared away.
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
        if (!preg_match('/^[0-9a-f]{26}$/', $id)) {
            return null;
        }

        $data = Plugin::getInstance()->store->state("image:{$id}");

        return $data === [] ? null : $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        Plugin::getInstance()->store->putState("image:{$data['id']}", $data);

        return $data;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        if ($this->find($id) === null) {
            return null;
        }

        return Plugin::getInstance()->store->changeState("image:{$id}", fn(array $data) => array_merge($data, $changes));
    }

    /**
     * Keep a picture for a request: the one made (`made`) or the one
     * uploaded to put in it (`source`).
     */
    public function putFile(string $id, string $which, string $content, string $mime, string $extension): void
    {
        Plugin::getInstance()->store->putFile("{$id}-{$which}", $content, $mime, $extension);
    }

    /**
     * @return array{content: string, mime: string, extension: string}|null
     */
    public function file(string $id, string $which): ?array
    {
        return Plugin::getInstance()->store->file("{$id}-{$which}");
    }

    public function deleteFile(string $id, string $which): void
    {
        Plugin::getInstance()->store->deleteFile("{$id}-{$which}");
    }

    private function clearOld(): void
    {
        $store = Plugin::getInstance()->store;
        $store->clearStateOlderThan('image:', self::KEEP_FOR);
        $store->clearFilesOlderThan(self::KEEP_FOR);
    }
}
