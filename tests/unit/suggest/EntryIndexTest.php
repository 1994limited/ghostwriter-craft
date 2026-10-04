<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EntryIndexContract;
use nineteenninetyfour\ghostwriter\suggest\DbEntryIndex;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\tests\support\SuggestSites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's EntryIndexContract against the plugin's index table, filled by
 * its own save hook (the queued refresh): four entries saved through
 * Craft, on two sites.
 */
class EntryIndexTest extends TestCase
{
    use EntryIndexContract;
    use SuggestSites;

    /** @var array{design: EntryRef, planting: EntryRef, journal: EntryRef, other: EntryRef} */
    private array $indexed;

    protected function _before(): void
    {
        parent::_before();

        $this->twoSites();

        $save = fn($section, $site, string $title, string $summary, string $body) => EntryChecks::ref($this->siteEntry($section, $site, $title, ['summary' => $summary, 'body' => $body]));

        $this->indexed = [
            'design' => $save($this->sitePages, $this->english, 'Garden design', 'Designs for whole gardens.', self::DESIGN),
            'planting' => $save($this->sitePages, $this->english, 'Planting plans', 'Planting plans for borders, pots and new gardens.', 'We draw planting plans for borders and pots, with every plant named and placed for the light it gets.'),
            'journal' => $save($this->siteJournal, $this->english, 'A walled garden in Corbridge', 'Two years on from a garden we finished.', 'Two years on, the walled garden in Corbridge has filled out and the espaliers are fruiting.'),
            'other' => $save($this->sitePages, $this->welsh, 'Garden design', 'Designs for whole gardens.', self::DESIGN),
        ];

        $this->runQueue();
    }

    protected function _after(): void
    {
        $this->dropWelsh();

        parent::_after();
    }

    protected function entryIndex(): EntryIndex
    {
        return $this->plugin->entryIndex;
    }

    protected function indexedEntries(): array
    {
        return $this->indexed;
    }

    public function test_the_plugin_has_its_own(): void
    {
        $this->assertInstanceOf(DbEntryIndex::class, $this->entryIndex());
    }

    public function test_a_deleted_entry_leaves_the_index(): void
    {
        \Craft::$app->getElements()->deleteElementById((int) $this->indexed['planting']->id, hardDelete: true);

        $this->assertNotContains($this->indexed['planting']->key(), array_map(fn($entry) => $entry->entry->key(), $this->entryIndex()->nearest($this->indexed['design'], 'Planting plans', 10)));
    }

    public function test_a_digest_entry_links_as_craft_stores_a_link(): void
    {
        $nearest = $this->entryIndex()->nearest($this->indexed['design'], 'Planting plans', 1)[0];

        $this->assertMatchesRegularExpression('/^\{entry:\d+@\d+:url\|\|/', (string) $nearest->link);
    }
}
