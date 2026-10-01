<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use nineteenninetyfour\ghostwriter\images\ImageryState;
use nineteenninetyfour\ghostwriter\images\ImageSampler;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Looks at the images each section uses and writes the image style guide
 * from them, a section per heading, replacing any guide already there.
 */
class GenerateImageryGuide extends Job
{
    /** @var array<int, string> */
    public array $sections = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $state = $plugin->imageryState;

        try {
            $sections = [];
            $seen = [];
            $sampler = new ImageSampler();

            foreach ($this->sections as $handle) {
                $section = Craft::$app->getEntries()->getSectionByHandle($handle);
                $samples = $section ? $sampler->samples($handle, $plugin->getSettings()->imageGuideSamples) : [];

                // Two images are too few to call a style.
                if (count($samples) < 3) {
                    continue;
                }

                $name = Craft::t('site', $section->name);
                $sections[] = "## {$name}\n\n" . trim($plugin->studio->analyseImagery($name, $samples));

                foreach ($samples as $sample) {
                    $seen[] = ['title' => $sample['entry'] . ' (' . $sample['label'] . ')', 'section' => $handle];
                }
            }

            if ($sections === []) {
                $state->update(['status' => ImageryState::FAILED, 'error' => 'None of those sections has enough images to describe a style from. It takes at least three.', 'task' => null]);

                return;
            }

            $plugin->imageryGuide->save("# Image style\n\n" . implode("\n\n", $sections));

            $state->update(['status' => ImageryState::IDLE, 'error' => null, 'task' => null, 'messages' => [], 'scanned' => $seen]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $state->update(['status' => ImageryState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing the image style guide');
    }
}
