<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\images\ImageRequests;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Searches the photo libraries for an image field: with the searches the
 * person typed, or ones the model chooses from the block and page, and has
 * the model pick out the photographs that suit the page and the site's own
 * (ghostwriter-core's PhotoFinder).
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

            $results = $plugin->imagePicker->find($slot, $data['terms'] ?? []);

            if ($results->terms === []) {
                throw new InvalidArgumentException('There was nothing to search for. Type what the picture should show.');
            }

            $found = $results->toArray();

            $requests->update($this->request, [
                'status' => ImageRequests::READY,
                'terms' => $found['terms'],
                'options' => $found['photos'],
                'judged' => $found['judged'],
                'noneFit' => $found['none_fit'],
                'withReferences' => $found['with_references'],
                'error' => $results->isEmpty() ? 'Nothing was found for those searches. Try other words.' : null,
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
