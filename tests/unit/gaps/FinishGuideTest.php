<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use Craft;
use craft\fields\PlainText;
use craft\web\View;
use nineteenninetyfour\ghostwriter\gaps\FinishGuide;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Where "Finish this page" appears on the edit screen: the pill beside the
 * entry's buttons and the guide behind it, in sections Ghostwriter writes
 * for, for whoever may use Ghostwriter and see the entry; never on a
 * revision. Its words are all Craft translations.
 */
class FinishGuideTest extends TestCase
{
    public function testThePillAndTheGuideAreOnEntriesGhostwriterWritesFor(): void
    {
        $section = $this->makeSection('notes', [$this->makeEntryType('note', [$this->makeField(PlainText::class, 'summary')])]);
        $entry = $this->makeEntry($section, 'Note');
        Craft::$app->getRequest()->setIsCpRequest(true);

        // Someone else's entry: Craft's peer permission lets them see it.
        $see = ['viewEntries:' . $section->uid, 'viewPeerEntries:' . $section->uid];
        $this->signIn(extra: $see);
        $html = FinishGuide::buttonFor($entry);

        $this->assertStringContainsString('id="gw-finish-pill"', $html);
        $this->assertStringContainsString('hidden', $html, 'Hidden until the check finds something that blocks.');
        $js = implode("\n", Craft::$app->getView()->js[View::POS_READY] ?? []);
        $this->assertStringContainsString('new Ghostwriter.Finish(', $js);
        $this->assertStringContainsString('"elementId":' . $entry->id, $js);
        $this->assertStringContainsString('"state":"minimised"', $js, 'Minimised for someone new.');
        $this->assertStringContainsString('"title":"Finish this page"', $js);
        $this->assertStringContainsString('"count":"{count} things to finish"', $js);
        $this->assertStringContainsString('"suggestionsOne":"and 1 suggestion"', $js, 'A fragment, joined after a comma: no capital.');

        // A revision can't be edited.
        Craft::$app->getRevisions()->createRevision($entry);
        $this->assertSame('', FinishGuide::buttonFor(\craft\elements\Entry::find()->revisionOf($entry)->status(null)->one()));

        // Not for someone who can't see the section, nor without Ghostwriter.
        $this->signIn();
        $this->assertSame('', FinishGuide::buttonFor($entry));
        $this->signIn(permitted: false, extra: $see);
        $this->assertSame('', FinishGuide::buttonFor($entry));

        // Nor in a section Ghostwriter doesn't write for.
        $this->signIn(extra: $see);
        $this->plugin->getSettings()->sections = ['elsewhere'];
        $this->assertSame('', FinishGuide::buttonFor($entry));
    }

    public function testEveryWordTheGuideShowsIsACraftTranslation(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/src/web/assets/cp/dist/finish.js');
        preg_match_all("/\\b(?:t|say)\\('((?:[^'\\\\]|\\\\.)*)'/u", $script, $matches);

        $words = array_values(array_unique(array_filter($matches[1], fn(string $text) => preg_match('/[A-Z←]/u', $text) === 1 && !in_array($text, ['GET', 'POST'], true))));

        $this->assertNotEmpty($words);

        foreach ($words as $text) {
            $this->assertContains(stripslashes($text), FinishGuide::JS_STRINGS, "finish.js says “{$text}” but FinishGuide::JS_STRINGS doesn't register it for translation.");
        }

        // Core's patterns come with it, so the page highlights what the server finds.
        $this->assertArrayHasKey('ask', FinishGuide::patterns());
    }
}
