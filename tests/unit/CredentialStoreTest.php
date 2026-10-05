<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\CredentialStoreContract;
use nineteenninetyfour\ghostwriter\domain\DbCredentialStore;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's CredentialStoreContract against the plugin's credentials table,
 * encrypted with the security key.
 */
class CredentialStoreTest extends TestCase
{
    use CredentialStoreContract;

    protected function _after(): void
    {
        foreach (['pexels', 'health:pexels', 'tokens:shutterstock', 'anthropic', 'unsplash'] as $name) {
            $this->plugin->credentials->forget($name);
        }

        parent::_after();
    }

    protected function contractStore(): CredentialStore
    {
        return $this->plugin->credentials;
    }

    protected function contractRaw(string $name): ?string
    {
        return $this->plugin->credentials->raw($name);
    }

    public function test_the_plugin_has_its_own(): void
    {
        $this->assertInstanceOf(DbCredentialStore::class, $this->contractStore());
    }
}
