<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Reads a sample of the site's published content and writes the tone of
 * voice guide from it, replacing any guide already there.
 */
class GenerateVoiceGuide extends Job
{
    /** @var string[]|null Section handles; null reads the configured set. */
    public ?array $sections = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $domain = $plugin->domain;

        try {
            $samples = $plugin->scanner->samples($this->sections);

            if ($samples === []) {
                $domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->fail('There is no published content long enough to learn a voice from. Publish a few entries, or pick different sections.'));

                return;
            }

            $response = $plugin->studio->analyseVoice($samples);

            $domain->saveGuide(Guide::VOICE, (string) $response->document);

            $domain->changeGuideState(Guide::VOICE, function(GuideState $state) use ($samples): void {
                $state->succeed();
                $state->messages = [];
                $state->scanned = array_map(fn(array $sample) => ['title' => $sample['title'], 'section' => $sample['section']], $samples);
            });
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->fail($exception->getMessage()));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing the tone of voice guide');
    }
}
