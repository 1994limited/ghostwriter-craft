<?php

namespace nineteenninetyfour\ghostwriter\images;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use GuzzleHttp\Promise\Utils;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;
use yii\base\Component;

/**
 * What the Ghostwriter button beside an image field does: find photographs
 * that suit the block and page the field is on, or have a new picture made
 * in the style of those already in that place. Each ends as an asset in the
 * field's own upload folder.
 *
 * Nothing about a site's look is assumed. The pictures in the same place on
 * the section's other entries are the style, with the image style guide.
 */
class ImagePicker extends Component
{
    /** Photographs picked out first, and results looked at per search. */
    public const SHORTLIST = 3;

    private const PER_TERM = 6;

    private ?StockSearch $stock = null;

    public function stock(): StockSearch
    {
        return $this->stock ??= new StockSearch();
    }

    public function canFind(): bool
    {
        return $this->stock()->sources() !== [];
    }

    public function canMake(): bool
    {
        return Plugin::getInstance()->providers->imageHandle() !== null;
    }

    /**
     * Three searches for a photograph that suits the slot, chosen by the
     * model from the words around it. Without a model, the page's title.
     *
     * @return array<int, string>
     */
    public function searchTerms(ImageSlot $slot): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->studio->configured()) {
            return array_filter([mb_strtolower($slot->title())]);
        }

        $style = $plugin->imageryGuide->for($slot->sectionName());
        $prompt = "Page title: {$slot->title()}\n\nThe picture goes in: {$slot->label()}\n\n"
            . (($block = $slot->blockText()) !== '' ? "Words in that part of the page:\n\n{$block}\n\n" : '')
            . "The whole page:\n\n" . ($slot->pageText() ?: '(nothing written yet)')
            . ($style !== '' ? "\n\nThe site's own description of its images in this section:\n\n{$style}" : '');

        $answer = $plugin->studio->ask('photo-researcher', $plugin->paths->prompt('photo-researcher'), $prompt, effort: 'low')->text;

        return self::terms($answer);
    }

    /**
     * Searches, from a reply or from what a person typed: up to three,
     * separated by semicolons or new lines.
     *
     * @return array<int, string>
     */
    public static function terms(string $text): array
    {
        $terms = array_map(fn(string $term) => trim(preg_replace('/[^\p{L}\p{N} \'-]+/u', ' ', $term) ?? '', " \t-'"), preg_split('/[;\n]+/u', $text) ?: []);
        $terms = array_values(array_unique(array_filter(array_map(fn(string $term) => mb_strtolower(preg_replace('/\s+/u', ' ', $term) ?? ''), $terms))));

        return array_slice($terms, 0, self::SHORTLIST);
    }

    /**
     * Photographs worth offering: each search is run, and the three that sit
     * best beside the pictures already in that place come first, followed by
     * the rest.
     *
     * @param array<int, string> $terms
     * @return array<int, array<string, mixed>>
     */
    public function shortlist(ImageSlot $slot, array $terms): array
    {
        $candidates = [];

        foreach ($terms as $term) {
            foreach (array_slice($this->stock()->search($term, $slot->shape()), 0, self::PER_TERM) as $photo) {
                $candidates[$photo['source'] . $photo['id']] ??= $photo + ['term' => $term];
            }
        }

        $candidates = array_values($candidates);
        $judged = $this->judged($slot, $candidates);
        $best = $judged ?? $this->oneOfEach($candidates);
        $ids = array_map(fn(array $photo) => $photo['source'] . $photo['id'], $best);

        // Only photos the model compared with the site's own are marked as
        // the best match; otherwise they are simply first in search order.
        return [
            ...array_map(fn(array $photo) => $photo + ['picked' => $judged !== null], $best),
            ...array_values(array_filter($candidates, fn(array $photo) => !in_array($photo['source'] . $photo['id'], $ids, true))),
        ];
    }

    /**
     * Ask the model, which can see both, which candidates match the site's
     * own pictures. Null when there is nothing to compare with or no model.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>|null
     */
    private function judged(ImageSlot $slot, array $candidates): ?array
    {
        $plugin = Plugin::getInstance();
        $references = $slot->references();

        if (count($candidates) <= self::SHORTLIST || $references === [] || !$plugin->studio->configured()) {
            return null;
        }

        try {
            $sampler = new ImageSampler();
            $shown = array_values(array_filter(array_map(fn(Asset $asset) => $sampler->small($asset), $references)));

            $client = $plugin->providers->http();
            $results = Utils::settle(array_map(fn(array $photo) => $client->requestAsync('GET', $photo['thumb'], ['timeout' => 15]), $candidates))->wait();

            $seen = [];
            $images = $shown;

            foreach ($results as $i => $result) {
                $body = $result['state'] === 'fulfilled' ? (string) $result['value']->getBody() : '';

                if ($body !== '' && ($image = $this->small($body))) {
                    $images[] = $image;
                    $seen[] = $candidates[$i];
                }
            }

            if ($shown === [] || count($seen) <= self::SHORTLIST) {
                return null;
            }

            $list = implode("\n", array_map(fn(array $photo, int $i) => ($i + 1) . ". from the search \"{$photo['term']}\"", $seen, array_keys($seen)));
            $style = $plugin->imageryGuide->for($slot->sectionName());

            $answer = $plugin->studio->ask(
                'photo-picker',
                $plugin->paths->prompt('photo-picker'),
                "The page is titled \"{$slot->title()}\" and the picture goes in {$slot->label()}.\n\n"
                . 'The first ' . count($shown) . ' image(s) are the references. The ' . count($seen) . " after them are the candidates, in this order:\n{$list}\n\n"
                . ($style !== '' ? "The site's own description of its images in this section:\n{$style}\n\n" : '')
                . 'Rank the best ' . (self::SHORTLIST * 2) . '.',
                images: $images,
                effort: 'low',
            )->text;

            preg_match_all('/\d+/', $answer, $m);

            $ranked = [];

            foreach ($m[0] as $n) {
                $photo = $seen[(int) $n - 1] ?? null;

                if ($photo) {
                    $ranked[$photo['source'] . $photo['id']] ??= $photo;
                }
            }

            // The best from each search first, so the three on offer differ;
            // then whatever ranked next.
            $chosen = [];
            $terms = [];

            foreach ($ranked as $key => $photo) {
                if (!isset($terms[$photo['term']])) {
                    $terms[$photo['term']] = true;
                    $chosen[$key] = $photo;
                }
            }

            $chosen = array_slice(array_values($chosen + $ranked), 0, self::SHORTLIST);

            return $chosen ?: null;
        } catch (Throwable $exception) {
            Craft::warning("Choosing photographs failed: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }
    }

    /**
     * Without a judge, the top result of each search is as fair as any.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function oneOfEach(array $candidates): array
    {
        $byTerm = [];

        foreach ($candidates as $photo) {
            $byTerm[$photo['term']][] = $photo;
        }

        $picked = [];

        for ($i = 0; count($picked) < self::SHORTLIST && $i < self::PER_TERM; $i++) {
            foreach ($byTerm as $photos) {
                if (isset($photos[$i]) && count($picked) < self::SHORTLIST) {
                    $picked[] = $photos[$i];
                }
            }
        }

        return $picked;
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

        foreach ($slot->references() as $asset) {
            try {
                $references[] = Image::fromString((string) $asset->getContents());
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
     * @param array{title?: string, alt?: string, credit?: string|null, credit_url?: string|null, licence?: string|null} $meta
     */
    public function keep(ImageSlot $slot, string $content, string $extension, array $meta = []): Asset
    {
        $folder = Craft::$app->getAssets()->getFolderById($slot->folderId())
            ?? throw new InvalidArgumentException('The upload folder for this field no longer exists.');

        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $name = StringHelper::toKebabCase($meta['title'] ?? '') ?: (StringHelper::toKebabCase($slot->title()) ?: 'image');
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

    /**
     * An image small enough to show a model many of at once.
     */
    private function small(string $content): ?Image
    {
        if (@getimagesizefromstring($content) === false) {
            return null;
        }

        if (!extension_loaded('imagick')) {
            return strlen($content) < 1_000_000 ? Image::fromString($content) : null;
        }

        try {
            $image = new \Imagick();
            $image->readImageBlob($content);
            $image->thumbnailImage(512, 512, true);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(75);

            return new Image($image->getImageBlob(), 'image/jpeg');
        } catch (Throwable) {
            return null;
        }
    }

    private function prompt(ImageSlot $slot, string $direction, int $references, bool $hasSource): string
    {
        $summary = $slot->blockText() ?: $slot->pageText();
        $summary = mb_strlen($summary) > 1200 ? mb_substr($summary, 0, 1200) . '…' : $summary;
        $style = Plugin::getInstance()->imageryGuide->for($slot->sectionName());

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
