<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Closure;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Services;

/**
 * API keys from the environment (.env), read every time they are needed:
 * each service's variables as core's Connections\Services names them
 * (SHUTTERSTOCK_API_SECRET for `shutterstock_secret`). Core's Connections
 * puts these first, then what was set up in Settings → Connections. Tests
 * set keys of their own on the Providers component, which win over the
 * environment's.
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

        $variable = self::ENV[$provider] ?? (Services::all()->forHandle($provider)[1] ?? null)?->env;

        $key = array_key_exists($provider, $overrides)
            ? $overrides[$provider]
            : ($variable !== null ? App::env($variable) : null);

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
