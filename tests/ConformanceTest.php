<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\Invoist;
use Invoist\InvoistError;
use Invoist\Tests\Support\FakeTransport;
use Invoist\Transport\NetworkException;
use Invoist\Transport\TransportResponse;
use Invoist\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every vector in testdata/conformance, the cases every Invoist SDK
 * must pass.
 */
final class ConformanceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function load(string $name): array
    {
        $json = file_get_contents(__DIR__ . "/../testdata/conformance/$name.json");
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    private static function cases(string $name): iterable
    {
        $cases = self::load($name)['cases'];
        self::assertIsArray($cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $name = $case['name'] ?? null;
            self::assertIsString($name);

            /** @var array<string, mixed> $case */
            yield $name => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function webhookCases(): iterable
    {
        return self::cases('webhooks');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function errorCases(): iterable
    {
        return self::cases('errors');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function retryCases(): iterable
    {
        return self::cases('retries');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('webhookCases')]
    public function testWebhookVector(array $case): void
    {
        $secret = self::load('webhooks')['secret'];
        self::assertIsString($secret);
        self::assertIsString($case['payload']);
        self::assertIsString($case['header']);
        self::assertIsInt($case['tolerance']);
        self::assertIsInt($case['now']);
        self::assertIsArray($case['expect']);
        $expect = $case['expect'];

        try {
            $event = Webhooks::verify($case['payload'], $case['header'], $secret, $case['tolerance'], $case['now']);
        } catch (InvoistError $e) {
            self::assertFalse($expect['ok'], "expected success, got {$e->code}: {$e->getMessage()}");
            self::assertSame(400, $e->status);
            self::assertSame($expect['code'], $e->code);

            return;
        }
        self::assertTrue($expect['ok'], 'expected the check to fail with ' . var_export($expect['code'] ?? null, true));
        self::assertSame($expect['type'], $event->type);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('errorCases')]
    public function testErrorVector(array $case): void
    {
        self::assertIsInt($case['status']);
        self::assertIsArray($case['headers']);
        self::assertIsString($case['body']);
        self::assertIsArray($case['expect']);
        $expect = $case['expect'];

        /** @var array<string, string> $headers */
        $headers = $case['headers'];
        $transport = new FakeTransport(new TransportResponse($case['status'], $headers, $case['body']));
        $invoist = new Invoist('inv_sk_test', maxRetries: 0, transport: $transport);
        try {
            $invoist->listCategories();
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame($case['status'], $e->status);
            self::assertSame($expect['code'], $e->code);
            self::assertSame($expect['message'], $e->getMessage());
            self::assertSame($expect['request_id'], $e->requestId);
            self::assertSame($case['body'] === '' ? null : $case['body'], $e->body);
            self::assertSame($expect['is_auth_error'], $e->isAuthError());
            self::assertSame($expect['is_permission_error'], $e->isPermissionError());
            self::assertSame($expect['is_not_found'], $e->isNotFound());
            self::assertSame($expect['is_rate_limited'], $e->isRateLimited());
            self::assertSame($expect['is_retryable'], $e->isRetryable());
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('retryCases')]
    public function testRetryVector(array $case): void
    {
        self::assertIsArray($case['responses']);
        self::assertIsArray($case['expect']);
        $expect = $case['expect'];

        $transport = new FakeTransport();
        foreach ($case['responses'] as $response) {
            self::assertIsArray($response);
            self::assertIsInt($response['status']);
            if ($response['status'] === 0) {
                $transport->push(new NetworkException('connection refused'));
                continue;
            }
            $headers = isset($response['retry_after']) && \is_string($response['retry_after']) ? ['Retry-After' => $response['retry_after']] : [];
            $transport->push($response['status'] < 300
                ? FakeTransport::json($response['status'], ['categories' => []], $headers)
                : new TransportResponse($response['status'], $headers, ''));
        }
        $waits = [];
        $invoist = new Invoist('inv_sk_test', maxRetries: 2, transport: $transport, sleep: static function (float $s) use (&$waits): void {
            $waits[] = $s;
        });

        try {
            $invoist->listCategories();
            self::assertTrue($expect['ok'], 'expected the call to fail');
        } catch (InvoistError $e) {
            self::assertFalse($expect['ok'], "expected success, got {$e->code}");
            self::assertSame($expect['status'], $e->status);
        }
        self::assertCount($this->int($expect['attempts']), $transport->requests);
        self::assertCount($this->int($expect['attempts']) - 1, $waits);

        if (isset($expect['waits_ms'])) {
            self::assertIsArray($expect['waits_ms']);
            foreach ($expect['waits_ms'] as $i => $ms) {
                self::assertIsInt($i);
                $waited = $waits[$i] * 1000;
                if ($ms === null) {
                    // The backoff schedule: 500ms x 2^attempt, ±20%.
                    $base = 500 * (2 ** $i);
                    self::assertGreaterThanOrEqual($base * 0.8 - 0.001, $waited);
                    self::assertLessThanOrEqual($base * 1.2 + 0.001, $waited);
                } else {
                    self::assertEqualsWithDelta($ms, $waited, 0.001);
                }
            }
        }
    }

    private function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }
}
