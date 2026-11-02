<?php

declare(strict_types=1);

namespace Invoist;

use Invoist\Generated\Bill;
use Invoist\Generated\BillDetail;
use Invoist\Generated\BillRecord;
use Invoist\Generated\BillsStats;
use Invoist\Generated\BillsSummary;
use Invoist\Generated\ListBillsResponse;
use Invoist\Generated\MarkBillPaidResponse;
use Invoist\Generated\UnmarkBillPaidResponse;
use Invoist\Internal\Http;

/**
 * A household's bills (Invoist Personal), as `$invoist->bills`.
 *
 * These answer only a household's key or assistant, which holds `bills:read`
 * (and `bills:write` when the household allowed marking bills paid). A
 * business workspace's key gets a 403 with the code `household_feature`.
 *
 * ```php
 * foreach ($invoist->bills->list(paid: false) as $bill) {
 *     echo "$bill->vendor_name owes $bill->total_cents $bill->currency by $bill->due_date\n";
 * }
 * ```
 */
final readonly class Bills
{
    /** @internal the client makes this; use $invoist->bills */
    public function __construct(private Http $http)
    {
    }

    /**
     * Lists the household's bills; unpaid ones come soonest-due first. A
     * reminder or collection letter is folded into its bill's row (see
     * `letter`) rather than listed on its own.
     *
     * @param bool|null $paid true for bills marked paid, false for bills still to pay, null for both
     * @param string|\DateTimeInterface|null $month only this month's bills (`YYYY-MM`), by due date; a date
     *   object gives its month in its own time zone, the calendar date it shows
     * @param string|null $sender only this sender's bills, ignoring case and spacing
     * @param string|null $q matches filename, sender, bill number or reference in every month
     * @param int|null $limit at most this many (the API defaults to 50, caps at 200)
     * @return list<Bill>
     * @throws InvoistError
     */
    public function list(
        ?bool $paid = null,
        string|\DateTimeInterface|null $month = null,
        ?string $sender = null,
        ?string $q = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        return $this->http->json('GET', '/v1/bills', ListBillsResponse::fromArray(...), [
            'paid' => $paid,
            'month' => $month instanceof \DateTimeInterface ? $month->format('Y-m') : $month,
            'sender' => $sender,
            'q' => $q,
            'limit' => $limit,
            'offset' => $offset,
        ])->bills;
    }

    /**
     * One bill with everything read from it, its open warnings, the
     * household's record (paid, by whom, who pays) and its letters.
     *
     * @throws InvoistError
     */
    public function get(string $billId): BillDetail
    {
        return $this->http->json('GET', '/v1/bills/' . rawurlencode($billId), BillDetail::fromArray(...));
    }

    /**
     * Downloads a bill's original file, byte for byte.
     *
     * @throws InvoistError
     */
    public function getFile(string $billId): DownloadedFile
    {
        return $this->http->download('/v1/bills/' . rawurlencode($billId) . '/file');
    }

    /**
     * What there is to pay: how many bills are unpaid and overdue, and what
     * falls due in the next $days days, per currency.
     *
     * @param string|\DateTimeInterface|null $today the household's own date (`YYYY-MM-DD`), a date object in its own
     *   time zone; defaults to the server's
     * @param int|null $days how far ahead "due soon" reaches (1 to 366; the API defaults to 30)
     * @throws InvoistError
     */
    public function summary(string|\DateTimeInterface|null $today = null, ?int $days = null): BillsSummary
    {
        return $this->http->json('GET', '/v1/bills/summary', BillsSummary::fromArray(...), [
            'today' => $today instanceof \DateTimeInterface ? $today->format('Y-m-d') : $today,
            'days' => $days,
        ]);
    }

    /**
     * A month at a glance: its totals (billed and paid, per currency), the
     * twelve months up to it, the biggest senders, and every sender.
     *
     * @param string|\DateTimeInterface|null $month the month (`YYYY-MM`, a date object in its own time zone);
     *   defaults to the current one
     * @param string|null $sender narrow the totals and the trend to one sender
     * @throws InvoistError
     */
    public function stats(string|\DateTimeInterface|null $month = null, ?string $sender = null): BillsStats
    {
        return $this->http->json('GET', '/v1/bills/stats', BillsStats::fromArray(...), [
            'month' => $month instanceof \DateTimeInterface ? $month->format('Y-m') : $month,
            'sender' => $sender,
        ]);
    }

    /**
     * Marks a bill, and its letters, paid by the person who made the key.
     * Needs `bills:write`.
     *
     * @throws InvoistError
     */
    public function markPaid(string $billId): BillRecord
    {
        return $this->http->json('POST', '/v1/bills/' . rawurlencode($billId) . '/paid', MarkBillPaidResponse::fromArray(...))
            ->bill;
    }

    /**
     * Marks a bill as not paid. Needs `bills:write`.
     *
     * @throws InvoistError
     */
    public function markUnpaid(string $billId): BillRecord
    {
        return $this->http->json('DELETE', '/v1/bills/' . rawurlencode($billId) . '/paid', UnmarkBillPaidResponse::fromArray(...))
            ->bill;
    }
}
