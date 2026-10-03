<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\UploadedFile;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PreviewableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\jobs\FindImages;
use nineteenninetyfour\ghostwriter\jobs\MakeImage;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\StockLibraries;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Behind the Ghostwriter button on an image field: find photographs or have
 * a picture made, and turn the one chosen into an asset the field's own
 * input then shows.
 */
class ImagesController extends Controller
{
    /** Where each person's "Search in" choice is kept, in their user preferences. */
    public const SOURCE_PREFERENCE = 'ghostwriter:stockSource';

    /** Pictures that may be uploaded to put in a made image, by MIME type. */
    private const UPLOAD_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * Start a search (mode "find") or a picture (mode "make").
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $slot = $this->slot();
        $mode = $request->getRequiredBodyParam('mode');

        if ($mode === 'find') {
            if (!$plugin->imagePicker->canFind()) {
                return $this->refuse('No photo library is switched on. Turn on Openverse in the settings, or add an Unsplash, Pexels or Pixabay key.');
            }

            $terms = PhotoFinder::terms((string) $request->getBodyParam('words', ''));

            if ($terms === [] && !$plugin->studio->configured() && $slot->title() === '') {
                return $this->refuse('Type what the picture should show.');
            }

            // Where to search ("Search in"), remembered for this person.
            $source = $plugin->stockLibraries->normaliseSource((string) $request->getBodyParam('source', StockLibraries::FREE));
            $editorial = (bool) $request->getBodyParam('editorial', false);
            self::rememberSource($source);

            $request = $plugin->domain->images()->start(ImageRequest::FIND, $plugin->domain->viewer(), $this->details($slot) + ['source' => $source, 'editorial' => $editorial]);
            $request->terms = $terms;
            $plugin->imageStore->save($request);

            FindImages::start(['request' => $request->id]);

            return $this->asJson($this->payload($request));
        }

        if ($mode === 'make') {
            if (!$plugin->imagePicker->canMake()) {
                return $this->refuse('No image provider has an API key. Add OPENAI_API_KEY or GEMINI_API_KEY to your .env file.');
            }

            $made = $plugin->domain->images()->start(ImageRequest::MAKE, $plugin->domain->viewer(), $this->details($slot) + ['direction' => trim((string) $request->getBodyParam('direction', ''))]);

            if ($upload = UploadedFile::getInstanceByName('source')) {
                // Only pictures, by what the file is, not what it is called.
                $mime = (string) \craft\helpers\FileHelper::getMimeType($upload->tempName, checkExtension: false);
                $extension = self::UPLOAD_TYPES[$mime] ?? null;

                if ($extension === null) {
                    return $this->refuse('Add a PNG, JPEG or WebP picture.');
                }

                $plugin->domain->images()->putFile($made->id, StoredFile::SOURCE, new StoredFile((string) file_get_contents($upload->tempName), $mime, $extension));
            }

            MakeImage::start(['request' => $made->id]);

            return $this->asJson($this->payload($made));
        }

