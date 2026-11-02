<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\Internal\Http;
use Invoist\Invoist;
use Invoist\InvoistError;
use Invoist\Tests\Support\FakeTransport;
use Invoist\Tests\Support\Fixtures;
use Invoist\Tests\Support\Multipart;
use Invoist\Transport\NetworkException;
use Invoist\Transport\TransportResponse;
use Invoist\Upload;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    private FakeTransport $transport;

    /** @var list<float> */
    private array $sleeps = [];

    private function client(TransportResponse|NetworkException ...$responses): Invoist
    {
        $this->transport = new FakeTransport(...$responses);
        $this->sleeps = [];

        return new Invoist('inv_sk_test', baseUrl: 'https://api.example.test/', transport: $this->transport, sleep: function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    private function categories(): TransportResponse
    {
        return FakeTransport::json(200, ['categories' => []]);
    }

    // Construction ----------------------------------------------------------

    public function testAnEmptyKeyFailsBeforeAnyRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key is required');
        new Invoist('', transport: new FakeTransport());
    }

    public function testOptionsOutOfRangeFail(): void
    {
        foreach (
            [
            static fn () => new Invoist('k', timeout: 0, transport: new FakeTransport()),
            static fn () => new Invoist('k', maxRetries: -1, transport: new FakeTransport()),
            static fn () => new Invoist('k', baseUrl: 'api.invoi.st', transport: new FakeTransport()),
            ] as $make
        ) {
            try {
                $make();
                self::fail('expected an InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // Headers ----------------------------------------------------------------

    public function testEveryRequestSendsAuthAcceptAndUserAgent(): void
    {
        $this->client($this->categories())->listCategories();

        $headers = $this->transport->last()->headers;
        self::assertSame('Bearer inv_sk_test', $headers['Authorization']);
        self::assertSame('application/json', $headers['Accept']);
        self::assertSame('invoist-php/' . Invoist::VERSION . ' (php ' . PHP_VERSION . ')', $headers['User-Agent']);
        self::assertMatchesRegularExpression('#^invoist-php/\d+\.\d+\.\d+ \(php \d+\.\d+\.\d+.*\)$#', $headers['User-Agent']);
        self::assertArrayNotHasKey('Content-Type', $headers);
        self::assertNull($this->transport->last()->body);
    }

    public function testTrailingSlashesOnTheBaseUrlAreTrimmed(): void
    {
        $this->client($this->categories())->listCategories();

        self::assertSame('https://api.example.test/v1/categories', $this->transport->last()->url);
        self::assertSame(60.0, $this->transport->last()->timeout);
    }

    public function testJsonBodiesAreSentAsJson(): void
    {
        $this->client(FakeTransport::json(201, ['endpoint' => Fixtures::webhookEndpoint() + ['secret' => 'whsec_1']]))
            ->createWebhookEndpoint('https://example.test/hook', ['extraction.completed'], 'ERP');

        $request = $this->transport->last();
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('{"url":"https://example.test/hook","events":["extraction.completed"],"description":"ERP"}', $request->body);
    }

    public function testResolveReviewItemSendsTheStatus(): void
    {
        $this->client(FakeTransport::json(200, ['review_queue_item' => Fixtures::reviewItem()]))->resolveReviewItem('r1', 'dismissed');

        self::assertSame('{"status":"dismissed"}', $this->transport->last()->body);
    }

    // Query strings ------------------------------------------------------------

    public function testBooleansAreSentAsTrueAndFalse(): void
    {
        $invoist = $this->client(FakeTransport::json(200, ['bills' => []]), FakeTransport::json(200, ['bills' => []]), FakeTransport::json(200, ['documents' => []]));
        $invoist->bills->list(paid: false);
        self::assertSame('https://api.example.test/v1/bills?paid=false', $this->transport->last()->url);
        $invoist->bills->list(paid: true);
        self::assertSame('https://api.example.test/v1/bills?paid=true', $this->transport->last()->url);
        $invoist->listDocuments(flagged: true);
        self::assertSame('https://api.example.test/v1/documents?flagged=true', $this->transport->last()->url);
        $this->transport->push(FakeTransport::json(200, ['documents' => []]));
        $invoist->listDocuments(flagged: false);
        self::assertSame('https://api.example.test/v1/documents', $this->transport->last()->url);
    }

    public function testMonthsAndDatesAcceptDateObjects(): void
    {
        $invoist = $this->client(FakeTransport::json(200, ['bills' => []]), FakeTransport::json(200, Fixtures::billsSummary()), FakeTransport::json(200, Fixtures::billsStats()));
        $invoist->bills->list(month: new \DateTimeImmutable('2026-03-15'), sender: 'Northwind Oy', q: 'a&b', limit: 10, offset: 20);
        self::assertSame('https://api.example.test/v1/bills?month=2026-03&sender=Northwind%20Oy&q=a%26b&limit=10&offset=20', $this->transport->last()->url);
        // The calendar date the object shows, in its own zone: 23:30 in Helsinki is still 1 November there.
        $invoist->bills->summary(today: new \DateTimeImmutable('2026-11-01 23:30', new \DateTimeZone('Europe/Helsinki')), days: 7);
        self::assertSame('https://api.example.test/v1/bills/summary?today=2026-11-01&days=7', $this->transport->last()->url);
        $invoist->bills->stats(month: '2026-10');
        self::assertSame('https://api.example.test/v1/bills/stats?month=2026-10', $this->transport->last()->url);
    }

    public function testEmptyAndMissingFiltersAreLeftOut(): void
    {
        $this->client(FakeTransport::json(200, ['documents' => []]))->listDocuments(status: '', q: null, offset: 0);

        self::assertSame('https://api.example.test/v1/documents?offset=0', $this->transport->last()->url);
    }

    // Uploads -----------------------------------------------------------------

    public function testExtractSendsOneMultipartFilePart(): void
    {
        $this->client(FakeTransport::json(201, ['document' => Fixtures::document(), 'extraction' => Fixtures::extraction('pending'), 'duplicate' => false]))
            ->extract(Upload::fromString("%PDF-1.4\x00\xff", 'Lasku "10" – Northwind.pdf'), idempotencyKey: 'job-42');

        $request = $this->transport->last();
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->headers['Content-Type']);
        self::assertSame('job-42', $request->headers['Idempotency-Key']);
        $parts = Multipart::parse($request->headers['Content-Type'], (string) $request->body);
        self::assertCount(1, $parts);
        self::assertSame('file', $parts[0]['name']);
        self::assertSame('Lasku %2210%22 – Northwind.pdf', $parts[0]['filename']);
        self::assertSame('application/pdf', $parts[0]['type']);
        self::assertSame("%PDF-1.4\x00\xff", $parts[0]['bytes']);
    }

    public function testExtractWithoutAKeySendsNoIdempotencyHeader(): void
    {
        $this->client(FakeTransport::json(200, ['document' => Fixtures::document('done'), 'duplicate' => true]))->extract('%PDF');

        self::assertArrayNotHasKey('Idempotency-Key', $this->transport->last()->headers);
    }

    public function testExtractBatchSendsEveryFileAsFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inv') . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\nrest");
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\xFF\xD8\xFFjpeg");
        rewind($stream);
        $psr = (new Psr17Factory())->createStream('%PDF-psr');

        try {
            $this->client(FakeTransport::json(200, ['results' => [], 'accepted' => 4, 'rejected' => 0]))
                ->extractBatch(['%PDF-raw', new \SplFileInfo($path), $stream, $psr]);
        } finally {
            unlink($path);
        }

        $request = $this->transport->last();
        $parts = Multipart::parse($request->headers['Content-Type'], (string) $request->body);
        self::assertSame(['files', 'files', 'files', 'files'], array_column($parts, 'name'));
        self::assertSame(['upload-1', basename($path), 'upload', 'upload'], array_column($parts, 'filename'));
        self::assertSame(['application/pdf', 'image/png', 'image/jpeg', 'application/pdf'], array_column($parts, 'type'));
        self::assertSame(['%PDF-raw', "\x89PNG\r\n\x1a\nrest", "\xFF\xD8\xFFjpeg", '%PDF-psr'], array_column($parts, 'bytes'));
    }

    public function testAnUploadIsResentUnchangedOnRetry(): void
    {
        $this->client(
            new TransportResponse(503, [], ''),
            FakeTransport::json(201, ['document' => Fixtures::document(), 'extraction' => Fixtures::extraction('pending'), 'duplicate' => false]),
        )->extract(Upload::fromString('%PDF', 'a.pdf'));

        self::assertCount(2, $this->transport->requests);
        self::assertSame($this->transport->requests[0]->body, $this->transport->requests[1]->body);
        self::assertSame($this->transport->requests[0]->headers, $this->transport->requests[1]->headers);
    }

    public function testAnUnreadableFileFailsClearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->extract(new \SplFileInfo('/nonexistent/invoice.pdf'));
    }

    public function testAnEmptyBatchFailsBeforeAnyRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->extractBatch([]);
    }

    // Responses -----------------------------------------------------------------

    public function testResponsesAreTypedAndMoneyIsAnInt(): void
    {
        $bills = $this->client(FakeTransport::json(200, ['bills' => [Fixtures::bill() + ['brand_new_field' => ['x' => 1]]]]))->bills->list();

        self::assertSame(48000, $bills[0]->total_cents);
        self::assertSame('2026-11-15', $bills[0]->due_date);
        self::assertNull($bills[0]->bill->paid_at);
        self::assertNull($bills[0]->letter);
        self::assertSame('northwind.example', $bills[0]->logo);
    }

    public function testNumbersDecodeToTheirDeclaredTypes(): void
    {
        $detail = $this->client(FakeTransport::json(200, Fixtures::documentDetail()))->getDocument('d1');

        self::assertNotNull($detail->extraction);
        $line = ($detail->extraction->line_items ?? [])[0];
        self::assertSame(2.0, $line->quantity);
        self::assertSame(['total_cents' => 0.98, 'vendor_name' => 1.0], $detail->extraction->confidence);
    }

    public function testModelsEncodeBackToTheApisJson(): void
    {
        $category = $this->client(FakeTransport::json(200, ['categories' => [Fixtures::category()]]))->listCategories()[0];

        self::assertSame(Fixtures::category(), json_decode((string) json_encode($category), true));
    }

    public function testAResponseMissingARequiredFieldIsAnUnexpectedResponse(): void
    {
        $bill = Fixtures::bill();
        unset($bill['related']);
        try {
            $this->client(FakeTransport::json(200, ['bills' => [$bill]], ['X-Request-Id' => 'req_9']))->bills->list();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame('unexpected_response', $e->code);
            self::assertSame(200, $e->status);
            self::assertSame('req_9', $e->requestId);
            self::assertStringContainsString('Bill.related is missing', $e->getMessage());
        }
    }

    public function testASuccessThatIsNotJsonIsAnUnexpectedResponse(): void
    {
        try {
            $this->client(new TransportResponse(200, ['Content-Type' => 'text/html'], '<html>captive portal</html>'))->getUsage();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame('unexpected_response', $e->code);
            self::assertSame('<html>captive portal</html>', $e->body);
        }
    }

    public function testA204ReturnsNothing(): void
    {
        $this->client(new TransportResponse(204, [], ''))->deleteWebhookEndpoint('e1');

        self::assertSame('DELETE', $this->transport->last()->method);
    }

    public function testADownloadKeepsTheExactBytesAndContentType(): void
    {
        $bytes = random_bytes(4096);
        $file = $this->client(new TransportResponse(200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => "inline; filename=\"scan.png\"; filename*=UTF-8''Lasku%20%E2%82%AC.png",
        ], $bytes))->bills->getFile('b1');

        self::assertSame($bytes, $file->bytes);
        self::assertSame('image/png', $file->contentType);
        self::assertSame('Lasku €.png', $file->filename);
        self::assertSame(4096, $file->size());
        self::assertSame('*/*', $this->transport->last()->headers['Accept']);

        $path = tempnam(sys_get_temp_dir(), 'inv');
        $file->saveTo($path);
        self::assertSame($bytes, file_get_contents($path));
        unlink($path);
    }

    public function testADownloadThatFailsIsAnInvoistError(): void
    {
        try {
            $this->client(FakeTransport::json(404, ['error' => ['code' => 'not_found', 'message' => 'document not found']]))->getDocumentFile('d1');
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertTrue($e->isNotFound());
            self::assertSame('not_found', $e->code);
        }
    }

    // Errors and retries --------------------------------------------------------

    public function testNoResponseIsANetworkErrorAfterEveryRetry(): void
    {
        $failure = new NetworkException('Could not resolve host');
        try {
            $this->client($failure, $failure, $failure)->listCategories();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame(0, $e->status);
            self::assertSame('network_error', $e->code);
            self::assertTrue($e->isRetryable());
            self::assertSame($failure, $e->getPrevious());
        }
        self::assertCount(3, $this->transport->requests);
        self::assertCount(2, $this->sleeps);
    }

    public function testATimeoutHasItsOwnCode(): void
    {
        $timeout = new NetworkException('Operation timed out', timedOut: true);
        try {
            $this->client($timeout, $timeout, $timeout)->listCategories();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame(0, $e->status);
            self::assertSame('timeout', $e->code);
        }
    }

    public function testRetryAfterIsHonouredAndBackoffIsUsedOtherwise(): void
    {
        $this->client(
            new TransportResponse(429, ['Retry-After' => '3'], ''),
            new TransportResponse(500, [], ''),
            $this->categories(),
        )->listCategories();

        self::assertSame(3.0, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(0.8, $this->sleeps[1]);
        self::assertLessThanOrEqual(1.2, $this->sleeps[1]);
    }

    public function testARetryAfterOverAMinuteFailsAtOnce(): void
    {
        try {
            $this->client(new TransportResponse(503, ['Retry-After' => '61'], ''), $this->categories())->listCategories();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->sleeps);
        $this->client(new TransportResponse(429, ['Retry-After' => '60'], ''), $this->categories())->listCategories();
        self::assertSame([60.0], $this->sleeps);
    }

    public function testMaxRetriesZeroTriesOnce(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, [], ''));
        try {
            (new Invoist('k', maxRetries: 0, transport: $transport))->listCategories();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(1, $transport->requests);
    }

    public function testTheBackoffScheduleHasJitterAndAnEightSecondCap(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $base = 0.5 * (2 ** $attempt);
            for ($i = 0; $i < 50; $i++) {
                $delay = Http::delay($attempt, null);
                self::assertGreaterThanOrEqual(min(8.0, $base * 0.8), $delay);
                self::assertLessThanOrEqual(min(8.0, $base * 1.2), $delay);
            }
        }
        self::assertSame(8.0, Http::delay(30, null));
    }

    public function testRetryAfterMustBeWholeSeconds(): void
    {
        self::assertSame(0.0, Http::delay(0, '0'));
        self::assertSame(120.0, Http::delay(0, ' 120 '));
        foreach (['1.5', '-1', 'Wed, 21 Oct 2026 07:28:00 GMT', ''] as $header) {
            $delay = Http::delay(0, $header);
            self::assertTrue($delay >= 0.4 && $delay <= 0.6, "Retry-After \"$header\" should fall back to the backoff");
        }
    }

    // waitForExtraction -----------------------------------------------------------

    public function testWaitForExtractionPollsUntilDone(): void
    {
        $noExtraction = Fixtures::documentDetail();
        unset($noExtraction['extraction']);
        $extraction = $this->client(
            FakeTransport::json(200, $noExtraction),
            FakeTransport::json(200, Fixtures::documentDetail('pending')),
            FakeTransport::json(200, Fixtures::documentDetail('done')),
        )->waitForExtraction('d1', pollInterval: 0.25);

        self::assertSame('done', $extraction->status);
        self::assertSame('Acme Oy', $extraction->vendor_name);
        self::assertCount(3, $this->transport->requests);
        self::assertSame([0.25, 0.25], $this->sleeps);
    }

    public function testWaitForExtractionReturnsAFailedReading(): void
    {
        $failed = Fixtures::documentDetail('failed');
        \assert(\is_array($failed['extraction']));
        $failed['extraction']['error_message'] = 'too blurry to read';
        $extraction = $this->client(FakeTransport::json(200, $failed))->waitForExtraction('d1');

        self::assertSame('failed', $extraction->status);
        self::assertSame('too blurry to read', $extraction->error_message);
    }

    public function testWaitForExtractionGivesUpAtTheTimeout(): void
    {
        $pending = FakeTransport::json(200, Fixtures::documentDetail('pending'));
        $invoist = $this->client(...array_fill(0, 10, $pending));
        try {
            $invoist->waitForExtraction('d1', timeout: 5, pollInterval: 2);
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame('extraction_timeout', $e->code);
            self::assertSame(0, $e->status);
        }
        // Polls at 0s, 2s, 4s and a last time at 5s.
        self::assertCount(4, $this->transport->requests);
        self::assertSame([2.0, 2.0, 1.0], $this->sleeps);
    }

    // PSR-18 ------------------------------------------------------------------------

    public function testAPsr18ClientCanSendTheRequests(): void
    {
        $client = new class () implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @var list<ResponseInterface|NetworkExceptionInterface> */
            public array $responses = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $next = array_shift($this->responses) ?? throw new \LogicException('no response queued');
                if ($next instanceof NetworkExceptionInterface) {
                    throw $next;
                }

                return $next;
            }
        };
        $failure = new class ('connection reset') extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('GET', 'https://example.test');
            }
        };
        $client->responses = [
            $failure,
            new Response(201, ['X-Request-Id' => 'req_1'], (string) json_encode(['document' => Fixtures::document(), 'extraction' => Fixtures::extraction('pending'), 'duplicate' => false])),
        ];
        $invoist = new Invoist('inv_sk_test', httpClient: $client, requestFactory: new Psr17Factory(), sleep: static function (float $_): void {
        });

        $result = $invoist->extract(Upload::fromString('%PDF', 'a.pdf'));

        self::assertSame(Fixtures::DOC_ID, $result->document->id);
        self::assertCount(2, $client->requests);
        $request = $client->requests[1];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.invoi.st/v1/extract', (string) $request->getUri());
        self::assertSame('Bearer inv_sk_test', $request->getHeaderLine('Authorization'));
        self::assertStringStartsWith('invoist-php/', $request->getHeaderLine('User-Agent'));
        $parts = Multipart::parse($request->getHeaderLine('Content-Type'), (string) $request->getBody());
        self::assertSame('%PDF', $parts[0]['bytes']);
    }

    public function testAPsr18ClientWithoutFactoriesIsRefused(): void
    {
        $client = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        new Invoist('k', httpClient: $client);
    }
}
