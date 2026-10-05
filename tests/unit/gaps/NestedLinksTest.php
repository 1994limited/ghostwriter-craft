<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use nineteenninetyfour\ghostwriter\jobs\SuggestLinks;
use nineteenninetyfour\ghostwriter\suggest\CkeditorEntries;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Suggest links on a page whose words are in an entry nested in a CKEditor
 * field: the nested entry's own rich text is read, the link found there is
 * a "Link it" step, and the guide is told where it is (the nested entry's
 * ID in the form and its field), as Suggest edits does. Nothing is saved.
 */
class NestedLinksTest extends TestCase
{
    private const LEAD = 'We cut back the grasses that have stood all winter, lift and divide the perennials that have grown too big, and mulch the borders while the soil is still damp. Roses are pruned to an outward bud, and climbers are tied in along the wires.';

    private Section $section;

    private Entry $page;

    private Entry $nested;

    private Entry $contact;

    protected function _before(): void
    {
        parent::_before();

        $card = $this->makeEntryType('booking', [$this->makeField(Ckeditor::class, 'bookingCopy', ['toolbar' => ['bold', 'italic', 'link']])], hasTitle: false);
        $body = $this->makeField(Ckeditor::class, 'nestBody', ['entryTypes' => [$card->uid], 'toolbar' => ['heading', 'bold', 'italic', 'link', 'createEntry']]);
        $this->section = $this->makeSection('visits', [$this->makeEntryType('visit', [$this->makeField(PlainText::class, 'summary'), $body])]);
        $pages = $this->makeSection('pages', [$this->makeEntryType('page')]);
        $this->contact = $this->makeEntry($pages, 'Contact us');

        $intro = '<p>Our gardeners contact you the week before each winter visit.</p>' . str_repeat('<p>' . self::LEAD . '</p>', 8);
        $this->page = $this->makeEntry($this->section, 'Winter visits', ['summary' => 'Winter.', 'nestBody' => $intro]);
        $this->nested = new Entry(['fieldId' => $body->id, 'ownerId' => $this->page->id, 'typeId' => $card->id, 'siteId' => $this->page->siteId]);
        $this->nested->setFieldValues(['bookingCopy' => '<p>Booking is simple. If you would like a winter visit, contact us about your garden and we will arrange a first walk round.</p>']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->nested), json_encode($this->nested->getErrors()));
        $this->page->setFieldValue('nestBody', $intro . '<craft-entry data-entry-id="' . $this->nested->id . '"></craft-entry>');
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->page));
        $this->runQueue();
        Craft::$app->getCache()->flush();
    }

    public function testALinkInANestedEntrysTextIsAStepThere(): void
    {
        $this->signIn(extra: ['viewEntries:' . $this->section->uid, 'saveEntries:' . $this->section->uid, 'viewPeerEntries:' . $this->section->uid, 'savePeerEntries:' . $this->section->uid]);
        $few = array_values(array_filter($this->plugin->gaps->payload($this->page)['gaps'], fn(array $gap) => $gap['kind'] === 'few-links'))[0];
        $started = $this->action('ghostwriter/gaps/links', ['elementId' => $this->page->id, 'gap' => $few['id']]);
        $this->assertSame('working', $started['data']['status'], json_encode($started['data']));

        $this->fake->respond('seo-editor', function(TextRequest $request) {
            $at = strpos($request->prompt, 'contact us about your garden');
            preg_match_all('/\[(u\d+)\]/', substr($request->prompt, 0, (int) $at), $units);
            preg_match('/(e\d+)\. Contact us/', $request->prompt, $target);

            return (string) json_encode(['notes' => '…', 'links' => [['unit' => end($units[1]), 'exact' => 'contact us about your garden', 'prefix' => '', 'target' => $target[1] ?? 'e0', 'hint' => '', 'why' => 'An invitation to get in touch.']], 'markers' => [], 'title' => '', 'description' => '']);
        });
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.']]]);
        $this->runQueue();

        $this->assertCount(1, $this->fake->prompted('seo-editor'));
        $this->assertStringContainsString('contact us about your garden', $this->fake->prompted('seo-editor')[0]->prompt, 'The nested entry\'s text is read.');
        $gaps = $this->action('ghostwriter/gaps/check', ['elementId' => $this->page->id, 'links' => $started['data']['id']], 'GET')['data']['gaps'];
        $step = array_values(array_filter($gaps, fn(array $gap) => $gap['kind'] === 'link-proposed'))[0] ?? null;

        $this->assertNotNull($step, json_encode(array_column($gaps, 'kind')));
        $this->assertSame('contact us about your garden', $step['hint']);
        $this->assertSame(['elementId' => (int) $this->nested->id, 'handle' => 'bookingCopy', 'blocks' => [(int) $this->nested->id], 'field' => 'nestBody' . CkeditorEntries::SUFFIX], $step['location']);
        $this->assertNotContains('few-links', array_column($gaps, 'kind'));
        $this->assertStringNotContainsString('<a ', (string) Entry::find()->id($this->nested->id)->status(null)->one()->getFieldValue('bookingCopy'), 'Nothing is saved.');
    }
}
