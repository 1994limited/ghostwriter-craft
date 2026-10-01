<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\helpers\FileHelper;
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

            $source = !empty($data['source']) && is_file($data['source']) ? Image::fromPath($data['source']) : null;
            $image = $plugin->imagePicker->make($slot, (string) ($data['direction'] ?? ''), $source);
            $path = $requests->file($this->request, $image->extension());

            FileHelper::writeToFile($path, $image->data);

            $requests->update($this->request, ['status' => ImageRequests::READY, 'file' => basename($path), 'mime' => $image->mime]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $requests->update($this->request, ['status' => ImageRequests::FAILED, 'error' => $exception->getMessage()]);
        } finally {
            if (!empty($data['source']) && is_file($data['source'])) {
                FileHelper::unlink($data['source']);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Making an image');
    }
}
