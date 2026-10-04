<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use nineteenninetyfour\ghostwriter\suggest\CkeditorEntries;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The entries nested in a CKEditor field are read like Matrix entries:
 * their text is checked, reviewed and reconciled, Content to revisit
 * reads it, and the guide is told where each one is, by its ID in the
 * form. Fake reviewer and verifier; nothing is saved to the entry.
 */
class NestedEntriesTest extends TestCase
{
    private const PULL = 'We leverage our expertise to deliver bespoke garden solutions';

    private const BETTER = 'We design gardens and help them grow';

    private const INTRO = '<p>We plan, plant and look after gardens across Northumberland, from small town yards to walled kitchen gardens on the coast. Every garden starts with a visit, a walk round and a cup of tea, and a talk about how you want to use the space through the year.</p><p>After the visit we draw a concept, then a planting plan, and our own team builds it. We come back each season for the first two years to see how it is settling in, and to move anything that is unhappy where it is.</p>';

    private Section $section;

    private EntryType $quote;

    private Entry $services;

    private Entry $nested;

    protected function _before(): void
    {
        parent::_before();

        $this->quote = $this->makeEntryType('pullQuote', [$this->makeField(PlainText::class, 'quote'), $this->makeField(PlainText::class, 'credit')], hasTitle: false);
        $body = $this->makeField(Ckeditor::class, 'nestBody', ['entryTypes' => [$this->quote->uid], 'toolbar' => ['heading', 'bold', 'italic', 'link', 'createEntry']]);
        $this->section = $this->makeSection('nestPages', [$this->makeEntryType('nestPage', [$this->makeField(PlainText::class, 'heading'), $body])], Section::TYPE_STRUCTURE);
        $this->services = $this->makeEntry($this->section, 'Services', ['heading' => 'Garden design in Northumberland', 'nestBody' => self::INTRO]);

        $this->nested = new Entry([
            'fieldId' => $body->id,
            'ownerId' => $this->services->id,
            'typeId' => $this->quote->id,
            'siteId' => $this->services->siteId,
        ]);
        $this->nested->setFieldValues(['quote' => self::PULL, 'credit' => 'New for 2024: winter care visits']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->nested), json_encode($this->nested->getErrors()));

