<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use nineteenninetyfour\ghostwriter\Store;
use SensitiveParameter;
use Throwable;

/**
 * Where Settings → Connections keeps what was set up (core's
 * CredentialStore): the `ghostwriter_credentials` table, one row per name,
 * each value encrypted with Craft's security component and the site's
 * security key (CRAFT_SECURITY_KEY), so the database alone gives nothing
 * away. Never project config. A value that can't be decrypted (the
 * security key changed) reads as none: set it up again.
 */
class DbCredentialStore implements CredentialStore
{
    public function get(string $name): ?array
    {
        $sealed = $this->raw($name);
        $bytes = is_string($sealed) ? base64_decode($sealed, true) : false;

        if ($bytes === false) {
            return null;
        }

        try {
            $json = Craft::$app->getSecurity()->decryptByKey($bytes);
        } catch (Throwable) {
            return null;
        }

        $value = is_string($json) ? Json::decodeIfJson($json) : null;

        return is_array($value) ? $value : null;
    }

    public function put(string $name, #[SensitiveParameter] array $value): void
    {
        $sealed = base64_encode(Craft::$app->getSecurity()->encryptByKey(Json::encode($value)));
        $now = Db::prepareDateForDb(new \DateTime());
        $db = Craft::$app->getDb();

        $updated = $db->createCommand()->update(Store::CREDENTIALS, ['value' => $sealed, 'dateUpdated' => $now], ['name' => $name], [], false)->execute();

        if ($updated === 0 && !(new Query())->from(Store::CREDENTIALS)->where(['name' => $name])->exists()) {
            $db->createCommand()->insert(Store::CREDENTIALS, ['name' => $name, 'value' => $sealed, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()], false)->execute();
        }
    }

    public function forget(string $name): void
    {
        Craft::$app->getDb()->createCommand()->delete(Store::CREDENTIALS, ['name' => $name])->execute();
    }

    /** What is kept for a name as it is at rest (still encrypted), for tests. */
    public function raw(string $name): ?string
    {
        try {
            $value = (new Query())->select('value')->from(Store::CREDENTIALS)->where(['name' => $name])->scalar();
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
