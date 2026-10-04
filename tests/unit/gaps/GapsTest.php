<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Link;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use nineteenninetyfour\ghostwriter\controllers\GapsController;
use nineteenninetyfour\ghostwriter\domain\VolumeAssetSink;
use nineteenninetyfour\ghostwriter\gaps\CraftLinkTargets;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\jobs\FillGap;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\stock\StockMarkers;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * "Finish this page" on the server: the check the guide runs on an entry
 * as the form has it (every kind of gap, translated, with where each is in
 * the form), which never asks a model; the guide's remembered state; and
 * the fixes that write, which ask once each and never for a fact.
 */
class GapsTest extends TestCase
{
    private Section $events;

    private Assets $cover;

    private Assets $image;

    private Entry $contact;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->stockLibraries = [];
        $this->plugin->stockLibraries->reset();
        $this->plugin->stockComps->reset();
        StockMarkers::reset();

        $volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $volume->uid], 'defaultUploadLocationSource' => 'volume:' . $volume->uid];
        $this->cover = $this->makeField(Assets::class, 'cover', $sources + ['maxRelations' => 1]);
        $this->image = $this->makeField(Assets::class, 'image', $sources + ['maxRelations' => 1]);
        $feature = $this->makeEntryType('feature', [$this->makeField(PlainText::class, 'heading')], hasTitle: false);

        $type = $this->makeEntryType('event', [
            $this->makeField(PlainText::class, 'summary'),
            $this->makeField(PlainText::class, 'intro'),
            $this->makeField(Ckeditor::class, 'body'),
            $this->makeField(Number::class, 'price'),
            $this->makeField(Link::class, 'button', ['types' => ['url', 'entry']]),
            $this->cover,
            $this->image,
            $this->makeMatrix('blocks', [$feature]),
        ]);

        // Summary is required on this layout.
        $layout = $type->getFieldLayout();

        foreach ($layout->getCustomFieldElements() as $element) {
            if ($element->getField()->handle === 'summary') {
                $element->required = true;
            }
        }

        Craft::$app->getEntries()->saveEntryType($type);

        $this->events = $this->makeSection('events', [$type]);
        $pages = $this->makeSection('pages', [$this->makeEntryType('page')], Section::TYPE_CHANNEL);
        $this->contact = $this->makeEntry($pages, 'Contact us');
    }

    public function testTheCheckFindsEveryKindOfGapWithoutAskingAModel(): void
    {
        $this->signInToEdit();
        $entry = $this->unfinished();

        $result = $this->action('ghostwriter/gaps/check', ['elementId' => $entry->id, 'siteId' => $entry->siteId], 'GET');

        $this->assertSame(200, $result['status'], json_encode($result['data']));
        $data = $result['data'];
        $kinds = array_count_values(array_column($data['gaps'], 'kind'));

        foreach (['ask', 'ask-value', 'link', 'link-broken', 'image-placeholder', 'stock-preview', 'leftover-token', 'placeholder-text'] as $kind) {
            $this->assertArrayHasKey($kind, $kinds, "No {$kind} gap: " . json_encode(array_keys($kinds)));
        }

        // The required Summary is empty, but Craft's own validation says so on save.
        $this->assertArrayNotHasKey('required', $kinds);
        $this->assertNotContains('summary', array_column($data['gaps'], 'field'));

        // Two links to choose: inline, and the Link field on the sentinel.
        $this->assertSame(2, $kinds['link']);
        $this->assertSame(count(array_filter($data['gaps'], fn(array $gap) => $gap['severity'] !== 'suggestion')), $data['count']);
        $this->assertSame($entry->id, $data['elementId']);
        $this->fake->assertNothingSent();

        $byKind = [];

        foreach ($data['gaps'] as $gap) {
            $byKind[$gap['kind']] ??= $gap;
        }

        // Translated, with the speech label and the fixes' labels.
        $ask = $byKind['ask'];
        $this->assertSame('I left a gap in Body: adult ticket price. Only you know this. What should it say?', $ask['message']);
        $this->assertSame('Fill this in', $ask['speech']);
        $this->assertSame(['Type it in', 'Write around it'], array_column($ask['fixes'], 'label'));
        $this->assertSame(['elementId' => (int) $entry->id, 'handle' => 'body', 'blocks' => [], 'field' => 'body'], $ask['location']);

        // A fact for the number field, from the session: the reason is the writer's.
        $this->assertSame('Price is empty: adult ticket price. This one needs you.', $byKind['ask-value']['message']);
        $this->assertSame('Only you know this.', $byKind['ask-value']['reason']);

        // "Link to Contact us", from the hint, as a CKEditor link.
        $link = array_values(array_filter($data['gaps'], fn(array $gap) => $gap['kind'] === 'link' && $gap['meta']['inline']))[0];
        $this->assertSame('Link to Contact us', $link['fixes'][0]['label']);
        $this->assertStringStartsWith("{entry:{$this->contact->id}@", $link['fixes'][0]['value']);

        // The stock step carries the stock feature's own badge, by library name.
        $this->assertSame('Image is still a Demo stock preview. License it before publishing.', $byKind['stock-preview']['message']);
        $this->assertSame('I\'ll write it', $byKind['placeholder-text']['fixes'][0]['label']);
        $this->assertSame($byKind['stock-preview']['meta']['stockId'], $byKind['stock-preview']['stock']['id']);
        $this->assertSame('License me', $byKind['stock-preview']['speech']);

        // A gap in a block is found in that block's own field.
        $heading = array_values(array_filter($data['gaps'], fn(array $gap) => $gap['field'] === 'blocks'))[0];
        $block = $entry->getFieldValue('blocks')->status(null)->one();
        $this->assertSame(['elementId' => (int) $block->id, 'handle' => 'heading', 'blocks' => [(int) $block->id], 'field' => 'blocks'], $heading['location']);
        $this->assertSame('Heading (in the Feature block)', $heading['label']);
        $this->assertSame('I left a gap in Heading (in the Feature block): opening days. Only you know this. What should it say?', $heading['message']);
    }

    public function testTheCheckReadsTheDraftTheFormIsEditing(): void
    {
        $user = $this->signInToEdit();
        $entry = $this->makeEntry($this->events, 'Finished', ['summary' => 'Done.', 'body' => '<p>All done.</p>']);
        $draft = Craft::$app->getDrafts()->createDraft($entry, $user->id, null, null, [], true);
        $draft->setFieldValue('body', '<p>Tickets cost [[ask: adult ticket price]].</p>');
        Craft::$app->getElements()->saveElement($draft);

        $live = $this->action('ghostwriter/gaps/check', ['elementId' => $entry->id, 'siteId' => $entry->siteId], 'GET')['data'];
        $editing = $this->action('ghostwriter/gaps/check', ['elementId' => $draft->id, 'siteId' => $entry->siteId], 'GET')['data'];

        $this->assertNotContains('ask', array_column($live['gaps'], 'kind'));
        $this->assertContains('ask', array_column($editing['gaps'], 'kind'));
    }

    public function testTheCheckNeedsTheEntryToBeViewable(): void
    {
        $entry = $this->makeEntry($this->events, 'Hidden');

        // May use Ghostwriter, but not see this section's entries.
        $this->signIn();
        $this->assertSame(403, $this->action('ghostwriter/gaps/check', ['elementId' => $entry->id], 'GET')['status']);
        $this->assertSame(404, $this->action('ghostwriter/gaps/check', ['elementId' => 999999], 'GET')['status']);

        // May see it, but not use Ghostwriter.
        $this->signIn(permitted: false, extra: ['viewEntries:' . $this->events->uid]);
        $this->assertSame(403, $this->action('ghostwriter/gaps/check', ['elementId' => $entry->id], 'GET')['status']);
    }

    public function testTheGuideRemembersOpenOrMinimisedForEachPerson(): void
    {
        $this->signIn();

        $this->assertSame('minimised', GapsController::rememberedGuide(), 'Minimised for someone new.');
        $this->assertSame('open', $this->action('ghostwriter/gaps/guide', ['state' => 'open'])['data']['state']);
        $this->assertSame('open', GapsController::rememberedGuide());
        $this->action('ghostwriter/gaps/guide', ['state' => 'anything']);
        $this->assertSame('minimised', GapsController::rememberedGuide());
    }

    public function testWriteItForMeAsksOnceAndPutsNothingIntoTheEntry(): void
    {
        $this->signInToEdit();
        $entry = $this->withoutIntro('<p>A fair on the green, with plants for sale.</p>');
        $expected = $this->introGap($entry);

        $started = $this->action('ghostwriter/gaps/fill', ['elementId' => $entry->id, 'gap' => $expected->id]);
        $this->assertSame('working', $started['data']['status'], json_encode($started['data']));
        $this->assertCount(1, $this->queued(FillGap::class));

        $this->fake->respond('gap-filler', '<result>A spring fair on the green, with plants for sale.</result>');
        $this->runQueue();

        $done = $this->action('ghostwriter/gaps/fill-status', [], 'GET', params: ['id' => $started['data']['id']]);
        $this->assertSame(['status' => 'done', 'text' => 'A spring fair on the green, with plants for sale.', 'message' => null], $done['data']);
        $this->assertCount(1, $this->fake->prompted('gap-filler'));
        $this->assertStringContainsString('A fair on the green', $this->fake->prompted('gap-filler')[0]->prompt);

        // Shown once, then gone; and the entry itself is untouched.
        $this->assertSame(404, $this->action('ghostwriter/gaps/fill-status', [], 'GET', params: ['id' => $started['data']['id']])['status']);
        $this->assertSame('', (string) Entry::find()->id($entry->id)->one()->getFieldValue('intro'));
    }

    public function testAFactIsNeverWrittenOnlyWrittenAround(): void
    {
        $this->signInToEdit();
        $entry = $this->unfinished();
        $report = $this->plugin->gaps->report($entry);
        $askValue = $report->ofKind(\NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind::AskValue)[0];
        $ask = $report->ofKind(\NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind::Ask)[0];

        $this->assertSame(422, $this->action('ghostwriter/gaps/fill', ['elementId' => $entry->id, 'gap' => $askValue->id])['status']);
        $this->assertSame([], $this->queued(FillGap::class));

        $started = $this->action('ghostwriter/gaps/fill', ['elementId' => $entry->id, 'gap' => $ask->id, 'sentence' => 'Tickets cost [[ask: adult ticket price]] for adults.']);
        $this->fake->respond('gap-filler', '<result>Tickets are sold for adults at the gate.</result>');
        $this->runQueue();

        $request = $this->fake->prompted('gap-filler')[0];
        $this->assertStringContainsString('Task: write-around', $request->prompt);
        $this->assertStringContainsString('Missing: adult ticket price', $request->prompt);
        $this->assertSame('done', $this->action('ghostwriter/gaps/fill-status', [], 'GET', params: ['id' => $started['data']['id']])['data']['status']);
    }

    public function testSomeoneElsesAnswerIsNotShown(): void
    {
        $this->signInToEdit();
        $entry = $this->withoutIntro('<p>A fair.</p>');
        $id = $this->action('ghostwriter/gaps/fill', ['elementId' => $entry->id, 'gap' => $this->introGap($entry)->id])['data']['id'];

        $this->signInToEdit();

        $this->assertSame(404, $this->action('ghostwriter/gaps/fill-status', [], 'GET', params: ['id' => $id])['status']);
    }

    public function testLinkTargetsMatchTitlesAndSlugsAndKnowWhatHasGone(): void
    {
        $targets = new CraftLinkTargets();
        $field = new Field('button', \NineteenNinetyFour\Ghostwriter\Core\Schema\Kind::Reference, type: Link::class);

        $found = $targets->search('contact page');
        $this->assertSame('Contact us', $found[0]->title);
        $this->assertSame([], $targets->search('the page'));

        $this->assertTrue($targets->exists(['type' => 'entry', 'value' => "{entry:{$this->contact->id}@1:url}"], $field));
        $this->assertFalse($targets->exists(['type' => 'entry', 'value' => '{entry:999999@1:url}'], $field));
        $this->assertFalse($targets->exists('{entry:999999@1:url||https://example.test/gone}', $field));
        $this->assertNull($targets->exists('https://example.org/', $field));
        $this->assertNull($targets->exists(null, $field));
    }

    public function testAPlaceInABlockIsNamedOnce(): void
    {
        $this->signInToEdit();
        $text = $this->makeEntryType('text', [$this->makeField(PlainText::class, 'text')], hasTitle: false);
        $builder = $this->makeMatrix('page', [$text]);
        $section = $this->makeSection('notes', [$this->makeEntryType('note', [$builder])]);
        $entry = $this->makeEntry($section, 'Note', [
            'page' => ['entries' => ['new1' => ['type' => 'text', 'enabled' => true, 'fields' => ['text' => 'Bring a [[item]] and pay [[ask: the fee]].']]], 'sortOrder' => ['new1']],
        ], live: false);

        $gaps = array_column($this->plugin->gaps->payload($entry)['gaps'], 'message', 'kind');

        // The block and its field are both "Text": said once, not "Text: Text".
        $this->assertSame('Some template text slipped into the Text block: “[[item]]”.', $gaps['leftover-token']);
        $this->assertSame('I left a gap in the Text block: the fee. Only you know this. What should it say?', $gaps['ask']);

        // And the guard says it the same way, starting with a capital.
        $entry->enabled = true;
        $entry->setScenario(\craft\base\Element::SCENARIO_LIVE);
        $this->assertFalse(Craft::$app->getElements()->saveElement($entry));
        $this->assertSame('The Text block: Add the fee before publishing. The Text block: Remove the template text “[[item]]” before publishing.', $entry->getFirstError('page'));
    }

    public function testAGapInANeoChildBlockIsFoundInThatBlock(): void
    {
        $this->signInToEdit();
        $neo = $this->makeNeo('body2', [
            ['handle' => 'section', 'fields' => [$this->makeField(PlainText::class, 'sectionTitle')], 'children' => ['card']],
            ['handle' => 'card', 'topLevel' => false, 'fields' => [$this->makeField(PlainText::class, 'cardText')]],
        ]);
        $section = $this->makeSection('guides', [$this->makeEntryType('guide', [$neo])]);
        $entry = $this->makeEntry($section, 'Guide', [
            'body2' => [
                'blocks' => [
                    'new1' => ['type' => 'section', 'enabled' => true, 'level' => 1, 'fields' => ['sectionTitle' => 'Prices']],
                    'new2' => ['type' => 'card', 'enabled' => true, 'level' => 2, 'fields' => ['cardText' => 'Adults pay [[ask: adult ticket price]].']],
                ],
                'sortOrder' => ['new1', 'new2'],
            ],
        ], live: false);

        $blocks = $entry->getFieldValue('body2')->status(null)->all();
        $ask = array_values(array_filter($this->plugin->gaps->payload($entry)['gaps'], fn(array $gap) => $gap['kind'] === 'ask'))[0];

        // The card's own field, inside the section block: both on the way down.
        $this->assertSame(['elementId' => (int) $blocks[1]->id, 'handle' => 'cardText', 'blocks' => [(int) $blocks[0]->id, (int) $blocks[1]->id], 'field' => 'body2'], $ask['location']);
        $this->assertSame('CardText (in the Card block)', $ask['label']);
    }

    public function testEveryCoreStringIsInCraftsTranslationsAsCoreHasIt(): void
    {
        $craft = require dirname(__DIR__, 3) . '/src/translations/en/ghostwriter.php';

        foreach (Message::strings() as $key => $english) {
            $this->assertSame(preg_replace('/:([a-zA-Z][a-zA-Z_]*)/', '{$1}', $english), $craft["gaps.{$key}"] ?? null, "gaps.{$key} is out of date: run php bin/sync-core-strings.");
        }

        // The brief in the conversation's, likewise.
        foreach (require dirname(__DIR__, 3) . '/vendor/1994/ghostwriter-core/resources/lang/en/brief.php' as $key => $english) {
            $this->assertSame($english, $craft["brief.{$key}"] ?? null, "brief.{$key} is out of date: run php bin/sync-core-strings.");
        }

        // Suggest edits' and Content to revisit's, likewise.
        foreach (['suggest', 'revisit'] as $namespace) {
            foreach (Message::strings($namespace) as $key => $english) {
                $this->assertSame(preg_replace('/:([a-zA-Z][a-zA-Z_]*)/', '{$1}', $english), $craft["{$namespace}.{$key}"] ?? null, "{$namespace}.{$key} is out of date: run php bin/sync-core-strings.");
            }
        }

        // No "guess" in anything a person reads (Suggest edits' "I won't
        // guess" is Ghostwriter refusing to make up a fact, not a retry).
        foreach (array_diff_key($craft, ['suggest.fact.ask' => true]) as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\bguess/i', $text, $key);
        }

        $this->assertSame('Finish this page', Craft::t('ghostwriter', 'gaps.guide.title'));
        $this->assertSame('3 things to finish', Gaps::translate(new Message('gaps.guide.count', ['count' => 3])));
        $this->assertSame('gaps.unknown-key', Gaps::translate(new Message('gaps.unknown-key')), 'Neither Craft nor core has it: the key, as core does.');
    }

    /**
     * Someone who may use Ghostwriter and edit events.
     */
    private function signInToEdit(): \craft\elements\User
    {
        return $this->signIn(extra: ['viewEntries:' . $this->events->uid, 'saveEntries:' . $this->events->uid, 'viewPeerEntries:' . $this->events->uid, 'savePeerEntries:' . $this->events->uid, 'viewPeerEntryDrafts:' . $this->events->uid]);
    }

    /**
     * An event with no intro, where the published ones before it have one:
     * an intro is expected (a suggestion "Write it for me" can fill).
     */
    private function withoutIntro(string $body): Entry
    {
        foreach (['Harvest supper', 'Seed swap', 'Pond dipping'] as $title) {
            $this->makeEntry($this->events, $title, ['summary' => 'An event.', 'intro' => "{$title} on the green.", 'body' => '<p>Come along.</p>']);
        }

        return $this->makeEntry($this->events, 'Spring fair', ['body' => $body]);
    }

    private function introGap(Entry $entry): \NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap
    {
        foreach ($this->plugin->gaps->report($entry)->ofKind(\NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind::Expected) as $gap) {
            if ($gap->path->handle() === 'intro') {
                return $gap;
            }
        }

        $this->fail('No expected intro: ' . json_encode(array_map(fn($gap) => $gap->id, $this->plugin->gaps->report($entry)->all())));
    }

    /**
     * An entry with one of each gap: a fact to add, a fact for a number
     * field (from the session), links to choose and to a deleted page, an
     * image placeholder, a stock preview, template text, "TBC", and a fact in a block.
     */
    private function unfinished(): Entry
    {
        $carrier = $this->makeEntry($this->events, 'Carrier', live: false);
        $preview = $this->plugin->imagePicker->insertPreview(ImageSlot::for($this->image, $carrier), $this->plugin->stockLibraries->demo(), 'demo-03');
        $this->plugin->stockComps->reset();

        $coverField = (new SchemaReader())->schema($this->events->getEntryTypes()[0])->field('cover');
        $placeholder = (new VolumeAssetSink())->placeholder($coverField, fn() => Placeholders::png());

        $entry = $this->makeEntry($this->events, 'Spring fair', [
            'body' => '<p>Tickets cost [[ask: adult ticket price]] for adults. <a href="#gw-link:contact-page">Talk to us</a> or read <a href="{entry:999999@1:url||https://example.test/gone}">last year’s</a>. Bring a [[item]]. Opening times TBC.</p>',
            'button' => ['type' => 'url', 'value' => 'https://example.com/#gw-link:button', 'label' => 'Link to choose'],
            'cover' => [(int) $placeholder],
            'image' => [(int) $preview->id],
            'blocks' => ['entries' => ['new1' => ['type' => 'feature', 'enabled' => true, 'fields' => ['heading' => 'Open [[ask: opening days]]']]], 'sortOrder' => ['new1']],
        ], live: false);

        $session = Session::start(Format::Craft, ContentType::GENERIC . 'events', [], Craft::$app->getUser()->getId());
        $session->recordId = (int) $entry->id;
        $session->gaps = [['kind' => 'ask-value', 'path' => 'price', 'label' => 'Price', 'hint' => 'adult ticket price', 'reason' => 'draft']];
        $this->plugin->sessions->save($session);

        return $entry;
    }
}
