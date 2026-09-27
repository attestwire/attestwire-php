<?php

declare(strict_types=1);

namespace Attestwire\Http;

use Attestwire\Exception\AttestwireException;

/**
 * The one seam `Client` talks to the network through.
 *
 * The default implementation, `CurlHttpClient`, is the only thing in this
 * package that uses ext-curl. Tests (and any caller who wants to swap in
 * their own PSR-18 client, a mock, or something else entirely) inject their
 * own implementation instead — see `Attestwire\Client`'s constructor.
 */
interface HttpClientInterface
{
    /**
     * @param  array<string, string> $headers        header name => value; sent as given,
     *                                                no case changes.
     * @throws AttestwireException                   the request could not be sent at all
     *                                                (DNS, connection refused, timeout, ...).
     *                                                An HTTP error STATUS is not this — that
     *                                                is a normal `HttpResponse` for the caller
     *                                                to inspect.
     */
    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse;
}
