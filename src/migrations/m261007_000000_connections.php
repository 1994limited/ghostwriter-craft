<?php

namespace nineteenninetyfour\ghostwriter\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use nineteenninetyfour\ghostwriter\ai\EnvironmentCredentials;
use nineteenninetyfour\ghostwriter\domain\DbCredentialStore;
use nineteenninetyfour\ghostwriter\Store;
use Throwable;

/**
 * Settings → Connections: its table, and what Ghostwriter kept before it
 * moved in: Connect with OpenRouter's key (`provider-key:<provider>` in the
 * state table) and paid libraries' account tokens (`library-tokens:<id>`).
 * Each is decrypted, kept again in the new table, and its old row removed.
 * One that can't be decrypted (the security key changed) is dropped, as it
 * already read as not connected.
 */
class m261007_000000_connections extends Migration
{
    public function safeUp(): bool
    {
        Install::createCredentials($this);

        if (!$this->db->tableExists(Store::STATE)) {
            return true;
        }

        $store = new DbCredentialStore();
        $connections = new Connections(new EnvironmentCredentials(fn() => []), $store);
        $tokens = new StoredLibraryTokens($store);
        $rows = (new Query())->select(['name', 'value'])->from(Store::STATE)
            ->where(['or', ['like', 'name', 'provider-key:%', false], ['like', 'name', 'library-tokens:%', false]])
            ->all($this->db);

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $value = Json::decodeIfJson((string) $row['value']);
            $plain = $this->open(is_array($value) ? ($value['sealed'] ?? null) : null);

            if ($plain !== null) {
                if (str_starts_with($name, 'provider-key:')) {
                    $connections->adopt(substr($name, strlen('provider-key:')), ['key' => $plain], 'connect');
                } else {
                    $data = Json::decodeIfJson($plain);
                    $set = is_array($data) ? TokenSet::fromArray($data) : null;
                    $library = substr($name, strlen('library-tokens:'));

                    if ($set !== null && $tokens->get($library) === null) {
                        $tokens->put($library, $set);
                    }
                }
            }

            $this->delete(Store::STATE, ['name' => $name]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Store::CREDENTIALS);

        return true;
    }

    private function open(mixed $sealed): ?string
    {
        $bytes = is_string($sealed) ? base64_decode($sealed, true) : false;

        if ($bytes === false) {
            return null;
        }

        try {
            $plain = Craft::$app->getSecurity()->decryptByKey($bytes);
        } catch (Throwable) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }
}
