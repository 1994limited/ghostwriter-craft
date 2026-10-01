<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Craft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use craft\helpers\App;
use nineteenninetyfour\ghostwriter\ai\providers\Anthropic;
use nineteenninetyfour\ghostwriter\ai\providers\Gemini;
use nineteenninetyfour\ghostwriter\ai\providers\OpenAi;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Chooses the provider for each call, from the settings and from which API
 * keys the environment has. Keys are read from the environment every time
 * and never stored.
 *
 * Every request Ghostwriter makes to the outside world, models and photo
 * libraries alike, goes through http(), so one Guzzle handler can stand in
 * for all of them in tests.
 */
class Providers extends Component
{
    /** Environment variable holding each provider's key. */
    public const KEYS = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
        'unsplash' => 'UNSPLASH_ACCESS_KEY',
        'pixabay' => 'PIXABAY_API_KEY',
        'pexels' => 'PEXELS_API_KEY',
    ];

    /** Providers that make images, in the order they are tried. */
    public const IMAGE_PROVIDERS = ['openai', 'gemini'];

    /**
     * Keys to use in place of the environment's, by provider. For tests;
     * a site sets its keys in .env.
     *
     * @var array<string, string|null>
     */
    public array $keys = [];

    /** A Guzzle handler to send every request through, in place of the network. */
    public ?HandlerStack $handler = null;

    private ?FakeProvider $fake = null;

    /**
     * Stand a fake in for every model, text and image alike.
     */
    public function fake(?FakeProvider $fake = null): FakeProvider
    {
        return $this->fake = $fake ?? new FakeProvider();
    }

    public function key(string $provider): ?string
    {
        $key = array_key_exists($provider, $this->keys)
            ? $this->keys[$provider]
            : (isset(self::KEYS[$provider]) ? App::env(self::KEYS[$provider]) : null);

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Which keys are set, by variable name, for the settings screen. Never the keys themselves.
     *
     * @return array<string, bool>
     */
    public function keyStatus(): array
    {
        $status = [];

        foreach (self::KEYS as $provider => $variable) {
            $status[$variable] = $this->key($provider) !== null;
        }

        return $status;
    }

    /** The provider chosen to write with. */
    public function handle(): string
    {
        return Plugin::getInstance()->getSettings()->provider;
    }

    /**
     * Whether the chosen provider has a key to call with.
     */
    public function configured(): bool
    {
        return $this->fake !== null || $this->key($this->handle()) !== null;
    }

    /**
     * @throws ProviderException when the chosen provider has no key.
     */
    public function text(): TextProvider
    {
        if ($this->fake) {
            return $this->fake;
        }

        $handle = $this->handle();
        $key = $this->key($handle) ?? throw new ProviderException('No API key is set for ' . $handle . '. Add ' . self::KEYS[$handle] . ' to your .env file.');

        return match ($handle) {
            'openai' => new OpenAi($key, $this->http()),
            'gemini' => new Gemini($key, $this->http()),
            default => new Anthropic($key, $this->http()),
        };
    }

    /**
     * The provider to make images with: the one chosen in the settings, or
     * the first that has a key. Null when there is none.
     */
    public function imageHandle(): ?string
    {
        if ($this->fake) {
            return 'fake';
        }

        $chosen = Plugin::getInstance()->getSettings()->imageProvider;

        foreach ($chosen ? [$chosen] : self::IMAGE_PROVIDERS as $provider) {
            if ($this->key($provider) !== null) {
                return $provider;
            }
        }

        return null;
    }

    public function image(): ?ImageProvider
    {
        if ($this->fake) {
            return $this->fake;
        }

        $handle = $this->imageHandle();

        return match ($handle) {
            'openai' => new OpenAi((string) $this->key('openai'), $this->http()),
            'gemini' => new Gemini((string) $this->key('gemini'), $this->http()),
            default => null,
        };
    }

    public function http(): ClientInterface
    {
        return Craft::createGuzzleClient($this->handler ? ['handler' => $this->handler] : []);
    }
}