        return $this->refuse('Choose to find a photograph or make a picture.');
    }

    /**
     * What the dialog says before anything is asked for: whether there are
     * images in the same place on other entries to match.
     */
    public function actionSlot(): Response
    {
        $request = $this->request;
        $slot = ImageSlot::find(
            (int) $request->getRequiredParam('fieldId'),
            (int) $request->getRequiredParam('elementId'),
            (int) $request->getRequiredParam('siteId'),
            Craft::$app->getUser()->getIdentity(),
        ) ?? throw new NotFoundHttpException('That image field could not be found, or Ghostwriter is not used for this entry.');

        return $this->asJson(['references' => count($slot->references()), 'shape' => $slot->shape()]);
    }

    public function actionStatus(): Response
    {
        return $this->asJson($this->payload($this->mine((string) $this->request->getRequiredParam('id'))));
    }

    /**
     * A picture that has been made, before it is kept.
     */
    public function actionPreview(): Response
    {
        $request = $this->mine((string) $this->request->getRequiredParam('id'));
        $file = $request->file ? Plugin::getInstance()->domain->images()->file($request->id, StoredFile::MADE) : null;

        if ($file === null) {
            throw new NotFoundHttpException();
        }

        return $this->response->sendContentAsFile($file->content, $request->id . '.' . $file->extension, ['mimeType' => $file->mime, 'inline' => true]);
    }

    /**
     * Keep a found photograph, or the picture made, as an asset.
     */
    public function actionUse(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = $this->mine((string) $this->request->getRequiredBodyParam('id'));
        $data = $request->details;
        $slot = ImageSlot::find((int) ($data['fieldId'] ?? 0), (int) ($data['elementId'] ?? 0), (int) ($data['siteId'] ?? 0), Craft::$app->getUser()->getIdentity());

        if (!$slot) {
            return $this->refuse('That image field is no longer on the page.');
        }

        $preview = false;

        try {
            if ($request->mode === ImageRequest::FIND) {
                $source = (string) $this->request->getRequiredBodyParam('source');
                $id = (string) $this->request->getRequiredBodyParam('photo');
                $library = $plugin->stockLibraries->paid()[$source] ?? null;

                if ($library !== null) {
                    // A paid photo goes in as a preview, never as the file.
                    if (!$library instanceof PreviewableLibrary) {
                        return $this->refuse('That library’s photos can’t be previewed here.');
                    }

                    $asset = $plugin->imagePicker->insertPreview($slot, $library, $id);
                    $preview = true;
                } else {
                    $asset = $plugin->imagePicker->keepPhoto($slot, $source, $id);
                }
            } else {
                $file = $request->file ? $plugin->domain->images()->file($request->id, StoredFile::MADE) : null;

                if ($file === null) {
                    return $this->refuse('That picture is no longer here. Make it again.');
                }

                $asset = $plugin->imagePicker->keep($slot, $file->content, $file->extension, [
                    'title' => trim((string) ($data['direction'] ?? '')) !== '' ? mb_substr(trim($data['direction']), 0, 80) : $slot->title(),
                ]);
            }
        } catch (InvalidArgumentException|PhotoUnavailable $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->asJson($this->kept($asset) + ['preview' => $preview]);
    }

    /**
     * The person's last "Search in" choice, kept in their Craft user
     * preferences; the site's default until they choose.
     */
    public static function rememberedSource(): string
    {
        $user = Craft::$app->getUser()->getIdentity();
        $plugin = Plugin::getInstance();
        $source = $user?->getPreference(self::SOURCE_PREFERENCE) ?? $plugin->getSettings()->stockDefaultSource;

        return $plugin->stockLibraries->normaliseSource(is_string($source) ? $source : null);
    }

    private static function rememberSource(string $source): void
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user && $user->getPreference(self::SOURCE_PREFERENCE) !== $source) {
            Craft::$app->getUsers()->saveUserPreferences($user, [self::SOURCE_PREFERENCE => $source]);
        }
    }

    private function slot(): ImageSlot
    {
        $request = $this->request;

        $slot = ImageSlot::find(
            (int) $request->getRequiredBodyParam('fieldId'),
            (int) $request->getRequiredBodyParam('elementId'),
            (int) $request->getRequiredBodyParam('siteId'),
            Craft::$app->getUser()->getIdentity(),
        );

        if (!$slot) {
            throw new NotFoundHttpException('That image field could not be found, or Ghostwriter is not used for this entry.');
        }

        return $slot;
    }

    /**
     * Where the picture is for, kept with the request.
     *
     * @return array<string, mixed>
     */
    private function details(ImageSlot $slot): array
    {
        return [
            'fieldId' => (int) $slot->field->id,
            'elementId' => (int) $slot->element->id,
            'siteId' => (int) $slot->element->siteId,
            'label' => $slot->label(),
        ];
    }

    /**
     * A request, only for the person who made it: anyone else's is not
     * there at all.
     */
    private function mine(string $id): ImageRequest
    {
        $domain = Plugin::getInstance()->domain;

        try {
            return $domain->images()->mine($id, $domain->viewer());
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('That image request could not be found.');
        }
    }

    /**
     * What the field's input needs to show the new asset, and which
     * placeholders it should take out.
     *
     * @return array<string, mixed>
     */
    private function kept(Asset $asset): array
    {
        return [
            'assetId' => (int) $asset->id,
            'title' => (string) $asset->title,
            'placeholderIds' => array_map('intval', Asset::find()->filename(Placeholders::FILENAME)->status(null)->ids()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ImageRequest $request): array
    {
        $details = $request->details;

        $libraries = Plugin::getInstance()->stockLibraries;
        $options = array_map(function(array $photo) use ($libraries): array {
            $source = (string) ($photo['source'] ?? '');
            $paid = isset($libraries->all()[$source]);
            $offer = is_array($photo['offer'] ?? null) ? $photo['offer'] : null;

            $option = array_intersect_key($photo, array_flip(['source', 'id', 'thumb', 'credit', 'credit_url', 'licence', 'term', 'picked', 'reason', 'alt', 'title', 'editorial', 'restrictions']));

            // The demo library calls nobody: its thumbnails are served here.
            if ($source === StockLibraries::DEMO) {
                $option['thumb'] = StockController::demoThumbUrl((string) $photo['id']);
            }

            return $option + [
                'paid' => $paid,
                'source_label' => $libraries->shortLabel($source),
                'cost' => $paid ? (string) ($offer['label'] ?? 'Paid') : 'Free',
                'editorial' => (bool) ($photo['editorial'] ?? false),
            ];
        }, $request->options);

        return [
            'id' => $request->id,
            'mode' => $request->mode,
            'status' => $request->storedStatus(Format::Craft),
            'error' => $request->error,
            'terms' => $request->terms,
            'direction' => $details['direction'] ?? null,
            'source' => $details['source'] ?? StockLibraries::FREE,
            'paidLibraries' => array_values(array_unique(array_map(fn(array $option) => $libraries->standInName((string) $option['source']), array_filter($options, fn(array $option) => $option['paid'])))),
            'options' => $options,
            'judged' => (bool) ($details['judged'] ?? false),
            'noneFit' => (bool) ($details['noneFit'] ?? false),
            'withReferences' => (bool) ($details['withReferences'] ?? false),
            'preview' => $request->isDone() && !empty($request->file)
                ? \craft\helpers\UrlHelper::actionUrl('ghostwriter/images/preview', ['id' => $request->id, 'v' => $request->file])
                : null,
        ];
    }
}
