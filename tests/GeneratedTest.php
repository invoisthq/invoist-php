<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\Generated\Document;
use Invoist\Generated\DocumentStatus;
use Invoist\Generated\Extraction;
use Invoist\Generated\WebhookEndpointWithSecret;
use Invoist\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** The classes generated from openapi.yaml. */
final class GeneratedTest extends TestCase
{
    public function testTheGeneratedCodeMatchesTheSpec(): void
    {
        $root = \dirname(__DIR__);
        $env = getenv('INVOIST_OPENAPI');
        $spec = \is_string($env) && $env !== '' ? $env : 'testdata/openapi.yaml';
        if (!str_starts_with($spec, '/')) {
            $spec = "$root/$spec";
        }
        if (!is_file($spec)) {
            self::markTestSkipped("no OpenAPI spec at $spec");
        }
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/scripts/generate.php") . ' --check 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    public function testUnknownFieldsAreIgnoredAndOptionalOnesAreNull(): void
    {
        $extraction = Extraction::fromArray([
            'id' => 'e1',
            'document_id' => 'd1',
            'engine' => 'x',
            'status' => 'pending',
            'created_at' => Fixtures::TIME,
            'updated_at' => Fixtures::TIME,
            'something_new' => ['nested' => true],
        ]);

        self::assertNull($extraction->total_cents);
        self::assertNull($extraction->line_items);
        self::assertNull($extraction->quality);
    }

    public function testAWrongTypeNamesTheField(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Extraction.total_cents should be an integer, got string');
        Extraction::fromArray(['total_cents' => '48000'] + Fixtures::extraction());
    }

    public function testAllOfSchemasCarryEveryPart(): void
    {
        $endpoint = WebhookEndpointWithSecret::fromArray(Fixtures::webhookEndpoint() + ['secret' => 'whsec_1', 'last_status' => 200]);

        self::assertSame('whsec_1', $endpoint->secret);
        self::assertSame('https://example.test/hook', $endpoint->url);
        self::assertSame(['*'], $endpoint->events);
        self::assertSame(200, $endpoint->last_status);
    }

    public function testEnumsAreConstants(): void
    {
        $document = Document::fromArray(Fixtures::document('done'));

        self::assertSame(DocumentStatus::DONE, $document->status);
        self::assertContains($document->status, DocumentStatus::ALL);
    }
}
