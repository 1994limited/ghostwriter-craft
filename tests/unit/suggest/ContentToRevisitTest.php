<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkStatus;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use nineteenninetyfour\ghostwriter\jobs\RefreshRevisit;
use nineteenninetyfour\ghostwriter\jobs\RefreshRevisitEntry;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Content to revisit kept current with no model: a save queues the
 * entry's refresh once, a delete checks again the pages that linked to
 * it, the daily command reads what changed, and the weekly check of links
 * to other sites asks nobody anything until an admin turns it on.
 */
class ContentToRevisitTest extends TestCase
{
    private Section $section;

    private Entry $showGarden;

    private Entry $services;

    protected function _before(): void
    {
        parent::_before();

        $eyebrow = $this->makeField(PlainText::class, 'eyebrow');
        $body = $this->makeField(Ckeditor::class, 'revisitBody');
        $this->section = $this->makeSection('revisitPages', [$this->makeEntryType('revisitPage', [$eyebrow, $body])], Section::TYPE_STRUCTURE);

        $this->showGarden = $this->makeEntry($this->section, 'Our show garden');
        $this->services = $this->makeEntry($this->section, 'Services', [
            'eyebrow' => 'New for 2024: winter care visits',
            'revisitBody' => '<p>See <a href="{entry:' . $this->showGarden->id . '@1:url||https://northfold.test/show-garden}">our show garden</a>, or <a href="https://example.org/gone">the RHS write-up</a>.</p>',
        ]);

        $this->runQueue();
    }

    private function row(Entry $entry): ?RevisitRow
    {
        return $this->plugin->revisitStore->get(EntryChecks::ref($entry));
    }

    public function test_a_save_scans_the_entry_with_no_model(): void
    {
        $row = $this->row($this->services);

        $this->assertNotNull($row);
        $this->assertTrue($row->has(ReasonKind::PastYear));
        $this->assertGreaterThan(0, $row->score);
        $this->assertNotEmpty(array_filter($row->linksTo, fn(string $target) => str_starts_with($target, '{entry:' . $this->showGarden->id . '@')));
        $this->assertSame([], $this->fake->requests());
    }

    public function test_saving_twice_while_it_waits_queues_it_once(): void
    {
        $this->clearQueue();
        Craft::$app->getElements()->saveElement($this->services);
        Craft::$app->getElements()->saveElement($this->services);

        $this->assertCount(1, $this->queued(RefreshRevisitEntry::class));
    }

    public function test_a_draft_queues_nothing(): void
    {
        $this->clearQueue();
        Craft::$app->getDrafts()->createDraft($this->services, 1);

        $this->assertSame([], $this->queued(RefreshRevisitEntry::class));
    }

    public function test_deleting_a_page_flags_the_pages_that_linked_to_it(): void
    {
        $this->assertFalse($this->row($this->services)->has(ReasonKind::BrokenLink));

        Craft::$app->getElements()->deleteElement($this->showGarden);

        $this->assertTrue($this->row($this->services)->has(ReasonKind::BrokenLink));
        $this->assertNull($this->row($this->showGarden));
    }

    public function test_disabling_takes_the_entry_off_the_list(): void
    {
        $this->services->enabled = false;
        Craft::$app->getElements()->saveElement($this->services);
        $this->runQueue();

        $this->assertNull($this->row($this->services));
    }

    public function test_the_daily_command_reads_everything_the_first_time(): void
    {
        $this->plugin->revisitStore->forget(EntryChecks::ref($this->services));

        $read = $this->plugin->revisit->daily();

        $this->assertGreaterThanOrEqual(2, $read);
        $this->assertNotNull($this->row($this->services));
        $this->assertNotNull($this->plugin->revisit->lastRun());
        $this->assertFalse($this->plugin->revisit->due());
        $this->assertSame([], $this->fake->requests());
    }

    public function test_the_full_pass_forgets_an_entry_that_went_without_a_word(): void
    {
        $ref = EntryChecks::ref($this->showGarden);
        $this->plugin->revisit->daily(full: true);
        $this->assertNotNull($this->plugin->entryIndex->row($ref));

        // Gone with no event (a query, or while the plugin was off).
        \craft\helpers\Db::delete(\craft\db\Table::ELEMENTS, ['id' => $this->showGarden->id]);

        $this->plugin->revisit->daily(full: true);

        $this->assertNull($this->plugin->entryIndex->row($ref), 'Out of the index.');
        $this->assertNull($this->plugin->revisitStore->get($ref), 'Off the list.');
        $this->assertNotNull($this->plugin->entryIndex->row(EntryChecks::ref($this->services)), 'The rest stay.');
    }

    public function test_garbage_collection_queues_the_daily_pass_when_it_is_due(): void
    {
        $this->clearQueue();
        $this->assertTrue($this->plugin->revisit->due());

        Craft::$app->getGc()->run(true);
        Craft::$app->getGc()->run(true);

        $this->assertCount(1, $this->queued(RefreshRevisit::class));
    }

