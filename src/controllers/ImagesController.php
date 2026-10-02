<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\UploadedFile;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use nineteenninetyfour\ghostwriter\images\ImageRequests;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\images\Placeholders;
use nineteenninetyfour\ghostwriter\jobs\FindImages;
use nineteenninetyfour\ghostwriter\jobs\MakeImage;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Behind the Ghostwriter button on an image field: find photographs or have
 * a picture made, and turn the one chosen into an asset the field's own
 * input then shows.
 */
class ImagesController extends Controller
{
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

            $data = $plugin->imageRequests->create($this->owner($slot) + ['mode' => 'find', 'terms' => $terms]);
            FindImages::start(['request' => $data['id']]);

            return $this->asJson($this->payload($data));
        }

        if ($mode === 'make') {
            if (!$plugin->imagePicker->canMake()) {
                return $this->refuse('No image provider has an API key. Add OPENAI_API_KEY or GEMINI_API_KEY to your .env file.');
            }

            $data = $plugin->imageRequests->create($this->owner($slot) + ['mode' => 'make', 'direction' => trim((string) $request->getBodyParam('direction', ''))]);

            if ($upload = UploadedFile::getInstanceByName('source')) {
                // Only pictures, by what the file is, not what it is called.
                $mime = (string) \craft\helpers\FileHelper::getMimeType($upload->tempName, checkExtension: false);
                $extension = self::UPLOAD_TYPES[$mime] ?? null;

                if ($extension === null) {
                    return $this->refuse('Add a PNG, JPEG or WebP picture.');
                }

                $plugin->imageRequests->putFile($data['id'], 'source', (string) file_get_contents($upload->tempName), $mime, $extension);
            }

            MakeImage::start(['request' => $data['id']]);

            return $this->asJson($this->payload($data));
        }

        return $this->refuse('Choose to find a photograph or make a picture.');
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
        $data = $this->mine((string) $this->request->getRequiredParam('id'));
        $file = $data['file'] ? Plugin::getInstance()->imageRequests->file($data['id'], 'made') : null;

        if ($file === null) {
            throw new NotFoundHttpException();
        }

        return $this->response->sendContentAsFile($file['content'], $data['id'] . '.' . $file['extension'], ['mimeType' => $file['mime'], 'inline' => true]);
    }

    /**
     * Keep a found photograph, or the picture made, as an asset.
     */
    public function actionUse(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $data = $this->mine((string) $this->request->getRequiredBodyParam('id'));
        $slot = ImageSlot::find((int) $data['fieldId'], (int) $data['elementId'], (int) $data['siteId'], Craft::$app->getUser()->getIdentity());

        if (!$slot) {
            return $this->refuse('That image field is no longer on the page.');
        }

        try {
            if ($data['mode'] === 'find') {
                $asset = $plugin->imagePicker->keepPhoto($slot, (string) $this->request->getRequiredBodyParam('source'), (string) $this->request->getRequiredBodyParam('photo'));
            } else {
                $file = $data['file'] ? $plugin->imageRequests->file($data['id'], 'made') : null;

                if ($file === null) {
                    return $this->refuse('That picture is no longer here. Make it again.');
                }

                $asset = $plugin->imagePicker->keep($slot, $file['content'], $file['extension'], [
                    'title' => trim((string) ($data['direction'] ?? '')) !== '' ? mb_substr(trim($data['direction']), 0, 80) : $slot->title(),
                ]);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->asJson($this->kept($asset));
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
     * @return array<string, int>
     */
    private function owner(ImageSlot $slot): array
    {
        return [
            'userId' => (int) Craft::$app->getUser()->getId(),
            'fieldId' => (int) $slot->field->id,
            'elementId' => (int) $slot->element->id,
            'siteId' => (int) $slot->element->siteId,
            'label' => $slot->label(),
        ];
    }

    /**
     * A request, only for the person who made it.
     *
     * @return array<string, mixed>
     */
    private function mine(string $id): array
    {
        $data = Plugin::getInstance()->imageRequests->find($id);

        if (!$data || (int) $data['userId'] !== (int) Craft::$app->getUser()->getId()) {
            throw new NotFoundHttpException('That image request could not be found.');
        }

        return $data;
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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'id' => $data['id'],
            'mode' => $data['mode'],
            'status' => $data['status'],
            'error' => $data['error'],
            'terms' => $data['terms'] ?? [],
            'direction' => $data['direction'] ?? null,
            'options' => array_map(fn(array $photo) => array_intersect_key($photo, array_flip(['source', 'id', 'thumb', 'credit', 'credit_url', 'licence', 'term', 'picked', 'reason', 'alt'])), $data['options'] ?? []),
            'judged' => (bool) ($data['judged'] ?? false),
            'noneFit' => (bool) ($data['noneFit'] ?? false),
            'withReferences' => (bool) ($data['withReferences'] ?? false),
            'preview' => $data['status'] === ImageRequests::READY && !empty($data['file'])
                ? \craft\helpers\UrlHelper::actionUrl('ghostwriter/images/preview', ['id' => $data['id'], 'v' => $data['file']])
                : null,
        ];
    }
}
