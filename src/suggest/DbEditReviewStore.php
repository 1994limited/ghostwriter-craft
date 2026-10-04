<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;
use nineteenninetyfour\ghostwriter\Store;
use Throwable;

/**
 * Suggest edits' reviews, one row each in `ghostwriter_edit_reviews`: the
 * whole record as JSON in `data`, beside what it is looked up by (the
 * entry, its status and when its unactioned suggestions expire). An
 * entry's reviews are its history, newest first; they go only with the
 * entry.
 *
 * The version check is core's optimistic concurrency: EditReviews makes
 * every change under the entry's lock, so a save that finds a newer
 * version stored is someone else's, and is refused.
 */
class DbEditReviewStore implements EditReviewStore
{
    public function find(string $id): ?EditReview
    {
        if (!self::isId($id)) {
            return null;
        }

        $data = (new Query())->select('data')->from(Store::EDIT_REVIEWS)->where(['id' => $id])->scalar();

        return $data === false ? null : self::review((string) $data);
    }

    public function latestFor(EntryRef $entry): ?EditReview
    {
        return $this->history($entry, 1)[0] ?? null;
    }

    public function history(EntryRef $entry, int $limit = 50): array
    {
        $rows = (new Query())->select('data')->from(Store::EDIT_REVIEWS)->where(['entryKey' => $entry->key()])->orderBy(['seq' => SORT_DESC])->limit(max(0, $limit))->column();

        return array_values(array_filter(array_map(fn($data) => self::review((string) $data), $rows)));
    }

    public function save(EditReview $review): EditReview
    {
        $db = \Craft::$app->getDb();

        return $db->transaction(function() use ($review) {
            $stored = (new Query())->select('version')->from(Store::EDIT_REVIEWS)->where(['id' => $review->id])->scalar();

            if ($stored !== false && (int) $stored !== $review->version) {
                throw new Conflict('Someone else changed this review first.');
            }

            $review->version++;

            $columns = [
                'entryKey' => $review->entry->key(),
                'status' => $review->status->value,
                'version' => $review->version,
                'expiresAt' => self::date($review->expiresAt),
                'data' => Json::encode($review->toArray()),
            ];

            if ($stored === false) {
                Db::insert(Store::EDIT_REVIEWS, ['id' => $review->id] + $columns);
            } else {
                Db::update(Store::EDIT_REVIEWS, $columns, ['id' => $review->id]);
            }

            return $review;
        });
    }

    public function dueToExpire(DateTimeInterface $now, int $limit = 100): array
    {
        $at = DateTimeImmutable::createFromInterface($now);
        $rows = (new Query())
            ->select(['id', 'data'])
            ->from(Store::EDIT_REVIEWS)
            ->where(['status' => [ReviewStatus::Ready->value, ReviewStatus::Failed->value]])
            ->andWhere(['not', ['expiresAt' => null]])
            ->andWhere(['<=', 'expiresAt', self::date($at->format(DATE_ATOM))])
            ->orderBy(['seq' => SORT_ASC])
            ->limit(max(0, $limit) * 2)
            ->all();

        $due = [];

        foreach ($rows as $row) {
            $review = self::review((string) $row['data']);

            if ($review !== null && $review->isDue($at) && count($due) < $limit) {
                $due[] = $review->id;
            }
        }

        return $due;
    }

    public function delete(string $id): void
    {
        if (self::isId($id)) {
            Db::delete(Store::EDIT_REVIEWS, ['id' => $id]);
        }
    }

    /** Core's ULIDs: nothing else is ours to look up. */
    private static function isId(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1;
    }

    private static function review(string $json): ?EditReview
    {
        $data = Json::decodeIfJson($json);

        if (!is_array($data) || !isset($data['id'])) {
            return null;
        }

        try {
            return EditReview::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    /** An ATOM date as the database keeps it: UTC, without a zone. */
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
