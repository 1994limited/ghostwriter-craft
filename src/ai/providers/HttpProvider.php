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

        try {
            $response = $this->http->request($method, $url, $options + ['timeout' => $timeout, 'connect_timeout' => 15]);
        } catch (RequestException $exception) {
            throw new ProviderException($this->explain($exception->getResponse()) ?? $exception->getMessage(), 0, $exception);
        } catch (ConnectException|TransferException $exception) {
            throw new ProviderException("Could not reach {$this->name()}: {$exception->getMessage()}", 0, $exception);
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data)) {
            throw new ProviderException("{$this->name()} sent back something that was not JSON.");
        }

        return $data;
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
