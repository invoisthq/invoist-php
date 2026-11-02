<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\InvoistError;
use Invoist\Webhooks;
use PHPUnit\Framework\TestCase;

/** What the conformance vectors don't cover. */
final class WebhooksTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    private static function sign(string $payload, int $t): string
    {
        return "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", self::SECRET);
    }

    public function testReturnsTheEvent(): void
    {
        $payload = '{"id":"ev_1","type":"extraction.completed","created_at":"2026-11-01T10:40:00Z","data":{"document_id":"d1","status":"done"},"new":1}';
        $event = Webhooks::verify($payload, self::sign($payload, 1_793_520_000), self::SECRET, now: 1_793_520_000);

        self::assertSame('ev_1', $event->id);
        self::assertSame(Webhooks::EXTRACTION_COMPLETED, $event->type);
        self::assertSame('2026-11-01T10:40:00Z', $event->created_at);
        self::assertSame(['document_id' => 'd1', 'status' => 'done'], $event->data);
    }

    public function testAMissingHeaderIsAnInvalidSignature(): void
    {
        try {
            Webhooks::verify('{}', null, self::SECRET);
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame('invalid_signature', $e->code);
            self::assertSame(400, $e->status);
        }
    }

    public function testASignedBodyThatIsNotAnEventIsAnInvalidPayload(): void
    {
        try {
            Webhooks::verify('[1,2]', self::sign('[1,2]', 100), self::SECRET, tolerance: 0);
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame('invalid_payload', $e->code);
            self::assertSame(400, $e->status);
        }
    }

    public function testTwoTimestampsAreRefused(): void
    {
        $payload = '{"id":"e","type":"t","created_at":"c","data":{}}';
        try {
            Webhooks::verify($payload, self::sign($payload, 100) . ',t=200', self::SECRET, tolerance: 0);
            self::fail('expected an InvoistError');
        } catch (InvoistError $e) {
            self::assertSame('invalid_signature', $e->code);
        }
    }

    public function testEveryEventTypeConstantVerifies(): void
    {
        foreach (Webhooks::EVENT_TYPES as $type) {
            $payload = (string) json_encode(['id' => 'e', 'type' => $type, 'created_at' => '2026-11-01T10:40:00Z', 'data' => []]);
            $event = Webhooks::verify($payload, self::sign($payload, 100), self::SECRET, tolerance: 0);

            self::assertSame($type, $event->type);
            self::assertSame([], $event->data);
        }
    }
}
