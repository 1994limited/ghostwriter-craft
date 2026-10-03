<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use Craft;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use nineteenninetyfour\ghostwriter\controllers\GapsController;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * "Finish this page" on an entry's edit screen: the count pill beside the
 * entry's buttons, and the guide (the floating panel, the dock it
 * minimises to, the flying Ghostwriter mark and the highlights), which
 * asks gaps/check what is unfinished in the entry as the form has it.
 *
 * On any entry in a section Ghostwriter writes for, whoever wrote it, for
 * anyone who may use Ghostwriter. Not on revisions, which can't be edited.
 */
class FinishGuide
{
    /** The guide's own words, translated here so the script needs no keys. */
    private const STRINGS = [
        'title' => 'gaps.guide.title',
        'count' => 'gaps.guide.count',
        'countOne' => 'gaps.guide.count-one',
        'ready' => 'gaps.guide.ready',
        'suggestions' => 'gaps.guide.suggestions',
        'suggestionsOne' => 'gaps.guide.suggestions-one',
        'done' => 'gaps.guide.done',
        'skipped' => 'gaps.guide.skipped',
        'skippedOne' => 'gaps.guide.skipped-one',
    ];

    /** The guide script's own words (finish.js), for Craft.t() in the editor's language. */
    public const JS_STRINGS = [
        '← Back', 'Skip for now', 'Next →', 'Next', 'Minimise', 'Show the step', 'Go through again', 'All done', 'All done!',
        '{n} of {total}', 'Step {n} of {total}.', 'Fixed ✓', 'Fixed: {label}', 'Fixed. {message}', 'Fixed. {left} left.', 'Fixed. {ready}',
        '{count} to do', '{tag}: {label}', 'Suggestion {n} of {total}', 'Suggestion {n} of {total}.', 'A suggestion: it won’t stop the page going live.', 'Ghostwriter: {speech}',
        'Finish this page minimised.', 'Finish this page opened.', 'Alt+Shift+G opens or minimises the guide',
        'Alt+Shift+N for the next step, Alt+Shift+P for the one before, Alt+Shift+G to open or minimise.',
        'What should it say?', 'What should it say instead?', 'Put it in', 'Show me', 'Open block', 'uses Ghostwriter',
        'License', 'License ({cost})', 'Request licence', 'Refresh preview', 'Choose another', 'Download again and replace', 'Licence requested by {name}',
        'Ghostwriter couldn’t find that gap in the field any more.', 'Something went wrong.', 'That didn’t change the field. Try another fix, or change it yourself.',
    ];

    public static function buttonFor(Entry $entry): string
    {
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();

        if (!$request->getIsCpRequest() || !$user || !$entry->id || $entry->getIsRevision()) {
            return '';
        }

        if (!$user->can(Plugin::PERMISSION) || !Gaps::writesHere($entry) || !Craft::$app->getElements()->canView($entry, $user)) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);

        $strings = [];

        foreach (self::STRINGS as $name => $key) {
            $strings[$name] = Gaps::translate(new Message($key, ['count' => '{count}']), sentence: false);
        }

        $config = [
            'elementId' => (int) $entry->id,
            'siteId' => (int) $entry->siteId,
            // Open or minimised, as this person last left it (minimised for someone new).
            'state' => GapsController::rememberedGuide(),
            'openAfterDraft' => Plugin::getInstance()->getSettings()->finishOpenAfterDraft,
            'icon' => (string) file_get_contents(dirname(__DIR__) . '/mark.svg'),
            'patterns' => self::patterns(),
            'strings' => $strings,
        ];

        $view->registerTranslations('ghostwriter', self::JS_STRINGS);
        $view->registerJs('new Ghostwriter.Finish(' . Json::encode($config) . ');');

        // Filled in by the script once it knows the count; hidden until then.
        return Html::button(Html::tag('span', '', ['class' => 'gw-mark', 'aria-hidden' => 'true']) . Html::tag('span', '', ['class' => 'gw-finish-pill__text']), [
            'type' => 'button',
            'class' => 'btn gw-finish-pill hidden',
            'id' => 'gw-finish-pill',
            'aria-keyshortcuts' => 'Alt+Shift+G',
        ]);
    }

    /**
     * Core's marker patterns, so the highlights match exactly what the
     * server finds (core's resources/gaps/patterns.json).
     *
     * @return array<string, mixed>
     */
    public static function patterns(): array
    {
        $file = dirname((new \ReflectionClass(Message::class))->getFileName(), 3) . '/resources/gaps/patterns.json';
        $patterns = is_file($file) ? Json::decodeIfJson((string) file_get_contents($file)) : null;

        return is_array($patterns) ? $patterns : [];
    }
}
