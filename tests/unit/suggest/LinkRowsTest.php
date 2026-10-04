<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\TitleField;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RowKind;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\suggest\CraftLinkSource;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\suggest\LinkRows;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use RuntimeException;

/**
 * The link rows' daily pass (SEO layer §7.1): it builds rows for every
 * routable page outside Ghostwriter's sections, within the caps and a run
 * at a time, writes a marked group again, swaps link and full rows as a
 * section joins or leaves Ghostwriter's, and keeps categories with text of
 * their own. No model.
 */
class LinkRowsTest extends TestCase
{
    private Section $journal;

    private Section $pages;

    protected function _before(): void
    {
        parent::_before();

        $body = $this->makeField(PlainText::class, 'linkBody', ['multiline' => true]);
        $this->journal = $this->makeSection('linkJournal', [$this->makeEntryType('linkPost', [$body])]);
        $this->pages = $this->makeSection('linkPages', [$this->makeEntryType('linkPage', [$body])], Section::TYPE_STRUCTURE);
        $this->plugin->getSettings()->sections = ['linkJournal'];
    }

    public function test_the_daily_pass_builds_the_rows_saves_missed(): void
    {
        $about = $this->makeEntry($this->pages, 'About the studio', ['linkBody' => 'We design gardens.']);
        $post = $this->makeEntry($this->journal, 'Pruning roses in winter', ['linkBody' => 'Prune roses in late winter.']);
        $this->clearQueue();
        Craft::$app->getDb()->createCommand()->delete(Store::ENTRY_INDEX)->execute();

        $this->plugin->revisit->daily(full: true);

        $this->assertSame(IndexScope::Link, $this->row($about)?->scope);
        $this->assertSame(IndexScope::Full, $this->row($post)?->scope);
        $this->assertSame(1, $this->plugin->revisit->linkResults[$this->siteId()]['written']);
        $this->assertSame([], $this->fake->requests());
    }

    public function test_a_page_gone_is_forgotten_and_an_unchanged_one_left_alone(): void
    {
        $about = $this->makeEntry($this->pages, 'About the studio');
        $contact = $this->makeEntry($this->pages, 'Contact us');
        $this->runQueue();
        $source = $this->plugin->revisit->linkRows();
        $source->pass($this->siteId(), new DateTimeImmutable());
        $this->assertSame(0, $source->last['written'], 'Saved already.');

        // Gone without its delete event (a database restore, say).
        Craft::$app->getDb()->createCommand()->delete('{{%elements}}', ['id' => $contact->id])->execute();
        $source->pass($this->siteId(), new DateTimeImmutable());

        $this->assertSame(1, $source->last['forgotten']);
        $this->assertNull($this->row($contact));
        $this->assertNotNull($this->row($about));
    }

    public function test_the_caps_keep_key_pages_then_the_newest_and_say_so(): void
    {
        $parent = $this->makeEntry($this->pages, 'Services');

        foreach (['Hedges', 'Lawns', 'Borders'] as $i => $title) {
            $child = new Entry(['sectionId' => $this->pages->id, 'typeId' => $this->pages->getEntryTypes()[0]->id, 'title' => $title, 'authorId' => 1]);
            $child->setParentId($parent->id);
            Craft::$app->getElements()->saveElement($child);
            Craft::$app->getDb()->createCommand()->update('{{%elements}}', ['dateUpdated' => '2026-0' . ($i + 1) . '-01 00:00:00'], ['id' => $child->id])->execute();
        }

        $this->runQueue();
        Craft::$app->getDb()->createCommand()->delete(Store::ENTRY_INDEX)->execute();

        $this->plugin->revisit->linkRows()->pass($this->siteId(), new DateTimeImmutable(), groupCap: 2);

        $titles = array_map(fn(array $row) => $row['title'], $this->rowsOf('linkPages'));
        sort($titles);
        $this->assertSame(['Borders', 'Services'], $titles, 'The key page, then the most recently updated.');
        $this->assertSame(['LinkPages has 4 entries; Ghostwriter links to the 2 most recently updated.'], $this->plugin->revisit->linkRows()->notes());
    }

    public function test_a_first_build_is_spread_over_runs(): void
    {
        foreach (range(1, 5) as $n) {
            $this->makeEntry($this->pages, "Page {$n}");
        }

        $this->runQueue();
        Craft::$app->getDb()->createCommand()->delete(Store::ENTRY_INDEX)->execute();
        $rows = $this->plugin->revisit->linkRows();

        $rows->pass($this->siteId(), new DateTimeImmutable(), perRun: 2);
        $this->assertSame(['written' => 2, 'forgotten' => 0, 'promoted' => 0, 'pending' => 3], $rows->last);

        $rows->pass($this->siteId(), new DateTimeImmutable(), perRun: 2);
        $rows->pass($this->siteId(), new DateTimeImmutable(), perRun: 2);
        $this->assertCount(5, $this->rowsOf('linkPages'));
        $this->assertSame(0, $rows->last['pending']);
    }

    public function test_a_marked_group_is_written_again(): void
    {
        $about = $this->makeEntry($this->pages, 'About the studio');
        $this->runQueue();
        $rows = $this->plugin->revisit->linkRows();

        LinkRows::mark('linkPages', new DateTimeImmutable('+1 minute'));
        $rows->pass($this->siteId(), new DateTimeImmutable('+2 minutes'));
        $this->assertSame(1, $rows->last['written']);

        $rows->pass($this->siteId(), new DateTimeImmutable('+3 minutes'));
        $this->assertSame(0, $rows->last['written'], 'The mark is done with.');
        $this->assertNotNull($this->row($about));
    }

