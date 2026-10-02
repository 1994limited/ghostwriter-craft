<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImagePage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use nineteenninetyfour\ghostwriter\Store;

/**
 * The stock image ledger, one row per record in `ghostwriter_stock_images`:
 * the whole record as JSON in `data`, beside the columns it is looked up by
 * (state, library and ID, the asset, when it was inserted and last changed).
 * Where each image is used is copied into `ghostwriter_stock_usages`, so
 * the ledger can be filtered by entry.
 *
 * There is no delete. A save goes over the stored record through
 * StockImage::over(), so a record's history only grows and a licensed one
 * never goes back to being a preview.
 *
 * "Request licence" is kept beside the record (requestedAt, requestedById,
 * requestedByName), not in it: it isn't part of core's record.
 */
class DbStockImageStore implements StockImageStore
{
    public function save(StockImage $image): StockImage
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $image = $image->over($this->find($image->id));

            Db::upsert(Store::STOCK_IMAGES, [
                'id' => $image->id,
                'state' => $image->state(),
                'library' => $image->library,
                'externalId' => $image->externalId,
                'assetId' => is_numeric($image->asset->id) ? (int) $image->asset->id : null,
                'assetKey' => $image->asset->key(),
                'insertedAt' => Db::prepareDateForDb($image->insertedAt),
                'stateChangedAt' => Db::prepareDateForDb($image->stateChangedAt()),
                'data' => Json::encode($image->toArray(Format::Craft)),
            ]);

            Db::delete(Store::STOCK_USAGES, ['stockImageId' => $image->id]);

            foreach ($image->usages() as $usage) {
                Db::insert(Store::STOCK_USAGES, $this->usageRow($image->id, $usage));
            }

            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();

