<?php

declare(strict_types=1);

namespace Attestwire\Http;

/** A minimal HTTP response: enough for Client to read a status and a JSON body. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }
}
