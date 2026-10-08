<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\elements\Entry;
use craft\fields\Lightswitch;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkIndexContract;
use nineteenninetyfour\ghostwriter\suggest\CraftLinkSource;
use nineteenninetyfour\ghostwriter\suggest\DbEntryIndex;
use nineteenninetyfour\ghostwriter\tests\support\SuggestSites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use RuntimeException;

/**
 * Core's LinkIndexContract against the plugin's index table, filled by its
 * own save hooks: Ghostwriter writes for the Journal only, so Pages (a
 * structure), a Landing channel with a `noindex` lightswitch and the Home
 * single are link-only; pages on two sites.
 */
class LinkIndexTest extends TestCase
{
    use LinkIndexContract;
    use SuggestSites;

    /** @var array{design: EntryRef, about: EntryRef, contact: EntryRef, draft: EntryRef, scheduled: EntryRef, noindex: EntryRef, search: EntryRef, home: ?EntryRef, other: ?EntryRef} */
    private array $entries;

    private DateTimeImmutable $scheduled;

    protected function _before(): void
    {
        parent::_before();

        $this->twoSites();
        $this->plugin->getSettings()->sections = ['siteJournal'];
        $this->scheduled = (new DateTimeImmutable('+10 days'))->setTime(9, 0);

        $summary = Craft::$app->getFields()->getFieldByHandle('summary');
        $body = Craft::$app->getFields()->getFieldByHandle('body');
        $landing = $this->makeSection('siteLanding', [$this->makeEntryType('siteLandingPage', [$summary, $body, $this->makeField(Lightswitch::class, 'noindex')])]);
        $home = $this->homeSingle();

        $page = fn(Section $section, Site $site, string $title, array $fields = [], ?string $slug = null, bool $live = true) => $this->page($section, $site, $title, $fields, $slug, $live);

        $this->entries = [
            'design' => $page($this->siteJournal, $this->english, 'Garden design', ['summary' => 'A full design for your garden.', 'body' => self::ABOUT . "\n\n" . self::DRAFT]),
            'about' => $page($this->sitePages, $this->english, 'About our garden design studio', ['body' => self::ABOUT]),
            'contact' => $page($this->sitePages, $this->english, 'Contact us', ['summary' => 'Book a garden design consultation or ask us a question.']),
            'draft' => $page($this->sitePages, $this->english, 'Garden design draft notes', ['body' => 'Notes towards a garden design page.'], live: false),
            'scheduled' => $this->scheduledPage(),
            'noindex' => $page($landing, $this->english, 'Garden design offer', ['body' => 'A garden design offer for this spring only.', 'noindex' => true]),
            'search' => $page($this->sitePages, $this->english, 'Search garden design', [], 'search'),
            'home' => $home,
            'other' => $page($this->sitePages, $this->welsh, 'About our garden design studio', ['body' => self::ABOUT]),
        ];

        $this->runQueue();
    }

    protected function _after(): void
    {
        $this->dropWelsh();

        parent::_after();
    }

    protected function linkIndex(): LinkIndex
    {
        return $this->plugin->linkIndex;
    }

    protected function entryIndex(): EntryIndex
    {
        return $this->plugin->entryIndex;
    }

    protected function linkEntries(): array
    {
        return $this->entries;
    }

    protected function draftGroup(): string
    {
        return 'siteJournal';
    }

    protected function scheduledFrom(): DateTimeImmutable
    {
        return $this->scheduled;
    }

