<?php

declare(strict_types=1);

namespace Invoist\Internal;

use Invoist\DownloadedFile;
use Invoist\Invoist;
use Invoist\InvoistError;
use Invoist\Transport\NetworkException;
use Invoist\Transport\Transport;
use Invoist\Transport\TransportRequest;
use Invoist\Transport\TransportResponse;
use Invoist\Upload;

/**
 * Builds requests, sends them through the transport, retries what is worth
 * retrying, and turns failures into InvoistError. Shared by the client and
 * its resource groups.
 *
 * @internal not part of the public API; use Invoist\Invoist.
 */
final readonly class Http
{
    /** The first retry waits this long (seconds); each later one twice as long. */
    private const float BASE_DELAY = 0.5;

    /** No computed backoff waits longer than this (seconds). */
    private const float MAX_DELAY = 8.0;

    /** A Retry-After longer than this (seconds) is not waited for: the call fails at once. */
    private const int MAX_RETRY_AFTER = 60;

    /** Backoff varies by up to this fraction either way, so clients don't retry in step. */
    private const float JITTER = 0.2;

    private string $userAgent;

    /** @param \Closure(float): void $sleep */
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private float $timeout,
        private int $maxRetries,
        private Transport $transport,
        private \Closure $sleep,
    ) {
        $this->userAgent = \sprintf('invoist-php/%s (php %s)', Invoist::VERSION, PHP_VERSION);
    }

    /**
     * Sends a request and decodes its JSON with $decode.
     *
     * @template T
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $json
     * @param list<array{0: string, 1: Upload}>|null $files multipart parts as [field name, file]
     * @param array<string, string> $headers
     * @param callable(array<array-key, mixed>): T $decode
     * @return T
     * @throws InvoistError
     */
    public function json(
        string $method,
        string $path,
        callable $decode,
        array $query = [],
        ?array $json = null,
        ?array $files = null,
        array $headers = [],
    ): mixed {
        $response = $this->send($method, $path, $query, $json, $files, $headers);
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                throw new \UnexpectedValueException('the body is not a JSON object');
            }

            return $decode($data);
        } catch (\JsonException | \UnexpectedValueException $e) {
            throw new InvoistError(
                "Unexpected response from $method $path: " . $e->getMessage(),
                $response->status,
                'unexpected_response',
                $response->header('x-request-id'),
                $response->body === '' ? null : $response->body,
                $e,
            );
        }
    }

    /**
     * Sends a request whose response has no body worth reading (204).
     *
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @throws InvoistError
     */
    public function empty(string $method, string $path): void
    {
        $this->send($method, $path);
    }

    /**
     * Downloads a file.
     *
     * @param non-empty-string $path
     * @throws InvoistError
     */
    public function download(string $path): DownloadedFile
    {
        $response = $this->send('GET', $path, accept: '*/*');

        return DownloadedFile::fromResponse(
            $response->body,
            $response->header('content-type'),
            $response->header('content-disposition'),
        );
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $json
     * @param list<array{0: string, 1: Upload}>|null $files
     * @param array<string, string> $headers
     * @throws InvoistError
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?array $files = null,
        array $headers = [],
        string $accept = 'application/json',
    ): TransportResponse {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => $accept,
            'User-Agent' => $this->userAgent,
        ] + $headers;
        $body = null;
        if ($files !== null) {
            [$body, $boundary] = self::multipart($files);
            $headers['Content-Type'] = "multipart/form-data; boundary=$boundary";
        } elseif ($json !== null) {
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }
        $request = new TransportRequest($method, $this->baseUrl . $path . self::query($query), $headers, $body, $this->timeout);

        for ($attempt = 0;; $attempt++) {
            $retryAfter = null;
            try {
                $response = $this->transport->send($request);
                if ($response->status >= 200 && $response->status < 300) {
                    return $response;
                }
                $error = InvoistError::fromResponse($response->status, $response->body, $response->header('x-request-id'));
                $retryAfter = $response->header('retry-after');
            } catch (NetworkException $e) {
                $error = new InvoistError(
                    $e->timedOut
                        ? "The request timed out after {$this->timeout}s: {$e->getMessage()}"
                        : "The request failed without a response: {$e->getMessage()}",
                    0,
                    $e->timedOut ? 'timeout' : 'network_error',
                    previous: $e,
                );
            }
            if (!$error->isRetryable() || $attempt >= $this->maxRetries) {
                throw $error;
            }
            $delay = self::delay($attempt, $retryAfter);
            if ($delay > self::MAX_RETRY_AFTER) {
                throw $error; // the server asks for a longer pause than a program should hang for
            }
            ($this->sleep)($delay);
        }
    }

    /**
     * Seconds to wait before retry number $attempt + 1: the server's
     * Retry-After when it sent a whole number of seconds (anything else, such
     * as an HTTP date, is ignored), otherwise 0.5s x 2^attempt with ±20%
     * jitter, at most 8s. A Retry-After over 60s is not retried at all.
     */
    public static function delay(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            return (float) (int) trim($retryAfter);
        }
        $jitter = (new \Random\Randomizer())->getFloat(1 - self::JITTER, 1 + self::JITTER, \Random\IntervalBoundary::ClosedClosed);

        return min(self::MAX_DELAY, self::BASE_DELAY * (2 ** $attempt) * $jitter);
    }

    /**
     * Encodes a query string: nulls and empty strings are left out, booleans
     * are `true`/`false`.
     *
     * @param array<string, scalar|null> $query
     */
    private static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[$key] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $pairs === [] ? '' : '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Builds a multipart/form-data body. Done by hand because neither curl's
     * PHP binding nor PSR-7 can send one field name twice (extractBatch
     * sends every file as `files`).
     *
     * @param list<array{0: string, 1: Upload}> $files
     * @return array{0: string, 1: string} [body, boundary]
     */
    private static function multipart(array $files): array
    {
        $boundary = '----invoist-' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($files as [$field, $file]) {
            // Quotes and line breaks escaped the way browsers do it (WHATWG multipart/form-data).
            $name = str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $file->filename);
            $type = str_replace(["\r", "\n"], '', $file->contentType);
            $body .= "--$boundary\r\n"
                . "Content-Disposition: form-data; name=\"$field\"; filename=\"$name\"\r\n"
                . "Content-Type: $type\r\n\r\n"
                . $file->bytes . "\r\n";
        }

        return [$body . "--$boundary--\r\n", $boundary];
    }
}
