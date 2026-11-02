<?php

declare(strict_types=1);

namespace Invoist;

/**
 * The one exception the client throws for a failed call: an API error, a
 * response that isn't what the API sends, no response at all, a timeout,
 * or waitForExtraction() giving up. Webhooks::verify() throws it too.
 *
 * Branch on $code (stable), never on the message (it may change):
 *
 * ```php
 * try {
 *     $bill = $invoist->bills->get($id);
 * } catch (InvoistError $e) {
 *     if ($e->isNotFound()) { ... }
 *     error_log("$e->code ($e->status), request $e->requestId: {$e->getMessage()}");
 * }
 * ```
 */
class InvoistError extends \RuntimeException
{
    /**
     * The stable machine code: the API's own (`invalid_api_key`,
     * `insufficient_scope`, `not_found`, `rate_limited`, ...), or one the SDK
     * sets: `unexpected_response` (the body wasn't the API's error envelope),
     * `network_error`, `timeout`, `extraction_timeout`, and for webhooks
     * `invalid_signature`, `signature_too_old` and `invalid_payload`.
     *
     * Untyped because \Exception declares $code untyped; it is always a
     * string here, and getCode() returns it too. Treat it as read-only.
     *
     * @var string
     */
    // @phpstan-ignore property.phpDocType (PHP forbids re-typing $code; string narrows Exception's mixed)
    public $code;

    /**
     * @param string $message human-readable text; may change, never branch on it
     * @param int $status the HTTP status, or 0 when no response arrived
     * @param string $code the stable machine code
     * @param string|null $requestId the X-Request-Id response header, for support
     * @param string|null $body the raw response body, when there was one
     */
    public function __construct(
        string $message,
        /** The HTTP status, or 0 when no response arrived. */
        public readonly int $status = 0,
        string $code = 'unexpected_response',
        /** The X-Request-Id response header: quote it when contacting support. */
        public readonly ?string $requestId = null,
        /** The raw response body, when there was one. */
        public readonly ?string $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->code = $code;
    }

    /**
     * Builds the error for a non-2xx response from the API's
     * `{"error":{"code","message"}}` envelope. A body that isn't that
     * envelope (a proxy's HTML page, an empty body) gives the code
     * `unexpected_response` and the message "Request failed with status <n>";
     * an envelope missing one of the two keys keeps the other.
     */
    public static function fromResponse(int $status, string $body, ?string $requestId = null): self
    {
        $fallback = "Request failed with status $status";
        $code = 'unexpected_response';
        $message = $fallback;
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        $error = \is_array($decoded) ? ($decoded['error'] ?? null) : null;
        // An envelope missing one of its two keys keeps the other.
        if (\is_array($error)) {
            if (\is_string($error['code'] ?? null) && $error['code'] !== '') {
                $code = $error['code'];
            }
            if (\is_string($error['message'] ?? null) && $error['message'] !== '') {
                $message = $error['message'];
            }
        }

        return new self($message, $status, $code, $requestId, $body === '' ? null : $body);
    }

    /** 401: the key is missing, malformed or revoked. */
    public function isAuthError(): bool
    {
        return $this->status === 401;
    }

    /** 403: the key is valid but may not do this (a missing scope, a household route with a business key). */
    public function isPermissionError(): bool
    {
        return $this->status === 403;
    }

    /** 404: no such resource for this key. */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** 429: too many requests; the client already waited and retried before throwing this. */
    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /** No response, 429 or 5xx: worth trying again later. */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
