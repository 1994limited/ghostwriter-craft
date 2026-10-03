<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use nineteenninetyfour\ghostwriter\Plugin;
use SensitiveParameter;
use Throwable;

/**
 * A model provider's key from "Connect with OpenRouter", one per provider
 * for the whole site. Kept in Ghostwriter's state table as
 * `provider-key:<provider>`, encrypted with Craft's security component and
 * the site's security key, so the database alone gives nothing away. A key
 * that can't be decrypted (the security key changed) is as good as none:
 * connect again. A key in .env always wins over it (core's
 * ConnectedCredentials).
 */
class DbProviderKeys implements ProviderKeys
{
    public function get(string $provider): ?string
    {
        $stored = Plugin::getInstance()->store->state(self::name($provider));
        $sealed = is_string($stored['sealed'] ?? null) ? base64_decode($stored['sealed'], true) : false;

        if ($sealed === false) {
            return null;
        }

        try {
            $key = Craft::$app->getSecurity()->decryptByKey($sealed);
        } catch (Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function put(string $provider, #[SensitiveParameter] string $key): void
    {
        $sealed = Craft::$app->getSecurity()->encryptByKey($key);

        Plugin::getInstance()->store->putState(self::name($provider), ['sealed' => base64_encode($sealed)]);
    }

    public function forget(string $provider): void
    {
        Plugin::getInstance()->store->deleteState(self::name($provider));
    }

    public static function name(string $provider): string
    {
        return 'provider-key:' . preg_replace('/[^a-z0-9_-]/', '', strtolower($provider));
    }
}
