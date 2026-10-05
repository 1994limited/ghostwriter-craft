<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\helpers\ElementHelper;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RetrySearch;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\seo\MetaContexts;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The search title, description and address on Craft (SEO layer row 5),
 * with plain SEO fields (an SEO title and a meta description as Plain
 * Text fields) on a Journal, a channel whose address is `journal/{slug}`:
 *
 * - the first draft's `seo-editor` call writes the description, and the
 *   pass makes the address from the title, kept on the session;
 * - the panel's Search section shows them; edits, Use this and Try again
 *   change them, and nothing goes into the entry until Use this draft;
 * - Use this draft writes the description into the field, never over a
 *   person's text, and sets the slug on a new entry only, and the session
 *   remembers what Ghostwriter wrote;
 * - Finish this page offers the draft's description where the field is
 *   empty ("Add a description for search").
 *
 * Every model reply is the fake's.
 */
class SearchMetaTest extends TestCase
{
    private const DESCRIPTION = 'Monthly winter visits to cut back, divide and mulch established gardens, from November to February, so the borders come back strong.';

    private const OTHER = 'Winter visits for established gardens: borders cut back, divided and mulched each month from November, ready to come back strong in spring.';

    private const DRAFT = <<<'YAML'
        title: Winter care visits for established gardens
        summary: Monthly winter visits to cut back, divide and mulch.
        body: |
          Established gardens need care in winter as much as in summer. Our monthly winter visits run from November to February, and each one is planned around what the borders need that month.

          We cut back what has finished, divide the perennials that have spread too far, and mulch the beds so the borders come back strong in spring. Nothing is left in a heap: everything goes for composting.
        YAML;

    private Section $journal;

    protected function _before(): void
    {
        parent::_before();

        $this->signIn(admin: true);
        $this->journal = $this->makeSection('journal', [$this->makeEntryType('article', [
            $this->makeField(PlainText::class, 'summary', ['multiline' => true]),
            $this->makeField(Ckeditor::class, 'body'),
            $this->makeField(PlainText::class, 'seoTitle', ['charLimit' => 60]),
            $this->makeField(PlainText::class, 'metaDescription', ['multiline' => true, 'charLimit' => 160]),
        ])]);
        $this->makeEntry($this->journal, 'Winter care visits', ['summary' => 'An older page.'], postDate: '2025-11-01');

        $this->plugin->types->save($this->plugin->types->make('article', [
            'title' => 'Journal article',
            'description' => 'A piece for the journal.',
            'section' => 'journal',
            'questions' => [['handle' => 'what', 'label' => 'What is it about?', 'type' => 'textarea', 'required' => true]],
        ]));
    }

    private function piece(Entry $target): Session
    {
        $session = Session::start(Format::Craft, 'article', ['what' => 'Winter care.'], Craft::$app->getUser()->getId());
        $session->addMessage('user', 'What is it about? Winter care.');
        $session->status = Session::WORKING;
        $session->recordId = (int) $target->getCanonicalId();
        $session->siteId = (int) $target->siteId;

        return $this->plugin->sessions->save($session);
    }

    /** The `seo-editor` reply: no links, and this description. */
    private static function editor(string $description = self::DESCRIPTION, string $title = ''): string
    {
        return (string) json_encode(['notes' => 'Winter care for established gardens.', 'links' => [], 'markers' => [], 'title' => $title, 'description' => $description]);
    }

    private function firstDraft(Entry $target): Session
    {
        $session = $this->piece($target);
        $this->fake->respond('writer', "<reply>Here it is.</reply>\n<draft>\n" . self::DRAFT . "\n</draft>");
        $this->fake->respond('seo-editor', self::editor());
        $this->fake->respond('layout-planner', "<plans>\n</plans>");

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);

