<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fieldlayoutelements\assets\AltField;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use nineteenninetyfour\ghostwriter\jobs\ReviewEdits;
use nineteenninetyfour\ghostwriter\Launcher;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\suggest\SuggestGuide;
use nineteenninetyfour\ghostwriter\tests\support\FakeReview;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Suggest edits, server side: nothing reaches the guide until a review
 * has judged it, a review queued after the confirm and read from a fake
 * reviewer and verifier, decisions shared and kept, Write another, and
 * "Save to the image". Nothing is ever saved to the entry; only alt text,
 * on the asset, after its confirm.
 */
class SuggestEditsTest extends TestCase
{
    use FakeReview;

    private const LONG = 'In terms of the actual process involved, what typically happens is that we will first of all come out and visit the garden in person, after which we will then go away and produce a concept.';

    private Section $section;

    private Entry $services;

    private Asset $photo;

    protected function _before(): void
    {
        parent::_before();

        $volume = $this->makeVolume('suggestPhotos');
        $layout = new FieldLayout(['type' => Asset::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new AssetTitleField(), new AltField()]);
        $layout->setTabs([$tab]);
        $volume->setFieldLayout($layout);
        Craft::$app->getVolumes()->saveVolume($volume);
        $this->photo = $this->makeAsset($volume, 'materials.png');

        $this->section = $this->makeSection('suggestPages', [$this->makeEntryType('suggestPage', [
            $this->makeField(PlainText::class, 'eyebrow'),
            $this->makeField(PlainText::class, 'heading'),
            $this->makeField(Ckeditor::class, 'suggestBody'),
            $this->makeField(Assets::class, 'suggestImage', ['sources' => ['volume:' . $volume->uid], 'maxRelations' => 1]),
            $this->makeField(PlainText::class, 'metaDescription', ['multiline' => true]),
        ])], Section::TYPE_STRUCTURE);

        $this->services = $this->makeEntry($this->section, 'Services', [
            'eyebrow' => 'New for 2024: winter care visits',
            'heading' => 'We leverage our expertise to deliver bespoke garden solutions',
            'suggestBody' => '<p>A full design for your garden from our team of 6 designers. ' . self::LONG . '</p><p>See <a href="{entry:99999@1:url}">our 2023 show garden</a>.</p>',
            'suggestImage' => [$this->photo->id],
            'metaDescription' => str_repeat('From a single planting plan to a full design and build, ', 4),
        ]);

        $this->plugin->domain->saveGuide(Guide::VOICE, "# Northfold\n\n## What this voice never does\n\nNo jargon: never leverage, bespoke or solutions.\n");
        $this->clearQueue();
    }

    private function editor(array $extra = []): \craft\elements\User
    {
        return $this->signIn(extra: ['viewEntries:' . $this->section->uid, 'saveEntries:' . $this->section->uid, 'viewPeerEntries:' . $this->section->uid, 'savePeerEntries:' . $this->section->uid, ...$extra]);
    }

    /** @return array<string, mixed> */
    private function guide(): array
    {
        $result = $this->action('ghostwriter/suggest/guide', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId]);
        $this->assertSame(200, $result['status'], json_encode($result['data']));

