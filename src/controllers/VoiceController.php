<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use nineteenninetyfour\ghostwriter\jobs\GenerateVoiceGuide;
use nineteenninetyfour\ghostwriter\jobs\RefineVoiceGuide;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\voice\VoiceState;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\Response;

/**
 * The tone of voice guide: generate it from published content, edit it by
 * hand, or refine it by asking for changes. Generating and refining run in
 * the queue, so the screen polls the status action until they finish.
 */
class VoiceController extends Controller
{
    public function actionShow(): Response
    {
        $plugin = Plugin::getInstance();
        $payload = $this->payload();

        $plugin->voiceState->forgetFailure();

        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/_guide', [
            'configured' => $plugin->studio->configured(),
            'provider' => $plugin->studio->provider(),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'sections' => $plugin->scanner->sections(),
            'state' => $payload,
            'guide' => [
                'title' => Craft::t('ghostwriter', 'Voice guide'),
                'nav' => 'voice',
                'actions' => ['status' => 'voice/status', 'scan' => 'voice/scan', 'update' => 'voice/update', 'refine' => 'voice/refine'],
                'labels' => [
                    'scanning' => Craft::t('ghostwriter', 'Reading your published content and writing the guide. This takes a minute or so.'),
                    'empty' => Craft::t('ghostwriter', 'No guide yet. Choose which sections to read, then generate it.'),
                    'read' => Craft::t('ghostwriter', 'Ghostwriter reads the newest published entries from each section you tick.'),
                    'note' => Craft::t('ghostwriter', 'Markdown. Every writing prompt includes this guide as it stands.'),
                    'scanned' => Craft::t('ghostwriter', 'Last written from {count, plural, =1{# entry} other{# entries}}.'),
                    'confirm' => Craft::t('ghostwriter', 'Read the site again and replace the current guide? Any edits you have made to it will be lost.'),
                    'saved' => Craft::t('ghostwriter', 'Voice guide saved'),
                ],
            ],
        ]);
    }

    public function actionStatus(): Response
    {
        return $this->asJson($this->payload());
    }

    public function actionScan(): Response
    {
        $this->requirePostRequest();

        if ($refusal = $this->notReady()) {
            return $refusal;
        }

        $sections = $this->request->getBodyParam('sections');
        $sections = is_array($sections) ? array_values(array_filter($sections, fn($handle) => is_string($handle) && $handle !== '')) : null;

        // Sections were offered and none was ticked: nothing to read. (Not
        // the same as none asked for, which reads the configured set.)
        if ($sections === []) {
            return $this->refuse('Choose at least one section to read.');
        }

        Plugin::getInstance()->voiceState->update(['status' => VoiceState::WORKING, 'error' => null, 'task' => 'scan']);

        GenerateVoiceGuide::start(['sections' => $sections]);

        return $this->asJson($this->payload());
    }

    public function actionUpdate(): Response
    {
        $this->requirePostRequest();

        $document = $this->request->getBodyParam('document');

        if (!is_string($document) || trim($document) === '') {
            return $this->refuse('The guide cannot be empty.');
        }

        if (mb_strlen($document) > 60000) {
            return $this->refuse('The guide is too long to save. Keep it under 60,000 characters.');
        }

        Plugin::getInstance()->voiceGuide->save($document);

        return $this->asJson($this->payload());
    }

    public function actionRefine(): Response
    {
        $this->requirePostRequest();

        if ($refusal = $this->notReady()) {
            return $refusal;
        }

        $plugin = Plugin::getInstance();
        $message = trim((string) $this->request->getBodyParam('message'));

        if (!$plugin->voiceGuide->exists()) {
            return $this->refuse('Generate a voice guide before refining it.');
        }

        if ($message === '' || mb_strlen($message) > 4000) {
            return $this->refuse('Say what to change, in under 4,000 characters.');
        }

        $plugin->voiceState->addMessage('user', $message);
        $plugin->voiceState->update(['status' => VoiceState::WORKING, 'error' => null, 'task' => 'refine']);

        RefineVoiceGuide::start();

        return $this->asJson($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $plugin = Plugin::getInstance();
        $updatedAt = $plugin->voiceGuide->updatedAt();

        return $plugin->voiceState->get() + [
            'document' => $plugin->voiceGuide->get(),
            'exists' => $plugin->voiceGuide->exists(),
            'updatedAt' => $updatedAt ? Craft::$app->getFormatter()->asRelativeTime($updatedAt) : null,
        ];
    }

    private function notReady(): ?Response
    {
        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if (Plugin::getInstance()->voiceState->get()['status'] === VoiceState::WORKING) {
            return $this->refuse('Ghostwriter is still working on the last request.', 409);
        }

        return null;
    }
}
