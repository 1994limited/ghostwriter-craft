<?php

namespace nineteenninetyfour\ghostwriter\ai;

use GuzzleHttp\HandlerStack;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Sleeper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers as Registry;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Craft's side of ghostwriter-core's provider registry: keys from the
 * environment, clients from Craft, choices from the plugin's settings and
 * logs to Craft's log. Core chooses and calls the provider.
 *
 * Every request Ghostwriter makes to the outside world, models and photo
 * libraries alike, goes through Craft's Guzzle clients with $handler, so one
 * Guzzle handler can stand in for all of them in tests.
 */
class Providers extends Component
{
    /** Environment variable holding each service's key. */
    public const KEYS = Credentials::ENV;

    /**
     * Keys to use in place of the environment's, by provider. For tests;
     * a site sets its keys in .env.
     *
     * @var array<string, string|null>
     */
    public array $keys = [];

    /** A Guzzle handler to send every request through, in place of the network. */
    public ?HandlerStack $handler = null;

    private ?Registry $registry = null;

    private ?Credentials $credentials = null;

    private ?CraftHttpClients $httpClients = null;

    /**
     * Core's registry, built once and shared. The ports read the keys,
     * handler and settings each time, so changing them applies at once.
     */
    public function registry(): Registry
    {
        return $this->registry ??= new Registry(
            $this->credentials(),
            $this->httpClients(),
            new SettingsProviderSettings(),
            new CraftLogger(),
        );
    }

    /**
     * Wait between retries with this sleeper from now on. For tests, which
     * record the waits rather than wait.
     */
    public function useSleeper(Sleeper $sleeper): void
    {
        $this->registry = $this->registry()->withSleeper($sleeper);
    }

    /**
     * Stand a fake in for every model, text and image alike.
     */
    public function fake(?FakeProvider $fake = null): FakeProvider
    {
        return $this->registry()->fake($fake);
    }

    /** Send model calls to the real providers again. */
    public function unfake(): void
    {
        $this->registry()->unfake();
    }

    public function key(string $provider): ?string
    {
        return $this->credentials()->key($provider);
    }

    /**
     * Which keys are set, by variable name, for the settings screen. Never the keys themselves.
     *
     * @return array<string, bool>
     */
    public function keyStatus(): array
    {
        return $this->registry()->keyStatus();
    }

    /** The provider chosen to write with in the settings, faked or not. */
    public function handle(): string
    {
        return Plugin::getInstance()->getSettings()->provider;
    }

    /**
     * Whether the chosen provider has a key to call with.
     */
    public function configured(): bool
    {
        return $this->registry()->configured();
    }

    /**
     * @throws \NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured when the chosen provider has no key.
     */
    public function text(): TextProvider
    {
        return $this->registry()->text();
    }

    /**
     * The provider to make images with: the one chosen in the settings, or
     * the first that has a key. Null when there is none.
     */
    public function imageHandle(): ?string
    {
        return $this->registry()->imageHandle();
    }

    public function image(): ?ImageProvider
    {
        return $this->registry()->image();
    }

    /**
     * Craft's HTTP clients, for model calls and the photo libraries alike.
     */
    public function httpClients(): CraftHttpClients
    {
        return $this->httpClients ??= new CraftHttpClients(fn() => $this->handler ? ['handler' => $this->handler] : []);
    }

    public function credentials(): Credentials
    {
        return $this->credentials ??= new EnvironmentCredentials(fn() => $this->keys);
    }
}
