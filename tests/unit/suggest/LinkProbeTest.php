<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\HttpLinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkProbeContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Core's LinkProbeContract against the probe the plugin uses (core's
 * HttpLinkProbe over Craft's Guzzle clients), with a handler answering as
 * each test says, so no request leaves.
 */
class LinkProbeTest extends TestCase
{
    use LinkProbeContract;

    protected function linkProbe(array $answers): LinkProbe
    {
        $this->plugin->providers->handler = HandlerStack::create(function(RequestInterface $request) use ($answers) {
            $answer = $answers[(string) $request->getUri()] ?? 404;

            if ($answer === 'dns') {
                return Create::rejectionFor(new ConnectException('cURL error 6: Could not resolve host: gone.example', $request));
            }

            if ($answer === 'timeout') {
                return Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds', $request));
            }

            return Create::promiseFor(new Response(is_array($answer) ? ($answer[$request->getMethod()] ?? 404) : (int) $answer));
        });

        $probe = $this->plugin->revisit->linkProbe();
        $this->assertInstanceOf(HttpLinkProbe::class, $probe);

        return $probe;
    }
}
