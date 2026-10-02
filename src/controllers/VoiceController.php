<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use nineteenninetyfour\ghostwriter\jobs\GenerateVoiceGuide;
use nineteenninetyfour\ghostwriter\jobs\RefineVoiceGuide;
use nineteenninetyfour\ghostwriter\Plugin;
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
        // A failure stays on screen until the next run, so a job that
        // failed while the person was away still explains itself.
        $payload = $this->payload();

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
                    'headingNew' => Craft::t('ghostwriter', 'Generate from your content'),
                    'headingAgain' => Craft::t('ghostwriter', 'Read the site again'),
                    'generate' => Craft::t('ghostwriter', 'Write the voice guide'),
                    'rescan' => Craft::t('ghostwriter', 'Rescan and rewrite'),
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

        Plugin::getInstance()->domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->begin('scan'));

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

        Plugin::getInstance()->domain->saveGuide(Guide::VOICE, $document);

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

        if (!$plugin->domain->guide(Guide::VOICE)->exists()) {
            return $this->refuse('Generate a voice guide before refining it.');
        }

        if ($message === '' || mb_strlen($message) > 4000) {
            return $this->refuse('Say what to change, in under 4,000 characters.');
        }

        $plugin->domain->changeGuideState(Guide::VOICE, function(GuideState $state) use ($message): void {
            $state->begin('refine');
            $state->addMessage('user', $message);
        });

        RefineVoiceGuide::start();

        return $this->asJson($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $domain = Plugin::getInstance()->domain;
        $guide = $domain->guide(Guide::VOICE);

        return $domain->guideState(Guide::VOICE)->toArray() + [
            'document' => $guide->body,
            'exists' => $guide->exists(),
            'updatedAt' => $guide->updatedAt ? Craft::$app->getFormatter()->asRelativeTime($guide->updatedAt) : null,
        ];
    }

    private function notReady(): ?Response
    {
        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if (Plugin::getInstance()->domain->guideState(Guide::VOICE)->isWorking()) {
            return $this->refuse('Ghostwriter is still working on the last request.', 409);
        }

        return null;
    }
}
