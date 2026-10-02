<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
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
        $requests = $plugin->domain->images();
        $request = $requests->find($this->request);

        if (!$request) {
            return;
        }

        try {
            $data = $request->details;
            $slot = ImageSlot::find((int) ($data['fieldId'] ?? 0), (int) ($data['elementId'] ?? 0), (int) ($data['siteId'] ?? 0))
                ?? throw new InvalidArgumentException('That image field is no longer on the page.');

            $results = $plugin->imagePicker->find($slot, $request->terms);

            if ($results->terms === []) {
                throw new InvalidArgumentException('There was nothing to search for. Type what the picture should show.');
            }

            $found = $results->toArray();

            $requests->change($this->request, function(ImageRequest $request) use ($found, $results): void {
                $request->succeed([
                    'terms' => $found['terms'],
                    'options' => $found['photos'],
                    'judged' => $found['judged'],
                    'noneFit' => $found['none_fit'],
                    'withReferences' => $found['with_references'],
                ]);

                // Ready, with nothing to choose from.
                $request->error = $results->isEmpty() ? 'Nothing was found for those searches. Try other words.' : null;
            });
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $requests->fail($this->request, $exception->getMessage());
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Finding photographs');
    }
}
