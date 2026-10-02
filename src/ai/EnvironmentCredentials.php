<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Closure;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;

/**
 * API keys, read from the environment (.env) every time they are needed and
 * never stored. Tests set keys of their own on the Providers component,
 * which win over the environment's.
 */
final class EnvironmentCredentials implements Credentials
{
    /**
     * @param Closure(): array<string, string|null> $overrides Keys to use in place of the environment's, by provider.
     */
    public function __construct(
        private readonly Closure $overrides,
    ) {
    }

    public function key(string $provider): ?string
    {
        $overrides = ($this->overrides)();

        $key = array_key_exists($provider, $overrides)
            ? $overrides[$provider]
            : (isset(self::ENV[$provider]) ? App::env(self::ENV[$provider]) : null);

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
