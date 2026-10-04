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
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkInsertContract;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\suggest\CraftLinkSource;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's LinkInsertContract on CKEditor (SEO layer §7.4, §20): a link the
 * SEO pass inserts as `{entry:12@1:url||/pages/contact-us}` goes through
 * "Use this draft" (core's EntryBuilder, HtmlDialect, HTMLPurifier and
 * CKEditor's own serialising into the provisional draft), reads back
 * through HtmlDialect as the same link beside the markers, and Craft
 * renders it as the Contact page's address.
 *
 * What CKEditor 5.8 on Craft 5.11 does with it: the reference tag is
 * stored as a reference tag, unencoded, with the entry's full address as
 * its fallback in place of the site-relative one written
 * (`{entry:12@1:url||https://…/pages/contact-us}`); the form's editor is
 * given it as `https://…/pages/contact-us#entry:12@1:url`, and that is
 * what the form posts back, which CKEditor's field turns back into the
 * reference tag on save. Rendered, Craft resolves the tag to the entry's
 * address. Core's linkKey() reads all three as `entry:12`.
 */
class LinkInsertTest extends TestCase
{
    use LinkInsertContract;

    private ?Section $section = null;

    private ?Entry $contact = null;

    protected function inlineLinks(): InlineLinks
    {
        return Gaps::links();
    }

    protected function linkTarget(): DigestEntry
    {
        $row = (new CraftLinkSource())->row($this->contact(), IndexScope::Link);
        $this->assertNotNull($row, 'The Contact page is a link row.');

        return $row->digest();
    }

    protected function linkField(): Field
    {
        return (new SchemaReader())->schema($this->section()->getEntryTypes()[0])->field('body');
    }

    protected function storedMarkdown(string $markdown, Field $field): string
    {
        return (string) (new HtmlDialect())->toMarkdown($this->stored($markdown), $field);
    }

    protected function renderedHref(string $href): ?string
    {
        $html = (string) Entry::find()->id($this->applied("Do [tell us about your garden]({$href}).")->id)->drafts(null)->status(null)->one()->getFieldValue('body');

        return preg_match('/<a [^>]*href="([^"]+)"[^>]*>tell us about your garden<\/a>/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    protected function targetUrl(): string
    {
        return (string) $this->contact()->getUrl();
    }

    public function testCkeditorKeepsAReferenceTagAndTheFormGetsTheAddress(): void
    {
        $href = (string) $this->inlineLinks()->inlineHref($this->linkTarget());
        $id = $this->contact()->id;
        $this->assertSame("{entry:{$id}@" . Craft::$app->getSites()->getPrimarySite()->id . ":url||/pages/contact-us}", $href);

        // Stored as a reference tag, unencoded; CKEditor's field writes the
        // entry's own address as its fallback.
        $raw = $this->stored("Do [tell us about your garden]({$href}).");
        $this->assertStringContainsString('href="{entry:' . $id . '@' . Craft::$app->getSites()->getPrimarySite()->id . ':url||' . $this->contact()->getUrl() . '}"', $raw);

        // The form's editor is given the address with the tag after a #, as CKEditor holds a link to an entry.
        $draft = Entry::find()->id($this->applied("Do [tell us about your garden]({$href}).")->id)->drafts(null)->status(null)->one();
        $field = $draft->getFieldLayout()->getFieldByHandle('body');
        $input = $field->getInputHtml($draft->getFieldValue('body'), $draft);
        $this->assertStringContainsString(htmlspecialchars($this->contact()->getUrl() . "#entry:{$id}@", ENT_QUOTES), $input);

        // What the form posts back, saved again, is the reference tag once more.
        $draft->setFieldValue('body', '<p>Do <a href="' . $this->contact()->getUrl() . "#entry:{$id}@" . Craft::$app->getSites()->getPrimarySite()->id . ':url">tell us about your garden</a>.</p>');
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));
        $saved = (string) Entry::find()->id($draft->id)->drafts(null)->status(null)->one()->getFieldValue('body')->getRawContent();
        $this->assertMatchesRegularExpression('/href="\{entry:' . $id . '@\d+:url\|\|[^"]*\}"/', $saved);
    }

    /** The raw HTML the field keeps once the markdown has been through "Use this draft". */
    private function stored(string $markdown): string
    {
        return (string) $this->applied($markdown)->getFieldValue('body')?->getRawContent();
    }

    /** Markdown into the body through "Use this draft", as the panel sends it: the entry's draft as it then is. */
    private function applied(string $markdown): Entry
    {
        $this->signIn(admin: true);
        $target = $this->newDraft($this->section());

        $session = Session::start(Format::Craft, ContentType::GENERIC . 'visits', [], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: A visit\nbody: |\n" . implode("\n", array_map(fn(string $line) => '  ' . $line, explode("\n", $markdown)));
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
