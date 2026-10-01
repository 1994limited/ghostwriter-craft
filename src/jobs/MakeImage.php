<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\ai\Image;
use nineteenninetyfour\ghostwriter\images\ImageRequests;
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
        $requests = $plugin->imageRequests;
        $data = $requests->find($this->request);

        if (!$data) {
            return;
        }

        try {
            $slot = ImageSlot::find((int) $data['fieldId'], (int) $data['elementId'], (int) $data['siteId'])
                ?? throw new InvalidArgumentException('That image field is no longer on the page.');

            $uploaded = $requests->file($this->request, 'source');
            $source = $uploaded ? Image::fromString($uploaded['content']) : null;
            $image = $plugin->imagePicker->make($slot, (string) ($data['direction'] ?? ''), $source);

            $requests->putFile($this->request, 'made', $image->data, $image->mime, $image->extension());
            $requests->update($this->request, ['status' => ImageRequests::READY, 'file' => $this->request . '.' . $image->extension(), 'mime' => $image->mime]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $requests->update($this->request, ['status' => ImageRequests::FAILED, 'error' => $exception->getMessage()]);
        } finally {
            $requests->deleteFile($this->request, 'source');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Making an image');
    }
}
