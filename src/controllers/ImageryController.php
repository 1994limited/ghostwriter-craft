<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\images\ImageryState;
use nineteenninetyfour\ghostwriter\jobs\GenerateImageryGuide;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\Response;

/**
 * The image style guide's screen. It is the voice guide's screen with other
 * words on it: generate from what the site has, then correct by hand.
 */
class ImageryController extends Controller
{
    public function actionShow(): Response
    {
        $plugin = Plugin::getInstance();
        $payload = $this->payload();

        $plugin->imageryState->forgetFailure();

        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/_guide', [
            'configured' => $plugin->studio->configured(),
            'provider' => $plugin->studio->provider(),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'sections' => array_map(fn($section) => [
                'handle' => $section->handle,
                'title' => Craft::t('site', $section->name),
                'entries' => (int) Entry::find()->section($section->handle)->status('live')->count(),
                'selected' => true,
            ], $plugin->types->sections()),
            'state' => $payload,
            'guide' => [
                'title' => Craft::t('ghostwriter', 'Image style'),
                'nav' => 'imagery',
                'actions' => ['status' => 'imagery/status', 'scan' => 'imagery/scan', 'update' => 'imagery/update', 'refine' => null],
                'labels' => [
                    'scanning' => Craft::t('ghostwriter', 'Looking at the images your entries use and describing their style. This takes a minute or so.'),
                    'empty' => Craft::t('ghostwriter', 'No guide yet. Choose which sections to look at, then generate it.'),
                    'read' => Craft::t('ghostwriter', 'Ghostwriter looks at the images used by the newest published entries in each section you tick, and writes a section for each.'),
                    'note' => Craft::t('ghostwriter', 'Markdown, with a ## heading for each section. Read whenever images are searched for, chosen or made.'),
                    'scanned' => Craft::t('ghostwriter', 'Last written from {count} images.'),
                    'confirm' => Craft::t('ghostwriter', 'Look at the site again and replace the current guide? Any edits you have made to it will be lost.'),
                    'saved' => Craft::t('ghostwriter', 'Image style saved'),
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

        $plugin = Plugin::getInstance();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($plugin->imageryState->get()['status'] === ImageryState::WORKING) {
            return $this->refuse('Ghostwriter is still working on the last request.', 409);
        }

        $sections = array_values(array_filter((array) $this->request->getBodyParam('sections'), fn($handle) => is_string($handle) && $plugin->types->enabled($handle)));

        if ($sections === []) {
            return $this->refuse('Choose at least one section to look at.');
        }

        $plugin->imageryState->update(['status' => ImageryState::WORKING, 'error' => null, 'task' => 'scan']);

        GenerateImageryGuide::start(['sections' => $sections]);

        return $this->asJson($this->payload());
    }

    public function actionUpdate(): Response
    {
        $this->requirePostRequest();

        $document = $this->request->getBodyParam('document');

        if (!is_string($document) || trim($document) === '' || mb_strlen($document) > 60000) {
            return $this->refuse('The guide cannot be empty, and must be under 60,000 characters.');
        }

        Plugin::getInstance()->imageryGuide->save($document);

        return $this->asJson($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $plugin = Plugin::getInstance();
        $updatedAt = $plugin->imageryGuide->updatedAt();

        return $plugin->imageryState->get() + [
            'document' => $plugin->imageryGuide->get(),
            'exists' => $plugin->imageryGuide->exists(),
            'updatedAt' => $updatedAt ? Craft::$app->getFormatter()->asRelativeTime($updatedAt) : null,
        ];
    }
}
