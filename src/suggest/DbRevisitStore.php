<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeZone;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use nineteenninetyfour\ghostwriter\Store;
use Throwable;

/**
 * Content to revisit's rows, one per entry and site in
 * `ghostwriter_revisit`: the row as JSON in `data`, beside the columns the
 * list sorts and filters by (site, section, score, reason kinds, snooze).
 * The links each row holds are in `ghostwriter_revisit_links`, indexed on
 * the target, so a deleted entry's linkers are found without reading
 * every row.
 *
 * A link to one of the site's elements is matched by the element it
 * holds, however it is written: `{entry:41@1:url}` in a Link field and
 * `{entry:41@1:url||https://…}` in CKEditor are both `entry:41`.
 */
class DbRevisitStore implements RevisitStore
{
    /** An element a link holds: Craft's reference tag. */
    private const ELEMENT = '/^\{(entry|asset|category):(\d+)(?:[@:}|]|$)/';

    public function put(RevisitRow $row): void
    {
        $key = $row->entry->key();
        $kinds = [];

        foreach ($row->reasons as $reason) {
            $kinds[$reason->kind->value] = true;
        }

        \Craft::$app->getDb()->transaction(function() use ($row, $key, $kinds) {
            Db::upsert(Store::REVISIT, [
                'entryKey' => $key,
                'site' => self::site($row->entry->site),
                'groupHandle' => $row->entry->group,
                'title' => mb_substr($row->title, 0, 255),
                'score' => $row->score,
                'kinds' => $kinds === [] ? '' : ',' . implode(',', array_keys($kinds)) . ',',
                'snoozedUntil' => self::date($row->snoozedUntil),
                'data' => Json::encode($row->toArray()),
            ]);

            Db::delete(Store::REVISIT_LINKS, ['entryKey' => $key]);

            $links = [];

            foreach (array_unique($row->linksTo) as $target) {
                $links[] = [$key, self::site($row->entry->site), sha1($target), self::element($target)];
            }

            if ($links !== []) {
                Db::batchInsert(Store::REVISIT_LINKS, ['entryKey', 'site', 'target', 'element'], $links);
            }
        });
    }

    public function get(EntryRef $entry): ?RevisitRow
    {
        $data = (new Query())->select('data')->from(Store::REVISIT)->where(['entryKey' => $entry->key()])->scalar();

        return $data === false ? null : self::row((string) $data);
    }

    public function forget(EntryRef $entry): void
    {
        Db::delete(Store::REVISIT_LINKS, ['entryKey' => $entry->key()]);
        Db::delete(Store::REVISIT, ['entryKey' => $entry->key()]);
    }

    public function top(int|string|null $site, ?string $group = null, int $limit = 25, int $offset = 0, array $kinds = [], ?DateTimeImmutable $now = null): array
    {
        $rows = $this->listed($site, $group, $kinds, $now)
            ->select('data')
            ->orderBy(['score' => SORT_DESC, 'title' => SORT_ASC, 'id' => SORT_ASC])
            ->limit(max(0, $limit))
            ->offset(max(0, $offset))
            ->column();

        return array_values(array_filter(array_map(fn($data) => self::row((string) $data), $rows)));
    }

    public function count(int|string|null $site, ?string $group = null, array $kinds = [], ?DateTimeImmutable $now = null): int
    {
        return (int) $this->listed($site, $group, $kinds, $now)->count();
    }

    public function stats(int|string|null $site, ?DateTimeImmutable $now = null): array
    {
        $stats = ['worth-a-look' => 0];
        $query = (new Query())->select(['score', 'kinds'])->from(Store::REVISIT);
        $this->notSnoozed($query, $now);

        if ($site !== null) {
            $query->andWhere(['site' => self::site($site)]);
        }

        foreach ($query->each(500) as $row) {
            if ((int) $row['score'] >= Priority::WORTH_A_LOOK) {
                $stats['worth-a-look']++;
            }

            foreach (array_filter(explode(',', (string) $row['kinds'])) as $kind) {
                $stats[$kind] = ($stats[$kind] ?? 0) + 1;
            }
        }

        return $stats;
    }

    public function linkingTo(string $target, int|string|null $site = null): array
    {
        $element = self::element($target);
        $query = (new Query())->select('entryKey')->distinct()->from(Store::REVISIT_LINKS)->where($element !== null ? ['element' => $element] : ['target' => sha1($target)]);

        if ($site !== null) {
            $query->andWhere(['site' => self::site($site)]);
        }

        $keys = $query->column();

        if ($keys === []) {
            return [];
        }

        $refs = [];

        foreach ((new Query())->select('data')->from(Store::REVISIT)->where(['entryKey' => $keys])->column() as $data) {
            $row = self::row((string) $data);

            if ($row !== null) {
                $refs[] = $row->entry;
            }
        }

        return $refs;
    }

    public function all(int|string|null $site = null): iterable
    {
        $query = (new Query())->select('data')->from(Store::REVISIT)->orderBy(['id' => SORT_ASC]);

        if ($site !== null) {
            $query->where(['site' => self::site($site)]);
        }

        foreach ($query->each(200) as $data) {
            $row = self::row((string) (is_array($data) ? ($data['data'] ?? '') : $data));

            if ($row !== null) {
                yield $row;
            }
        }
    }

    /**
     * The list before paging: unsnoozed rows scoring above nothing.
     *
     * @param array<int, string> $kinds
     */
    private function listed(int|string|null $site, ?string $group, array $kinds, ?DateTimeImmutable $now): Query
    {
        $query = (new Query())->from(Store::REVISIT)->where(['>', 'score', 0]);
        $this->notSnoozed($query, $now);

        if ($site !== null) {
            $query->andWhere(['site' => self::site($site)]);
        }

        if ($group !== null) {
            $query->andWhere(['groupHandle' => $group]);
        }

        if ($kinds !== []) {
            $query->andWhere(['or', ...array_map(fn(string $kind) => ['like', 'kinds', ",{$kind},"], array_values($kinds))]);
        }

        return $query;
    }

    private function notSnoozed(Query $query, ?DateTimeImmutable $now): void
    {
        $at = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $query->andWhere(['or', ['snoozedUntil' => null], ['<=', 'snoozedUntil', $at]]);
    }

    /** The element a link holds ("entry:41"), or null for an address. */
    public static function element(string $target): ?string
    {
        if (preg_match(self::ELEMENT, trim($target), $match) === 1) {
            return "{$match[1]}:{$match[2]}";
        }

        return null;
    }

    private static function site(int|string|null $site): string
    {
        return (string) ($site ?? '');
    }

    private static function row(string $json): ?RevisitRow
    {
        $data = Json::decodeIfJson($json);

        if (!is_array($data)) {
            return null;
        }

        try {
            return RevisitRow::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    private static function date(?string $atom): ?string
    {
        if ($atom === null || $atom === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($atom))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
