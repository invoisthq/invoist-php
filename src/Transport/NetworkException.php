<?php

declare(strict_types=1);

namespace Invoist\Transport;

/**
 * Thrown by a transport when no response arrived at all: DNS, TLS, a
 * refused or dropped connection, or a timeout. The client turns it into an
 * InvoistError with status 0 and the code `network_error` or `timeout`.
 */
final class NetworkException extends \RuntimeException
{
    public function __construct(
        string $message,
        /** True when the request ran out of time rather than failing outright. */
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
