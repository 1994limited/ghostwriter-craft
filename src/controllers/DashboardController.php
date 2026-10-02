<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\types\ContentType;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\Response;

/**
 * Ghostwriter's home: the voice guide, and the sections it writes for.
 * Opening it asks the model nothing: kinds are suggested by themselves only
 * during Get started, and here only when someone asks.
 */
class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $guide = $plugin->voiceGuide;
        $presenter = new Presenter();

        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/index', [
            'configured' => $plugin->studio->configured(),
            'provider' => $plugin->studio->provider(),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'voice' => [
                'exists' => $guide->exists(),
                'updatedAt' => $guide->updatedAt(),
            ],
            'sections' => array_map(fn($section) => [
                'handle' => $section->handle,
                'name' => Craft::t('site', $section->name),
                'entries' => (int) Entry::find()->section($section->handle)->status(null)->count(),
                'learning' => $plugin->typeState->get($section->handle),
                'kinds' => ['suggestions' => $plugin->kinds->presented($section->handle)] + $plugin->kinds->get($section->handle),
                'types' => array_values(array_map(fn(ContentType $type) => [
                    'title' => $type->title,
                    'description' => $type->description,
                    'examples' => count($type->examples),
                    'url' => \craft\helpers\UrlHelper::cpUrl('ghostwriter/types/' . $type->handle),
                ], $plugin->types->forSection($section->handle))),
            ], $plugin->types->sections()),
            'sessions' => array_map(fn($session) => $presenter->summary($session), array_slice($plugin->sessions->visibleTo((int) Craft::$app->getUser()->getId()), 0, 30)),
            'setup' => $plugin->onboarding->progress(),
            'canHideSetup' => $plugin->onboarding->canToggle(),
            'nextStep' => $plugin->onboarding->nextStep(),
            'imagery' => ['exists' => $plugin->imageryGuide->exists(), 'updatedAt' => $plugin->imageryGuide->updatedAt()],
            'planOpen' => count(array_filter($plugin->ideas->all(), fn(array $idea) => $idea['status'] === \nineteenninetyfour\ghostwriter\planning\IdeaRepository::OPEN)),
            'isAdmin' => Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }
}
