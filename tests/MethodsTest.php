<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\DownloadedFile;
use Invoist\Generated;
use Invoist\Invoist;
use Invoist\Tests\Support\FakeTransport;
use Invoist\Tests\Support\Fixtures;
use Invoist\Transport\TransportResponse;
use Invoist\Upload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every API method sends the right method and path, and returns the right type. */
final class MethodsTest extends TestCase
{
    private const string ID = 'a1/b?c';
    private const string ENC = 'a1%2Fb%3Fc';

    /**
     * @return iterable<string, array{
     *     \Closure(Invoist): mixed,
     *     string,
     *     string,
     *     TransportResponse,
     *     \Closure(mixed): void,
     * }>
     */
    public static function methods(): iterable
    {
        $d = Fixtures::document();
        $json = FakeTransport::json(...);
        $file = new TransportResponse(200, ['Content-Type' => 'application/pdf'], "%PDF-1.4\x00\xff");
        $is = self::isA(...);
        $listOf = self::listOf(...);

        yield 'extract' => [fn (Invoist $i) => $i->extract('%PDF'), 'POST', '/v1/extract',
            $json(201, ['document' => $d, 'extraction' => Fixtures::extraction('pending'), 'duplicate' => false]), $is(Generated\ExtractResult::class)];
        yield 'extractBatch' => [fn (Invoist $i) => $i->extractBatch(['%PDF', '%PDF']), 'POST', '/v1/extract/batch',
            $json(200, [
                'results' => [
                    ['filename' => 'a.pdf', 'document_id' => Fixtures::DOC_ID, 'status' => 'pending'],
                    ['filename' => 'b.pdf', 'error' => ['code' => 'unsupported_type', 'message' => 'no']],
                ],
                'accepted' => 1,
                'rejected' => 1,
            ]),
            $is(Generated\BatchResult::class)];
        yield 'listDocuments' => [fn (Invoist $i) => $i->listDocuments(), 'GET', '/v1/documents',
            $json(200, ['documents' => [Fixtures::listItem()]]), $listOf(Generated\DocumentListItem::class)];
        yield 'getDocument' => [fn (Invoist $i) => $i->getDocument(self::ID), 'GET', '/v1/documents/' . self::ENC,
            $json(200, Fixtures::documentDetail()), $is(Generated\DocumentDetail::class)];
        yield 'getDocumentFile' => [fn (Invoist $i) => $i->getDocumentFile(self::ID), 'GET', '/v1/documents/' . self::ENC . '/file',
            $file, $is(DownloadedFile::class)];
        yield 'waitForExtraction' => [fn (Invoist $i) => $i->waitForExtraction(self::ID), 'GET', '/v1/documents/' . self::ENC,
            $json(200, Fixtures::documentDetail('done')), $is(Generated\Extraction::class)];
        yield 'listReviewQueue' => [fn (Invoist $i) => $i->listReviewQueue(), 'GET', '/v1/review-queue',
            $json(200, ['review_queue' => [Fixtures::reviewListItem()]]), $listOf(Generated\ReviewQueueListItem::class)];
        yield 'resolveReviewItem' => [fn (Invoist $i) => $i->resolveReviewItem(self::ID), 'POST', '/v1/review-queue/' . self::ENC . '/resolve',
            $json(200, ['review_queue_item' => Fixtures::reviewItem()]), $is(Generated\ReviewQueueItem::class)];
        yield 'listCategories' => [fn (Invoist $i) => $i->listCategories(), 'GET', '/v1/categories',
            $json(200, ['categories' => [Fixtures::category()]]), $listOf(Generated\Category::class)];
        yield 'getUsage' => [fn (Invoist $i) => $i->getUsage(), 'GET', '/v1/usage', $json(200, Fixtures::usage()), $is(Generated\Usage::class)];
        yield 'getStats' => [fn (Invoist $i) => $i->getStats(), 'GET', '/v1/stats/overview', $json(200, Fixtures::stats()), $is(Generated\StatsOverview::class)];
        yield 'listWebhookEndpoints' => [fn (Invoist $i) => $i->listWebhookEndpoints(), 'GET', '/v1/webhook-endpoints',
            $json(200, ['endpoints' => [Fixtures::webhookEndpoint()], 'known_events' => ['document.created']]), $is(Generated\WebhookEndpointList::class)];
        yield 'createWebhookEndpoint' => [fn (Invoist $i) => $i->createWebhookEndpoint('https://example.test/hook'), 'POST', '/v1/webhook-endpoints',
            $json(201, ['endpoint' => Fixtures::webhookEndpoint() + ['secret' => 'whsec_x']]), $is(Generated\WebhookEndpointWithSecret::class)];
        yield 'deleteWebhookEndpoint' => [fn (Invoist $i) => $i->deleteWebhookEndpoint(self::ID), 'DELETE', '/v1/webhook-endpoints/' . self::ENC,
            new TransportResponse(204, [], ''), static function (mixed $r): void {
                self::assertNull($r);
            }];
        yield 'bills.list' => [fn (Invoist $i) => $i->bills->list(), 'GET', '/v1/bills',
            $json(200, ['bills' => [Fixtures::bill(), Fixtures::bill()]]), $listOf(Generated\Bill::class, 2)];
        yield 'bills.get' => [fn (Invoist $i) => $i->bills->get(self::ID), 'GET', '/v1/bills/' . self::ENC,
            $json(200, Fixtures::billDetail()), $is(Generated\BillDetail::class)];
        yield 'bills.getFile' => [fn (Invoist $i) => $i->bills->getFile(self::ID), 'GET', '/v1/bills/' . self::ENC . '/file',
            $file, $is(DownloadedFile::class)];
        yield 'bills.summary' => [fn (Invoist $i) => $i->bills->summary(), 'GET', '/v1/bills/summary',
            $json(200, Fixtures::billsSummary()), $is(Generated\BillsSummary::class)];
        yield 'bills.stats' => [fn (Invoist $i) => $i->bills->stats(), 'GET', '/v1/bills/stats',
            $json(200, Fixtures::billsStats()), $is(Generated\BillsStats::class)];
        yield 'bills.markPaid' => [fn (Invoist $i) => $i->bills->markPaid(self::ID), 'POST', '/v1/bills/' . self::ENC . '/paid',
            $json(200, ['bill' => Fixtures::billRecord(Fixtures::TIME)]), $is(Generated\BillRecord::class)];
        yield 'bills.markUnpaid' => [fn (Invoist $i) => $i->bills->markUnpaid(self::ID), 'DELETE', '/v1/bills/' . self::ENC . '/paid',
            $json(200, ['bill' => Fixtures::billRecord()]), $is(Generated\BillRecord::class)];
        yield 'heldEmails.list' => [fn (Invoist $i) => $i->heldEmails->list(), 'GET', '/v1/held-emails',
            $json(200, ['held_emails' => [Fixtures::heldEmail()]]), $listOf(Generated\HeldEmail::class)];
    }

