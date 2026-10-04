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
 * while it has one (the button itself isn't repeated in it). With nothing
 * in the menu its button is hidden, and Edit with Ghostwriter stands alone. The guides report their
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

    /**
     * The keyed strings the panel uses for the SEO layer (core's seo.php):
     * "Checking headings and links…", and the marks and popover on the
     * links Ghostwriter added.
     */
    public const SEO_STRINGS = [
        'seo.status.checking', 'seo.link.added', 'seo.link.added-long', 'seo.link.remove', 'seo.link.open', 'seo.link.removed',
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
     * `$suggest` says whether Suggest edits is (SuggestGuide::register()):
     * its item starts a review, and its row opens one.
     */
    public static function buttonFor(Entry $entry, bool $finish = false, bool $suggest = false): string
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $section = $entry->getSection();

        if (!$request->getIsCpRequest() || !$user || !$section) {
            return $finish ? self::menu(null, $suggest) : '';
        }

        if (!$user->can(Plugin::PERMISSION) || !$plugin->types->enabled($section->handle) || !Craft::$app->getElements()->canSave($entry, $user)) {
            return $finish ? self::menu(null, $suggest) : '';
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
            // ("suggest" is Suggest edits', from Content to revisit's Review.)
            'open' => $request->getQueryParam('ghostwriter') === 'suggest' ? null : $request->getQueryParam('ghostwriter'),
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
        $view->registerTranslations('ghostwriter', self::SEO_STRINGS);
        $view->registerJs('new Ghostwriter.Launcher(' . Json::encode($config) . ');');

        $label = $editing ? Craft::t('ghostwriter', 'Edit with Ghostwriter') : Craft::t('ghostwriter', 'Write with Ghostwriter');

        // Joined with its menu, like Craft's own Save button.
        return Html::tag('div', Html::button(Html::tag('span', '', ['class' => 'gw-mark', 'aria-hidden' => 'true']) . Html::tag('span', Html::encode($label), ['class' => 'gw-launch-label']), [
            'type' => 'button',
            // Square on the right only while the menu's button shows (ghostwriter.js).
            'class' => 'btn btngroup-btn-last',
            'id' => 'ghostwriter-launch',
            'title' => $label,
        ]) . self::menu($label, $suggest && $editing), ['class' => 'btngroup gw-launch']);
    }

    /**
     * The menu: the two count rows, hidden until their guide has a count.
     * Its button shows the count before Craft's chevron, and is hidden
     * while there is nothing in the menu.
     */
    private static function menu(?string $launch, bool $suggest = false): string
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
            'listAttributes' => ['data' => ['gw-menu-counts' => true]],
        ]];

        // Suggest edits, on an entry that exists: always there, as it
        // starts a review (its confirm says what it costs first).
        if ($suggest) {
            $items[] = [
                'type' => 'group',
                'items' => [[
                    'html' => Html::tag('span', Html::encode(Craft::t('ghostwriter', 'Suggest edits')), ['class' => 'gw-menu-row-label'])
                        . Html::tag('span', Html::encode(Craft::t('ghostwriter', 'Reads the page against your voice guide and checks each suggestion twice. Uses Ghostwriter.')), ['class' => 'gw-menu-row-info']),
                    'icon' => 'wand-magic-sparkles',
                    'attributes' => ['class' => ['gw-menu-row'], 'data' => ['ghostwriter-suggest' => true, 'gw-menu-always' => true]],
                ]],
            ];
        }

        $name = $launch !== null ? Craft::t('ghostwriter', 'More ways to edit with Ghostwriter') : Craft::t('ghostwriter', 'Ghostwriter');

        return Cp::disclosureMenu($items, [
            'id' => 'gw-menu',
            'class' => 'gw-menu',
            'hiddenLabel' => $name,
            'buttonHtml' => ($launch === null ? Html::tag('span', Html::encode($name)) : '')
                . Html::tag('span', '', ['class' => 'gw-count gw-count--total hidden', 'data-gw-menu-total' => true, 'role' => 'img']),
            'buttonAttributes' => [
                'class' => array_filter(['gw-menu-btn', $suggest ? null : 'hidden']),
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
