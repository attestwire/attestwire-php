<?php

declare(strict_types=1);

namespace Attestwire\Tests\Fixtures;

use Attestwire\Exception\AttestwireException;
use Attestwire\Http\HttpClientInterface;
use Attestwire\Http\HttpResponse;

/** Simulates a request that never reached the server (DNS, connection refused, ...). */
final class ThrowingHttpClient implements HttpClientInterface
{
    public function __construct(private readonly string $reason = 'Could not resolve host')
    {
    }

    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse
    {
        throw new AttestwireException(sprintf('Could not reach %s: %s', $url, $this->reason));
    }
}
