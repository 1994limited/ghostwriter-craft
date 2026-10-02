<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Has a new picture made for an image field and keeps it beside the
 * request, for the person to look at before it becomes an asset.
 */
class MakeImage extends Job
{
    public string $request = '';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $requests = $plugin->domain->images();
        $request = $requests->find($this->request);

        if (!$request) {
            return;
        }

        try {
            $data = $request->details;
            $slot = ImageSlot::find((int) ($data['fieldId'] ?? 0), (int) ($data['elementId'] ?? 0), (int) ($data['siteId'] ?? 0))
                ?? throw new InvalidArgumentException('That image field is no longer on the page.');

            $uploaded = $requests->file($this->request, StoredFile::SOURCE);
            $source = $uploaded ? Image::fromString($uploaded->content) : null;
            $image = $plugin->imagePicker->make($slot, (string) ($data['direction'] ?? ''), $source);

            $requests->putFile($this->request, StoredFile::MADE, new StoredFile($image->data, $image->mime, $image->extension()));
            $requests->succeed($this->request, ['mime' => $image->mime], $this->request . '.' . $image->extension());
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $requests->fail($this->request, $exception->getMessage());
        } finally {
            $plugin->imageStore->deleteFile($this->request, StoredFile::SOURCE);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Making an image');
    }
}