    public function test_links_to_other_sites_are_never_checked_until_an_admin_turns_it_on(): void
    {
        $probe = new class implements LinkProbe {
            /** @var list<string> */
            public array $asked = [];

            public function probe(string $url, int $timeout): LinkResult
            {
                $this->asked[] = $url;

                return new LinkResult($url, LinkStatus::Broken, 404, gmdate(DATE_ATOM));
            }
        };
        $this->plugin->revisit->probe = $probe;

        $this->assertSame(0, $this->plugin->revisit->links());
        $this->assertSame([], $probe->asked, 'Off by default: nobody is asked.');

        $this->plugin->getSettings()->checkExternalLinks = true;
        $this->plugin->revisit->links();

        $this->assertSame(['https://example.org/gone'], $probe->asked);
        $this->assertSame(LinkStatus::Broken, $this->row($this->services)->external['https://example.org/gone']->status);
    }

    public function test_the_list_ranks_pages_with_their_reasons_and_a_review_link(): void
    {
        $this->signIn(extra: ['viewEntries:' . $this->section->uid, 'saveEntries:' . $this->section->uid]);
        $this->plugin->revisit->daily(full: true);

        $page = $this->action('ghostwriter/revisit/show', [], 'GET');
        $this->assertSame(200, $page['status']);
        $this->assertSame('ghostwriter/revisit', $page['data']['template']);
        $rows = $page['data']['variables']['rows'];
        $this->assertSame('Services', $rows[0]['title']);
        $this->assertSame('high', $rows[0]['reasons'][0]['severity']);
        $this->assertStringEndsWith('ghostwriter=suggest', (string) $rows[0]['reviewUrl']);
        $this->assertFalse($page['data']['variables']['reading']);

        $this->assertSame([], $this->action('ghostwriter/revisit/show', ['show' => 'missing-alt'], 'GET')['data']['variables']['rows']);

        $overview = $this->action('ghostwriter/dashboard/index', [], 'GET');
        $this->assertSame('Services', $overview['data']['variables']['revisit']['top'][0]['title']);
        $this->assertSame([], $this->fake->requests(), 'No model.');
    }

    public function test_only_sections_the_person_can_view_are_listed(): void
    {
        $this->signIn();
        $this->plugin->revisit->daily(full: true);

        $this->assertSame([], $this->action('ghostwriter/revisit/show', [], 'GET')['data']['variables']['rows']);
    }

    public function test_a_snoozed_page_leaves_the_list_for_everyone_and_needs_the_right_to_save_it(): void
    {
        $this->signIn(extra: ['viewEntries:' . $this->section->uid]);
        $key = EntryChecks::ref($this->services)->key();

        $this->assertSame(403, $this->action('ghostwriter/revisit/snooze', ['key' => $key])['status']);

        $this->signIn(extra: ['viewEntries:' . $this->section->uid, 'saveEntries:' . $this->section->uid, 'viewPeerEntries:' . $this->section->uid, 'savePeerEntries:' . $this->section->uid]);
        $this->assertSame(200, $this->action('ghostwriter/revisit/snooze', ['key' => $key])['status']);
        $this->assertNotNull($this->row($this->services)->snoozedUntil);
        $this->assertSame([], $this->action('ghostwriter/revisit/show', [], 'GET')['data']['variables']['rows']);
    }

    public function test_opening_the_list_queues_the_daily_pass_when_it_is_due(): void
    {
        $this->signIn(extra: ['viewEntries:' . $this->section->uid]);
        $this->clearQueue();

        $page = $this->action('ghostwriter/revisit/show', [], 'GET')['data']['variables'];

        $this->assertTrue($page['reading']);
        $this->assertTrue($page['stale']);
        $this->assertCount(1, $this->queued(RefreshRevisit::class));
    }

    public function test_the_tables_are_installed(): void
    {
        foreach ([\nineteenninetyfour\ghostwriter\Store::EDIT_REVIEWS, \nineteenninetyfour\ghostwriter\Store::REVISIT, \nineteenninetyfour\ghostwriter\Store::REVISIT_LINKS, \nineteenninetyfour\ghostwriter\Store::ENTRY_INDEX] as $table) {
            $this->assertTrue(Craft::$app->getDb()->tableExists($table), $table);
        }
    }

    public function test_the_three_settings_have_their_defaults(): void
    {
        $settings = new \nineteenninetyfour\ghostwriter\models\Settings();

        $this->assertFalse($settings->checksExternalLinks());
        $this->assertTrue($settings->checksClaims());
        $this->assertSame([], $settings->ageInFull());
    }

    public function test_a_channel_counts_age_a_quarter_unless_an_admin_says_in_full(): void
    {
        $news = $this->makeSection('revisitNews', [$this->makeEntryType('revisitPost', [])]);

        $this->assertSame(0.25, $this->plugin->revisit->checks()->age()->weight('revisitNews'));
        $this->assertSame(1.0, $this->plugin->revisit->checks()->age()->weight('revisitPages'));

        $this->plugin->getSettings()->ageInFullSections = [$news->handle];
        $this->assertSame(1.0, $this->plugin->revisit->checks()->age()->weight('revisitNews'));
    }
}
