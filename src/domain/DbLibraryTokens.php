<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * A connected library account's tokens ("Connect account"), one set per
 * library for the whole site: the licences belong to the company, not to
 * whoever pressed the button. Kept in Ghostwriter's state table as
 * `library-tokens:<id>`, encrypted with Craft's security component and the
 * site's security key, so the database alone gives nothing away. Tokens
 * that can't be decrypted (the key changed) are as good as none: connect
 * again.
 */
class DbLibraryTokens implements LibraryTokens
{
    public function get(string $library): ?TokenSet
    {
        $stored = Plugin::getInstance()->store->state(self::key($library));
        $sealed = is_string($stored['sealed'] ?? null) ? base64_decode($stored['sealed'], true) : false;

        if ($sealed === false) {
            return null;
        }

        try {
            $json = Craft::$app->getSecurity()->decryptByKey($sealed);
        } catch (Throwable) {
            return null;
        }

        $data = is_string($json) ? Json::decodeIfJson($json) : null;

        return is_array($data) ? TokenSet::fromArray($data) : null;
    }

    public function put(string $library, TokenSet $tokens): void
    {
        $sealed = Craft::$app->getSecurity()->encryptByKey(Json::encode($tokens->toArray()));

        Plugin::getInstance()->store->putState(self::key($library), ['sealed' => base64_encode($sealed)]);
    }

    public function forget(string $library): void
    {
        Plugin::getInstance()->store->deleteState(self::key($library));
    }

    private static function key(string $library): string
    {
        return 'library-tokens:' . preg_replace('/[^a-z0-9_-]/', '', strtolower($library));
    }
}