    public function test_saving_a_section_or_moving_a_page_marks_its_group(): void
    {
        Craft::$app->getEntries()->saveSection($this->pages);
        $this->assertArrayHasKey('linkPages', $this->plugin->store->state(LinkRows::STATE)['sites'][(string) $this->siteId()]['marked']);

        Craft::$app->getDb()->createCommand()->delete(Store::STATE, ['name' => LinkRows::STATE])->execute();
        $a = $this->makeEntry($this->pages, 'First');
        $b = $this->makeEntry($this->pages, 'Second');
        Craft::$app->getStructures()->moveBefore((int) Craft::$app->getEntries()->getSectionById($this->pages->id)->structureId, $b, $a);

        $this->assertArrayHasKey('linkPages', $this->plugin->store->state(LinkRows::STATE)['sites'][(string) $this->siteId()]['marked'] ?? []);
    }

    public function test_a_section_joining_ghostwriters_gets_full_rows_and_one_leaving_link_rows(): void
    {
        $about = $this->makeEntry($this->pages, 'About the studio', ['linkBody' => 'We design gardens across the north, from courtyards to walled gardens.']);
        $post = $this->makeEntry($this->journal, 'Pruning roses in winter', ['linkBody' => 'Prune roses in late winter, before the buds break, to an outward-facing bud.']);
        $this->runQueue();
        $this->assertSame(IndexScope::Link, $this->row($about)?->scope);

        $this->plugin->getSettings()->sections = ['linkPages'];
        $this->plugin->revisit->linkRows()->pass($this->siteId(), new DateTimeImmutable());

        $this->assertSame(IndexScope::Full, $this->row($about)?->scope);
        $this->assertNotNull($this->plugin->revisitStore->get(EntryChecks::ref($about)));
        $this->assertSame(IndexScope::Link, $this->row($post)?->scope);
    }

    public function test_a_category_with_text_of_its_own_is_a_link_target(): void
    {
        $group = $this->categoryGroup();
        $rich = $this->category($group, 'Climbing roses', str_repeat('Climbing roses cover walls and fences with summer flowers. ', 6));
        $bare = $this->category($group, 'Shrub roses', 'Roses.');
        $this->runQueue();

        $row = $this->plugin->entryIndex->row(CraftLinkSource::ref($rich));
        $this->assertSame(RowKind::Category, $row?->kind);
        $this->assertSame('{category:' . $rich->id . '@' . $this->siteId() . ':url}', $row->link);
        $this->assertSame('/plants/climbing-roses', $row->url);
        $this->assertNull($this->plugin->entryIndex->row(CraftLinkSource::ref($bare)));

        $related = $this->plugin->linkIndex->related("Growing climbing roses\n\nClimbing roses on a wall.", 'linkJournal', $this->siteId());
        $this->assertSame(['Climbing roses'], array_map(fn($entry) => $entry->title, $related));
        $this->assertSame('Plants', $related[0]->type);

        Craft::$app->getElements()->deleteElement($rich);
        $this->assertNull($this->plugin->entryIndex->row(CraftLinkSource::ref($rich)));
    }

    public function test_the_link_index_tables_are_installed(): void
    {
        $this->assertTrue(Craft::$app->getDb()->tableExists(Store::INDEX_STEMS));
        $this->assertNotNull(Craft::$app->getDb()->getTableSchema(Store::ENTRY_INDEX, true)->getColumn('scope'));
    }

    private function siteId(): int
    {
        return (int) Craft::$app->getSites()->getPrimarySite()->id;
    }

    private function row(Entry $entry): ?\NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow
    {
        return $this->plugin->entryIndex->row(new EntryRef((string) $entry->getSection()->handle, (int) $entry->id, (int) $entry->siteId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $group): array
    {
        return array_map(fn($data) => json_decode((string) $data, true), (new \craft\db\Query())->select('data')->from(Store::ENTRY_INDEX)->where(['groupHandle' => $group])->column());
    }

    private function categoryGroup(): CategoryGroup
    {
        $field = $this->makeField(PlainText::class, 'plantText', ['multiline' => true]);
        $layout = new FieldLayout(['type' => Category::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new TitleField(['attribute' => 'title']), new CustomField($field)]);
        $layout->setTabs([$tab]);

        $group = new CategoryGroup(['name' => 'Plants', 'handle' => 'plants']);
        $group->setFieldLayout($layout);
        $group->setSiteSettings([$this->siteId() => new CategoryGroup_SiteSettings(['siteId' => $this->siteId(), 'hasUrls' => true, 'uriFormat' => 'plants/{slug}', 'template' => '_category'])]);

        if (!Craft::$app->getCategories()->saveGroup($group)) {
            throw new RuntimeException('Could not save the category group: ' . json_encode($group->getErrors()));
        }

        return $group;
    }

    private function category(CategoryGroup $group, string $title, string $text): Category
    {
        $category = new Category(['groupId' => $group->id, 'title' => $title]);
        $category->setFieldValue('plantText', $text);

        if (!Craft::$app->getElements()->saveElement($category)) {
            throw new RuntimeException('Could not save the category: ' . json_encode($category->getErrors()));
        }

        return $category;
    }
}
