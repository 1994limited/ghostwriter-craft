<?php

namespace nineteenninetyfour\ghostwriter\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Cp;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\planning\IdeaRepository;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * Ghostwriter on Craft's own dashboard: what is being written, what the
 * content plan has waiting, and a quick way to start something new.
 */
class GhostwriterWidget extends Widget
{
    /** Pieces in progress listed. */
    public int $limit = 5;

    public static function displayName(): string
    {
        return 'Ghostwriter';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission(Plugin::PERMISSION);
    }

    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    public function getTitle(): ?string
    {
        return 'Ghostwriter';
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 20];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Cp::textFieldHtml([
            'label' => Craft::t('ghostwriter', 'Pieces in progress to list'),
            'id' => 'limit',
            'name' => 'limit',
            'type' => 'number',
            'min' => 1,
            'max' => 20,
            'value' => $this->limit,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION)) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $presenter = new Presenter();
        $sessions = array_map(fn($session) => $presenter->summary($session), $plugin->sessions->forUser((int) Craft::$app->getUser()->getId()));
        $inProgress = array_values(array_filter($sessions, fn(array $summary) => !$summary['finished']));

        Craft::$app->getView()->registerAssetBundle(GhostwriterAsset::class);

        return Craft::$app->getView()->renderTemplate('ghostwriter/_widget', [
            'inProgress' => array_slice($inProgress, 0, $this->limit),
            'moreInProgress' => max(0, count($inProgress) - $this->limit),
            'planOpen' => count(array_filter($plugin->ideas->all(), fn(array $idea) => $idea['status'] === IdeaRepository::OPEN)),
            'setup' => $plugin->onboarding->progress(),
            'nextStep' => $plugin->onboarding->nextStep(),
            'configured' => $plugin->studio->configured(),
            'sections' => array_map(fn($section) => ['handle' => $section->handle, 'name' => Craft::t('site', $section->name)], $plugin->types->sections()),
        ]);
    }
}
