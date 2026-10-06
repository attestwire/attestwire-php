<?php

declare(strict_types=1);

namespace Attestwire\Http;

/** A minimal HTTP response: enough for Client to read a status and a JSON body. */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers response headers, names lower-cased.
     *                                       Optional, so an HttpClientInterface written
     *                                       before 0.2.0 still works for validate();
     *                                       generate() reads them for a PDF's file name
     *                                       and watermark.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