        $this->services->setFieldValue('nestBody', self::INTRO . '<craft-entry data-entry-id="' . $this->nested->id . '"></craft-entry><p>Call us.</p>');
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->services));
        $this->runQueue();
        $this->clearQueue();
        $this->plugin->domain->saveGuide(Guide::VOICE, "# Northfold\n\n## What this voice never does\n\nNo jargon: never leverage, bespoke or solutions.\n");
    }

    private function editor(): \craft\elements\User
    {
        return $this->signIn(extra: ['viewEntries:' . $this->section->uid, 'saveEntries:' . $this->section->uid, 'viewPeerEntries:' . $this->section->uid, 'savePeerEntries:' . $this->section->uid]);
    }

    /** A reviewer with one suggestion, on the pull quote; the verifier keeps it. */
    private function fakeReview(): void
    {
        $this->fake->respond('reviewer', function(TextRequest $request) {
            preg_match('/<unit id="(u\d+)" field="[^"]*Quote[^"]*"/', $request->prompt, $unit);
            preg_match_all('/^(f\d+) /m', $request->prompt, $candidates);
            $answers = array_map(fn(string $number) => ['finding' => $number, 'drop' => 'Fine in context.', 'decline' => 'Fine in context.'], $candidates[1]);
            $answers[] = ['category' => 'voice', 'unit' => $unit[1] ?? 'u1', 'quote' => self::PULL, 'reason' => 'Three words the guide rules out.', 'source' => ['kind' => 'voice-guide', 'heading' => 'What this voice never does'], 'replacement' => self::BETTER];

            return "<suggestions>\n" . json_encode(['suggestions' => $answers]) . "\n</suggestions>";
        });
        $this->fake->respond('verifier', function(TextRequest $request) {
            preg_match_all('/<suggestion id="(s\d+)"/', $request->prompt, $ids);

            return "<verdicts>\n" . json_encode(['verdicts' => array_map(fn(string $id) => ['id' => $id, 'verdict' => 'keep', 'reason' => 'Reads well.'], $ids[1])]) . "\n</verdicts>";
        });
    }

    /** @return array<string, mixed> */
    private function guide(Entry $entry): array
    {
        $result = $this->action('ghostwriter/suggest/guide', ['elementId' => $entry->id, 'siteId' => $entry->siteId]);
        $this->assertSame(200, $result['status'], json_encode($result['data']));

        return $result['data'];
    }

    /** @return array<string, mixed> */
    private function pullQuote(array $guide): array
    {
        foreach ($guide['suggestions'] as $suggestion) {
            if ($suggestion['category'] === 'voice') {
                return $suggestion;
            }
        }

        $this->fail('No suggestion on the pull quote: ' . json_encode($guide));
    }

    public function testTheChecksReadTheNestedEntriesText(): void
    {
        $context = $this->plugin->revisit->checks()->context($this->services);
        $path = 'nestBody' . CkeditorEntries::SUFFIX . '/#' . $this->nested->id . '/quote';
        $text = $context->textAt($path);

        $this->assertNotNull($text, 'Paths: ' . implode(', ', array_map(fn($t) => $t->visit->path->toString(), $context->texts())));
        $this->assertSame(self::PULL, $text->plain);
        $this->assertSame('PullQuote: Quote', $text->visit->label);
        $this->assertNotNull($context->textAt('nestBody'), 'The field itself is read as before.');
        $this->assertStringNotContainsString('leverage', $context->textAt('nestBody')->plain);

        // A disabled nested entry isn't published, so isn't read.
        $this->nested->enabled = false;
        Craft::$app->getElements()->saveElement($this->nested);
        $this->assertNull($this->plugin->revisit->checks()->context($this->services)->textAt($path));
    }

    public function testFinishThisPageReadsTheFieldAsItIs(): void
    {
        $context = $this->plugin->gaps->context($this->services);

        $this->assertNull($context->schema->field('nestBody' . CkeditorEntries::SUFFIX));
        $this->assertArrayNotHasKey('nestBody' . CkeditorEntries::SUFFIX, $context->entry->values);
    }

    public function testContentToRevisitReadsTheNestedEntries(): void
    {
        Craft::$app->getElements()->saveElement($this->services);
        $this->runQueue();
        $row = $this->plugin->revisitStore->get(EntryChecks::ref($this->services));

        $this->assertNotNull($row);
        $this->assertTrue($row->has(ReasonKind::PastYear), 'The dated phrase is in the nested entry only.');
        $this->assertSame([], $this->fake->requests());
    }

    public function testASuggestionInANestedEntryIsFoundAppliedAndDoneOnceSaved(): void
    {
        $this->editor();
        $this->fakeReview();
        $this->action('ghostwriter/suggest/start', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId]);
        $this->runQueue();

        $this->assertStringContainsString(self::PULL, $this->fake->prompted('reviewer')[0]->prompt, 'The reviewer reads the nested entry.');

        $suggestion = $this->pullQuote($this->guide($this->services));
        $this->assertSame('nestBody' . CkeditorEntries::SUFFIX . '/#' . $this->nested->id . '/quote', $suggestion['path']);
        $this->assertSame(['elementId' => (int) $this->nested->id, 'blocks' => [(int) $this->nested->id], 'handle' => 'quote', 'field' => 'nestBody' . CkeditorEntries::SUFFIX], $suggestion['location']);
        $this->assertSame('text', $suggestion['fieldType']);
        $this->assertSame('Quote (in the PullQuote block)', $suggestion['place']);

        // Accepted in the form: nothing is saved.
        $before = Entry::find()->id($this->nested->id)->status(null)->one()->getFieldValue('quote');
        $decided = $this->action('ghostwriter/suggest/decide', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId, 'reviewId' => $this->guide($this->services)['review']['id'], 'decisions' => [['suggestion' => $suggestion['id'], 'state' => 'accepted', 'text' => self::BETTER]]]);
        $this->assertSame(200, $decided['status'], json_encode($decided['data']));
        $this->assertSame($before, Entry::find()->id($this->nested->id)->status(null)->one()->getFieldValue('quote'));
        $this->assertSame('accepted', $this->pullQuote($this->guide($this->services))['state']);

        // Saved with the new words: Done.
        $nested = Entry::find()->id($this->nested->id)->status(null)->one();
        $nested->setFieldValue('quote', self::BETTER);
        Craft::$app->getElements()->saveElement($nested);
        Craft::$app->getElements()->saveElement(Entry::find()->id($this->services->id)->one());
        $this->runQueue();

        $this->assertSame('done', $this->pullQuote($this->guide($this->services))['state']);
    }

    public function testThePersonsDraftFindsTheNestedEntryInItsForm(): void
    {
        $user = $this->editor();
        $this->fakeReview();
        $this->action('ghostwriter/suggest/start', ['elementId' => $this->services->id, 'siteId' => $this->services->siteId]);
        $this->runQueue();

        $draft = Craft::$app->getDrafts()->createDraft($this->services, $user->id, provisional: true);
        $suggestion = $this->pullQuote($this->guide($draft));

        $this->assertSame('nestBody' . CkeditorEntries::SUFFIX . '/#' . $this->nested->id . '/quote', $suggestion['path'], 'The same place in the entry and in a draft of it.');
        $this->assertSame('open', $suggestion['state']);
        $this->assertSame([(int) $this->nested->id], $suggestion['location']['blocks'], 'The draft shares the nested entry until it changes it.');

        // Opened in its slideout, the nested entry gets a draft of its own; the form still shows the entry.
        Craft::$app->getDrafts()->createDraft(Entry::find()->id($this->nested->id)->ownerId($this->services->id)->status(null)->one(), $user->id, provisional: true);
        $this->assertSame([(int) $this->nested->id], $this->pullQuote($this->guide($this->services))['location']['blocks']);
    }

    public function testEntriesNestedInAMatrixEntrysCkeditorFieldAreReadToo(): void
    {
        $text = $this->makeField(Ckeditor::class, 'blockText', ['entryTypes' => [$this->quote->uid], 'toolbar' => ['bold', 'createEntry']]);
        $builder = $this->makeMatrix('nestBuilder', [$this->makeEntryType('textBlock', [$text], hasTitle: false)]);
        $section = $this->makeSection('builderPages', [$this->makeEntryType('builderPage', [$builder])]);
        $page = $this->makeEntry($section, 'About', ['nestBuilder' => ['entries' => ['new1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['blockText' => '<p>Hello.</p>']]], 'sortOrder' => ['new1']]]);
        $block = $page->getFieldValue('nestBuilder')->status(null)->one();

        $nested = new Entry(['fieldId' => $text->id, 'ownerId' => $block->id, 'typeId' => $this->quote->id, 'siteId' => $page->siteId]);
        $nested->setFieldValues(['quote' => self::PULL]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($nested));
        $block->setFieldValue('blockText', '<p>Hello.</p><craft-entry data-entry-id="' . $nested->id . '"></craft-entry>');
        $this->assertTrue(Craft::$app->getElements()->saveElement($block));

        $page = Entry::find()->id($page->id)->one();
        $path = "nestBuilder/#{$block->id}/blockText" . CkeditorEntries::SUFFIX . "/#{$nested->id}/quote";
        $context = $this->plugin->revisit->checks()->context($page);

        $this->assertSame(self::PULL, $context->textAt($path)?->plain, 'Paths: ' . implode(', ', array_map(fn($t) => $t->visit->path->toString(), $context->texts())));
        $this->assertSame(['elementId' => (int) $nested->id, 'handle' => 'quote', 'blocks' => [(int) $block->id, (int) $nested->id], 'field' => 'nestBuilder'], \nineteenninetyfour\ghostwriter\gaps\Gaps::locationOf(\NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath::parse($path), (int) $page->id));
    }

    public function testNestedEntryIdsAreReadInOrder(): void
    {
        $this->assertSame([12, 7], CkeditorEntries::ids('<p>a</p><craft-entry data-entry-id="12"></craft-entry><craft-entry class="x" data-entry-id=\'7\'></craft-entry>'));
        $this->assertSame([], CkeditorEntries::ids('<p>No entries.</p>'));
        $this->assertSame([], CkeditorEntries::ids(null));
    }
}
