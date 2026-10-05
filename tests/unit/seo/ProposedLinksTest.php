<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\ProposedLinksContract;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\suggest\CraftLinkSource;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's ProposedLinksContract on CKEditor (SEO layer §12): a body put in
 * through "Use this draft" (core's EntryBuilder, HtmlDialect, CKEditor's
 * own serialising into the draft), read by Finish this page's own context
 * (Gaps), gets a Link it step per Suggest links proposal with CKEditor's
 * reference tag, loses it once the words are linked, and says when
 * nothing was found.
 */
class ProposedLinksTest extends TestCase
{
    use ProposedLinksContract;

    private ?Section $section = null;

    private ?Entry $contact = null;

    protected function finishContext(string $markdown, ?LinkProposals $proposals = null): GapContext
    {
        return $this->plugin->gaps->context($this->applied($markdown), proposals: $proposals);
    }

    protected function proposalPath(): FieldPath
    {
        return FieldPath::of('body');
    }

    protected function proposalHref(): string
    {
        $row = (new CraftLinkSource())->row($this->contact(), IndexScope::Link);
        $this->assertNotNull($row, 'The Contact page is a link row.');

        return (string) Gaps::links()->inlineHref($row->digest());
    }

    /** Markdown into the body through "Use this draft", as the panel sends it: the entry's draft as it then is. */
    private function applied(string $markdown): Entry
    {
        $this->signIn(admin: true);
        $target = $this->newDraft($this->section());

        $session = Session::start(Format::Craft, ContentType::GENERIC . 'visits', [], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Winter visits\nbody: |\n" . implode("\n", array_map(fn(string $line) => '  ' . $line, explode("\n", $markdown)));
        $this->plugin->sessions->save($session);

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        return Entry::find()->id($target->id)->drafts(null)->status(null)->one();
    }

    private function contact(): Entry
    {
        if ($this->contact === null) {
            $pages = $this->makeSection('pages', [$this->makeEntryType('page', [$this->makeField(PlainText::class, 'summary')])]);
            $this->contact = $this->makeEntry($pages, 'Contact us', ['summary' => 'Book a garden design consultation.']);
        }

        return $this->contact;
    }

    private function section(): Section
    {
        return $this->section ??= $this->makeSection('visits', [$this->makeEntryType('visit', [
            $this->makeField(Ckeditor::class, 'body', ['toolbar' => ['heading', '|', 'bold', 'italic', 'link'], 'headingLevels' => [2, 3]]),
        ])]);
    }
}
