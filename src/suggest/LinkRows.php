<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\elements\Entry;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkPlan;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * The link rows' part of the daily pass (SEO layer §7.1, "Keeping it
 * fresh"), one site at a time, with core's LinkPlan deciding what to do:
 *
 * - pages of a section that has become one of Ghostwriter's get full rows
 *   (Revisit::saved()) in place of their link rows;
 * - every linkable page of the other routable groups (CraftLinkSource) is
 *   compared with its row: missing, older than the page, written before
 *   its group was marked (a section's URLs or a structure changed) or
 *   before the weekly full pass began, and it's written; rows whose page
 *   is gone, no longer linkable or over a cap are forgotten;
 * - at most LinkCandidates::PER_RUN rows a run, in chunks of CHUNK, so a
 *   big site's first build is spread over several nights;
 * - groups over their cap are kept for the settings page's developer note.
 *
 * Marks and each site's progress are in Ghostwriter's state (STATE).
 */
class LinkRows
{
    /** Where marks and each site's progress are kept, in Ghostwriter's state. */
    public const STATE = 'links.state';

    /** @var array{written: int, forgotten: int, promoted: int, pending: int} What the last pass() did. */
    public array $last = ['written' => 0, 'forgotten' => 0, 'promoted' => 0, 'pending' => 0];

    public function __construct(private readonly Revisit $revisit) {}

    /**
     * A group's routes or structure changed: its link rows are written
     * again on each site's next daily pass.
     */
    public static function mark(string $group, ?DateTimeImmutable $now = null): void
    {
        $store = Plugin::getInstance()->store;
        $state = $store->state(self::STATE);
        $at = ($now ?? new DateTimeImmutable())->format(DATE_ATOM);

        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
            $state['sites'][(string) $siteId]['marked'][$group] = $at;
        }

        $store->putState(self::STATE, $state);
    }

    /**
     * One site's link rows brought up to date. $full starts the weekly
     * full pass: every row written before now is written again, over as
     * many runs as it takes. Returns how many rows were written.
     */
    public function pass(int $siteId, DateTimeImmutable $now, bool $full = false, int $perRun = LinkCandidates::PER_RUN, int $groupCap = LinkCandidates::GROUP_ROWS, int $siteCap = LinkCandidates::SITE_ROWS): int
    {
        $plugin = Plugin::getInstance();
        $index = $plugin->entryIndex;
        $source = $this->revisit->linkSource();
        $store = $plugin->store;
        $state = $store->state(self::STATE);
        $site = $state['sites'][(string) $siteId] ?? [];

        // The weekly full pass, unless one is still being worked through.
        if ($full && (!is_string($site['fullFrom'] ?? null) || (int) ($site['pending'] ?? 0) === 0)) {
            $site['fullFrom'] = $now->format(DATE_ATOM);
        }

        $fullGroups = $source->fullGroups();
        $promoted = $this->promote($siteId, $fullGroups, $now, $perRun);

        $pages = [];
        $listed = [];

        foreach ($source->pages($siteId) as $page) {
            $pages[] = ['key' => $page['key'], 'group' => $page['group'], 'updated' => $page['updated'], 'key_page' => $page['key_page']];
            $listed[$page['key']] = ['kind' => $page['kind'], 'id' => $page['id']];
        }

        $marked = array_filter(is_array($site['marked'] ?? null) ? $site['marked'] : [], 'is_string');
        $plan = LinkPlan::make($pages, $index->planRows($siteId, $fullGroups), $marked, is_string($site['fullFrom'] ?? null) ? $site['fullFrom'] : null, max(0, $perRun - $promoted), $groupCap, $siteCap);

        $index->forgetKeys($plan->forget);
        $written = 0;

        foreach ($plan->chunks() as $chunk) {
            $rows = $source->rows(array_values(array_filter(array_map(fn(string $key) => $listed[$key] ?? null, $chunk))), $siteId, $now);
            $index->putRows($rows);
            $written += count($rows);
        }

        // Read again: a group may have been marked while the pass ran.
        // Marks are done with once every row they asked for is written.
        $state = $store->state(self::STATE);
        $marks = array_filter(is_array($state['sites'][(string) $siteId]['marked'] ?? null) ? $state['sites'][(string) $siteId]['marked'] : [], 'is_string');

        if ($plan->pending === 0) {
            $marks = array_filter($marks, fn(string $at) => self::time($at) > $now->getTimestamp());
        }

        $state['sites'][(string) $siteId] = [
            'fullFrom' => $site['fullFrom'] ?? null,
            'pending' => $plan->pending,
            'over' => $plan->over,
            'cap' => $groupCap,
            'run' => $now->format(DATE_ATOM),
            'marked' => $marks,
        ];
        $store->putState(self::STATE, $state);
        $this->last = ['written' => $written, 'forgotten' => count($plan->forget), 'promoted' => $promoted, 'pending' => $plan->pending];

        return $written;
    }

    /**
     * Groups over their cap, for the settings page: "Products has 48,000
     * entries; Ghostwriter links to the 5,000 most recently updated."
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $over = [];

        foreach (Plugin::getInstance()->store->state(self::STATE)['sites'] ?? [] as $site) {
            foreach (is_array($site['over'] ?? null) ? $site['over'] : [] as $group => $count) {
                $cap = min((int) $count, (int) ($site['cap'] ?? LinkCandidates::GROUP_ROWS));
                $over[(string) $group] = [max($over[(string) $group][0] ?? 0, (int) $count), $cap];
            }
        }

        $notes = [];
        $formatter = Craft::$app->getFormatter();

        foreach ($over as $group => [$count, $cap]) {
            $notes[] = Craft::t('ghostwriter', '{group} has {count} entries; Ghostwriter links to the {cap} most recently updated.', [
                'group' => $this->revisit->linkSource()->label($group),
                'count' => $formatter->asInteger($count),
                'cap' => $formatter->asInteger($cap),
            ]);
        }

        return $notes;
    }

    /**
     * Pages of a section that has become one of Ghostwriter's: their link
     * rows become full rows. Returns how many.
     *
     * @param list<string> $fullGroups
     */
    private function promote(int $siteId, array $fullGroups, DateTimeImmutable $now, int $limit): int
    {
        $index = Plugin::getInstance()->entryIndex;
        $done = 0;

        foreach ($index->linkRowsIn($siteId, $fullGroups, $limit) as $ref) {
            $entry = Entry::find()->id((int) $ref->id)->siteId($siteId)->status(null)->one();

            try {
                if ($entry instanceof Entry && Revisit::follows($entry)) {
                    $this->revisit->saved($entry, $now);
                } else {
                    $index->forget($ref);
                }
            } catch (Throwable $exception) {
                Craft::warning("Ghostwriter couldn't index {$ref->key()} fully: {$exception->getMessage()}", 'ghostwriter');
                $index->forget($ref);
            }

            $done++;
        }

        return $done;
    }

    private static function time(string $value): int
    {
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
