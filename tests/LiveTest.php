<?php

declare(strict_types=1);

namespace Invoist\Tests;

use Invoist\Generated\Bill;
use Invoist\Generated\Category;
use Invoist\Generated\DocumentListItem;
use Invoist\Invoist;
use Invoist\InvoistError;
use PHPUnit\Framework\TestCase;

/**
 * Calls a real API, read-only. Skipped unless INVOIST_API_KEY is set;
 * INVOIST_BASE_URL points it somewhere other than production.
 *
 *     INVOIST_API_KEY=inv_sk_... vendor/bin/phpunit --testsuite live
 *
 * Works with a business workspace's key and with a household's: whichever
 * side the key can't reach answers 403, which is checked too.
 */
final class LiveTest extends TestCase
{
    private const string MISSING_ID = '00000000-0000-0000-0000-000000000000';

    private Invoist $invoist;

    private string $baseUrl;

    protected function setUp(): void
    {
        $key = (string) getenv('INVOIST_API_KEY');
        if ($key === '') {
            self::markTestSkipped('set INVOIST_API_KEY to run the live tests');
        }
        $baseUrl = (string) getenv('INVOIST_BASE_URL');
        $this->baseUrl = $baseUrl !== '' ? $baseUrl : Invoist::DEFAULT_BASE_URL;
        $this->invoist = new Invoist($key, baseUrl: $this->baseUrl);
    }

    private function isHousehold(): bool
    {
        try {
            $this->invoist->heldEmails->list();

            return true;
        } catch (InvoistError $e) {
            self::assertTrue($e->isPermissionError(), "unexpected error: $e->code {$e->getMessage()}");

            return false;
        }
    }

    public function testReadsWhatTheKeyCanSee(): void
    {
        if ($this->isHousehold()) {
            self::assertContainsOnlyInstancesOf(Bill::class, $this->invoist->bills->list(limit: 5));
            self::assertGreaterThanOrEqual(0, $this->invoist->bills->summary()->unpaid);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $this->invoist->bills->stats()->month);
        } else {
            self::assertContainsOnlyInstancesOf(DocumentListItem::class, $this->invoist->listDocuments(limit: 5));
            self::assertContainsOnlyInstancesOf(Category::class, $this->invoist->listCategories());
            self::assertGreaterThanOrEqual(0, $this->invoist->getUsage()->total_documents);
        }
    }

    public function testTheOtherSideIsForbidden(): void
    {
        try {
            $this->isHousehold() ? $this->invoist->listCategories() : $this->invoist->bills->list(limit: 1);
            self::fail('expected a 403');
        } catch (InvoistError $e) {
            self::assertTrue($e->isPermissionError());
            self::assertNotNull($e->requestId);
        }
    }

    public function testAMissingResourceIsANotFound(): void
    {
        try {
            $this->isHousehold() ? $this->invoist->bills->get(self::MISSING_ID) : $this->invoist->getDocument(self::MISSING_ID);
            self::fail('expected a 404');
        } catch (InvoistError $e) {
            self::assertTrue($e->isNotFound(), "got $e->status $e->code");
        }
    }

    public function testABadKeyIsAnAuthError(): void
    {
        try {
            (new Invoist('inv_sk_not_a_real_key', baseUrl: $this->baseUrl, maxRetries: 0))->getUsage();
            self::fail('expected a 401');
        } catch (InvoistError $e) {
            self::assertTrue($e->isAuthError());
            self::assertSame(401, $e->status);
        }
    }
}
