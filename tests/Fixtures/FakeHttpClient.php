<?php

declare(strict_types=1);

namespace Attestwire\Tests\Fixtures;

use Attestwire\Http\HttpClientInterface;
use Attestwire\Http\HttpResponse;

/**
 * A scripted `HttpClientInterface` for tests: no curl, no network.
 *
 * Queue responses with `queue()`, then inspect what was sent via
 * `$fake->requests` after the call — each entry is
 * `['method' => ..., 'url' => ..., 'headers' => ..., 'body' => ..., 'timeoutSeconds' => ...]`.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, timeoutSeconds: float}> */
    public array $requests = [];

    public function queue(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeoutSeconds' => $timeoutSeconds,
        ];

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new \RuntimeException('FakeHttpClient::send() called with no queued response.');
        }

        return $response;
    }
}