        return $result['data'];
    }

    /** @return array<string, mixed> The guide once the queued review has run. */
    private function review(): array
    {
        $this->fakeReview();
        $started = $this->action('ghostwriter/suggest/start', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId]);
        $this->assertSame(200, $started['status'], json_encode($started['data']));
        $this->assertTrue($started['data']['running']);
        $this->assertCount(1, $this->queued(ReviewEdits::class));
        $this->runQueue();

        return $this->guide();
    }

    /** @param array<string, mixed> $guide */
    private static function find(array $guide, string $category): ?array
    {
        foreach ($guide['suggestions'] as $suggestion) {
            if ($suggestion['category'] === $category) {
                return $suggestion;
            }
        }

        return null;
    }

    public function testFreeFindingsAreOnlyCandidatesUntilTheReviewHasJudgedThem(): void
    {
        $this->editor();
        $guide = $this->guide();

        $this->assertNull($guide['review']);
        $this->assertSame(1, $guide['calls']);
        $this->assertSame([], $guide['suggestions'], 'Nothing reaches the editor unjudged.');
        $this->assertGreaterThanOrEqual(3, $guide['candidates']);
        $this->assertSame([], $this->fake->requests(), 'No model.');
    }

    public function testAReviewRunsAfterTheConfirmAndNothingIsSavedToTheEntry(): void
    {
        $this->editor();
        $before = Entry::find()->id($this->services->id)->one()->getSerializedFieldValues();
        $guide = $this->review();

        $this->assertSame('ready', $guide['review']['status'], (string) $guide['review']['error']);
        $voice = self::find($guide, 'voice');
        $this->assertNotNull($voice);
        $this->assertSame('We design gardens and help them grow', $voice['replacement']);
        $this->assertCount(2, $voice['alternatives']);
        $this->assertSame('heading', $voice['location']['handle']);
        $this->assertSame('heading', $voice['dotted']);
        $this->assertCount(1, $this->fake->prompted('reviewer'));

        $dated = self::find($guide, 'out-of-date');
        $this->assertNotNull($dated, 'The dated eyebrow, kept by the review.');

        // Core main anchors it on its sentence, the dated words marked inside.
        if (method_exists(\NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckText::class, 'sentenceAnchor')) {
            $this->assertSame('New for 2024', $dated['phrase']);
        }

        $decided = $this->action('ghostwriter/suggest/decide', ['reviewId' => $guide['review']['id'], 'decisions' => [
            ['suggestion' => $voice['id'], 'state' => 'accepted', 'text' => 'We design gardens and help them grow'],
        ]]);
        $this->assertSame(200, $decided['status']);
        $this->assertSame([$voice['id']], $decided['data']['decided']);

        $this->assertSame($before, Entry::find()->id($this->services->id)->one()->getSerializedFieldValues(), 'The entry is never saved.');
    }

    public function testADismissalIsKeptAndUndoOpensItAgain(): void
    {
        $this->editor();
        $guide = $this->review();
        $voice = self::find($guide, 'voice');

        $this->action('ghostwriter/suggest/decide', ['reviewId' => $guide['review']['id'], 'decisions' => [['suggestion' => $voice['id'], 'state' => 'dismissed']]]);
        $this->assertSame('dismissed', self::find($this->guide(), 'voice')['state']);

        $this->action('ghostwriter/suggest/decide', ['reviewId' => $guide['review']['id'], 'decisions' => [['suggestion' => $voice['id'], 'state' => 'open']]]);
        $this->assertSame('open', self::find($this->guide(), 'voice')['state']);
    }

    public function testSaveToTheImageWritesTheAltTextAndUndoPutsItBack(): void
    {
        $this->editor();
        $guide = $this->review();
        $alt = self::find($guide, 'accessibility');

        $this->assertNotNull($alt);
        $this->assertSame('asset', $alt['scope']);
        $this->assertSame('materials.png', $alt['asset']['filename']);
        $this->assertSame(1, $alt['asset']['uses']);
        $this->assertFalse($alt['asset']['canEdit'], 'Not without permission to save the volume\'s assets.');
        $this->assertSame(403, $this->action('ghostwriter/suggest/alt', ['reviewId' => $guide['review']['id'], 'suggestion' => $alt['id'], 'alt' => 'Stone'])['status']);

        $this->editor(['saveAssets:' . $this->photo->getVolume()->uid, 'savePeerAssets:' . $this->photo->getVolume()->uid, 'viewAssets:' . $this->photo->getVolume()->uid, 'viewPeerAssets:' . $this->photo->getVolume()->uid]);
        $saved = $this->action('ghostwriter/suggest/alt', ['reviewId' => $guide['review']['id'], 'suggestion' => $alt['id'], 'alt' => 'Stone, gravel and timber samples']);

        $this->assertSame(200, $saved['status'], json_encode($saved['data']));
        $this->assertSame('', $saved['data']['before']);
        $this->assertSame('Stone, gravel and timber samples', Asset::find()->id($this->photo->id)->one()->alt);

        $this->assertSame(200, $this->action('ghostwriter/suggest/unalt', ['reviewId' => $guide['review']['id'], 'suggestion' => $alt['id'], 'before' => ''])['status']);
        $this->assertEmpty(Asset::find()->id($this->photo->id)->one()->alt);
    }

    public function testWriteAnotherIsOneSmallCall(): void
    {
        $this->editor();
        $guide = $this->review();
        $voice = self::find($guide, 'voice');

        $this->fake->respond('reworder', "<versions>\n<version>We plan gardens and help them grow</version>\n</versions>");
        $result = $this->action('ghostwriter/suggest/another', ['reviewId' => $guide['review']['id'], 'elementId' => $this->services->id, 'siteId' => $this->services->siteId, 'suggestion' => $voice['id']]);

        $this->assertSame(200, $result['status'], json_encode($result['data']));
        $this->assertSame(['We plan gardens and help them grow'], $result['data']['versions']);
        $this->assertCount(1, $this->fake->prompted('reworder'));
        $this->assertSame(['We plan gardens and help them grow'], self::find($this->guide(), 'voice')['versions']);
    }

    public function testAReplyThatCantBeReadIsToldInPlainWords(): void
    {
        $this->editor();
        $this->fake->respond('reviewer', 'I read the page and it reads well. Nothing to change.');
        $this->action('ghostwriter/suggest/start', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId]);
        $this->runQueue();

        $review = $this->guide()['review'];
        $this->assertSame('failed', $review['status']);
        $this->assertSame('the answer came back in a shape I couldn’t read. Try again.', str_replace("'", '’', (string) $review['error']), 'Not the `unreadable` code.');
    }

    public function testTheReviewReadsThePersonsProvisionalDraft(): void
    {
        $user = $this->editor();
        $draft = Craft::$app->getDrafts()->createDraft($this->services, $user->id, provisional: true);
        $draft->setFieldValue('heading', 'We leverage our expertise to deliver bespoke garden solutions, and more');
        Craft::$app->getElements()->saveElement($draft);
        $this->fakeReview();

        $this->action('ghostwriter/suggest/start', ['elementId' => $draft->id, 'siteId' => $draft->siteId]);
        $this->runQueue();

        $this->assertStringContainsString('bespoke garden solutions, and more', $this->fake->prompted('reviewer')[0]->prompt);
        $this->assertSame(EntryChecks::ref($this->services)->key(), $this->plugin->editReviewStore->latestFor(EntryChecks::ref($this->services))?->entry->key(), 'Reviews are the entry\'s, whoever\'s draft.');
    }

    public function testOnlyPeopleWhoCanSaveTheEntryGetAReview(): void
    {
        $this->signIn(extra: ['viewEntries:' . $this->section->uid, 'viewPeerEntries:' . $this->section->uid]);

        $this->assertSame(403, $this->action('ghostwriter/suggest/guide', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId])['status']);
        $this->assertSame(403, $this->action('ghostwriter/suggest/start', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId])['status']);
        $this->fake->assertNothingSent();
    }

    public function testTheMenuItemIsOnExistingEntriesOnly(): void
    {
        $this->editor();
        Craft::$app->getRequest()->setIsCpRequest(true);

        $this->assertTrue(SuggestGuide::register($this->services));
        $this->assertStringContainsString('data-ghostwriter-suggest', Launcher::buttonFor($this->services, false, true));

        $draft = $this->newDraft($this->section);
        $this->assertFalse(SuggestGuide::register($draft), 'Not on a new entry.');
        $this->assertStringNotContainsString('data-ghostwriter-suggest', Launcher::buttonFor($draft, false, SuggestGuide::register($draft)));
    }
}
