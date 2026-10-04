<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use Craft;
use craft\elements\Entry;
use craft\fields\PlainText;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use nineteenninetyfour\ghostwriter\controllers\SessionsController;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\tests\support\Pages;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Internal links on Craft (SEO layer row 4): a first draft is linked to
 * the site's other pages between the writer and the planner, with the
 * panel showing "Checking headings and links…" meanwhile; the links are
 * CKEditor's `{entry:12@1:url||/address}`; the panel lists them for the
 * Text tab's marks; Remove link keeps the words; Finish this page asks
 * the editor to check each one. Every model reply is the fake's.
 */
class InternalLinksTest extends TestCase
{
    use Pages;

    private const DRAFT = <<<'YAML'
        title: Faceted search
        summary: Why narrowing the range helps shoppers find the right appliance.
        blocks:
          - type: hero
            heading: Find it faster
            subheading: A search for a kitchen appliance maker.
          - type: copy
            body: |
              Shoppers rarely know the model number of the oven they want, and most of them arrive with a size, a finish and a budget in mind rather than a product name. The old search asked them for a name, matched it against a long catalogue and showed nothing useful when the words were slightly off, so people gave up and phoned the showroom instead.

              We built a search that narrows the range by size, finish and price as the shopper chooses, with the number of matching models shown at every step. Each choice can be undone on its own, the results update without a reload, and the most popular combinations are offered first so nobody has to start from a blank page. It works the same on a phone in the kitchen as it does at a desk.

              ## What changed

              In the first three months calls to the showroom about finding a model fell by a third, and more of the people who did call already knew which oven they wanted. The catalogue team now sees which combinations people look for and cannot find, which tells them what to stock next. If you are planning something similar, tell us about your project and we will walk you through how we approached it and what we would do differently next time.
          - type: cta
            heading: Talk to us about search
            button: Get in touch
        YAML;

    private Entry $contact;

    protected function _before(): void
    {
        parent::_before();

        $this->makePagesSection();
        $this->signIn(admin: true);
        $this->serviceType();

        $pages = $this->makeSection('pages', [$this->makeEntryType('page', [Craft::$app->getFields()->getFieldByHandle('summary') ?? $this->makeField(PlainText::class, 'summary', ['multiline' => true])])]);
        $this->contact = $this->makeEntry($pages, 'Contact us', ['summary' => 'Tell us about your project and book a call with our search team.']);
        $this->runQueue();
        Craft::$app->getCache()->flush();
    }