    protected function retitle(EntryRef $entry, string $title): void
    {
        $found = Entry::find()->id((int) $entry->id)->siteId((int) $entry->site)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $found);
        $found->title = $title;
        $this->assertTrue(Craft::$app->getElements()->saveElement($found));
        $this->runQueue();
    }

    protected function deleteEntry(EntryRef $entry): void
    {
        $this->assertTrue(Craft::$app->getElements()->deleteElementById((int) $entry->id, siteId: (int) $entry->site));
        $this->runQueue();
    }

    protected function hasRevisitRow(EntryRef $entry): bool
    {
        return $this->plugin->revisitStore->get($entry) !== null;
    }

    public function test_the_plugin_binds_its_own(): void
    {
        $this->assertInstanceOf(DbEntryIndex::class, $this->plugin->linkIndex);
        $this->assertSame($this->plugin->entryIndex, Craft::$container->get(LinkIndex::class));
    }

    public function test_rows_say_what_they_are(): void
    {
        $index = $this->plugin->entryIndex;
        $about = $index->row($this->entries['about']);
        $design = $index->row($this->entries['design']);

        $this->assertSame(IndexScope::Link, $about?->scope);
        $this->assertSame('/sitePages/about-our-garden-design-studio', $about->url);
        $this->assertSame('{entry:' . $this->entries['about']->id . '@' . $this->english->id . ':url}', $about->link);
        $this->assertSame('SitePages', $about->type);
        $this->assertTrue($about->key, 'Level 1 of a structure.');
        $this->assertNotSame([], $about->stemsOf('title'));
        $this->assertSame(IndexScope::Full, $design?->scope);
        $this->assertFalse($design->key);
        $this->assertTrue($index->row($this->entries['noindex'])?->noindex);
        $this->assertSame('/', $index->row($this->entries['home'])?->url);
        $this->assertNotNull($index->row($this->entries['scheduled'])?->liveFrom);
        $this->assertNull($index->row($this->entries['draft']), 'A disabled page has no row.');
    }

    public function test_disabling_a_link_page_forgets_its_row(): void
    {
        $entry = Entry::find()->id((int) $this->entries['contact']->id)->siteId($this->english->id)->one();
        $entry->enabled = false;
        Craft::$app->getElements()->saveElement($entry);
        $this->runQueue();

        $this->assertNull($this->plugin->entryIndex->row($this->entries['contact']));
    }

    public function test_deleting_a_link_page_flags_the_pages_that_linked_to_it(): void
    {
        $this->plugin->getSettings()->sections = ['siteJournal', 'siteStories'];
        $stories = $this->makeSection('siteStories', [$this->makeEntryType('siteStory', [$this->makeField(\craft\ckeditor\Field::class, 'storyBody')])]);
        $about = (int) $this->entries['about']->id;
        $linker = $this->page($stories, $this->english, 'Our studio', ['storyBody' => "<p>Read <a href=\"{entry:{$about}@{$this->english->id}:url||https://northfold.test/about}\">about us</a>.</p>"]);
        $this->runQueue();

        $this->assertFalse($this->plugin->revisitStore->get($linker)?->has(ReasonKind::BrokenLink));

        $this->deleteEntry($this->entries['about']);

        $this->assertTrue($this->plugin->revisitStore->get($linker)?->has(ReasonKind::BrokenLink));
        $this->assertNull($this->plugin->entryIndex->row($this->entries['about']));
    }

    public function test_a_page_linking_to_the_page_ranks_higher_for_it(): void
    {
        $this->plugin->getSettings()->sections = ['siteJournal', 'siteStories'];
        $stories = $this->makeSection('siteStories', [$this->makeEntryType('siteStory', [$this->makeField(\craft\ckeditor\Field::class, 'storyBody')])]);
        $about = (int) $this->entries['about']->id;
        $linker = $this->page($stories, $this->english, 'Our process', ['storyBody' => "<p>Read <a href=\"{entry:{$about}@{$this->english->id}:url||https://northfold.test/about}\">about us</a> first.</p>"]);
        $this->runQueue();

        $this->assertContains("entry:{$about}", $this->plugin->entryIndex->row($linker)?->links ?? [], 'A full row keeps where the page links.');

        $keys = array_map(fn($entry) => $entry->entry?->key(), $this->plugin->entryIndex->related("Opening hours\n\nClosed on Mondays.", 'siteJournal', $this->english->id, $this->entries['about']));
        $this->assertSame($linker->key(), $keys[0], 'It links to the page: first, though it shares no words.');
    }

    public function test_stems_are_kept_beside_the_row(): void
    {
        $stems = (new \craft\db\Query())->select('stem')->from(\nineteenninetyfour\ghostwriter\Store::INDEX_STEMS)->where(['entryKey' => $this->entries['contact']->key()])->column();

        // Each stem by its first four letters (LinkCandidates::indexKeys()): "consultation" and "contact".
        $this->assertContains('cons', $stems);
        $this->assertContains('cont', $stems);

        $this->deleteEntry($this->entries['contact']);
        $this->assertSame([], (new \craft\db\Query())->from(\nineteenninetyfour\ghostwriter\Store::INDEX_STEMS)->where(['entryKey' => $this->entries['contact']->key()])->column());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function page(Section $section, Site $site, string $title, array $fields = [], ?string $slug = null, bool $live = true, ?DateTimeImmutable $postDate = null): EntryRef
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => $site->id,
            'title' => $title,
            'slug' => $slug ?? \craft\helpers\StringHelper::toKebabCase($title),
            'enabled' => $live,
            'authorId' => 1,
        ]);

        if ($postDate !== null) {
            $entry->postDate = \DateTime::createFromImmutable($postDate);
        }

        $entry->setFieldValues($fields);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException("Could not save \"{$title}\": " . json_encode($entry->getErrors()));
        }

        return CraftLinkSource::ref($entry);
    }

    private function scheduledPage(): EntryRef
    {
        return $this->page($this->sitePages, $this->english, 'Garden design open day', ['body' => 'Come to our garden design open day.'], postDate: $this->scheduled);
    }

    /** The home page: a single at `__home__` on the English site. */
    private function homeSingle(): EntryRef
    {
        $section = new Section([
            'name' => 'Home',
            'handle' => 'siteHome',
            'type' => Section::TYPE_SINGLE,
            'siteSettings' => [new Section_SiteSettings([
                'siteId' => $this->english->id,
                'hasUrls' => true,
                'uriFormat' => '__home__',
                'template' => '_entry',
            ])],
        ]);
        $section->setEntryTypes([$this->makeEntryType('siteHomePage', [Craft::$app->getFields()->getFieldByHandle('body') ?? $this->makeField(PlainText::class, 'body')])]);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException('Could not save the home single: ' . json_encode($section->getErrors()));
        }

        $home = Entry::find()->sectionId($section->id)->siteId($this->english->id)->status(null)->one();
        $home->title = 'Garden design studio';
        $home->setFieldValue('body', 'A garden design studio in the north.');
        Craft::$app->getElements()->saveElement($home);

        return CraftLinkSource::ref($home);
    }
}
