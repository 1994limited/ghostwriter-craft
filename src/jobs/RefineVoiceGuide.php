<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Applies the latest request in the voice guide's refine conversation.
 */
class RefineVoiceGuide extends Job
{
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $domain = $plugin->domain;

        try {
            $messages = $domain->guideState(Guide::VOICE)->messages;
            $request = array_pop($messages);

            $response = $plugin->studio->refineVoice($domain->guide(Guide::VOICE)->body, $messages, (string) ($request['content'] ?? ''));

            if ($response->document !== null) {
                $domain->saveGuide(Guide::VOICE, $response->document);
            }

            $domain->changeGuideState(Guide::VOICE, function(GuideState $state) use ($response): void {
                $state->addMessage('assistant', $response->reply !== '' ? $response->reply : 'Done.');
                $state->succeed();
            });
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->fail($exception->getMessage()));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Updating the tone of voice guide');
    }
}
