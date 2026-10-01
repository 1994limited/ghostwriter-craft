<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\voice\VoiceState;
use Throwable;

/**
 * Applies the latest request in the voice guide's refine conversation.
 */
class RefineVoiceGuide extends Job
{
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $state = $plugin->voiceState;

        try {
            $messages = $state->get()['messages'];
            $request = array_pop($messages);

            $response = $plugin->studio->refineVoice($plugin->voiceGuide->get(), $messages, (string) ($request['content'] ?? ''));

            if ($response->document !== null) {
                $plugin->voiceGuide->save($response->document);
            }

            $state->addMessage('assistant', $response->reply !== '' ? $response->reply : 'Done.');
            $state->update(['status' => VoiceState::IDLE, 'error' => null, 'task' => null]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');

            $state->update(['status' => VoiceState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Updating the tone of voice guide');
    }
}
