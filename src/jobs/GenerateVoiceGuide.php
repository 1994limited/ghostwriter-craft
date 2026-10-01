<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\voice\VoiceState;
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
        $state = $plugin->voiceState;

        try {
            $samples = $plugin->scanner->samples($this->sections);

            if ($samples === []) {
                $state->update(['status' => VoiceState::FAILED, 'error' => 'There is no published content long enough to learn a voice from. Publish a few entries, or pick different sections.', 'task' => null]);

                return;
            }

            $response = $plugin->studio->analyseVoice($samples);

            $plugin->voiceGuide->save((string) $response->document);

            $state->update([
                'status' => VoiceState::IDLE,
                'error' => null,
                'task' => null,
                'messages' => [],
                'scanned' => array_map(fn(array $sample) => ['title' => $sample['title'], 'section' => $sample['section']], $samples),
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $state->update(['status' => VoiceState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing the tone of voice guide');
    }
}