            throw $exception;
        }

        return $image;
    }

    public function find(string $id): ?StockImage
    {
        // IDs are ours; anything else is not ours to look up.
        if (!Format::Craft->isSessionId($id)) {
            return null;
        }

        $data = (new Query())->select('data')->from(Store::STOCK_IMAGES)->where(['id' => $id])->scalar();

        return $data === false ? null : $this->image((string) $data);
    }

    public function forAsset(AssetRef $asset): ?StockImage
    {
        $data = (new Query())
            ->select('data')
            ->from(Store::STOCK_IMAGES)
            ->where(['assetKey' => $asset->key()])
            ->andWhere(['not', ['state' => StockImage::REMOVED]])
            ->orderBy(['insertedAt' => SORT_DESC, 'id' => SORT_DESC])
            ->scalar();

        return $data === false ? null : $this->image((string) $data);
    }

    public function forExternal(string $library, string $externalId): array
    {
        return $this->rows((new Query())->from(Store::STOCK_IMAGES)->where(['library' => $library, 'externalId' => $externalId]));
    }

    public function query(StockImageQuery $query): StockImagePage
    {
        $rows = (new Query())->from(['i' => Store::STOCK_IMAGES]);

        if ($query->states !== []) {
            $rows->andWhere(['i.state' => $query->states]);
        }

        if ($query->library !== null) {
            $rows->andWhere(['i.library' => $query->library]);
        }

        if ($query->since !== null) {
            $rows->andWhere(['>=', 'i.insertedAt', Db::prepareDateForDb($query->since)]);
        }

        if ($query->ownerType !== null && $query->ownerId !== null) {
            $used = (new Query())
                ->select('stockImageId')
                ->from(Store::STOCK_USAGES)
                ->where(['ownerType' => $query->ownerType, 'ownerId' => (string) $query->ownerId]);

            if ($query->site !== null) {
                $used->andWhere(['site' => $query->site]);
            }

            $rows->andWhere(['i.id' => $used]);
        }

        $total = (int) (clone $rows)->count('*');
        $images = $this->rows(
            $rows->select('i.data')
                ->orderBy(['i.insertedAt' => SORT_DESC, 'i.id' => SORT_DESC])
                ->offset(($query->page - 1) * $query->perPage)
                ->limit($query->perPage),
            ordered: true,
        );

        return new StockImagePage($images, $total, $query->page, $query->perPage);
    }

    public function previewsBefore(DateTimeInterface $cutoff): array
    {
        return $this->rows((new Query())->from(Store::STOCK_IMAGES)->where(['state' => StockImage::PREVIEW])->andWhere(['<', 'stateChangedAt', Db::prepareDateForDb($cutoff)]));
    }

    /**
     * Ask for a licence: someone without the licence permission pressed
     * "Request licence". The request goes once the image is licensed or
     * removed (see requested()).
     */
    public function request(string $id, int|string|null $userId, ?string $name): void
    {
        Db::update(Store::STOCK_IMAGES, [
            'requestedAt' => Db::prepareDateForDb(new DateTimeImmutable()),
            'requestedById' => is_numeric($userId) ? (int) $userId : null,
            'requestedByName' => $name !== null ? mb_substr($name, 0, 255) : null,
        ], ['id' => $id], updateTimestamp: false);
    }

    /**
     * Who asked for a licence, and when, by record ID: only for records
     * still waiting for one.
     *
     * @param array<int, string>|null $ids Null for every record.
     * @return array<string, array{at: DateTimeImmutable, userId: ?int, name: ?string}>
     */
    public function requested(?array $ids = null): array
    {
        $query = (new Query())
            ->select(['id', 'requestedAt', 'requestedById', 'requestedByName'])
            ->from(Store::STOCK_IMAGES)
            ->where(['not', ['requestedAt' => null]])
            ->andWhere(['state' => [StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED]]);

        if ($ids !== null) {
            $query->andWhere(['id' => $ids ?: ['']]);
        }

        $requested = [];

        foreach ($query->all() as $row) {
            $requested[(string) $row['id']] = [
                'at' => new DateTimeImmutable((string) $row['requestedAt'], new DateTimeZone('UTC')),
                'userId' => $row['requestedById'] !== null ? (int) $row['requestedById'] : null,
                'name' => $row['requestedByName'] !== null ? (string) $row['requestedByName'] : null,
            ];
        }

        return $requested;
    }

    /**
     * Records whose comp is still held privately, whatever their state:
     * for the comp route and the URL hook. By asset ID.
     *
     * @return array<int, array{id: string, state: string}>
     */
    public function withComps(): array
    {
        $found = [];
        $rows = (new Query())
            ->select(['id', 'assetId', 'state', 'data'])
            ->from(Store::STOCK_IMAGES)
            ->where(['state' => [StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED]])
            ->andWhere(['not', ['assetId' => null]])
            ->orderBy(['insertedAt' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            $data = Json::decodeIfJson((string) $row['data']);

            if (is_array($data) && !empty($data['comp'])) {
                $found[(int) $row['assetId']] = ['id' => (string) $row['id'], 'state' => (string) $row['state']];
            }
        }

        return $found;
    }

    /**
     * How many records are in each state.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return array_map('intval', (new Query())->select(['n' => 'COUNT(*)', 'state'])->from(Store::STOCK_IMAGES)->groupBy('state')->indexBy('state')->column() ?: []);
    }

    /**
     * Whether there is any record at all: most sites have none, and then
     * nothing need be looked up on each save or asset URL.
     */
    public function isEmpty(): bool
    {
        return !(new Query())->from(Store::STOCK_IMAGES)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function usageRow(string $id, Usage $usage): array
    {
        $field = explode('.', $usage->field);
        $handle = end($field);

        return [
            'stockImageId' => $id,
            'ownerType' => $usage->ownerType,
            'ownerId' => (string) $usage->ownerId,
            'elementId' => is_numeric($usage->ownerId) ? (int) $usage->ownerId : null,
            'site' => $usage->site,
            'siteId' => is_numeric($usage->site) ? (int) $usage->site : null,
            'fieldId' => is_string($handle) && $handle !== '' ? Craft::$app->getFields()->getFieldByHandle($handle)?->id : null,
            'fieldPath' => mb_substr($usage->field, 0, 255),
            'live' => $usage->live,
        ];
    }

    /**
     * @return array<int, StockImage> Newest first.
     */
    private function rows(Query $query, bool $ordered = false): array
    {
        if (!$ordered) {
            $query->select('data')->orderBy(['insertedAt' => SORT_DESC, 'id' => SORT_DESC]);
        }

        return array_values(array_filter(array_map(fn($data) => $this->image((string) $data), $query->column())));
    }

    private function image(string $json): ?StockImage
    {
        $data = Json::decodeIfJson($json);

        return is_array($data) && isset($data['id']) ? StockImage::fromArray($data, Format::Craft) : null;
    }
}
