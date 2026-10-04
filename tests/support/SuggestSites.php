<?php

namespace nineteenninetyfour\ghostwriter\tests\support;

use Craft;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use RuntimeException;

/**
 * Two sites (English and Welsh) and two sections on both, Pages (a
 * structure) and a dated Journal (a channel), for Suggest edits' and
 * Content to revisit's tests. The Welsh site is removed again after
 * each test.
 */
trait SuggestSites
{
    protected Site $english;

    protected ?Site $welsh = null;

    protected Section $sitePages;

    protected Section $siteJournal;

    protected function twoSites(): void
    {
        $sites = Craft::$app->getSites();
        $this->english = $sites->getPrimarySite();

        $welsh = new Site([
            'groupId' => $this->english->groupId,
            'name' => 'Welsh',
            'handle' => 'cy',
            'language' => 'cy-GB',
            'hasUrls' => true,
            'baseUrl' => 'https://northfold.test/cy/',
        ]);

        if (!$sites->saveSite($welsh)) {
            throw new RuntimeException('Could not save the Welsh site: ' . json_encode($welsh->getErrors()));
        }

        $this->welsh = $welsh;
        // Craft remembers whether it's multi-site; it is now.
        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);

        $summary = Craft::$app->getFields()->getFieldByHandle('summary') ?? $this->makeField(PlainText::class, 'summary', ['multiline' => true]);
        $body = Craft::$app->getFields()->getFieldByHandle('body') ?? $this->makeField(PlainText::class, 'body', ['multiline' => true]);

        $this->sitePages = $this->sectionOnBoth('sitePages', Section::TYPE_STRUCTURE, $this->makeEntryType('sitePage', [$summary, $body]));
        $this->siteJournal = $this->sectionOnBoth('siteJournal', Section::TYPE_CHANNEL, $this->makeEntryType('sitePost', [$summary, $body]));
    }

    private function sectionOnBoth(string $handle, string $type, \craft\models\EntryType $entryType): Section
    {
        $section = new Section([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'type' => $type,
            'propagationMethod' => Section::PROPAGATION_METHOD_NONE,
            'siteSettings' => array_map(fn(Site $site) => new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => true,
                'uriFormat' => $handle . '/{slug}',
                'template' => '_entry',
            ]), [$this->english, $this->welsh]),
        ]);
        $section->setEntryTypes([$entryType]);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException("Could not save the {$handle} section: " . json_encode($section->getErrors()));
        }

        return $section;
    }

    /**
     * An entry on one site, saved as an editor would.
     *
     * @param array<string, mixed> $fields
     */
    protected function siteEntry(Section $section, Site $site, string $title, array $fields = [], bool $live = true): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => $site->id,
            'title' => $title,
            'slug' => \craft\helpers\StringHelper::toKebabCase($title),
            'enabled' => $live,
            'authorId' => 1,
        ]);
        $entry->setFieldValues($fields);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException("Could not save \"{$title}\": " . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    protected function dropWelsh(): void
    {
        if ($this->welsh !== null) {
            try {
                Craft::$app->getSites()->deleteSite($this->welsh);
            } catch (\Throwable) {
                // Rolled back with the test's transaction anyway.
            }

            $this->welsh = null;
            Craft::$app->getIsMultiSite(true);
            Craft::$app->getIsMultiSite(true, true);
        }
    }
}
