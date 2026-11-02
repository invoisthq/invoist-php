<?php

declare(strict_types=1);

namespace Invoist\Tests\Support;

/** Minimal API objects carrying every field the spec requires. */
final class Fixtures
{
    public const string DOC_ID = '01a19812-0000-7000-8000-0000000000aa';
    public const string TIME = '2026-11-01T10:40:00Z';

    /** @return array<string, mixed> */
    public static function document(string $status = 'pending'): array
    {
        return [
            'id' => self::DOC_ID,
            'source' => 'api',
            'original_filename' => 'invoice.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 1234,
            'status' => $status,
            'created_at' => self::TIME,
            'updated_at' => self::TIME,
        ];
    }

    /** @return array<string, mixed> */
    public static function extraction(string $status = 'done'): array
    {
        return [
            'id' => '01a19812-0000-7000-8000-0000000000ee',
            'document_id' => self::DOC_ID,
            'engine' => 'invoist-extraction-v1',
            'status' => $status,
            'vendor_name' => 'Acme Oy',
            'total_cents' => 48000,
            'currency' => 'EUR',
            'line_items' => [['description' => 'Widgets', 'quantity' => 2, 'line_total_cents' => 48000]],
            'confidence' => ['total_cents' => 0.98, 'vendor_name' => 1],
            'created_at' => self::TIME,
            'updated_at' => self::TIME,
        ];
    }

    /** @return array<string, mixed> */
    public static function documentDetail(string $extractionStatus = 'done'): array
    {
        return ['document' => self::document(), 'extraction' => self::extraction($extractionStatus), 'review_queue' => []];
    }

    /** @return array<string, mixed> */
    public static function listItem(): array
    {
        return self::document('done') + ['pending_review' => 0, 'flagged' => false, 'vendor_name' => 'Acme Oy', 'total_cents' => 48000];
    }

    /** @return array<string, mixed> */
    public static function billRecord(?string $paidAt = null): array
    {
        return ['paid_at' => $paidAt, 'paid_by' => null, 'assigned_to' => null];
    }

    /** @return array<string, mixed> */
    public static function bill(): array
    {
        return self::listItem() + [
            'due_date' => '2026-11-15',
            'bill' => self::billRecord(),
            'kind' => null,
            'related' => ['document_id' => null, 'link' => ''],
            'letter' => null,
            'logo' => 'northwind.example',
        ];
    }

    /** @return array<string, mixed> */
    public static function billDetail(): array
    {
        return ['document' => self::document('done'), 'extraction' => self::extraction(), 'review_queue' => [], 'bill' => self::billRecord()];
    }

    /** @return array<string, mixed> */
    public static function reviewItem(): array
    {
        return [
            'id' => '01a19812-0000-7000-8000-0000000000bb',
            'document_id' => self::DOC_ID,
            'extraction_id' => '01a19812-0000-7000-8000-0000000000ee',
            'reason' => 'low_confidence',
            'status' => 'resolved',
            'created_at' => self::TIME,
        ];
    }

    /** @return array<string, mixed> */
    public static function reviewListItem(): array
    {
        return self::reviewItem() + ['original_filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'document_status' => 'done'];
    }

    /** @return array<string, mixed> */
    public static function category(): array
    {
        return ['id' => '01a19812-0000-7000-8000-0000000000cc', 'name' => 'Software', 'is_default' => true, 'created_at' => self::TIME];
    }

    /** @return array<string, mixed> */
    public static function usage(): array
    {
        return [
            'period_start' => self::TIME,
            'total_calls' => 3,
            'total_documents' => 2,
            'total_cost_cents' => 40,
            'by_day' => [['day' => '2026-11-01', 'calls' => 3, 'documents' => 2]],
        ];
    }

    /** @return array<string, mixed> */
    public static function stats(): array
    {
        return ['documents_this_month' => 2, 'documents_total' => 9, 'pending_review_count' => 1, 'period_start' => self::TIME, 'plan' => 'growth'];
    }

    /** @return array<string, mixed> */
    public static function webhookEndpoint(): array
    {
        return [
            'id' => '01a19812-0000-7000-8000-0000000000dd',
            'url' => 'https://example.test/hook',
            'description' => '',
            'events' => ['*'],
            'enabled' => true,
            'consecutive_failures' => 0,
            'created_at' => self::TIME,
        ];
    }

    /** @return array<string, mixed> */
    public static function billsSummary(): array
    {
        return ['unpaid' => 2, 'overdue' => 1, 'due_soon' => [['currency' => 'EUR', 'count' => 2, 'total_cents' => 9900]], 'days' => 30, 'today' => '2026-11-01'];
    }

    /** @return array<string, mixed> */
    public static function billsStats(): array
    {
        return [
            'month' => '2026-11',
            'totals' => [['currency' => 'EUR', 'count' => 2, 'paid_count' => 1, 'total_cents' => 9900, 'paid_cents' => 4900]],
            'trend' => [['month' => '2026-11', 'currency' => 'EUR', 'count' => 2, 'total_cents' => 9900]],
            'top_senders' => [['name' => 'Northwind', 'count' => 1, 'currency' => 'EUR', 'total_cents' => 4900, 'logo' => null]],
            'senders' => [['name' => 'Northwind', 'count' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    public static function heldEmail(): array
    {
        return [
            'id' => '01a19812-0000-7000-8000-0000000000ff',
            'from_email' => 'billing@northwind.example',
            'subject' => 'Your bill',
            'status' => 'held',
            'created_at' => self::TIME,
            'sender' => 'verified',
            'files' => [['id' => '01a19812-0000-7000-8000-000000000011', 'filename' => 'bill.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 2048]],
        ];
    }
}
