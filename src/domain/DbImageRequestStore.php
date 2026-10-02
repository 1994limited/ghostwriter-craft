<?php

namespace nineteenninetyfour\ghostwriter\domain;

use craft\db\Query;
use craft\helpers\Db;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;

/**
 * Image requests as `image:<id>` state, with the pictures beside them in
 * the files table as `<id>-made` and `<id>-source`. Working state, not
 * content: cleared a day later.
 */
class DbImageRequestStore implements ImageRequestStore
{
    private const FILES = [StoredFile::MADE, StoredFile::SOURCE];

    public function save(ImageRequest $request): ImageRequest
    {
        $this->store()->putState("image:{$request->id}", $request->toArray());

        return $request;
    }

    public function find(string $id): ?ImageRequest
    {
        if (!Format::Craft->isSessionId($id)) {
            return null;
        }

        $data = $this->store()->state("image:{$id}");

        if ($data === []) {
            return null;
        }

        $request = ImageRequest::fromArray($data, Format::Craft);
        $request->changedAt = $this->store()->stateUpdatedAt("image:{$id}");

        return $request;
    }

    public function delete(string $id): void
    {
        $this->store()->deleteState("image:{$id}");

        foreach (self::FILES as $which) {
            $this->deleteFile($id, $which);
        }
    }

    public function clearOlderThan(DateTimeInterface $cutoff): int
    {
        $before = Db::prepareDateForDb($cutoff);
        $names = (new Query())->select('name')->from(Store::STATE)->where(['and', ['like', 'name', 'image:%', false], ['<', 'dateUpdated', $before]])->column();

        foreach ($names as $name) {
            $this->delete(substr((string) $name, strlen('image:')));
        }

        // Not stock photo comps: they are kept for the library's comp
        // period, and cleared by the stock cleanup.
        Db::delete(Store::FILES, ['and', ['<', 'dateCreated', $before], ['not like', 'id', 'stock-%', false]]);

        return count($names);
    }

    public function putFile(string $id, string $which, StoredFile $file): void
    {
        $this->store()->putFile("{$id}-{$which}", $file->content, $file->mime, $file->extension);
    }

    public function file(string $id, string $which): ?StoredFile
    {
        $file = $this->store()->file("{$id}-{$which}");

        return $file === null ? null : new StoredFile($file['content'], $file['mime'], $file['extension']);
    }

    /**
     * Lets go of one picture: the one uploaded to build a made picture
     * around, once it has served.
     */
    public function deleteFile(string $id, string $which): void
    {
        $this->store()->deleteFile("{$id}-{$which}");
    }

    private function store(): Store
    {
        return Plugin::getInstance()->store;
    }
}
