<?php

namespace nineteenninetyfour\ghostwriter\images;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoResults;
use NineteenNinetyFour\Ghostwriter\Core\Images\ReferenceImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use nineteenninetyfour\ghostwriter\ai\CraftLogger;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\StockUsages;
use Throwable;
use yii\base\Component;

/**
 * What the Ghostwriter button beside an image field does: find photographs
 * that suit the block and page the field is on (ghostwriter-core's
 * PhotoFinder does the searching and judging), or have a new picture made
 * in the style of those already in that place. Each ends as an asset in the
 * field's own upload folder.
 *
 * Nothing about a site's look is assumed. The pictures in the same place on
 * the section's other entries are the style, with the image style guide.
 */
class ImagePicker extends Component
{
    private ?StockSearch $stock = null;

    private ?PhotoFinder $finder = null;

    /**
     * Core's photo library search, with Craft's HTTP clients and keys.
     */
    public function stock(): StockSearch
    {
        $providers = Plugin::getInstance()->providers;

        return $this->stock ??= new StockSearch(
            $providers->httpClients(),
            $providers->credentials(),
            openverse: fn() => (bool) Plugin::getInstance()->getSettings()->openverse,
            logger: new CraftLogger(),
        );
    }

    /**
     * Core's whole "find a photo" flow: choosing searches, searching,
     * judging against the page and the pictures already there, and a second
     * round when nothing fits.
     */
    public function finder(): PhotoFinder
    {
        $plugin = Plugin::getInstance();

        // The ranker's reference images go through the model-input guard.
        return $this->finder ??= new PhotoFinder($this->stock(), $plugin->providers->registry(), $plugin->paths->prompts(), new CraftLogger(), guard: $plugin->domain->guard());
    }

    public function canFind(): bool
    {
        return $this->finder()->canFind();
    }

    public function canMake(): bool
    {
        return Plugin::getInstance()->providers->imageHandle() !== null;
    }

    /**
     * Photographs for the slot: with the searches a person typed, or ones
     * the model chooses from the block and the page; judged against the
     * words and the pictures already in that place.
     *
     * @param array<int, string> $terms Empty to have them chosen.
     */
    public function find(ImageSlot $slot, array $terms = []): PhotoResults
    {
        $references = [];

        foreach ($slot->references() as $asset) {
            try {
                $content = (string) $asset->getContents();

                // With where it came from, so the model-input guard can
                // check its ledger record and name as well as its bytes.
                if ($content !== '') {
                    $references[] = new ReferenceImage($content, self::ref($asset), (string) $asset->filename);
                }
            } catch (Throwable $exception) {
                Craft::warning("Could not read {$asset->filename}: {$exception->getMessage()}", 'ghostwriter');
            }
        }

        return $this->finder()->find($this->context($slot), $references, $terms ?: null);
    }

    public function context(ImageSlot $slot): PhotoContext
    {
        return PhotoContext::make(
            title: $slot->title(),
            label: $slot->label(),
            blockText: $slot->blockText(),
            pageText: $slot->pageText(),
            shape: $slot->shape(),
            style: Plugin::getInstance()->domain->guide(Guide::IMAGERY)->section($slot->sectionName()),
        );
    }

    /**
     * Download a found photograph, looked up again by its library and ID,
     * and keep it as an asset named, titled and described from what the
     * library says it shows.
     */
    public function keepPhoto(ImageSlot $slot, string $source, string $id): Asset
    {
        $file = $this->stock()->fetch($source, $id);
        $photo = $file->photo;

        $asset = $this->keep($slot, $file->content, $file->extension, [
            'filename' => $photo->filenameBase($slot->title() ?: null),
            'title' => $photo->assetTitle($slot->title() ?: null),
            'alt' => $photo->alt($slot->title() ?: null),
            'credit' => $photo->credit,
            'credit_url' => $photo->creditUrl,
            'licence' => $photo->licence,
        ]);

        // A free library's photo is licensed at once: the ledger says where
        // it came from, under which licence, and where it is used.
        $plugin = Plugin::getInstance();
        $capabilities = $this->stock()->library($source)?->capabilities();
        $plugin->domain->stock()->recordFree($photo, self::ref($asset), $plugin->domain->person(), StockUsages::usageFor($slot->element, $slot->field), (bool) $capabilities?->noModelInput);
        $plugin->stockUsages->forget();

        return $asset;
    }

    /**
     * An asset as the ledger refers to it: its ID, with its volume and path.
     */
    public static function ref(Asset $asset): AssetRef
    {
        return AssetRef::craft((int) $asset->id, (string) $asset->getVolume()->handle, (string) $asset->getPath());
    }

