<?php

namespace nineteenninetyfour\ghostwriter\domain;

use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * A connected library account's tokens ("Connect account"), one set per
 * library for the whole site: the licences belong to the company, not to
 * whoever pressed the button. Kept beside the library's key in Settings →
 * Connections (core's StoredLibraryTokens over DbCredentialStore,
 * encrypted with the security key).
 */
class DbLibraryTokens implements LibraryTokens
{
    public function get(string $library): ?TokenSet
    {
        return $this->tokens()->get($library);
    }

    public function put(string $library, TokenSet $tokens): void
    {
        $this->tokens()->put($library, $tokens);
    }

    public function forget(string $library): void
    {
        $this->tokens()->forget($library);
    }

    private function tokens(): StoredLibraryTokens
    {
        return new StoredLibraryTokens(Plugin::getInstance()->credentials);
    }
}