        return $this->plugin->sessions->find($session->id);
    }

    public function testTheFirstDraftGetsADescriptionAndAnAddressFromItsTitle(): void
    {
        $session = $this->firstDraft($this->newDraft($this->journal));
        $meta = SeoState::of($session)->meta;

        $this->assertSame(self::DESCRIPTION, $meta->description);
        $this->assertSame('', $meta->title, 'The page title fits: the SEO title keeps using it (decision 12).');
        $this->assertSame('winter-care-visits-for-established-gardens', $meta->slug, 'The whole phrase, apart from the page already called "Winter care visits".');

        $this->fake->assertSent('seo-editor', fn(TextRequest $request) => str_contains($request->prompt, 'description'));

        // The Text tab's Search section, as the panel gets it.
        $search = (new Presenter())->detail($session)['seo']['search'];

        $this->assertSame(self::DESCRIPTION, $search['description']['text']);
        $this->assertSame(mb_strlen(self::DESCRIPTION), $search['description']['length']);
        $this->assertSame([160, 120, 155], [$search['description']['limit'], $search['description']['min'], $search['description']['max']]);
        $this->assertSame('Aim for 120 to 155 characters', $search['description']['range']);
        $this->assertSame('Only what the page says.', $search['description']['note']);
        $this->assertFalse($search['title']['own']);
        $this->assertSame('Uses the page title: “Winter care visits for established gardens”', $search['title']['usesTitle']);
        $this->assertSame('It fits, so your SEO settings keep using it.', $search['title']['note']);
        $this->assertSame('winter-care-visits-for-established-gardens', $search['address']['slug']);
        $this->assertTrue($search['address']['editable']);
        $this->assertStringEndsWith('/journal/', $search['address']['base']);
        $this->assertSame('Set on this new page only. Published pages keep their address.', $search['address']['note']);
        $this->assertFalse($search['busy']);
        $this->assertNull($search['failed']);
    }

    public function testEditsInTheSearchSectionAreTheEditorsAndNothingGoesIntoTheEntry(): void
    {
        $target = $this->newDraft($this->journal);
        $session = $this->firstDraft($target);

        $edited = $this->action('ghostwriter/sessions/edit-search', ['id' => $session->id, 'role' => 'description', 'text' => "Our winter visits,\nmonthly."]);
        $this->assertSame(200, $edited['status'], json_encode($edited['data']));
        $this->assertSame('Our winter visits, monthly.', $edited['data']['seo']['search']['description']['text']);
        $this->assertSame('Yours now: a later turn won\'t rewrite it.', $edited['data']['seo']['search']['description']['note']);

        $this->action('ghostwriter/sessions/edit-search', ['id' => $session->id, 'role' => 'slug', 'text' => 'Winter Visits!']);
        $this->action('ghostwriter/sessions/edit-search', ['id' => $session->id, 'role' => 'title', 'text' => 'Winter garden care visits']);
        $meta = SeoState::of($this->plugin->sessions->find($session->id))->meta;

        $this->assertSame('winter-visits', $meta->slug);
        $this->assertSame('Winter garden care visits', $meta->title);
        $this->assertSame(['description', 'slug', 'title'], $meta->edited);

        // "Use the page title": the title goes back to inheriting.
        $back = $this->action('ghostwriter/sessions/edit-search', ['id' => $session->id, 'role' => 'title', 'text' => '']);
        $this->assertFalse($back['data']['seo']['search']['title']['own']);

        $this->assertSame(422, $this->action('ghostwriter/sessions/edit-search', ['id' => $session->id, 'role' => 'keywords', 'text' => 'x'])['status']);

        $entry = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $this->assertSame('', (string) $entry->getFieldValue('metaDescription'), 'Nothing is in the entry until the draft is used.');
    }

    public function testTryAgainAsksForAnotherAndSaysWhenItFailed(): void
    {
        $session = $this->firstDraft($this->newDraft($this->journal));

        $asked = $this->action('ghostwriter/sessions/try-search-again', ['id' => $session->id]);
        $this->assertSame(200, $asked['status'], json_encode($asked['data']));
        $this->assertSame('working', $asked['data']['status']);
        $this->assertTrue($asked['data']['seo']['search']['busy'], '"Writing another…"');
        $this->assertCount(1, $this->queued(RetrySearch::class));

        $this->fake->reset('seo-editor')->respond('seo-editor', self::editor(self::OTHER));
        $this->runQueue();

        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame(self::OTHER, SeoState::of($session)->meta->description);
        $this->assertSame(Session::IDLE, $session->status);
        $prompts = $this->fake->prompted('seo-editor');
        $this->assertStringContainsString(self::DESCRIPTION, (string) end($prompts)->prompt, 'Told what it has now, to write it differently.');

        $search = (new Presenter())->detail($session)['seo']['search'];
        $this->assertFalse($search['busy']);
        $this->assertNull($search['failed']);

        // The provider fails: the texts stay, and the panel says so.
        $this->action('ghostwriter/sessions/try-search-again', ['id' => $session->id]);
        $this->fake->reset('seo-editor')->failWith('seo-editor', new ProviderException('The provider is busy.', 'fake'));
        $this->runQueue();

        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame(self::OTHER, SeoState::of($session)->meta->description);
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertSame('That didn\'t work. Try again in a moment.', (new Presenter())->detail($session)['seo']['search']['failed']);
    }

    public function testUseThisDraftWritesTheDescriptionAndTheSlugOnANewEntry(): void
    {
        $target = $this->newDraft($this->journal);
        $this->assertTrue(ElementHelper::isTempSlug((string) $target->slug) || (string) $target->slug === '');
        $session = $this->firstDraft($target);

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        $entry = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $this->assertSame(self::DESCRIPTION, (string) $entry->getFieldValue('metaDescription'));
        $this->assertSame('', (string) $entry->getFieldValue('seoTitle'), 'No SEO title: the page title fits.');
        $this->assertSame('winter-care-visits-for-established-gardens', $entry->slug);
        $this->assertTrue($entry->getIsUnpublishedDraft(), 'Nothing is published.');

        // What Ghostwriter wrote is known as its own from now on.
        $session = $this->plugin->sessions->find($session->id);
        $this->assertTrue(SeoState::of($session)->written->owns(SeoField::DESCRIPTION, self::DESCRIPTION));
        $this->assertSame(self::DESCRIPTION, SeoState::of($session)->meta->description, 'The meta stays on the session.');
    }

    public function testAPublishedEntryKeepsItsSlugAndAPersonsDescription(): void
    {
        $published = $this->makeEntry($this->journal, 'Winter care for borders', ['summary' => 'Ours.', 'metaDescription' => 'Our own description, written by a person for this page, about winter care for borders.'], postDate: '2025-12-01');
        $slug = $published->slug;
        $session = $this->firstDraft($published);

        $this->assertNull(SeoState::of($session)->meta->slug, 'A published page keeps its address.');

        $search = (new Presenter())->detail($session)['seo']['search'];
        $this->assertFalse($search['address']['editable']);
        $this->assertSame($slug, $search['address']['slug']);
        $this->assertSame('Published pages keep their address.', $search['address']['note']);
        $this->assertSame('suggest', $search['description']['action']);
        $this->assertSame('Your SEO description stays. Suggested instead:', $search['description']['note']);

        $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $published->id]);
        $draft = Entry::find()->draftOf($published)->provisionalDrafts()->status(null)->one();

        $this->assertNotNull($draft);
        $this->assertSame($slug, $draft->slug);
        $this->assertSame('Our own description, written by a person for this page, about winter care for borders.', (string) $draft->getFieldValue('metaDescription'), 'A person\'s text is never written over.');
        $this->assertTrue(SeoState::of($this->plugin->sessions->find($session->id))->written->isEmpty());

        // "Use this" in the Search section: it goes in after all.
        $used = $this->action('ghostwriter/sessions/use-search', ['id' => $session->id, 'role' => 'description']);
        $this->assertSame(200, $used['status'], json_encode($used['data']));
        $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $published->id]);
        $draft = Entry::find()->draftOf($published)->provisionalDrafts()->status(null)->one();

        $this->assertSame(self::DESCRIPTION, (string) $draft->getFieldValue('metaDescription'));
        $this->assertSame($slug, $draft->slug);
    }

    public function testFinishOffersTheDraftsDescriptionWhereTheFieldIsEmpty(): void
    {
        $target = $this->newDraft($this->journal);
        $session = $this->firstDraft($target);
        $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);

        // The editor empties the field in the form.
        $entry = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $entry->setFieldValue('metaDescription', '');

        $context = $this->plugin->gaps->context($entry);
        $this->assertNotNull($context->seo, 'Finish this page reads the SEO fields.');
        $this->assertSame(self::DESCRIPTION, $context->session->meta[SeoField::DESCRIPTION] ?? null);

        $payload = $this->plugin->gaps->payload($entry);
        $gaps = array_values(array_filter($payload['gaps'], fn(array $gap) => $gap['kind'] === GapKind::SeoMissing->value));

        $this->assertCount(1, $gaps);
        $this->assertSame('suggestion', $gaps[0]['severity']);
        $this->assertSame(['use-text', 'focus'], array_column($gaps[0]['fixes'], 'action'));
        $this->assertSame(self::DESCRIPTION, $gaps[0]['fixes'][0]['value']);
        $this->assertSame('Use this', $gaps[0]['fixes'][0]['label']);
        $this->assertSame('metaDescription', $gaps[0]['location']['handle']);
        $this->assertSame('gaps.step.seo-missing', $gaps[0]['meta']['step']);
        $this->assertSame(0, $payload['count'], 'A suggestion: never counted.');
    }

    public function testAnSeomaticDescriptionIsFoundInItsField(): void
    {
        $gap = Gap::make(GapKind::SeoMissing, FieldPath::of('seoSettings')->with('metaGlobalVars')->with('seoDescription'), 'SEO description');
        $location = Gaps::location($gap, 12);

        $this->assertSame('seoSettings', $location['handle']);
        $this->assertSame('seoDescription', $location['seomatic']);
        $this->assertSame(12, $location['elementId']);
    }

    public function testTheSlugIsLeftWhereTheAddressDoesntUseIt(): void
    {
        $settings = $this->journal->getSiteSettings();
        $settings[Craft::$app->getSites()->getPrimarySite()->id]->uriFormat = 'journal/{id}';
        $this->journal->setSiteSettings($settings);
        Craft::$app->getEntries()->saveSection($this->journal);

        $target = $this->newDraft($this->journal);
        $session = $this->firstDraft($target);

        $this->assertNull(SeoState::of($session)->meta->slug);
        $this->assertNull((new Presenter())->detail($session)['seo']['search']['address']);
        $this->assertFalse(MetaContexts::slugSettable(Entry::find()->id($target->id)->drafts(null)->status(null)->one()));
    }

    public function testAnotherPiecesWritingIsKnownAsGhostwritersOwn(): void
    {
        $target = $this->newDraft($this->journal);
        $earlier = $this->piece($target);
        SeoState::of($earlier)->withWritten((new SeoProvenance())->with(SeoField::DESCRIPTION, 'Written before.'))->saveTo($earlier);
        $this->plugin->sessions->save($earlier);

        $now = $this->piece($target);

        $this->assertTrue(MetaContexts::provenance($now)->owns(SeoField::DESCRIPTION, 'Written before.'));
    }
}
