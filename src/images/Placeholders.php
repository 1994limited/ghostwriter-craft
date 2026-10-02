<?php

namespace nineteenninetyfour\ghostwriter\images;

use Craft;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\helpers\FileHelper;
use craft\models\Volume;

/**
 * Marks where an image belongs but none has been chosen: the same diagonal
 * stripes, the usual sign of a picture still to come, so the page shows its
 * layout as it will be and nobody can mistake the placeholder for a real
 * image.
 *
 * An image "belongs" where the field is required, or where at least half of
 * the existing entries (for a block, half the existing blocks of that type)
 * have one. An optional picture, such as a background few pages use, is
 * left empty.
 *
 * One placeholder file is made per volume and reused, so the asset library
 * does not fill up with copies.
 */
class Placeholders
{
    public const FILENAME = 'ghostwriter-image-placeholder.png';

    /** @var array<int, int> Placeholder asset ID by volume ID. */
    private array $assets = [];

    /** @var array<int, string> Where placeholders went, for the notes. */
    private array $filled = [];

    /** Share of existing entries or blocks with an image that makes one expected. */
    private const EXPECTED = 0.5;

    /**
     * @param array<string, float> $rates How often each field is filled, from the pattern core's PatternFinder found.
     */
    public function __construct(private array $rates = [])
    {
    }

    /**
     * @param array<string, mixed> $data Entry data in core EntryData's shape, as EntryReader reads it.
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public function fill(array $data, array $schema, ?string $block = null, ?string $type = null): array
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];
            $label = ($block ? "{$block}: " : '') . ($spec['display'] ?: $handle);
            $value = $data[$handle] ?? null;
            $expected = $this->expected($spec, ($type ? "{$type}." : '') . $handle);

            if ($spec['type'] === Assets::class) {
                // A field kept to PDFs or videos is no place for a picture.
                if (empty($value) && $expected && ($spec['images'] ?? true) && ($id = $this->assetFor($spec)) !== null) {
                    $data[$handle] = [$id];
                    $this->filled[] = $label;
                }

                continue;
            }

            if (!isset($spec['engine'])) {
                continue;
            }

            if (is_array($value) && $value !== []) {
                // Blocks in the draft: fill in each one's own image fields.
                foreach ($value as $i => $item) {
                    $set = is_array($item) ? ($spec['sets'][$item['type'] ?? ''] ?? null) : null;

                    if ($set) {
                        $data[$handle][$i] = $this->fill($item, $set['fields'], $block ?? $set['display'], $item['type']);
                    }
                }
            } elseif ($spec['kind'] === 'reference' && $expected && ($item = $this->imageItem($spec, $label)) !== null) {
                // A builder that holds nothing but images, such as a Matrix
                // of pictures inside a block: one item, with the placeholder.
                $data[$handle] = [$item];
            }
        }

        return $data;
    }

    /**
     * Required, or filled on most of the entries (or blocks) like this one.
     * With no history to go by, only a required field.
     */
    private function expected(array $spec, string $key): bool
    {
        return ($spec['required'] ?? false) || ($this->rates[$key] ?? 0) >= self::EXPECTED;
    }

    /**
     * @return array<int, string>
     */
    public function filled(): array
    {
        return array_values(array_unique($this->filled));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imageItem(array $spec, string $label): ?array
    {
        foreach ($spec['sets'] ?? [] as $type => $set) {
            foreach ($set['fields'] as $field) {
                if ($field['type'] === Assets::class && ($field['images'] ?? true) && ($id = $this->assetFor($field)) !== null) {
                    $this->filled[] = $label;

                    return ['type' => $type, 'enabled' => true, $field['handle'] => [$id]];
                }
            }
        }

        return null;
    }

    private function assetFor(array $spec): ?int
    {
        $volume = $this->volumeFor($spec);

        if (!$volume) {
            return null;
        }

        return $this->assets[$volume->id] ??= $this->make($volume);
    }

    /**
     * The volume a field uploads to, or the first it can choose from.
     */
    private function volumeFor(array $spec): ?Volume
    {
        $volumes = Craft::$app->getVolumes();
        $sources = array_filter([$spec['source'] ?? null, ...(is_array($spec['sources'] ?? null) ? $spec['sources'] : [])]);

        foreach ($sources as $source) {
            if (is_string($source) && str_starts_with($source, 'volume:') && ($volume = $volumes->getVolumeByUid(substr($source, 7)))) {
                return $volume;
            }
        }

        return $volumes->getAllVolumes()[0] ?? null;
    }

    private function make(Volume $volume): ?int
    {
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        if (!$folder) {
            return null;
        }

        $existing = Asset::find()->folderId($folder->id)->filename(self::FILENAME)->status(null)->one();

        if ($existing) {
            return (int) $existing->id;
        }

        $path = Craft::$app->getPath()->getTempPath() . '/' . self::FILENAME;
        FileHelper::writeToFile($path, $this->png());

        $asset = new Asset();
        $asset->tempFilePath = $path;
        $asset->filename = self::FILENAME;
        $asset->title = 'Image to choose (placeholder from Ghostwriter)';
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $volume->id;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            Craft::warning('The placeholder image could not be saved: ' . implode(' ', $asset->getFirstErrors()), 'ghostwriter');

            return null;
        }

        return (int) $asset->id;
    }

    /**
     * Soft grey diagonal stripes edge to edge, drawn in code.
     */
    private function png(): string
    {
        $width = 1600;
        $height = 1000;
        $band = 40;

        $image = imagecreatetruecolor($width, $height);
        $light = imagecolorallocate($image, 0xEE, 0xF0, 0xF3);
        $dark = imagecolorallocate($image, 0xDD, 0xE1, 0xE6);

        imagefill($image, 0, 0, $light);

        // Bands at 45 degrees, wide enough to read at any size the image is shown.
        for ($x = -$height; $x < $width; $x += $band * 2) {
            imagefilledpolygon($image, [$x, $height, $x + $band, $height, $x + $band + $height, 0, $x + $height, 0], $dark);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
