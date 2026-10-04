<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ExternalLinkCheck;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\HttpLinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitIndex;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitOptions;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitScanner;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use nineteenninetyfour\ghostwriter\ai\CraftLogger;
use nineteenninetyfour\ghostwriter\jobs\RefreshRevisit;
use nineteenninetyfour\ghostwriter\jobs\RefreshRevisitEntry;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Keeps Content to revisit and Suggest edits' records in step with the
 * site, with no model:
 *
 * - saved(): one entry saved: its row, its paragraphs in the index, and
 *   its latest review's Done and Stale (core's Reconciler);
 * - linkSaved(): a page of any other section with URLs (or a category)
 *   saved: its link row in the index (SEO layer §7.1), nothing else;
 * - deleted(): its row, index entry and reviews forgotten, and every
 *   page that linked to it checked again;
 * - daily(): entries saved since the last run and rows whose day has
 *   come, a full pass once a week (and the first time), the link rows
 *   (LinkRows), and suggestions nobody acted on for 14 days expired;
 * - links(): the weekly check of links to other sites, only when an admin
 *   has turned it on.
 *
 * Also where core's pieces are put together for the plugin: the checks,
 * the reviews (EditReviews over the plugin's store and lock), the scanner
 * and the index.
 */
class Revisit extends Component
{
    /** Days between full passes. */
    public const FULL_EVERY = 7;

    /** The daily pass is due again after this long (seconds). */
    public const DAILY = 86400;

    /** The cron line that keeps it daily, for the list and the settings. */
    public const CRON = '0 3 * * * php craft ghostwriter/revisit/refresh';

    /** Where each site's last pass is kept, in Ghostwriter's state. */
    public const STATE = 'revisit.state';

    /** The probe for links to other sites; core's HttpLinkProbe over the plugin's clients unless set (tests). */
    public ?LinkProbe $probe = null;

    /** How the link check waits between requests to one site; really waits unless set (tests). */
    public ?\NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Sleeper $sleeper = null;

    private ?EntryChecks $checks = null;

    /** @var array<int, array{written: int, forgotten: int, promoted: int, pending: int}> What the last daily() did to each site's link rows. */
    public array $linkResults = [];

    private ?CraftEntrySource $source = null;

    private ?CraftLinkSource $linkSource = null;

    private ?LinkRows $linkRows = null;

    public function checks(): EntryChecks
    {
        return $this->checks ??= new EntryChecks();
    }

    public function source(): CraftEntrySource
    {
        return $this->source ??= new CraftEntrySource($this);
    }

    /**
     * Core's Suggest edits service over the plugin's store, lock and
     * Studio. Made each time, so a faked provider applies at once.
     */
    public function linkSource(): CraftLinkSource
    {
        return $this->linkSource ??= new CraftLinkSource();
    }

    public function linkRows(): LinkRows
    {
        return $this->linkRows ??= new LinkRows($this);
    }

    public function reviews(): EditReviews
    {
        $plugin = Plugin::getInstance();

        return new EditReviews($plugin->editReviewStore, $plugin->lock, $plugin->studio->core(), logger: new CraftLogger());
    }

    public function scanner(): RevisitScanner
    {
        return new RevisitScanner($this->checks()->age(), ownHosts: EntryChecks::ownHosts());
    }

    public function index(): RevisitIndex
    {
        return new RevisitIndex($this->scanner(), Plugin::getInstance()->revisitStore);
    }

    /** What asks another site whether a page is there: core's probe over Craft's Guzzle clients. */
    public function linkProbe(): LinkProbe
    {
        return $this->probe ?? new HttpLinkProbe(Plugin::getInstance()->providers->httpClients());
    }

    public function linkCheck(): ExternalLinkCheck
    {
        return new ExternalLinkCheck($this->linkProbe(), $this->scanner(), $this->sleeper ?? new \NineteenNinetyFour\Ghostwriter\Core\Ai\Http\SystemSleeper(), new CraftLogger());
    }

    /** Decisions that keep an entry's findings quiet: none if they can't be read. */
    public function quieted(EntryRef $ref, ?DateTimeImmutable $now = null): Quieted
    {
        try {
            return $this->reviews()->quieted($ref, $now ?? new DateTimeImmutable());
        } catch (Throwable) {
            return new Quieted();
        }
    }

    /**
     * Whether a save of this entry is Content to revisit's business: the
     * entry itself (not a draft, a revision or a nested entry) in a
     * section Ghostwriter writes for.
     */
    public static function follows(Entry $entry): bool
    {
        $section = $entry->getSection();

        return $section !== null
            && !ElementHelper::isDraftOrRevision($entry)
            && $entry->getIsCanonical()
            && Plugin::getInstance()->types->enabled($section->handle);
    }

    /**
     * Whether a save of this element is the link index's business only: a
     * page of a section with URLs that isn't one of Ghostwriter's, or a
     * category of a group with URLs (SEO layer §7.1).
     */
    public static function linksTo(ElementInterface $element): bool
    {
        return CraftLinkSource::linkable($element) && !($element instanceof Entry && self::follows($element));
    }

    /**
     * Queue an entry's (or a category's) refresh once: a second save
     * while it waits finds it queued, as the job reads the element when
     * it runs.
     */
    public static function queue(Entry|Category $element): void
    {
        $category = $element instanceof Category;
        $key = RefreshRevisitEntry::cacheKey((int) $element->id, (int) $element->siteId, $category);

        if (Craft::$app->getCache()->add($key, 1, 600)) {
            RefreshRevisitEntry::start(['entryId' => (int) $element->id, 'siteId' => (int) $element->siteId, 'category' => $category]);
        }
    }

    public function saved(Entry $entry, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $ref = EntryChecks::ref($entry);
        $context = $this->checks()->context($entry, $now);

        Plugin::getInstance()->entryIndex->put($ref, (string) $entry->title, $entry->getUrl(), $context, self::summary($entry), $this->linkSource()->row($entry, IndexScope::Full, $now));
        $this->index()->refreshOne($this->source(), $ref, $now);

        try {
            $this->reviews()->saved($ref, $context, $now);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't bring a review of entry {$entry->id} up to date: {$exception->getMessage()}", 'ghostwriter');
        }
    }

    /**
     * A page of a section with URLs that isn't one of Ghostwriter's, or a
     * category, saved: its link row written again, or forgotten when it
     * can't be linked to any more (disabled, no URI, no text of its own).
     */
    public function linkSaved(Entry|Category $element, ?DateTimeImmutable $now = null): void
    {
        $row = $this->linkSource()->row($element, IndexScope::Link, $now);

        if ($row !== null) {
            Plugin::getInstance()->entryIndex->putRow($row);
        }
    }

    /**
     * An entry or category deleted: its rows and index entries on every
     * site forgotten, and every page that linked to it checked again. Its
     * reviews go only when it's gone for good (not in the trash).
     */
    public function deleted(Entry|Category $entry, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $plugin = Plugin::getInstance();
        $category = $entry instanceof Category;
        $group = $category ? CraftLinkSource::CATEGORY . $entry->getGroup()->handle : (string) ($entry->getSection()?->handle ?? '');
        $target = $category ? "{category:{$entry->id}}" : "{entry:{$entry->id}}";

        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            $ref = new EntryRef($group, (int) $entry->id, (int) $siteId);

            $plugin->entryIndex->forget($ref);
            $this->index()->deleted($this->source(), $ref, $now, [$target]);

            if ($entry->hardDelete && !$category) {
                foreach ($plugin->editReviewStore->history($ref) as $review) {
                    $plugin->editReviewStore->delete($review->id);
                }
            }
        }
    }

    /**
     * The daily pass, for each site. Returns how many entries were read.
     */
    public function daily(?DateTimeImmutable $now = null, bool $full = false, ?int $site = null): int
    {
        $now ??= new DateTimeImmutable();
        $store = Plugin::getInstance()->store;
        $state = $store->state(self::STATE);
        $read = 0;

        foreach ($site === null ? Craft::$app->getSites()->getAllSiteIds(true) : [$site] as $siteId) {
            $key = (string) $siteId;
            $last = self::time($state['sites'][$key]['run'] ?? null);
            $lastFull = self::time($state['sites'][$key]['full'] ?? null);
            $whole = $full || $last === null || $lastFull === null || $lastFull <= $now->modify('-' . self::FULL_EVERY . ' days');

            if ($whole) {
                foreach ($this->source()->all((int) $siteId) as $snapshot) {
                    $entry = Entry::find()->id((int) $snapshot->ref->id)->siteId((int) $siteId)->one();

                    if ($entry instanceof Entry) {
                        Plugin::getInstance()->entryIndex->put($snapshot->ref, $snapshot->title, $entry->getUrl(), $snapshot->context, self::summary($entry), $this->linkSource()->row($entry, IndexScope::Full, $now));
                    }
                }
            }

            $read += $this->index()->refresh($this->source(), $now, $whole ? null : $last, (int) $siteId, $whole);

            // Link rows: every other routable page of the site (SEO layer §7.1).
            try {
                $this->linkRows()->pass((int) $siteId, $now, $whole);
                $this->linkResults[(int) $siteId] = $this->linkRows()->last;
            } catch (Throwable $exception) {
                Craft::warning("Ghostwriter couldn't bring the link index up to date for site {$siteId}: {$exception->getMessage()}", 'ghostwriter');
            }

            $state['sites'][$key] = ['run' => $now->format(DATE_ATOM), 'full' => $whole ? $now->format(DATE_ATOM) : ($state['sites'][$key]['full'] ?? null)];
            $store->putState(self::STATE, $state);
        }

        $this->reviews()->expire($now);

        return $read;
    }

    /**
     * The weekly check of links to other sites. Nothing is asked of any
     * other site unless an admin has turned it on.
     */
    public function links(?DateTimeImmutable $now = null): int
    {
        if (!Plugin::getInstance()->getSettings()->checksExternalLinks()) {
            return 0;
        }

        $now ??= new DateTimeImmutable();
        $check = $this->linkCheck();
        $checked = 0;

        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            $checked += $check->run(Plugin::getInstance()->revisitStore, new RevisitOptions(externalLinks: true), $now, (int) $siteId);
        }

        return $checked;
    }

    /** When the list was last brought up to date for a site; null before the first pass. */
    public function lastRun(?int $site = null): ?DateTimeImmutable
    {
        $site ??= (int) Craft::$app->getSites()->getPrimarySite()->id;

        return self::time(Plugin::getInstance()->store->state(self::STATE)['sites'][(string) $site]['run'] ?? null);
    }

    /** "3 days ago", or null before the first pass. */
    public function lastRunText(?int $site = null): ?string
    {
        $last = $this->lastRun($site);

        return $last ? Craft::$app->getFormatter()->asRelativeTime($last->getTimestamp()) : null;
    }

    /** Whether any site's daily pass is due: never run, or over a day ago. */
    public function due(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            $last = $this->lastRun((int) $siteId);

            if ($last === null || $last <= $now->modify('-' . self::DAILY . ' seconds')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The daily pass, queued, when it's due and isn't waiting already:
     * for Craft's garbage collection and the list, on a site with no cron.
     */
    public function queueIfDue(?DateTimeImmutable $now = null): bool
    {
        if (!$this->due($now) || !Craft::$app->getCache()->add(RefreshRevisit::CACHE_KEY, 1, 1800)) {
            return false;
        }

        RefreshRevisit::start();

        return true;
    }

    /** A short summary of an entry for the digest: its summary field, if it has one. */
    public static function summary(Entry $entry): string
    {
        foreach (['summary', 'excerpt', 'intro', 'metaDescription', 'description'] as $handle) {
            try {
                $value = $entry->getFieldLayout()?->getFieldByHandle($handle) ? $entry->getFieldValue($handle) : null;
            } catch (Throwable) {
                $value = null;
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
