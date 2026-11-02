<?php

declare(strict_types=1);

namespace Invoist;

/**
 * Checks that a webhook really came from Invoist.
 *
 * ```php
 * $event = Webhooks::verify(
 *     file_get_contents('php://input'),
 *     $_SERVER['HTTP_X_INVOIST_SIGNATURE'] ?? null,
 *     getenv('INVOIST_WEBHOOK_SECRET'),
 * );
 * ```
 *
 * Pass the raw request body, byte for byte. Decoding the JSON and encoding
 * it again changes the bytes and the signature no longer matches.
 *
 * Needs nothing but PHP's hash extension, so it works without the client.
 */
final class Webhooks
{
    /** The header carrying the signature: `t=<unix seconds>,v1=<hex HMAC-SHA256>`. */
    public const string SIGNATURE_HEADER = 'X-Invoist-Signature';

    /** The header carrying the event type. */
    public const string EVENT_HEADER = 'X-Invoist-Event';

    /** The header carrying a delivery id, the same across retries of one delivery. */
    public const string DELIVERY_HEADER = 'X-Invoist-Delivery';

    /** How far, in seconds, a signature's timestamp may be from now, either way. */
    public const int DEFAULT_TOLERANCE = 300;

    /** A document was added. */
    public const string DOCUMENT_CREATED = 'document.created';

    /** A document was read; the event's data carries `document_id` and `status`. */
    public const string EXTRACTION_COMPLETED = 'extraction.completed';

    /** A document could not be read. */
    public const string EXTRACTION_FAILED = 'extraction.failed';

    /** Something was flagged for a person to check. */
    public const string REVIEW_ITEM_CREATED = 'review_item.created';

    /** A flagged item was resolved or dismissed. */
    public const string REVIEW_ITEM_RESOLVED = 'review_item.resolved';

    /** Someone sent the document a document request asked for. */
    public const string DOCUMENT_REQUEST_FULFILLED = 'document_request.fulfilled';

    /** Every event type above. */
    public const array EVENT_TYPES = [
        self::DOCUMENT_CREATED,
        self::EXTRACTION_COMPLETED,
        self::EXTRACTION_FAILED,
        self::REVIEW_ITEM_CREATED,
        self::REVIEW_ITEM_RESOLVED,
        self::DOCUMENT_REQUEST_FULFILLED,
    ];

    private function __construct()
    {
    }

    /**
     * Verifies the signature header and returns the event.
     *
     * The signature is an HMAC-SHA256 of `<t>.<payload>` keyed with the
     * endpoint's secret. Any `v1` in the header may match (there are two
     * while a secret is being rotated); the comparison is constant-time.
     *
     * @param string $payload the raw request body, exactly as received
     * @param string|null $signature the X-Invoist-Signature header; null when it was missing
     * @param string $secret the endpoint's signing secret (`whsec_...`)
     * @param int $tolerance seconds `t` may differ from now in either direction; 0 turns the check off
     * @param int|null $now the current Unix time, for tests; defaults to time()
     * @throws InvoistError with status 400 and the code `invalid_signature` (a missing or malformed
     *   header, or no matching signature), `signature_too_old` (outside the tolerance) or
     *   `invalid_payload` (a correctly signed body that isn't an event)
     */
    public static function verify(
        string $payload,
        ?string $signature,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): WebhookEvent {
        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signature ?? '') as $part) {
            [$key, $value] = array_map(trim(...), explode('=', $part, 2)) + [1 => ''];
            if ($key === 't') {
                if ($timestamp !== null) {
                    throw self::fail('The signature header has more than one timestamp.', 'invalid_signature');
                }
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $candidates[] = $value;
            }
        }
        if ($timestamp === null || $candidates === []) {
            throw self::fail('The signature header is missing or malformed.', 'invalid_signature');
        }
        if (!ctype_digit($timestamp) || \strlen($timestamp) > 18) {
            throw self::fail('The signature timestamp is not a number.', 'invalid_signature');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($candidates as $candidate) {
            // No early exit: every candidate is compared, each in constant time.
            $matched = hash_equals($expected, $candidate) || $matched;
        }
        if (!$matched) {
            throw self::fail('The signature does not match the payload.', 'invalid_signature');
        }

        if ($tolerance > 0 && abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw self::fail('The signature timestamp is outside the allowed window.', 'signature_too_old');
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                throw new \UnexpectedValueException('The payload is not a JSON object.');
            }

            return WebhookEvent::fromArray($data);
        } catch (\JsonException | \UnexpectedValueException $e) {
            throw new InvoistError(
                'The webhook payload is not an Invoist event: ' . $e->getMessage(),
                400,
                'invalid_payload',
                previous: $e,
            );
        }
    }

    private static function fail(string $message, string $code): InvoistError
    {
        return new InvoistError($message, 400, $code);
    }
}
