<?php

namespace nineteenninetyfour\ghostwriter\domain;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredProviderKeys;
use nineteenninetyfour\ghostwriter\Plugin;
use SensitiveParameter;

/**
 * A model provider's key from "Connect with OpenRouter", one per provider
 * for the whole site, kept on its card in Settings → Connections (core's
 * StoredProviderKeys over DbCredentialStore: the `ghostwriter_credentials`
 * table, encrypted with the security key). A key in .env always wins.
 */
class DbProviderKeys implements ProviderKeys
{
    public function get(string $provider): ?string
    {
        return $this->keys()->get($provider);
    }

    public function put(string $provider, #[SensitiveParameter] string $key): void
    {
        $this->keys()->put($provider, $key);
    }

    public function forget(string $provider): void
    {
        $this->keys()->forget($provider);
    }

    private function keys(): StoredProviderKeys
    {
        return new StoredProviderKeys(Plugin::getInstance()->providers->connections());
    }
}
