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
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PreviewableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\StandIn;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoResults;
use NineteenNinetyFour\Ghostwriter\Core\Images\ReferenceImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Seo\FilenameRules;
use nineteenninetyfour\ghostwriter\ai\CraftLogger;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\StockLibraries;
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
    /** Results asked of a paid library per search. */
    private const PAID_PER_TERM = 9;

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

    /**
     * Whether any photo library can be searched: a free one, or a paid one
     * that is set up and switched on.
     */
    public function canFind(): bool
    {
        return $this->finder()->canFind() || Plugin::getInstance()->stockLibraries->paid() !== [];
    }

    public function canMake(): bool
    {
        return Plugin::getInstance()->providers->imageHandle() !== null;
    }

    /**
     * Photographs for the slot, from where the person chose to search:
     *
     * - `free`: the free libraries, judged against the words and the
     *   pictures already in that place (core's PhotoFinder), as before;
     * - a paid library's ID: that library's own results, in its own order.
     *   No model sees them, nor their titles: their terms forbid it;
     * - `everything`: the free ones judged, then each paid library's.
     *
     * The searches are the ones the person typed, or ones the model
     * chooses from the block and the page (the customer's own words, never
     * a library's). Editorial-only photos are left out unless asked for.
     *
     * @param array<int, string> $terms Empty to have them chosen.
     */
    public function find(ImageSlot $slot, array $terms = [], string $source = StockLibraries::FREE, bool $editorial = false): PhotoResults
    {
        $libraries = Plugin::getInstance()->stockLibraries;
        $source = $libraries->normaliseSource($source);

        if ($source === StockLibraries::FREE) {
            return self::withoutEditorial($this->findFree($slot, $terms), $editorial);
        }

        if ($source !== StockLibraries::EVERYTHING) {
            $terms = $terms ?: $this->finder()->searchTerms($this->context($slot));

            return new PhotoResults($this->searchPaid([$libraries->paid()[$source]], $terms, $slot->shape(), $editorial), $terms);
        }

        $free = $this->finder()->canFind() ? self::withoutEditorial($this->findFree($slot, $terms), $editorial) : null;
        $terms = $free?->terms ?: ($terms ?: $this->finder()->searchTerms($this->context($slot)));
        $paid = $this->searchPaid(array_values($libraries->paid()), $terms, $slot->shape(), $editorial);

        return new PhotoResults([...($free?->photos ?? []), ...$paid], $terms, (bool) $free?->judged, (bool) $free?->noneFit, (bool) $free?->retried, (bool) $free?->withReferences);
    }

    /**
     * Paid libraries' results for the searches, each library's own order,
     * a few per search. Unjudged: never shown to a model.
     *
     * @param array<int, PhotoLibrary> $libraries
     * @param array<int, string> $terms
     * @return array<int, Photo>
     */
    private function searchPaid(array $libraries, array $terms, string $shape, bool $editorial): array
    {
        $found = [];

        foreach ($libraries as $library) {
            foreach (array_slice($terms, 0, PhotoFinder::MAX_TERMS) as $term) {
                try {
                    $photos = $library->search(new SearchQuery($term, $shape, perPage: self::PAID_PER_TERM, editorial: $editorial));
                } catch (Throwable $exception) {
                    Craft::warning("Searching {$library->id()} failed: {$exception->getMessage()}", 'ghostwriter');

                    continue;
                }

                foreach ($photos as $photo) {
                    if ($editorial || !$photo->editorial) {
                        $found[$photo->key()] ??= $photo->withTerm($term)->unjudged();
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * @return PhotoResults Without editorial-only photos, unless they are asked for.
     */
    private static function withoutEditorial(PhotoResults $results, bool $editorial): PhotoResults
    {
        if ($editorial) {
            return $results;
        }

        return new PhotoResults(array_values(array_filter($results->photos, fn(Photo $photo) => !$photo->editorial)), $results->terms, $results->judged, $results->noneFit, $results->retried, $results->withReferences);
    }

    /**
     * The free libraries' photographs for the slot: with the searches a
     * person typed, or ones the model chooses from the block and the page;
     * judged against the words and the pictures already in that place.
     *
     * @param array<int, string> $terms Empty to have them chosen.
     */
    private function findFree(ImageSlot $slot, array $terms = []): PhotoResults
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
        $alt = $photo->alt($slot->title() ?: null);

        $asset = $this->keep($slot, $file->content, $file->extension, [
            // Named from the alt text it is given (SEO layer §11), in the page's language.
            'filename' => $photo->filenameBase($slot->title() ?: null, alt: $alt, language: self::language($slot)),
            'title' => $photo->assetTitle($slot->title() ?: null),
            'alt' => $alt,
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
     * "Insert preview": a paid library's photo goes into the slot as a
     * stand-in asset, the comp kept privately for signed-in editors.
     *
     * - The photo is looked up again by its ID, never taken from the browser.
     * - The comp is kept in Ghostwriter's own files (never as an asset,
     *   never at a public address) until the library's comp period ends;
     *   where the library's terms allow nothing to be stored, only its
     *   preview address is noted.
     * - The asset holds the stand-in: stripes at the photo's aspect ratio
     *   and a label, nothing of the provider's. It is named, titled and
     *   described from the photo, as the licensed file will be.
     * - The ledger records a preview, with where it is used.
     */
    public function insertPreview(ImageSlot $slot, PreviewableLibrary $library, string $id): Asset
    {
        $plugin = Plugin::getInstance();
        $photo = $library->photo($id);
        $preview = $library->preview($id);
        $capabilities = $library->capabilities();

        $comp = $preview->url;

        if ($preview->file !== null) {
            $comp = 'stock-comp-' . bin2hex(random_bytes(10));
            $plugin->store->putFile($comp, $preview->file->content, $preview->file->mime, $preview->file->extension);
        }

        [$width, $height] = [$photo->width, $photo->height];

        if ((!$width || !$height) && $preview->file !== null && ($size = @getimagesizefromstring($preview->file->content))) {
            [$width, $height] = [$size[0], $size[1]];
        }

        $label = Craft::t('ghostwriter', '{library} {id} · preview, not licensed', ['library' => $plugin->stockLibraries->standInName($library->id()), 'id' => $photo->id]);
        $title = $slot->title() ?: null;

        $alt = $photo->alt($title);

        $asset = $this->keep($slot, StandIn::jpeg($width ?: 1600, $height ?: 1000, $label), 'jpg', [
            // Named from its alt text, as the licensed file will be (SEO layer §11).
            'filename' => $photo->filenameBase($title, alt: $alt, language: self::language($slot)) . '-' . $library->id() . '-' . $photo->id,
            'exact' => true,
            'title' => $photo->assetTitle($title),
            'alt' => $alt,
        ]);

        $plugin->domain->stock()->recordPreview($photo, self::ref($asset), $comp, $preview->keepUntil, $plugin->domain->person(), StockUsages::usageFor($slot->element, $slot->field), $capabilities->noModelInput);
        $plugin->stockUsages->forget();

        return $asset;
    }

    /** The language of the page the image is for ("en-GB"), for naming its file. */
    public static function language(ImageSlot $slot): string
    {
        try {
            return $slot->root->getSite()->language;
        } catch (Throwable) {
            return Craft::$app->language;
        }
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
     * @param array{filename?: string, exact?: bool, title?: string, alt?: string, credit?: string|null, credit_url?: string|null, licence?: string|null} $meta
     */
    public function keep(ImageSlot $slot, string $content, string $extension, array $meta = []): Asset
    {
        $folder = Craft::$app->getAssets()->getFolderById($slot->folderId())
            ?? throw new InvalidArgumentException('The upload folder for this field no longer exists.');

        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $name = ($meta['filename'] ?? '') ?: (FilenameRules::first([$meta['alt'] ?? null, $meta['title'] ?? null, $slot->title()], self::language($slot)) ?: (StringHelper::toKebabCase($meta['title'] ?? '') ?: (StringHelper::toKebabCase($slot->title()) ?: 'image')));

        // A stock photo keeps the name its licensed file will have; Craft
        // adds a number if it is taken.
        $filename = !empty($meta['exact'])
            ? mb_substr(StringHelper::toKebabCase($name) ?: 'image', 0, 100) . '.' . $extension
            : mb_substr($name, 0, 60) . '-' . strtolower(StringHelper::randomString(6)) . '.' . $extension;

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
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        // A credit goes in a field made for it, where the volume has one.
        if ($credit !== '' && ($handle = self::creditFieldOf($asset))) {
            $asset->setFieldValue($handle, $credit . (!empty($meta['credit_url']) ? " ({$meta['credit_url']})" : ''));
        }

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new InvalidArgumentException('The image could not be saved: ' . implode(' ', $asset->getFirstErrors()));
        }

        return $asset;
    }

    /**
     * The plain-text field on an asset made for a credit, where its volume
     * has one: "credit", "attribution", "copyright", "caption", "source".
     */
    public static function creditFieldOf(Asset $asset): ?string
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
