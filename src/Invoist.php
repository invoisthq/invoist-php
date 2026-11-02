<?php

declare(strict_types=1);

namespace Invoist;

use Invoist\Generated\BatchResult;
use Invoist\Generated\Category;
use Invoist\Generated\CreateWebhookEndpointResponse;
use Invoist\Generated\DocumentDetail;
use Invoist\Generated\DocumentListItem;
use Invoist\Generated\Extraction;
use Invoist\Generated\ExtractResult;
use Invoist\Generated\ListCategoriesResponse;
use Invoist\Generated\ListDocumentsResponse;
use Invoist\Generated\ListReviewQueueResponse;
use Invoist\Generated\ResolveReviewQueueItemResponse;
use Invoist\Generated\ReviewQueueItem;
use Invoist\Generated\ReviewQueueListItem;
use Invoist\Generated\StatsOverview;
use Invoist\Generated\Usage;
use Invoist\Generated\WebhookEndpointList;
use Invoist\Generated\WebhookEndpointWithSecret;
use Invoist\Internal\Http;
use Invoist\Transport\CurlTransport;
use Invoist\Transport\Psr18Transport;
use Invoist\Transport\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The client for the Invoist API.
 *
 * ```php
 * $invoist = new Invoist(getenv('INVOIST_API_KEY'));
 *
 * // A business workspace
 * $result = $invoist->extractAndWait(Upload::fromPath('invoice.pdf'));
 * echo $result->extraction->vendor_name, ' ', $result->extraction->total_cents;
 *
 * // A household (Invoist Personal)
 * foreach ($invoist->bills->list(paid: false) as $bill) {
 *     echo $bill->vendor_name, ' ', $bill->total_cents, ' ', $bill->due_date, "\n";
 * }
 * ```
 *
 * Every method returns typed, read-only objects whose fields are the API's
 * own (`total_cents`, `due_date`). Money is always an int of minor units.
 * Every failure throws InvoistError. Requests that fail without a response,
 * with 429 or with a 5xx are retried (see the constructor).
 */
final class Invoist
{
    /** This library's version. */
    public const string VERSION = '1.0.1';

    public const string DEFAULT_BASE_URL = 'https://api.invoi.st';

    /** Seconds one attempt of a request may take. */
    public const float DEFAULT_TIMEOUT = 60.0;

    /** Retries after the first attempt, so at most three attempts. */
    public const int DEFAULT_MAX_RETRIES = 2;

    /** A household's bills (keys and assistants with `bills:*` scopes). */
    public readonly Bills $bills;

    /** Emails a household's address is holding for approval. */
    public readonly HeldEmails $heldEmails;

