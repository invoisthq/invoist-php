<?php

declare(strict_types=1);

namespace Invoist\Transport;

/** One HTTP request, fully built by the client: a transport only sends it. */
final readonly class TransportRequest
{
    /**
     * @param non-empty-string $method HTTP method, upper case
     * @param non-empty-string $url absolute URL, query string included
     * @param array<string, string> $headers header name => value
     * @param string|null $body the request body, already encoded; null for none
     * @param float $timeout seconds the whole request may take
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeout,
    ) {
    }
}
