<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Closure;
use Craft;
use GuzzleHttp\Psr7\HttpFactory;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * HTTP clients for model calls, made by Craft so they get the project's
 * config/guzzle.php (a proxy, say) like every other request Craft makes.
 */
final class CraftHttpClients implements HttpClients
{
    /** Seconds to wait for a connection, whatever the response timeout. */
    private const CONNECT_TIMEOUT = 15;

    private ?HttpFactory $factory = null;

    /**
     * @param Closure(): array<string, mixed> $options Further Guzzle options, such as a test handler.
     */
    public function __construct(
        private readonly Closure $options,
    ) {
    }

    public function client(int $timeout): ClientInterface
    {
        return Craft::createGuzzleClient(['timeout' => $timeout, 'connect_timeout' => self::CONNECT_TIMEOUT] + ($this->options)());
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->factory ??= new HttpFactory();
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->factory ??= new HttpFactory();
    }
}
