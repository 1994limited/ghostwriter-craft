<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\images\ImageRequests;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Searches the photo libraries for an image field: with the searches the
 * person typed, or ones the model chooses from the block and page, and has
 * the model pick out the photographs that suit the site's own.
 */
class FindImages extends Job
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

            $terms = $data['terms'] ?: $plugin->imagePicker->searchTerms($slot);

            if ($terms === []) {
                throw new InvalidArgumentException('There was nothing to search for. Type what the picture should show.');
            }

            $requests->update($this->request, ['terms' => $terms]);

            $options = $plugin->imagePicker->shortlist($slot, $terms);

            $requests->update($this->request, [
                'status' => ImageRequests::READY,
                'options' => $options,
                'error' => $options === [] ? 'Nothing was found for those searches. Try other words.' : null,
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $requests->update($this->request, ['status' => ImageRequests::FAILED, 'error' => $exception->getMessage()]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Finding photographs');
    }
}
