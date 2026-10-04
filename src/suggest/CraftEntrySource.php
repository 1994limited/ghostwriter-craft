<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\Section;
use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * The entries Content to revisit reads: the live entries of the sections
 * Ghostwriter writes for, one site at a time, in batches. Always the
 * canonical entry as saved, never anyone's draft.
 */
class CraftEntrySource implements EntrySource
{
    public function __construct(private readonly Revisit $revisit) {}

    public function all(int|string|null $site = null, int $chunk = 200): iterable
    {
        $sections = $this->sectionIds();

        if ($sections === []) {
            return;
        }

        foreach ($this->sites($site) as $siteId) {
            $query = Entry::find()->sectionId($sections)->siteId($siteId)->status(Entry::STATUS_LIVE)->orderBy(['elements.id' => SORT_ASC]);

            foreach ($query->each(max(1, $chunk)) as $entry) {
                if ($entry instanceof Entry && ($snapshot = $this->snapshot($entry)) !== null) {
                    yield $snapshot;
                }
            }
        }
    }

    public function updatedSince(DateTimeInterface $since, int|string|null $site = null): iterable
    {
        $sections = $this->sectionIds();

        if ($sections === []) {
            return;
        }

        foreach ($this->sites($site) as $siteId) {
            $query = Entry::find()->sectionId($sections)->siteId($siteId)->status(null)->dateUpdated('> ' . Db::prepareDateForDb(DateTimeImmutable::createFromInterface($since)));

            foreach ($query->each(200) as $entry) {
                if ($entry instanceof Entry) {
                    yield EntryChecks::ref($entry);
                }
            }
        }
    }

    public function find(EntryRef $ref): ?EntrySnapshot
    {
        if (!is_numeric($ref->id)) {
            return null;
        }

        $query = Entry::find()->id((int) $ref->id)->status(null);
        $query = is_numeric($ref->site) ? $query->siteId((int) $ref->site) : $query->site('*')->unique();
        $entry = $query->one();

        if (!$entry instanceof Entry || !in_array((int) $entry->sectionId, $this->sectionIds(), true)) {
            return null;
        }

        return $this->snapshot($entry);
    }

    public function snapshot(Entry $entry): ?EntrySnapshot
    {
        try {
            $ref = EntryChecks::ref($entry);

            return new EntrySnapshot(
                $ref,
                (string) ($entry->title ?: $entry->slug ?: $entry->id),
                $entry->getCpEditUrl(),
                // Decisions keep their findings off the list too: "It's
                // still right" for 12 months, or until the passage changes.
                $this->revisit->checks()->context($entry, quieted: $this->revisit->quieted($ref)),
                $entry->getStatus() === Entry::STATUS_LIVE,
            );
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't read entry {$entry->id} for Content to revisit: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function sectionIds(): array
    {
        return array_values(array_map(fn(Section $section) => (int) $section->id, Plugin::getInstance()->types->sections()));
    }

    /**
     * @return list<int>
     */
    private function sites(int|string|null $site): array
    {
        if ($site !== null && is_numeric($site)) {
            return [(int) $site];
        }

        return array_values(array_map(fn($found) => (int) $found->id, Craft::$app->getSites()->getAllSites(true)));
    }
}