    public function testAFirstDraftIsLinkedBetweenTheWriterAndThePlanner(): void
    {
        $session = $this->servicePiece();
        $seen = null;
        $id = $this->contact->id;

        $this->fake->respond('writer', "<reply>Here it is.</reply>\n<draft>\n" . self::DRAFT . "\n</draft>");
        $this->fake->respond('seo-editor', function(TextRequest $request) use ($session, &$seen) {
            $seen = (new Presenter())->detail($this->plugin->sessions->find($session->id));

            return (string) json_encode(['notes' => 'Search work; Contact fits the invitation.', 'links' => [
                ['unit' => self::unitWith($request->prompt, 'tell us about your project'), 'exact' => 'tell us about your project', 'prefix' => '', 'target' => self::target($request->prompt, 'Contact us'), 'hint' => '', 'why' => 'An invitation to get in touch.'],
            ]]);
        });
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [['notes' => 'Right page.', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.']]]);
        $this->fake->respond('layout-planner', self::PAGE_PLANS);

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);
        $session = $this->plugin->sessions->find($session->id);

        $this->assertSame(['writer', 'seo-editor', 'seo-verifier', 'layout-planner'], array_map(fn(TextRequest $request) => $request->agent, $this->fake->requests()));

        // While the links were looked for, the draft was there to read, the piece working, and the panel said so.
        $this->assertTrue($seen['seo']['checking']);
        $this->assertSame('working', $seen['status']);
        $this->assertStringNotContainsString('{entry:', (string) $seen['draft']);

        $site = Craft::$app->getSites()->getPrimarySite()->id;
        $body = (string) Draft::parse((string) $session->draft)->data['blocks'][1]['body'];
        $this->assertStringContainsString("If you are planning something similar, [tell us about your project]({entry:{$id}@{$site}:url||/pages/contact-us}) and we will", $body);
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertNotSame([], $session->units, 'Its units were cut from the linked draft.');

        $detail = (new Presenter())->detail($session);
        $this->assertFalse($detail['seo']['checking']);
        $this->assertFalse(DraftLayouts::isChecking($session));
        $this->assertSame('I linked to one of your pages: Contact us.', $detail['seo']['notice']);
        $link = $detail['seo']['links'][0];
        $this->assertSame(['tell us about your project', "{entry:{$id}@{$site}:url||/pages/contact-us}", 'Contact us', '/pages/contact-us'], [$link['words'], $link['href'], $link['title'], $link['url']]);
        $this->assertSame($this->contact->getUrl(), $link['open_url']);
    }

    public function testRemoveLinkKeepsTheWordsAndTheWriterWontPutItBack(): void
    {
        $session = $this->linkedDraft();
        $href = SeoState::of($session)->links[0]['href'];
        $this->fake->reset();

        $this->assertSame(422, $this->action('ghostwriter/sessions/remove-link', ['id' => $session->id, 'href' => '{entry:999999@1:url}'])['status']);

        $removed = $this->action('ghostwriter/sessions/remove-link', ['id' => $session->id, 'href' => $href]);
        $this->assertSame(200, $removed['status'], json_encode($removed['data']));
        $this->assertSame([], $removed['data']['seo']['links']);
        $this->assertStringContainsString('If you are planning something similar, tell us about your project and we will', (string) $removed['data']['draft']);
        $this->assertSame([$href], SeoState::of($this->plugin->sessions->find($session->id))->removed);
        $this->fake->assertNothingSent();

        // A later turn that puts it back: taken out again, the words kept. No SEO call.
        $session = $this->plugin->sessions->find($session->id);
        $session->addMessage('user', 'Shorter, please.');
        $session->status = Session::WORKING;
        $this->plugin->sessions->save($session);
        $this->fake->respond('writer', "<reply>Done.</reply>\n<draft>\n" . self::draftWith($href) . "\n</draft>");

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);

        $this->assertSame(['writer'], array_map(fn(TextRequest $request) => $request->agent, $this->fake->requests()));
        $draft = (string) $this->plugin->sessions->find($session->id)->draft;
        $this->assertStringContainsString('If you are planning something similar, tell us about your project and we will', $draft);
        $this->assertStringNotContainsString('{entry:', $draft);
    }

    public function testAnAddressTheWriterMadeUpBecomesALinkToChoose(): void
    {
        $session = $this->linkedDraft();
        $this->fake->reset();
        $session->addMessage('user', 'Add a link to our pricing.');
        $session->status = Session::WORKING;
        $this->plugin->sessions->save($session);

        $draft = str_replace('Each choice can be undone on its own', 'Each choice, like [our pricing](/pricing-made-up), can be undone on its own', (string) $session->draft);
        $this->fake->respond('writer', "<reply>Done.</reply>\n<draft>\n{$draft}\n</draft>");

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);
        $draft = (string) $this->plugin->sessions->find($session->id)->draft;

