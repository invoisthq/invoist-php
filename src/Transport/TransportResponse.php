<?php

declare(strict_types=1);

namespace Invoist\Transport;

/** One HTTP response, before the client interprets it. */
final readonly class TransportResponse
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param int $status HTTP status code
     * @param array<string, string> $headers header name => value; repeated headers joined with ", "
     * @param string $body the raw response body
     */
    public function __construct(
        public int $status,
        array $headers,
        public string $body,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /** A header's value, matched without regard to case, or null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