    /**
     * Have a new picture made for the slot, in the style of those already
     * in that place.
     *
     * @param Image|null $source An image the editor supplied, such as a product shot, to be used in the picture.
     */
    public function make(ImageSlot $slot, string $direction = '', ?Image $source = null): Image
    {
        $plugin = Plugin::getInstance();
        $provider = $plugin->providers->image()
            ?? throw new InvalidArgumentException('No image provider has an API key. Set OPENAI_API_KEY or GEMINI_API_KEY.');

        $references = [];
        $guard = $plugin->domain->guard();

        foreach ($slot->references() as $asset) {
            try {
                $content = (string) $asset->getContents();

                // Never a Getty or iStock image, nor any other a library's
                // terms keep from models.
                if ($guard->allowsImage($content, self::ref($asset), (string) $asset->filename)) {
                    $references[] = Image::fromString($content);
                }
            } catch (Throwable) {
            }
        }

        // The model and timeout come from the settings.
        return $provider->image(new ImageRequest(
            prompt: $this->prompt($slot, $direction, count($references), $source !== null),
            references: $source ? [...$references, $source] : $references,
            shape: $slot->shape(),
        ));
    }

    /**
     * Save a picture as an asset in the slot's upload folder.
     *
     * @param array{filename?: string, title?: string, alt?: string, credit?: string|null, credit_url?: string|null, licence?: string|null} $meta
     */
    public function keep(ImageSlot $slot, string $content, string $extension, array $meta = []): Asset
    {
        $folder = Craft::$app->getAssets()->getFolderById($slot->folderId())
            ?? throw new InvalidArgumentException('The upload folder for this field no longer exists.');

        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $name = ($meta['filename'] ?? '') ?: (StringHelper::toKebabCase($meta['title'] ?? '') ?: (StringHelper::toKebabCase($slot->title()) ?: 'image'));
        $filename = mb_substr($name, 0, 60) . '-' . strtolower(StringHelper::randomString(6)) . '.' . $extension;

        $path = Craft::$app->getPath()->getTempPath() . '/' . $filename;
        FileHelper::writeToFile($path, $content);

        $credit = trim(implode(', ', array_filter([$meta['credit'] ?? null, $meta['licence'] ?? null])));

        $asset = new Asset();
        $asset->tempFilePath = $path;
        $asset->filename = $filename;
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $folder->volumeId;
        $asset->title = trim((string) ($meta['title'] ?? '')) ?: $slot->title();
        $asset->alt = trim((string) ($meta['alt'] ?? '')) ?: null;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        // A credit goes in a field made for it, where the volume has one.
        if ($credit !== '' && ($handle = $this->creditField($asset))) {
            $asset->setFieldValue($handle, $credit . (!empty($meta['credit_url']) ? " ({$meta['credit_url']})" : ''));
        }

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new InvalidArgumentException('The image could not be saved: ' . implode(' ', $asset->getFirstErrors()));
        }

        return $asset;
    }

    private function creditField(Asset $asset): ?string
    {
        foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof \craft\fields\PlainText && preg_match('/credit|attribution|copyright|caption|source/i', $field->handle)) {
                return $field->handle;
            }
        }

        return null;
    }

    private function prompt(ImageSlot $slot, string $direction, int $references, bool $hasSource): string
    {
        $summary = $slot->blockText() ?: $slot->pageText();
        $summary = mb_strlen($summary) > 1200 ? mb_substr($summary, 0, 1200) . '…' : $summary;
        $style = Plugin::getInstance()->domain->guide(Guide::IMAGERY)->section($slot->sectionName());

        return strtr(Plugin::getInstance()->paths->prompt('image'), [
            '{{ field }}' => $slot->label(),
            '{{ title }}' => $slot->title(),
            '{{ summary }}' => $summary !== '' ? $summary : '(none)',
            '{{ direction }}' => trim($direction) !== '' ? trim($direction) : '(none given; choose a subject that suits the title)',
            '{{ style }}' => $style !== '' ? $style : 'No written guide; go by the reference images.',
            '{{ references }}' => $references === 0
                ? 'No reference images are attached, so there is no house style to match. Make a clean, simple image with no text in it.'
                : "The first {$references} attached image(s) are what this same field holds on other pages of the site. They are the house style. Match them closely: the kind of image (photograph, illustration, or a mark on a flat ground), composition, palette, lighting, texture and how much detail there is. If they contain no text, neither does yours. Make a new image that belongs in the same set; do not copy their subject.",
            '{{ source }}' => $hasSource
                ? 'The last attached image was supplied by the editor, for example a logo or a product shot. It is the subject. Reproduce it exactly as it is, without redrawing, restyling or recolouring it, placed the way the references place theirs.'
                : 'No source image was supplied. Do not draw any real company\'s logo or brand mark from memory.',
        ]);
    }
}