    /**
     * @param class-string $class
     * @return \Closure(mixed): void
     */
    private static function isA(string $class): \Closure
    {
        return static function (mixed $result) use ($class): void {
            self::assertInstanceOf($class, $result);
        };
    }

    /**
     * @param class-string $class
     * @return \Closure(mixed): void
     */
    private static function listOf(string $class, int $count = 1): \Closure
    {
        return static function (mixed $result) use ($class, $count): void {
            self::assertIsArray($result);
            self::assertCount($count, $result);
            self::assertContainsOnlyInstancesOf($class, $result);
        };
    }

    /**
     * @param \Closure(Invoist): mixed $call
     * @param \Closure(mixed): void $check
     */
    #[DataProvider('methods')]
    public function testMethodHitsItsRoute(\Closure $call, string $method, string $path, TransportResponse $response, \Closure $check): void
    {
        $transport = new FakeTransport($response);
        $result = $call(new Invoist('inv_sk_test', baseUrl: 'https://api.example.test', transport: $transport));

        self::assertCount(1, $transport->requests);
        self::assertSame($method, $transport->last()->method);
        self::assertSame("https://api.example.test$path", $transport->last()->url);
        $check($result);
    }

    public function testExtractAndWaitPollsTheDocumentAndReturnsBoth(): void
    {
        $transport = new FakeTransport(
            FakeTransport::json(201, ['document' => Fixtures::document(), 'extraction' => Fixtures::extraction('pending'), 'duplicate' => false]),
            FakeTransport::json(200, Fixtures::documentDetail('pending')),
            FakeTransport::json(200, ['document' => Fixtures::document('done')] + Fixtures::documentDetail('done')),
        );
        $invoist = new Invoist('inv_sk_test', transport: $transport, sleep: static function (float $_): void {
        });

        $result = $invoist->extractAndWait(Upload::fromString('%PDF-1.4', 'march.pdf'));

        self::assertSame('done', $result->document->status);
        self::assertSame('done', $result->extraction->status);
        self::assertSame(48000, $result->extraction->total_cents);
        self::assertSame(['POST', 'GET', 'GET'], array_map(static fn ($r) => $r->method, $transport->requests));
    }

    public function testExtractAndWaitSkipsPollingWhenTheReadingIsAlreadyDone(): void
    {
        $transport = new FakeTransport(
            FakeTransport::json(201, ['document' => Fixtures::document('done'), 'extraction' => Fixtures::extraction('done'), 'duplicate' => false]),
        );
        $result = (new Invoist('inv_sk_test', transport: $transport))->extractAndWait('%PDF');

        self::assertSame('done', $result->extraction->status);
        self::assertCount(1, $transport->requests);
    }
}
