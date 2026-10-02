<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\Response;

/**
 * The Get started screen, and hiding or showing it on the dashboard.
 */
class SetupController extends Controller
{
    public function actionShow(): Response
    {
        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/setup', ['state' => $this->payload(), 'canHide' => Plugin::getInstance()->onboarding->canToggle()]);
    }

    public function actionStatus(): Response
    {
        return $this->asJson($this->payload());
    }

    /**
     * Save the sections chosen in the wizard: where Ghostwriter writes, and
     * which it reads to learn the voice. These are plugin settings, so only
     * an admin may, on an environment that allows changes.
     */
    public function actionSections(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $all = array_map(fn($section) => $section->handle, \Craft::$app->getEntries()->getAllSections());
        $pick = fn(string $key) => array_values(array_intersect($all, (array) $this->request->getBodyParam($key)));

        $writeFor = $pick('sections');
        $voice = $pick('voiceSections');

        // Every section ticked is the same as none chosen: all of them,
        // including any added later.
        $settings = [
            'sections' => count($writeFor) === count($all) ? [] : $writeFor,
            'voiceSections' => count($voice) === count($all) ? [] : $voice,
        ];

        if ($writeFor === []) {
            return $this->refuse('Choose at least one section to write for.');
        }

        if (!\Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            return $this->refuse('The sections could not be saved.');
        }

        return $this->asJson($this->payload());
    }

    /**
     * Hide or show Get started, for the whole site: a manager's call.
     */
    public function actionHide(): Response
    {
        $this->requirePostRequest();

        if (!Plugin::getInstance()->onboarding->canToggle()) {
            throw new \yii\web\ForbiddenHttpException('Only an admin can hide or show Get started.');
        }

        Plugin::getInstance()->onboarding->hide((bool) $this->request->getBodyParam('hidden', true));

        return $this->asJson($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $onboarding = Plugin::getInstance()->onboarding;

        return ['steps' => $onboarding->steps(), 'progress' => $onboarding->progress(), 'details' => $onboarding->details(), 'configured' => Plugin::getInstance()->studio->configured()];
    }
}
