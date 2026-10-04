<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use Craft;
use craft\helpers\Db;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EntrySourceContract;
use nineteenninetyfour\ghostwriter\suggest\CraftEntrySource;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\tests\support\SuggestSites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's EntrySourceContract against the plugin's: the live entries of
 * Ghostwriter's sections, one site at a time.
 */
class EntrySourceTest extends TestCase
{
    use EntrySourceContract;
    use SuggestSites;

    /** @var array{old: EntryRef, recent: EntryRef, draft: EntryRef, other: EntryRef, site: int|string, between: DateTimeImmutable} */
    private array $entries;

    protected function _before(): void
    {
        parent::_before();

        $this->twoSites();
        $now = new DateTimeImmutable();
        $body = ['body' => 'A full design for your garden from our team of designers, with a planting plan for every border.'];

        $save = function(string $title, $site, bool $live, DateTimeImmutable $updated) use ($body): EntryRef {
            $entry = $this->siteEntry($this->sitePages, $site, $title, $body, $live);
            // When it was last saved, as if long ago.
            Db::update('{{%elements}}', ['dateUpdated' => Db::prepareDateForDb($updated)], ['id' => $entry->id]);
            Db::update('{{%elements_sites}}', ['dateUpdated' => Db::prepareDateForDb($updated)], ['elementId' => $entry->id]);

            return EntryChecks::ref($entry);
        };

        $this->entries = [
            'old' => $save('Old', $this->english, true, $now->modify('-400 days')),
            'recent' => $save('Recent', $this->english, true, $now->modify('-1 day')),
            'draft' => $save('Draft', $this->english, false, $now->modify('-1 day')),
            'other' => $save('Other', $this->welsh, true, $now->modify('-1 day')),
            'site' => (int) $this->english->id,
            'between' => $now->modify('-30 days'),
        ];

        Craft::$app->getElements()->invalidateAllCaches();
    }

    protected function _after(): void
    {
        $this->dropWelsh();

        parent::_after();
    }

    protected function entrySource(): EntrySource
    {
        return $this->plugin->revisit->source();
    }

    protected function sourceEntries(): array
    {
        return $this->entries;
    }

    public function test_the_plugin_has_its_own(): void
    {
        $this->assertInstanceOf(CraftEntrySource::class, $this->entrySource());
    }

    public function test_only_ghostwriters_sections(): void
    {
        $this->plugin->getSettings()->sections = ['siteJournal'];

        $this->assertSame([], iterator_to_array($this->entrySource()->all((int) $this->english->id), false));
        $this->assertNull($this->entrySource()->find($this->entries['old']));
    }
}
