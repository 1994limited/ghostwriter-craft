<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * Suggest edits on an existing entry's edit screen: the guide
 * (suggest.js, in Finish this page's shell), which asks
 * ghostwriter/suggest/guide for the latest review of the entry as the
 * form has it. Suggest edits itself, and the count of its suggestions,
 * are on the menu beside Edit with Ghostwriter.
 *
 * For anyone who may use Ghostwriter and save the entry, in a section
 * Ghostwriter writes for; not on a new entry's draft or a revision.
 */
class SuggestGuide
{
    public static function offeredFor(Entry $entry): bool
    {
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();

        return $request->getIsCpRequest()
            && $user !== null
            && $entry->id
            && !$entry->getIsUnpublishedDraft()
            && !$entry->getIsRevision()
            && $user->can(Plugin::PERMISSION)
            && Gaps::writesHere($entry)
            && Craft::$app->getElements()->canSave($entry, $user);
    }

    /**
     * Puts the guide on this entry's screen, where it belongs, and says
     * whether it did. Its count, and Suggest edits itself, are on the menu
     * beside Edit with Ghostwriter (Launcher::buttonFor()).
     */
    public static function register(Entry $entry): bool
    {
        if (!self::offeredFor($entry)) {
            return false;
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);
        $view->registerTranslations('ghostwriter', self::strings());
        $view->registerJs('new Ghostwriter.Suggest(' . Json::encode([
            'elementId' => (int) $entry->id,
            'siteId' => (int) $entry->siteId,
            'icon' => (string) file_get_contents(dirname(__DIR__) . '/mark.svg'),
            // Content to revisit's Review: the review, or its confirm, ready to run.
            'asked' => Craft::$app->getRequest()->getQueryParam('ghostwriter') === 'suggest',
        ]) . ');');

        return true;
    }

    /**
     * The guide's own words (suggest.js), for Craft.t() in the editor's
     * language: every string it passes to t().
     *
     * @return list<string>
     */
    public static function strings(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/web/assets/cp/dist/suggest.js');
        preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

        return array_values(array_unique(array_map(fn(string $text) => stripcslashes($text), $matches[1])));
    }
}