    private readonly Http $http;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * Requests go through ext-curl unless you pass a PSR-18 client (with
     * PSR-17 factories, unless the client is its own factory, as Symfony's
     * Psr18Client is) or a Transport of your own.
     *
     * A request that gets no response, a 429 or a 5xx is tried again up to
     * $maxRetries times, waiting the server's Retry-After when it sends one,
     * otherwise 0.5s, 1s, 2s... (±20%, at most 8s). A Retry-After over 60s
     * fails at once instead of hanging the program. Uploads retry too: the
     * API recognises a file it has already seen.
     *
     * @param string $apiKey a workspace or household key (`inv_sk_...`), or an OAuth access token (`inv_at_...`)
     * @param string $baseUrl the API's address; trailing slashes are trimmed
     * @param float $timeout seconds one attempt may take (with the curl transport)
     * @param int $maxRetries retries after the first attempt; 0 turns retrying off
     * @param Transport|null $transport sends the requests; overrides $httpClient
     * @param ClientInterface|null $httpClient a PSR-18 client to send the requests with
     * @param RequestFactoryInterface|null $requestFactory a PSR-17 request factory for $httpClient
     * @param StreamFactoryInterface|null $streamFactory a PSR-17 stream factory for $httpClient
     * @param (\Closure(float): void)|null $sleep waits the given seconds between retries and polls;
     *   defaults to usleep(). Pass your own for tests or an event loop.
     * @throws \InvalidArgumentException when the key is empty or an option is out of range
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?Transport $transport = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?\Closure $sleep = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'An Invoist API key is required: pass one to new Invoist(), e.g. getenv(\'INVOIST_API_KEY\').',
            );
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('The timeout must be more than 0 seconds.');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries cannot be negative.');
        }
        $baseUrl = rtrim($baseUrl, '/');
        if (preg_match('#^https?://[^/]#i', $baseUrl) !== 1) {
            throw new \InvalidArgumentException("The base URL must start with https:// or http://, got \"$baseUrl\".");
        }

        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $transport ??= $httpClient !== null
            ? self::psr18($httpClient, $requestFactory, $streamFactory)
            : new CurlTransport();
        $this->http = new Http($apiKey, $baseUrl, $timeout, $maxRetries, $transport, $this->sleep);
        $this->bills = new Bills($this->http);
        $this->heldEmails = new HeldEmails($this->http);
    }

    // Extraction ------------------------------------------------------------

    /**
     * Submits a document (PDF, PNG or JPEG, under 20 MB) for reading.
     *
     * Returns as soon as the file is accepted, with the extraction usually
     * still `pending`; wait with waitForExtraction(), or handle the
     * `extraction.completed` webhook. Sending bytes this workspace already has
     * returns the existing document with `duplicate` true, at no charge.
     *
     * @param Upload|\SplFileInfo|StreamInterface|resource|string $file an Upload, a file on disk, a stream, or raw bytes
     * @param string|null $idempotencyKey replays the first response when the same key is sent again; a different
     *   file under a used key fails with status 422. Covers retrying with bytes that may differ.
     * @throws InvoistError
     */
    public function extract(mixed $file, ?string $idempotencyKey = null): ExtractResult
    {
        return $this->http->json(
            'POST',
            '/v1/extract',
            ExtractResult::fromArray(...),
            files: [['file', Upload::from($file)]],
            headers: $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [],
        );
    }

    /**
     * Submits up to ten documents in one request. One bad file doesn't fail
     * the others: each entry of `results` has its own outcome, in order.
     *
     * @param array<Upload|\SplFileInfo|StreamInterface|resource|string> $files
     * @throws InvoistError
     * @throws \InvalidArgumentException when $files is empty
     */
    public function extractBatch(array $files): BatchResult
    {
        if ($files === []) {
            throw new \InvalidArgumentException('extractBatch() needs at least one file.');
        }
        $parts = [];
        foreach (array_values($files) as $i => $file) {
            $parts[] = ['files', Upload::from($file, 'upload-' . ($i + 1))];
        }

        return $this->http->json('POST', '/v1/extract/batch', BatchResult::fromArray(...), files: $parts);
    }

    /**
     * Polls a document until its extraction is no longer `pending`, and
     * returns it.
     *
     * Returns on `done` and on `failed` alike: a document that couldn't be
     * read is an answer (see `error_message`), not an exception.
     *
     * @param float $timeout seconds to keep polling
     * @param float $pollInterval seconds between polls
     * @throws InvoistError with the code `extraction_timeout` (status 0) when it is still pending after $timeout
     */
    public function waitForExtraction(string $documentId, float $timeout = 120.0, float $pollInterval = 2.0): Extraction
    {
        return $this->pollDocument($documentId, $timeout, $pollInterval)[1];
    }

    /**
     * extract() and then waitForExtraction(): the document as it stands once
     * reading has finished, and its extraction.
     *
     * @param Upload|\SplFileInfo|StreamInterface|resource|string $file
     * @throws InvoistError
     */
    public function extractAndWait(
        mixed $file,
        float $timeout = 120.0,
        float $pollInterval = 2.0,
        ?string $idempotencyKey = null,
    ): ExtractAndWaitResult {
        $result = $this->extract($file, $idempotencyKey);
        if ($result->extraction !== null && $result->extraction->status !== 'pending') {
            return new ExtractAndWaitResult($result->document, $result->extraction); // a test key reads at once
        }
        [$document, $extraction] = $this->pollDocument($result->document->id, $timeout, $pollInterval);

        return new ExtractAndWaitResult($document, $extraction);
    }

    // Documents -------------------------------------------------------------

    /**
     * Lists documents, newest first.
     *
     * @param int|null $limit at most this many (the API defaults to 50, caps at 200)
     * @param string|null $status one of the Generated\DocumentStatus constants
     * @param bool|null $flagged true for only documents with open review items; false or null for all
     *   (only true is sent: the API treats false as no filter)
     * @param string|null $q matches filename, vendor, invoice number or reference, ignoring case
     * @param string|null $category a category id, or `none` for uncategorised documents
     * @return list<DocumentListItem>
     * @throws InvoistError
     */
    public function listDocuments(
        ?int $limit = null,
        ?int $offset = null,
        ?string $status = null,
        ?bool $flagged = null,
        ?string $q = null,
        ?string $category = null,
    ): array {
        return $this->http->json(
            'GET',
            '/v1/documents',
            ListDocumentsResponse::fromArray(...),
            [
                'limit' => $limit,
                'offset' => $offset,
                'status' => $status,
                'flagged' => $flagged === true ? true : null,
                'q' => $q,
                'category' => $category,
            ],
        )->documents;
    }

    /**
     * A document with its extraction (absent until one exists) and review items.
     *
     * @throws InvoistError
     */
    public function getDocument(string $documentId): DocumentDetail
    {
        return $this->http->json('GET', '/v1/documents/' . rawurlencode($documentId), DocumentDetail::fromArray(...));
    }

    /**
     * Downloads a document's original file, byte for byte.
     *
     * @throws InvoistError
     */
    public function getDocumentFile(string $documentId): DownloadedFile
    {
        return $this->http->download('/v1/documents/' . rawurlencode($documentId) . '/file');
    }

    // Review queue ----------------------------------------------------------

    /**
     * Lists what was flagged for a person to check.
     *
     * @param string|null $status one of the Generated\ReviewStatus constants; null for all
     * @return list<ReviewQueueListItem>
     * @throws InvoistError
     */
    public function listReviewQueue(?string $status = null): array
    {
        return $this->http->json('GET', '/v1/review-queue', ListReviewQueueResponse::fromArray(...), ['status' => $status])
            ->review_queue;
    }

    /**
     * Resolves or dismisses a review item.
     *
     * @param string $status `resolved` or `dismissed`
     * @throws InvoistError
     */
    public function resolveReviewItem(string $itemId, string $status = 'resolved'): ReviewQueueItem
    {
        return $this->http->json(
            'POST',
            '/v1/review-queue/' . rawurlencode($itemId) . '/resolve',
            ResolveReviewQueueItemResponse::fromArray(...),
            json: ['status' => $status],
        )->review_queue_item;
    }

    // Workspace -------------------------------------------------------------

    /**
     * The workspace's spend categories.
     *
     * @return list<Category>
     * @throws InvoistError
     */
    public function listCategories(): array
    {
        return $this->http->json('GET', '/v1/categories', ListCategoriesResponse::fromArray(...))->categories;
    }

    /**
     * Usage in the current billing period, with a per-day breakdown.
     *
     * @throws InvoistError
     */
    public function getUsage(): Usage
    {
        return $this->http->json('GET', '/v1/usage', Usage::fromArray(...));
    }

    /**
     * Document counts, the pending review count and the plan's allowance.
     *
     * @throws InvoistError
     */
    public function getStats(): StatsOverview
    {
        return $this->http->json('GET', '/v1/stats/overview', StatsOverview::fromArray(...));
    }

    // Webhook endpoints -----------------------------------------------------

    /**
     * Every registered webhook endpoint, and the event types there are.
     *
     * @throws InvoistError
     */
    public function listWebhookEndpoints(): WebhookEndpointList
    {
        return $this->http->json('GET', '/v1/webhook-endpoints', WebhookEndpointList::fromArray(...));
    }

    /**
     * Registers a URL to receive signed events. The returned `secret` is
     * shown only this once: store it to verify deliveries with Webhooks::verify().
     *
     * @param array<string>|null $events Webhooks event constants, or `['*']`; null subscribes to everything
     * @throws InvoistError
     */
    public function createWebhookEndpoint(string $url, ?array $events = null, ?string $description = null): WebhookEndpointWithSecret
    {
        $body = ['url' => $url];
        if ($events !== null) {
            $body['events'] = array_values($events);
        }
        if ($description !== null) {
            $body['description'] = $description;
        }

        return $this->http->json('POST', '/v1/webhook-endpoints', CreateWebhookEndpointResponse::fromArray(...), json: $body)
            ->endpoint;
    }

    /**
     * Removes a webhook endpoint; deliveries to it stop at once.
     *
     * @throws InvoistError
     */
    public function deleteWebhookEndpoint(string $endpointId): void
    {
        $this->http->empty('DELETE', '/v1/webhook-endpoints/' . rawurlencode($endpointId));
    }

    // -----------------------------------------------------------------------

    /**
     * @return array{0: \Invoist\Generated\Document, 1: Extraction}
     * @throws InvoistError
     */
    private function pollDocument(string $documentId, float $timeout, float $pollInterval): array
    {
        $started = hrtime(true);
        $slept = 0.0;
        while (true) {
            $detail = $this->getDocument($documentId);
            if ($detail->extraction !== null && $detail->extraction->status !== 'pending') {
                return [$detail->document, $detail->extraction];
            }
            // Time passed is the clock's, or what was slept when a test's sleep doesn't really wait.
            $elapsed = max((hrtime(true) - $started) / 1e9, $slept);
            if ($elapsed >= $timeout) {
                throw new InvoistError(
                    "The extraction of document $documentId was still pending after {$timeout}s.",
                    0,
                    'extraction_timeout',
                );
            }
            $wait = max(0.0, min($pollInterval, $timeout - $elapsed));
            ($this->sleep)($wait);
            $slept += $wait;
        }
    }

    private static function psr18(
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
    ): Psr18Transport {
        $requestFactory ??= $client instanceof RequestFactoryInterface ? $client : null;
        $streamFactory ??= match (true) {
            $requestFactory instanceof StreamFactoryInterface => $requestFactory,
            $client instanceof StreamFactoryInterface => $client,
            default => null,
        };
        if ($requestFactory === null || $streamFactory === null) {
            throw new \InvalidArgumentException(
                'A PSR-18 httpClient needs PSR-17 factories too: pass requestFactory and streamFactory.',
            );
        }

        return new Psr18Transport($client, $requestFactory, $streamFactory);
    }
}
