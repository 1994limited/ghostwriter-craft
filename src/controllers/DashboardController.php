<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\Plugin;
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
        $guide = $plugin->domain->guide(Guide::VOICE);
        $imagery = $plugin->domain->guide(Guide::IMAGERY);
        $presenter = new Presenter();

        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/index', [
            'configured' => $plugin->studio->configured(),
            'provider' => $plugin->studio->provider(),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'voice' => [
                'exists' => $guide->exists(),
                'updatedAt' => $guide->updatedAt,
            ],
            'sections' => array_map(fn($section) => [
                'handle' => $section->handle,
                'name' => Craft::t('site', $section->name),
                'entries' => (int) Entry::find()->section($section->handle)->status(null)->count(),
                'learning' => $plugin->types->analysis($section->handle)->toArray(),
                'kinds' => ['suggestions' => $plugin->types->presented($section->handle)] + $plugin->types->suggestions($section->handle)->toArray(),
                'types' => array_values(array_map(fn(ContentType $type) => [
                    'title' => $type->title,
                    'description' => $type->description,
                    'examples' => count($type->examples),
                    'url' => \craft\helpers\UrlHelper::cpUrl('ghostwriter/types/' . $type->handle),
                ], $plugin->types->forSection($section->handle))),
            ], $plugin->types->sections()),
            'sessions' => array_map(fn($session) => $presenter->summary($session), array_slice($plugin->domain->sessions()->visible($plugin->domain->viewer()), 0, 30)),
            'setup' => $plugin->onboarding->progress(),
            'canHideSetup' => $plugin->onboarding->canToggle(),
            'nextStep' => $plugin->onboarding->nextStep(),
            'imagery' => ['exists' => $imagery->exists(), 'updatedAt' => $imagery->updatedAt],
            'planOpen' => count(array_filter($plugin->domain->ideas(), fn(Idea $idea) => $idea->isOpen())),
            'isAdmin' => Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }
}
