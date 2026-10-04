<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * The "Write with Ghostwriter" button on an entry's edit screen, and the
 * script that opens the panel in a modal over the form, so the editor
 * never leaves the page.
 *
 * Beside it, a disclosure menu whose button carries one count: what is
 * left to finish plus the suggestions to review, amber while anything is
 * left to finish, plain with only suggestions, none at 0. The menu lists
 * Finish this page and Review suggestions with their counts, each only
 * while it has one, then Ghostwriter's own items. The guides report their
 * counts as `ghostwriter:counts` events on the document (ghostwriter.js
 * paints them), and the rows open them with `ghostwriter:finish-show` and
 * `ghostwriter:suggest-show`.
 */
class Launcher
{
    /** The keyed strings the panel uses for the brief card. */
    public const BRIEF_STRINGS = [
        'brief.ask', 'brief.filling', 'brief.filled', 'brief.failed', 'brief.region', 'brief.title', 'brief.model-on',
        'brief.agree', 'brief.try-again', 'brief.show', 'brief.hide', 'brief.save', 'brief.saved',
    ];

    /** The menu's words, for the count read out (ghostwriter.js). */
    public const MENU_STRINGS = [
        '{count} to finish', '{count} suggestions', '1 suggestion', '{count} items: {finish}, {suggestions}',
    ];

    /**
     * The button and its menu, or nothing where Ghostwriter has no
     * business: not the control panel, not a section it writes for, not
     * someone who may use it and save this entry. `$finish` says whether
     * Finish this page is on the screen (FinishGuide::register()): its row
     * is in the menu, and without the button the menu is there on its own.
     */
    public static function buttonFor(Entry $entry, bool $finish = false): string
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $section = $entry->getSection();

        if (!$request->getIsCpRequest() || !$user || !$section) {
            return $finish ? self::menu(null) : '';
        }

        if (!$user->can(Plugin::PERMISSION) || !$plugin->types->enabled($section->handle) || !Craft::$app->getElements()->canSave($entry, $user)) {
            return $finish ? self::menu(null) : '';
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
            // The Preview tab: the draft as the page it makes.
            'preview' => $plugin->getSettings()->preview,
        ];

        // The brief in the conversation's words, by key (core's brief.php).
        $view->registerTranslations('ghostwriter', self::BRIEF_STRINGS);
        $view->registerJs('new Ghostwriter.Launcher(' . Json::encode($config) . ');');

        $label = $editing ? Craft::t('ghostwriter', 'Edit with Ghostwriter') : Craft::t('ghostwriter', 'Write with Ghostwriter');

        // Joined with its menu, like Craft's own Save button.
        return Html::tag('div', Html::button(Html::tag('span', '', ['class' => 'gw-mark', 'aria-hidden' => 'true']) . Html::tag('span', Html::encode($label), ['class' => 'gw-launch-label']), [
            'type' => 'button',
            'class' => 'btn',
            'id' => 'ghostwriter-launch',
            'title' => $label,
        ]) . self::menu($label), ['class' => 'btngroup gw-launch']);
    }

    /**
     * The menu: the two count rows (hidden until their guide has a count),
     * then Write or Edit with Ghostwriter when there is a button to open
     * the panel. Its button shows the count before Craft's chevron.
     */
    private static function menu(?string $launch): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);
        $view->registerTranslations('ghostwriter', self::MENU_STRINGS);

        $row = fn(string $open, string $label, string $tone) => [
            'html' => Html::tag('span', Html::encode($label), ['class' => 'gw-menu-row-label'])
                . Html::tag('span', '', ['class' => "gw-count gw-count--{$tone}", 'data-gw-menu-count' => $open, 'aria-hidden' => 'true']),
            'icon' => $open === 'finish' ? 'list-check' : 'lightbulb',
            'liAttributes' => ['class' => 'hidden', 'data-gw-menu-row' => $open],
            'attributes' => ['class' => ['gw-menu-row'], 'data' => ['gw-menu-open' => $open]],
        ];

        $items = [[
            'type' => 'group',
            // Until a guide has a count (ghostwriter.js shows it).
            'hidden' => true,
            'items' => [
                $row('finish', Craft::t('ghostwriter', 'Finish this page'), 'finish'),
                $row('suggest', Craft::t('ghostwriter', 'Review suggestions'), 'suggest'),
            ],
        ]];

        if ($launch !== null) {
            $items[] = ['hr' => true];
            $items[] = ['label' => $launch, 'attributes' => ['data' => ['gw-launch' => true]]];
        }

        $name = $launch !== null ? Craft::t('ghostwriter', 'More ways to edit with Ghostwriter') : Craft::t('ghostwriter', 'Ghostwriter');

        return Cp::disclosureMenu($items, [
            'id' => 'gw-menu',
            'class' => 'gw-menu',
            'hiddenLabel' => $name,
            'buttonHtml' => ($launch === null ? Html::tag('span', Html::encode($name)) : '')
                . Html::tag('span', '', ['class' => 'gw-count gw-count--total hidden', 'data-gw-menu-total' => true, 'role' => 'img']),
            'buttonAttributes' => [
                'class' => ['gw-menu-btn'],
                'data' => ['gw-menu-btn' => true, 'gw-name' => $name],
            ],
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
