<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * The "Write with Ghostwriter" button on an entry's edit screen, and the
 * script that opens the panel in a modal over the form, so the editor
 * never leaves the page.
 */
class Launcher
{
    /**
     * The button's HTML, or nothing where Ghostwriter has no business: not
     * the control panel, not a section it writes for, not someone who may
     * use it and save this entry.
     */
    public static function buttonFor(Entry $entry): string
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $section = $entry->getSection();

        if (!$request->getIsCpRequest() || !$user || !$section) {
            return '';
        }

        if (!$user->can(Plugin::PERMISSION) || !$plugin->types->enabled($section->handle) || !Craft::$app->getElements()->canSave($entry, $user)) {
            return '';
        }

        // A new entry is written; one that exists already is edited, its
        // content as it stands becoming the draft.
        $editing = !$entry->getIsUnpublishedDraft();

        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);

        $config = [
            'section' => $section->handle,
            'entryType' => count($section->getEntryTypes()) > 1 ? $entry->getType()->handle : null,
            'elementId' => (int) $entry->id,
            'siteId' => (int) $entry->siteId,
            // Opened from the dashboard or the plan: "new", or a session to resume.
            'open' => $request->getQueryParam('ghostwriter'),
            // The conversation already going for this entry, if there is one,
            // so coming back to the entry, or reloading it, carries on there.
            'current' => self::currentSession((int) $entry->getCanonicalId()),
            'idea' => $request->getQueryParam('idea'),
            'dashboardUrl' => UrlHelper::cpUrl('ghostwriter'),
            'icon' => (string) file_get_contents(__DIR__ . '/mark.svg'),
            'editing' => $editing,
        ];

        $view->registerJs('new Ghostwriter.Launcher(' . Json::encode($config) . ');');

        return Html::button(Html::tag('span', '', ['class' => 'gw-mark', 'aria-hidden' => 'true']) . Html::encode($editing ? Craft::t('ghostwriter', 'Edit with Ghostwriter') : Craft::t('ghostwriter', 'Write with Ghostwriter')), [
            'type' => 'button',
            'class' => 'btn',
            'id' => 'ghostwriter-launch',
        ]);
    }

    /**
     * A "Write with Ghostwriter" button beside "New entry" on the entry
     * index, for the sections Ghostwriter writes for. Craft has no slot for
     * a button there, so the script adds it once the index has drawn its
     * own, and again whenever another section is chosen.
     */
    public static function registerIndexButton(): void
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();

        if (!Craft::$app->getRequest()->getIsCpRequest() || !$user || !$user->can(Plugin::PERMISSION)) {
            return;
        }

        $sections = [];

        foreach ($plugin->types->sections() as $section) {
            $sections[$section->handle] = UrlHelper::cpUrl("ghostwriter/write/{$section->handle}");
        }

        if ($sections === []) {
            return;
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);
        $view->registerJs('new Ghostwriter.IndexButton(' . Json::encode([
            'sections' => $sections,
            'label' => Craft::t('ghostwriter', 'Write with Ghostwriter'),
        ]) . ');');
    }

    /**
     * The newest conversation for this entry that is not finished with.
     */
    private static function currentSession(int $elementId): ?string
    {
        $domain = Plugin::getInstance()->domain;

        foreach ($domain->sessions()->visible($domain->viewer()) as $session) {
            if ($session->recordId === $elementId && !$session->isEditing()) {
                return $session->id;
            }
        }

        return null;
    }
}
