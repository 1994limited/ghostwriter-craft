<?php

namespace nineteenninetyfour\ghostwriter\images;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Matrix;
use Illuminate\Support\Collection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Finds the images a section's entries use, wherever they sit: in an Assets
 * field on the entry, or in one on a Matrix or Neo block, however deep. Each
 * is labelled with the field it is in, so a guide can say which field holds
 * which kind of picture.
 */
class ImageSampler
{
    /** Entries looked through for a section's images. */
    private const ENTRIES = 24;

    private const RASTER = ['jpg', 'jpeg', 'png', 'webp'];

    private const NEO = 'benf\neo\Field';

    /**
     * A spread of a section's images: a few from each field, newest entries
     * first, taking turns between fields so one busy field does not crowd
     * out the rest.
     *
     * @return array<int, array{label: string, entry: string, image: Image, asset: \NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef, filename: string}>
     */
    public function samples(string $section, int $limit = 10): array
    {
        $byField = [];

        foreach (Entry::find()->section($section)->status('live')->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(self::ENTRIES)->all() as $entry) {
            foreach ($this->find($entry) as [$label, $asset]) {
                $byField[$label][$asset->id] ??= ['label' => $label, 'entry' => (string) $entry->title, 'asset' => $asset];
            }
        }

        $queues = array_map('array_values', array_values($byField));
        $picked = [];

        for ($i = 0; count($picked) < $limit * 2 && $i < $limit * 2; $i++) {
            foreach ($queues as $queue) {
                if (isset($queue[$i]) && !isset($picked[$queue[$i]['asset']->id])) {
                    $picked[$queue[$i]['asset']->id] = $queue[$i];
                }
            }
        }

        // An image that cannot be read (a missing file, a remote volume with
        // no access from here) is passed over for the next.
        $samples = [];

        foreach ($picked as $sample) {
            if (count($samples) >= $limit) {
                break;
            }

            if ($image = $this->small($sample['asset'])) {
                // Where it came from goes with it, for the model-input guard.
                $samples[] = ['label' => $sample['label'], 'entry' => $sample['entry'], 'image' => $image, 'asset' => ImagePicker::ref($sample['asset']), 'filename' => (string) $sample['asset']->filename];
            }
        }

        return $samples;
    }

    /**
     * The raster images one element uses, with the label of the field each
     * is in: the field's name, or "Block: Field" inside a page builder.
     *
     * @return array<int, array{0: string, 1: Asset}>
     */
    public function find(ElementInterface $element, ?string $block = null, int $depth = 0): array
    {
        $found = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            try {
                $value = $element->getFieldValue($field->handle);
            } catch (Throwable) {
                continue;
            }

            if ($field instanceof Assets) {
                $label = ($block ? "{$block}: " : '') . $field->name;

                foreach ($this->elements($value) as $asset) {
                    if ($asset instanceof Asset && in_array(strtolower((string) $asset->getExtension()), self::RASTER, true)) {
                        $found[] = [$label, $asset];
                    }
                }
            } elseif (($field instanceof Matrix || is_a($field, self::NEO)) && $depth < 4) {
                foreach ($this->elements($value) as $child) {
                    // Inside a block, images are named after the outermost
                    // block: "Text with asset: Image" says where it sits.
                    $name = $block ?? (string) $child->getType()->name;

                    array_push($found, ...$this->find($child, $name, $depth + 1));
                }
            }
        }

        return $found;
    }

    /**
     * An image small enough to show a model many of at once.
     */
    public function small(Asset $asset): ?Image
    {
        try {
            $content = (string) $asset->getContents();
        } catch (Throwable $exception) {
            Craft::warning("Could not read {$asset->filename}: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }

        if ($content === '' || @getimagesizefromstring($content) === false) {
            return null;
        }

        // Read before it is made small, which drops the embedded credit:
        // an image whose IPTC or XMP names Getty Images or iStock never
        // goes to a model.
        if (!Plugin::getInstance()->domain->guard()->allowsImage($content, ImagePicker::ref($asset), (string) $asset->filename)) {
            return null;
        }

        if (!extension_loaded('imagick')) {
            return strlen($content) < 1_000_000 ? Image::fromString($content) : null;
        }

        $image = new \Imagick();
        $image->readImageBlob($content);
        $image->thumbnailImage(512, 512, true);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(75);

        return new Image($image->getImageBlob(), 'image/jpeg');
    }

    /**
     * @return ElementInterface[]
     */
    private function elements(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            return (clone $value)->status(null)->all();
        }

        if ($value instanceof Collection) {
            return $value->all();
        }

        return [];
    }
}
