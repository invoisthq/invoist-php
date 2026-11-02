<?php

declare(strict_types=1);

namespace Invoist\Transport;

/**
 * Sends one HTTP request and returns the response, whatever its status.
 *
 * The client does everything else (headers, retries, errors) on top of this,
 * so a transport only moves bytes. Two ship with the SDK: CurlTransport (the
 * default, needs only ext-curl) and Psr18Transport (any PSR-18 client).
 * Implement it yourself for tests or unusual environments.
 */
interface Transport
{
    /**
     * Returns the response for any HTTP status, 4xx and 5xx included.
     *
     * @throws NetworkException when no response arrived (DNS, TLS, refused connection, timeout)
     */
    public function send(TransportRequest $request): TransportResponse;
}
