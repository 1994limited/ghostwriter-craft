<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;
use nineteenninetyfour\ghostwriter\Store;

/**
 * The site's entries as Suggest edits compares pages with them: each
 * entry's title, address, a short summary and its paragraphs' shingles,
 * one row per entry and site in `ghostwriter_entry_index`, written when
 * the entry is saved. Duplicates (Overlaps) look for paragraphs sharing
 * shingles; the review's digest takes the entries whose titles and
 * summaries share most words with the page.
 *
 * No model, and no entry is read when asked: only these rows.
 */
class DbEntryIndex implements EntryIndex
{
    /** @var array<string, array<string, array<string, mixed>>> A site's rows, read once a request. */
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
                    $found[] = [$shared, new IndexedParagraph(EntryRef::fromArray($entry['entry']), (string) ($entry['title'] ?? ''), $paragraph, is_string($entry['url'] ?? null) ? $entry['url'] : null)];
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
            is_string($row[4]['url'] ?? null) ? $row[4]['url'] : null,
            mb_substr((string) ($row[4]['summary'] ?? ''), 0, DigestEntry::SUMMARY),
            // A link to it as CKEditor and Link fields store one.
            '{entry:' . $row[3]->id . '@' . $row[3]->site . ':url||' . ($row[4]['url'] ?? '') . '}',
        ), array_slice($scored, 0, max(0, $limit)));
    }

    /**
     * Keeps an entry's title, address, summary and paragraphs' shingles,
     * from what the checks read of it.
     */
    public function put(EntryRef $ref, string $title, ?string $url, CheckContext $context, string $summary = ''): void
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

        Db::upsert(Store::ENTRY_INDEX, [
            'entryKey' => $ref->key(),
            'site' => (string) ($ref->site ?? ''),
            'groupHandle' => $ref->group,
            'data' => Json::encode([
                'entry' => $ref->toArray(),
                'title' => $title,
                'url' => $url,
                'summary' => mb_substr(trim($summary !== '' ? $summary : $first), 0, DigestEntry::SUMMARY),
                'paragraphs' => $paragraphs,
            ]),
        ]);

        $this->loaded = [];
    }

    public function forget(EntryRef $ref): void
    {
        Db::delete(Store::ENTRY_INDEX, ['entryKey' => $ref->key()]);
        $this->loaded = [];
    }

    /**
     * Every indexed entry of a site, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entries(int|string|null $site): array
    {
        $site = (string) ($site ?? '');

        if (!isset($this->loaded[$site])) {
            $all = [];

            foreach ((new Query())->select(['entryKey', 'data'])->from(Store::ENTRY_INDEX)->where(['site' => $site])->each(200) as $row) {
                $data = Json::decodeIfJson((string) $row['data']);

                if (is_array($data) && is_array($data['entry'] ?? null)) {
                    $all[(string) $row['entryKey']] = $data;
                }
            }

            $this->loaded[$site] = $all;
        }

        return $this->loaded[$site];
    }
}
