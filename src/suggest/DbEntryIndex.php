<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeZone;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Linkable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkLookup;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;
use nineteenninetyfour\ghostwriter\Store;
use Throwable;

/**
 * The site's pages as Suggest edits and the SEO layer compare a page with
 * them, one row per page and site in `ghostwriter_entry_index`, written
 * when the page is saved and by the daily pass. Two kinds of row (core's
 * IndexScope):
 *
 * - full: an entry of one of Ghostwriter's sections, with its paragraphs'
 *   shingles. Duplicates (Overlaps) look for paragraphs sharing shingles;
 *   the review's digest takes the entries whose titles and summaries
 *   share most words with the page.
 * - link: a page of any other section with URLs, or a category with its
 *   own text, kept only as a link target (title, address, summary, type,
 *   dates, robots, stems). Only related() reads these.
 *
 * Each row's stems are also in `ghostwriter_index_stems`, so on a big site
 * related() scores only the rows sharing a stem with the draft.
 *
 * No model, and no page is read when asked: only these rows.
 */
class DbEntryIndex implements EntryIndex, LinkIndex, LinkLookup
{
    /** @var array<string, array<string, array<string, mixed>>> A site's full rows, read once a request. */
    private array $loaded = [];

    public function sharing(array $shingles, EntryRef $except, int $limit = 5): array
    {
        if ($shingles === []) {
            return [];
        }

        $found = [];

        foreach ($this->entries($except->site) as $key => $entry) {
            if ($key === $except->key()) {
                continue;
            }

            foreach (is_array($entry['paragraphs'] ?? null) ? $entry['paragraphs'] : [] as $paragraph) {
                $paragraph = array_values(array_filter(is_array($paragraph) ? $paragraph : [], 'is_int'));
                $shared = count(array_intersect($shingles, $paragraph));

                if ($shared > 0) {
                    $found[] = [$shared, new IndexedParagraph(EntryRef::fromArray($entry['entry']), (string) ($entry['title'] ?? ''), $paragraph, self::href($entry))];
                }
            }
        }

        usort($found, fn(array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(fn(array $pair) => $pair[1], array_slice($found, 0, max(0, $limit)));
    }

    public function nearest(EntryRef $entry, string $text, int $limit = 20): array
    {
        $words = array_unique(NormalisedText::words($text));
        $scored = [];

        foreach ($this->entries($entry->site) as $key => $other) {
            if ($key === $entry->key()) {
                continue;
            }

            $ref = EntryRef::fromArray($other['entry']);
            $theirs = array_unique(NormalisedText::words(($other['title'] ?? '') . ' ' . ($other['summary'] ?? '')));
            $shared = count(array_intersect($words, $theirs));

            if ($shared === 0) {
                continue;
            }

            $scored[] = [$ref->group === $entry->group ? 1 : 0, $shared, (string) ($other['title'] ?? ''), $ref, $other];
        }

        usort($scored, fn(array $a, array $b) => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return array_map(fn(array $row) => new DigestEntry(
            $row[3],
            $row[2],
            self::href($row[4]),
            mb_substr((string) ($row[4]['summary'] ?? ''), 0, DigestEntry::SUMMARY),
            // A link to it as CKEditor and Link fields store one.
            '{entry:' . $row[3]->id . '@' . $row[3]->site . ':url||' . (self::href($row[4]) ?? '') . '}',
            is_string($row[4]['type'] ?? null) ? $row[4]['type'] : '',
        ), array_slice($scored, 0, max(0, $limit)));
    }

    public function related(string $text, string $group, int|string|null $site = null, ?EntryRef $except = null, int $limit = LinkCandidates::LIMIT, array $linked = [], ?DateTimeImmutable $now = null): array
    {
        $siteKey = (string) ($site ?? '');
        $locale = self::locale($site);
        $query = (new Query())->select(['data'])->from(Store::ENTRY_INDEX)->where(['site' => $siteKey]);

        // A big site: only the rows sharing a stem with the draft are scored.
        if ((int) (new Query())->from(Store::ENTRY_INDEX)->where(['site' => $siteKey])->count() > LinkCandidates::STEM_INDEX_ABOVE) {
            $stems = LinkCandidates::draftStems($text, $locale);

            if ($stems === []) {
                return [];
            }

            $query->andWhere(['entryKey' => (new Query())->select('entryKey')->distinct()->from(Store::INDEX_STEMS)->where(['site' => $siteKey, 'stem' => $stems])]);
        }

        $rows = function() use ($query, $locale): iterable {
            foreach ($query->each(500) as $record) {
                $row = self::decode((string) $record['data'], $locale);

                if ($row !== null) {
                    yield $row;
                }
            }
        };

        return LinkCandidates::rank($rows(), $text, $group, $site, $except, $limit, $linked, $now, $locale);
    }

    /**
     * The row a link already in a draft points at (`{entry:12@1:url||…}`,
     * CKEditor's `…#entry:12@1:url`, or the page's address), so the
     * writer's links to real pages are kept (LinkGuard).
     */
    public function linkRow(string $href, int|string|null $site = null): ?IndexRow
    {
        $locale = self::locale($site);
        $rows = function() use ($site, $locale): iterable {
            foreach ((new Query())->select(['data'])->from(Store::ENTRY_INDEX)->where(['site' => (string) ($site ?? '')])->each(500) as $record) {
                $row = self::decode((string) $record['data'], $locale);

                if ($row !== null) {
                    yield $row;
                }
            }
        };

        return LinkCandidates::rowFor($rows(), $href, $site);
    }

    /**
     * Keeps an entry of one of Ghostwriter's sections as a full row: its
     * title, address, summary and paragraphs' shingles, from what the
     * checks read of it, and its link-row fields when given ($row, from
     * CraftLinkSource), so it's a link candidate too.
     */
    public function put(EntryRef $ref, string $title, ?string $url, CheckContext $context, string $summary = '', ?IndexRow $row = null): void
    {
        $paragraphs = [];
        $first = '';

        foreach ($context->texts() as $text) {
            foreach (array_keys($text->blocks) as $i) {
                $paragraph = $text->block($i);

                if ($first === '' && count(NormalisedText::words($paragraph)) >= 8) {
                    $first = trim($paragraph);
                }

                if (count(NormalisedText::words($paragraph)) >= Shingles::MIN_WORDS) {
                    $paragraphs[] = Shingles::of($paragraph);
                }
            }
        }

        $summary = mb_substr(trim($summary !== '' ? $summary : $first), 0, DigestEntry::SUMMARY);
        $locale = self::locale($ref->site);
        $row = $row !== null
            ? IndexRow::make($ref, IndexScope::Full, $title, $row->url, $row->summary !== '' ? $row->summary : $summary, $row->type, $row->kind, $row->liveFrom, $row->liveUntil, $row->noindex, $row->key, $row->link, $row->updated, $row->published, $row->indexed ?? self::now(), $locale)
            : IndexRow::make($ref, IndexScope::Full, $title, $url, $summary, link: "{entry:{$ref->id}@{$ref->site}:url}", indexed: self::now(), locale: $locale);

        $this->write([[$row, ['paragraphs' => $paragraphs, 'href' => $url, 'digest' => $summary]]]);
    }

    /**
     * Keeps a page as its row says: a link row (or a full row, without
     * paragraphs). A row of a page that can't be linked to at all (not
     * published, no address) is forgotten instead.
     */
    public function putRow(IndexRow $row): void
    {
        $this->putRows([$row]);
    }

    /**
     * @param iterable<IndexRow> $rows
     */
    public function putRows(iterable $rows): void
    {
        $keep = [];
        $forget = [];

        foreach ($rows as $row) {
            if (Linkable::keep($row)) {
                $keep[] = [$row->indexed === null ? $row->withIndexed(self::now()) : $row, []];
            } else {
                $forget[] = $row->entry->key();
            }
        }

        $this->forgetKeys($forget);
        $this->write($keep);
    }

    public function forget(EntryRef $ref): void
    {
        $this->forgetKeys([$ref->key()]);
    }

    /**
     * @param list<string> $keys
     */
    public function forgetKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        foreach (array_chunk($keys, 500) as $chunk) {
            Db::delete(Store::ENTRY_INDEX, ['entryKey' => $chunk]);
            Db::delete(Store::INDEX_STEMS, ['entryKey' => $chunk]);
        }

        $this->loaded = [];
    }

    /** A page's row, as related() reads it; null when it has none. */
    public function row(EntryRef $ref): ?IndexRow
    {
        $data = (new Query())->select('data')->from(Store::ENTRY_INDEX)->where(['entryKey' => $ref->key()])->scalar();

        return is_string($data) ? self::decode($data, self::locale($ref->site)) : null;
    }

    /**
     * The keys of the site's full rows (pages of Ghostwriter's sections),
     * for the weekly pass to forget those whose entry is gone.
     *
     * @return list<string>
     */
    public function fullKeys(int|string|null $site): array
    {
        return array_map('strval', (new Query())->select('entryKey')->from(Store::ENTRY_INDEX)->where(['site' => (string) ($site ?? ''), 'scope' => 'full'])->orderBy('id')->column());
    }

    /**
     * What the daily pass compares the site's pages with (core's
     * LinkPlan): every row outside the given sections (Ghostwriter's), of
     * either scope, by key. Only columns are read.
     *
     * @param list<string> $except
     * @return array<string, array{group: string, scope: string, updated: ?string, indexed: ?string}>
     */
    public function planRows(int|string|null $site, array $except = []): array
    {
        $query = (new Query())->select(['entryKey', 'groupHandle', 'scope', 'pageUpdated', 'indexed'])->from(Store::ENTRY_INDEX)->where(['site' => (string) ($site ?? '')]);

        if ($except !== []) {
            $query->andWhere(['not', ['groupHandle' => $except]]);
        }

        $rows = [];

        foreach ($query->each(1000) as $record) {
            $rows[(string) $record['entryKey']] = [
                'group' => (string) $record['groupHandle'],
                'scope' => (string) $record['scope'],
                'updated' => self::iso($record['pageUpdated']),
                'indexed' => self::iso($record['indexed']),
            ];
        }

        return $rows;
    }

    /**
     * The site's link rows in the given sections, as refs: pages of a
     * section that has become one of Ghostwriter's, waiting for full rows.
     *
     * @param list<string> $groups
     * @return list<EntryRef>
     */
    public function linkRowsIn(int|string|null $site, array $groups, int $limit = LinkCandidates::PER_RUN): array
    {
        if ($groups === []) {
            return [];
        }

        $refs = [];

        foreach ((new Query())->select('data')->from(Store::ENTRY_INDEX)->where(['site' => (string) ($site ?? ''), 'scope' => IndexScope::Link->value, 'groupHandle' => $groups])->limit($limit)->column() as $data) {
            $data = Json::decodeIfJson((string) $data);

            if (is_array($data) && is_array($data['entry'] ?? null)) {
                $refs[] = EntryRef::fromArray($data['entry']);
            }
        }

        return $refs;
    }

    /**
     * How many rows each group has on a site, by scope.
     *
     * @return list<array{group: string, scope: string, count: int}>
     */
    public function counts(int|string|null $site): array
    {
        return array_values(array_map(fn(array $row) => ['group' => (string) $row['groupHandle'], 'scope' => (string) $row['scope'], 'count' => (int) $row['n']], (new Query())
            ->select(['groupHandle', 'scope', 'n' => 'COUNT(*)'])
            ->from(Store::ENTRY_INDEX)
            ->where(['site' => (string) ($site ?? '')])
            ->groupBy(['groupHandle', 'scope'])
            ->orderBy(['groupHandle' => SORT_ASC, 'scope' => SORT_ASC])
            ->all()));
    }

    /** The site's language, for stop words and stems: "en-GB"; null when unknown. */
    public static function locale(int|string|null $site): ?string
    {
        if ($site === null || $site === '') {
            return null;
        }

        try {
            $found = is_numeric($site) ? Craft::$app->getSites()->getSiteById((int) $site, true) : Craft::$app->getSites()->getSiteByHandle((string) $site, true);

            return $found?->language;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rows, with what the data column keeps beside a full row (its
     * paragraphs, its absolute address, the digest's summary), and their
     * stems.
     *
     * @param list<array{0: IndexRow, 1: array<string, mixed>}> $rows
     */
    private function write(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $stems = [];

        foreach ($rows as [$row, $extra]) {
            $data = $row->toArray();

            if ($row->scope === IndexScope::Full) {
                $data['paragraphs'] = $extra['paragraphs'] ?? [];
                $data['href'] = $extra['href'] ?? null;
                $data['digest'] = $extra['digest'] ?? $row->summary;
            }

            Db::upsert(Store::ENTRY_INDEX, [
                'entryKey' => $row->entry->key(),
                'site' => (string) ($row->entry->site ?? ''),
                'groupHandle' => $row->entry->group,
                'scope' => $row->scope->value,
                'kind' => $row->kind->value,
                'liveFrom' => self::db($row->liveFrom),
                'liveUntil' => self::db($row->liveUntil),
                'noindex' => $row->noindex,
                'keyPage' => $row->key,
                'pageUpdated' => self::db($row->updated),
                'indexed' => self::db($row->indexed),
                'data' => Json::encode($data),
            ]);

            foreach ($row->allStems() as $stem) {
                $stems[] = [mb_substr($stem, 0, 64), $row->entry->key(), (string) ($row->entry->site ?? '')];
            }
        }

        Db::delete(Store::INDEX_STEMS, ['entryKey' => array_map(fn(array $pair) => $pair[0]->entry->key(), $rows)]);

        foreach (array_chunk($stems, 1000) as $chunk) {
            Db::batchInsert(Store::INDEX_STEMS, ['stem', 'entryKey', 'site'], $chunk);
        }

        $this->loaded = [];
    }

    /**
     * Every full row of a site, by key, as sharing() and nearest() read it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entries(int|string|null $site): array
    {
        $site = (string) ($site ?? '');

        if (!isset($this->loaded[$site])) {
            $all = [];

            foreach ((new Query())->select(['entryKey', 'data'])->from(Store::ENTRY_INDEX)->where(['site' => $site, 'scope' => IndexScope::Full->value])->each(200) as $row) {
                $data = Json::decodeIfJson((string) $row['data']);

                if (is_array($data) && is_array($data['entry'] ?? null)) {
                    // The digest's summary, as before link rows.
                    $data['summary'] = is_string($data['digest'] ?? null) ? $data['digest'] : ($data['summary'] ?? '');
                    $all[(string) $row['entryKey']] = $data;
                }
            }

            $this->loaded[$site] = $all;
        }

        return $this->loaded[$site];
    }

    /**
     * A stored row as core's IndexRow; a row written before link rows (no
     * scope, no stems, no link, an absolute address) reads as a full row.
     */
    private static function decode(string $json, ?string $locale): ?IndexRow
    {
        $data = Json::decodeIfJson($json);

        if (!is_array($data) || !is_array($data['entry'] ?? null)) {
            return null;
        }

        if (!array_key_exists('link', $data)) {
            $ref = EntryRef::fromArray($data['entry']);
            $data['link'] = "{entry:{$ref->id}@{$ref->site}:url}";
        }

        return IndexRow::fromArray($data, $locale);
    }

    /**
     * A full row's address as sharing() and nearest() give it: absolute,
     * as the entry's getUrl() gave it.
     *
     * @param array<string, mixed> $data
     */
    private static function href(array $data): ?string
    {
        foreach (['href', 'url'] as $key) {
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }

    private static function db(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Db::prepareDateForDb(new DateTimeImmutable($iso));
        } catch (Throwable) {
            return null;
        }
    }

    /** A date column (UTC, as Craft stores them) as ISO. */
    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