        $this->assertStringContainsString('[our pricing](#gw-link:', $draft);
        $this->assertStringContainsString('[tell us about your project]({entry:', $draft, 'The link Ghostwriter added stays.');
    }

    public function testAnEditInTheTextTabKeepsTheReferenceTag(): void
    {
        $session = $this->linkedDraft();
        $id = $this->contact->id;
        $detail = (new Presenter())->detail($session);

        // The panel shows the link as CommonMark encodes it; the editor changes a word around it.
        $copy = $detail['preview'][2]['items'][1]['fields'][0] ?? null;
        $this->assertNotNull($copy, json_encode($detail['preview']));
        $this->assertStringContainsString('%7Bentry:' . $id, $copy['html']);

        $edited = $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => json_encode($copy['path']), 'format' => 'html', 'value' => str_replace('If you are planning', 'If you are now planning', $copy['html'])]);
        $this->assertSame(200, $edited['status'], json_encode($edited['data']));
        $this->assertStringContainsString("[tell us about your project]({entry:{$id}@", (string) $edited['data']['draft']);
        $this->assertSame('tell us about your project', $edited['data']['seo']['links'][0]['words'] ?? null);
    }

    public function testRefTagsAreDecodedAndNothingElse(): void
    {
        $this->assertSame('[a]({entry:12@1:url||/contact}) and [b](%7Bnot-a-tag%7D)', SessionsController::refTags('[a](%7Bentry:12@1:url%7C%7C/contact%7D) and [b](%7Bnot-a-tag%7D)'));
    }

    public function testFinishThisPageAsksTheEditorToCheckEachLink(): void
    {
        $target = $this->newDraft($this->pages);
        $session = $this->linkedDraft($target);
        $id = $this->contact->id;

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $payload = $this->plugin->gaps->payload($draft);
        $added = array_values(array_filter($payload['gaps'], fn(array $gap) => $gap['kind'] === 'links-added'));

        $this->assertCount(1, $added);
        $this->assertSame('suggestion', $added[0]['severity']);
        $this->assertSame(0, $payload['count'], 'Never counted.');
        $this->assertSame('Check the link Ghostwriter added. “tell us about your project” goes to Contact us (/pages/contact-us). Keep it, or remove the link and keep the words.', $added[0]['message']);
        $this->assertSame([['dismiss', 'Keep it'], ['remove-link', 'Remove the link']], array_map(fn(array $fix) => [$fix['action'], $fix['label']], $added[0]['fixes']));
        $this->assertMatchesRegularExpression('/^\{entry:' . $id . '@\d+:url\|\|.+\}$/', $added[0]['meta']['formHref']);
    }

    /** The first draft written and linked to the Contact page. */
    private function linkedDraft(?Entry $target = null): Session
    {
        $session = $this->servicePiece($target);

        $this->fake->respond('writer', "<reply>Here it is.</reply>\n<draft>\n" . self::DRAFT . "\n</draft>");
        $this->fake->respond('seo-editor', fn(TextRequest $request) => (string) json_encode(['notes' => '…', 'links' => [
            ['unit' => self::unitWith($request->prompt, 'tell us about your project'), 'exact' => 'tell us about your project', 'prefix' => '', 'target' => self::target($request->prompt, 'Contact us'), 'hint' => '', 'why' => 'An invitation to get in touch.'],
        ]]));
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'reason' => '…']]]);
        $this->fake->respond('layout-planner', self::PAGE_PLANS);

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);
        $session = $this->plugin->sessions->find($session->id);
        $this->assertCount(1, SeoState::of($session)->links, (string) $session->draft);

        return $session;
    }

    /** The draft with the link to the Contact page back in it. */
    private static function draftWith(string $href): string
    {
        return str_replace('tell us about your project and we will', "[tell us about your project]({$href}) and we will", self::DRAFT);
    }

    /** The unit the model is shown some words in: the last `[uN]` before them. */
    private static function unitWith(string $prompt, string $words): string
    {
        $at = strpos($prompt, $words);

        return $at !== false && preg_match_all('/\[(u\d+)\]/', substr($prompt, 0, $at), $m) ? end($m[1]) : 'u0';
    }

    /** The candidate the model is shown a page as: its `eN`. */
    private static function target(string $prompt, string $title): string
    {
        return preg_match('/\[?(e\d+)\]?[^\n]*' . preg_quote($title, '/') . '/', $prompt, $m) === 1 ? $m[1] : 'e0';
    }
}
