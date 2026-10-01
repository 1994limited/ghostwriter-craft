<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\SuggestKinds;
use nineteenninetyfour\ghostwriter\types\KindSuggestions;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\types\ContentType;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\Response;

/**
 * Ghostwriter's home: the voice guide, and the sections it writes for.
 */
class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $guide = $plugin->voiceGuide;
        $presenter = new Presenter();

        $checking = $this->checkForKinds();

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
                'kinds' => $plugin->kinds->get($section->handle),
                'types' => array_values(array_map(fn(ContentType $type) => [
                    'title' => $type->title,
                    'description' => $type->description,
                    'examples' => count($type->examples),
                    'url' => \craft\helpers\UrlHelper::cpUrl('ghostwriter/types/' . $type->handle),
                ], $plugin->types->forSection($section->handle))),
            ], $plugin->types->sections()),
            'sessions' => array_map(fn($session) => $presenter->summary($session), array_slice($plugin->sessions->forUser((int) Craft::$app->getUser()->getId()), 0, 30)),
            'checking' => $checking,
            'setup' => $plugin->onboarding->progress(),
            'nextStep' => $this->nextStep(),
            'imagery' => ['exists' => $plugin->imageryGuide->exists(), 'updatedAt' => $plugin->imageryGuide->updatedAt()],
            'planOpen' => count(array_filter($plugin->ideas->all(), fn(array $idea) => $idea['status'] === \nineteenninetyfour\ghostwriter\planning\IdeaRepository::OPEN)),
            'autoKinds' => $plugin->getSettings()->suggestKindsAutomatically,
            'isAdmin' => Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * The automatic check: sections never looked at, or with enough
     * published since the last look, are queued to have their kinds
     * suggested. Only with a key, and only when the setting is on.
     *
     * @return array<int, string> The sections queued.
     */
    /**
     * The first setup step still to do, with its place in the list.
     *
     * @return array<string, mixed>|null
     */
    private function nextStep(): ?array
    {
        foreach (Plugin::getInstance()->onboarding->steps() as $i => $step) {
            if (!$step['done']) {
                return $step + ['number' => $i + 1];
            }
        }

        return null;
    }

    private function checkForKinds(): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->suggestKindsAutomatically || !$plugin->studio->configured()) {
            return [];
        }

        $due = array_values(array_filter($plugin->types->sections(), fn($section) => $plugin->kinds->due($section)));
        $handles = array_map(fn($section) => $section->handle, $due);

        foreach ($handles as $handle) {
            $plugin->kinds->update($handle, ['status' => KindSuggestions::WORKING, 'error' => null]);
        }

        if ($handles !== []) {
            SuggestKinds::start(['sections' => $handles]);
        }

        return $handles;
    }
}
