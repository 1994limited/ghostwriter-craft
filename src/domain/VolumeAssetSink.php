<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\models\Volume;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Keeps core's striped placeholder as an asset in the volume an image
 * field uploads to (or the first it may choose from), named
 * Placeholders::FILENAME at the volume's root. One per volume, made once
 * and reused, so the asset library does not fill up with copies. Assets
 * fields hold a list of asset IDs.
 */
class VolumeAssetSink implements AssetSink
{
    /** @var array<int, int> Placeholder asset ID by volume ID. */
    private array $assets = [];

    public function placeholder(Field $field, callable $png): string|int|null
    {
        $volume = $this->volumeFor($field);

        if (!$volume) {
            return null;
        }

        return $this->assets[(int) $volume->id] ??= $this->make($volume, $png);
    }

    public function value(Field $field, string|int $reference): mixed
    {
        return [(int) $reference];
    }

    public function block(Field $builder, string $set, Field $image, mixed $value): ?array
    {
        return ['type' => $set, 'enabled' => true, $image->handle => $value];
    }

    /**
     * The volume a field uploads to, or the first it can choose from.
     */
    private function volumeFor(Field $field): ?Volume
    {
        $volumes = Craft::$app->getVolumes();
        $sources = array_filter([$field->meta['source'] ?? null, ...(is_array($field->meta['sources'] ?? null) ? $field->meta['sources'] : [])]);

        foreach ($sources as $source) {
            if (is_string($source) && str_starts_with($source, 'volume:') && ($volume = $volumes->getVolumeByUid(substr($source, 7)))) {
                return $volume;
            }
        }

        return $volumes->getAllVolumes()[0] ?? null;
    }

    /**
     * @param callable(): string $png
     */
    private function make(Volume $volume, callable $png): ?int
    {
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        if (!$folder) {
            return null;
        }

        $existing = Asset::find()->folderId($folder->id)->filename(Placeholders::FILENAME)->status(null)->one();

        if ($existing) {
            return (int) $existing->id;
        }

        $path = Craft::$app->getPath()->getTempPath() . '/' . Placeholders::FILENAME;
        FileHelper::writeToFile($path, $png());

        $asset = new Asset();
        $asset->tempFilePath = $path;
        $asset->filename = Placeholders::FILENAME;
        $asset->title = Placeholders::TITLE;
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $volume->id;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            Craft::warning('The placeholder image could not be saved: ' . implode(' ', $asset->getFirstErrors()), 'ghostwriter');

            return null;
        }

        return (int) $asset->id;
    }
}
