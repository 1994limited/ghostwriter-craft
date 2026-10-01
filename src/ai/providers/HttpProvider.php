<?php

namespace nineteenninetyfour\ghostwriter\ai\providers;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\RequestException;
use nineteenninetyfour\ghostwriter\ai\ProviderException;
use Psr\Http\Message\ResponseInterface;

/**
 * What the three providers share: a Guzzle client, a key, and turning an
 * HTTP failure into something a person can read.
 */
abstract class HttpProvider
{
    /** Tries at a request that is refused for being busy, rate limited or broken for a moment. */
    private const ATTEMPTS = 3;

    /** Statuses that mean "not now" rather than "no": rate limits, overloads, outages. */
    private const RETRY_ON = [408, 429, 500, 502, 503, 504, 529];

    /** The longest wait between tries, in seconds, whatever the provider asks. */
    private const MAX_WAIT = 30;

    /**
     * How to wait, in seconds. Tests replace it, so they don't.
     *
     * @var callable(float): void|null
     */
    public static $sleep = null;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly ClientInterface $http,
    ) {
    }

    abstract public function handle(): string;

    /**
     * @param array<string, mixed> $options Guzzle request options.
     * @return array<string, mixed> The decoded JSON body.
     */
    protected function send(string $method, string $url, array $options, int $timeout): array
    {
        // Text from a site's database is not always clean UTF-8, and one bad
        // byte would stop the whole request from being encoded.
        if (isset($options['json'])) {
            $options['json'] = $this->clean($options['json']);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options + ['timeout' => $timeout, 'connect_timeout' => 15]);

                break;
            } catch (RequestException $exception) {
                if ($attempt < self::ATTEMPTS && $this->worthRetrying($exception->getResponse())) {
                    $this->wait($attempt, $exception->getResponse());

                    continue;
                }

                throw new ProviderException($this->explain($exception->getResponse()) ?? $exception->getMessage(), 0, $exception);
            } catch (ConnectException $exception) {
                if ($attempt < self::ATTEMPTS) {
                    $this->wait($attempt, null);

                    continue;
                }

                throw new ProviderException("Could not reach {$this->name()}: {$exception->getMessage()}", 0, $exception);
            } catch (TransferException $exception) {
                throw new ProviderException("Could not reach {$this->name()}: {$exception->getMessage()}", 0, $exception);
            }
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data)) {
            throw new ProviderException("{$this->name()} sent back something that was not JSON.");
        }

        return $data;
    }

    private function worthRetrying(?ResponseInterface $response): bool
    {
        if ($response === null) {
            return true;
        }

        if (in_array($response->getStatusCode(), self::RETRY_ON, true)) {
            return true;
        }

        // Anthropic and Google also say "overloaded" in the body.
        return str_contains(strtolower((string) $response->getBody()), 'overloaded');
    }

    /**
     * Wait before trying again: as long as the provider asks, or longer each
     * time with a little randomness, so many sites don't retry in step.
     */
    private function wait(int $attempt, ?ResponseInterface $response): void
    {
        $asked = $response?->getHeaderLine('retry-after');
        $seconds = is_numeric($asked) ? (float) $asked : (2 ** $attempt) + mt_rand(0, 1000) / 1000;
        $seconds = min(max($seconds, 0), self::MAX_WAIT);

        (self::$sleep ?? fn(float $seconds) => usleep((int) ($seconds * 1_000_000)))($seconds);
    }

    private function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
        }

        return is_array($value) ? array_map(fn($item) => $this->clean($item), $value) : $value;
    }

    /**
     * The provider's own words for what went wrong, where it gave any.
     */
    private function explain(?ResponseInterface $response): ?string
    {
        if (!$response) {
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);
        $message = $data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null);

        return sprintf('%s said no (%d)%s', $this->name(), $response->getStatusCode(), is_string($message) && $message !== '' ? ": {$message}" : '.');
    }

    protected function name(): string
    {
        return match ($this->handle()) {
            'anthropic' => 'Anthropic',
            'openai' => 'OpenAI',
            'gemini' => 'Gemini',
            default => $this->handle(),
        };
    }
}
