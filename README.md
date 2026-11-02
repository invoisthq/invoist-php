# Invoist for PHP

The official PHP library for the [Invoist API](https://invoi.st/docs). Send it
an invoice, receipt or bill and get back its details as typed PHP objects:
who sent it, the amounts, the due date and each line. A household can list
its bills, see what is due and mark bills paid.

```bash
composer require invoist/invoist
```

You need PHP 8.3 or newer and the `curl` extension (most PHP installs have
it). No other packages are installed. You can also send requests through a
PSR-18 client, such as Guzzle or Symfony's (see [HTTP clients](#http-clients)).

## A business: read an invoice

```php
use Invoist\Invoist;
use Invoist\Upload;

$invoist = new Invoist(getenv('INVOIST_API_KEY'));

$result = $invoist->extractAndWait(Upload::fromPath('invoice.pdf'));
echo $result->extraction->vendor_name, ': ', $result->extraction->total_cents, ' ', $result->extraction->currency;
```

## A household: what is still to pay

```php
$invoist = new Invoist(getenv('INVOIST_API_KEY')); // a household's key, from Settings > AI assistants

foreach ($invoist->bills->list(paid: false) as $bill) {
    echo "$bill->vendor_name: $bill->total_cents $bill->currency, due $bill->due_date\n";
}
```

Amounts are always whole numbers of the smallest unit (cents): a
`total_cents` of `48000` in `EUR` is €480.00. Dates are `YYYY-MM-DD`
strings. Methods return read-only objects whose fields have the API's own
names (`total_cents`, `due_date`). Every object works with `json_encode()`,
which gives back the API's JSON.

Keep API keys on your server. Never put one in a browser or a mobile app.

## Business workspaces

```php
// Send a file: an Upload, a path (as SplFileInfo), an open stream, or raw bytes.
$sent = $invoist->extract(Upload::fromPath('receipt.jpg'));
$sent = $invoist->extract(Upload::fromString($bytes, 'March.pdf'), idempotencyKey: 'job-42');
$batch = $invoist->extractBatch([new SplFileInfo('a.pdf'), new SplFileInfo('b.pdf')]); // up to 10

// Reading happens in the background. Wait for it (or use the extraction.completed webhook):
$extraction = $invoist->waitForExtraction($sent->document->id, timeout: 120);
if ($extraction->status === 'failed') {
    echo 'Could not read it: ', $extraction->error_message; // a failed reading is an answer, not an exception
}

$documents = $invoist->listDocuments(status: 'done', flagged: true, limit: 20);
$detail    = $invoist->getDocument($id);           // document, extraction, review items
$file      = $invoist->getDocumentFile($id);       // ->bytes, ->contentType, ->saveTo($path)

$queue = $invoist->listReviewQueue(status: 'pending');
$invoist->resolveReviewItem($itemId, 'dismissed');

$categories = $invoist->listCategories();
$usage      = $invoist->getUsage();
$stats      = $invoist->getStats();

$endpoint = $invoist->createWebhookEndpoint('https://example.com/invoist', [Invoist\Webhooks::EXTRACTION_COMPLETED]);
// Store $endpoint->secret: it is shown only this once.
$invoist->listWebhookEndpoints()->endpoints;
$invoist->deleteWebhookEndpoint($endpoint->id);
```

Sending the same bytes twice is safe: Invoist recognises the file and returns
the first document (`duplicate` is true), with no extra charge. If you resend
a file that might differ slightly (for example, one exported again), pass an
`idempotencyKey`. The same key always returns the first response.

## Households (Invoist Personal)

These need a household's key or assistant (scopes `bills:read`, and
`bills:write` to mark bills paid).

```php
$bills   = $invoist->bills->list(month: new DateTimeImmutable(), sender: 'Northwind');
$bill    = $invoist->bills->get($id);              // everything read from it, warnings, letters
$file    = $invoist->bills->getFile($id);
$summary = $invoist->bills->summary(days: 14);     // unpaid, overdue, due soon per currency
$month   = $invoist->bills->stats(month: '2026-10'); // totals, twelve-month trend, top senders

$invoist->bills->markPaid($id);
$invoist->bills->markUnpaid($id);

$waiting = $invoist->heldEmails->list();           // forwarded bills waiting for approval
```

`month` and `today` take a string (`2026-10`, `2026-11-01`) or any
`DateTimeInterface`. A date object is read in its own time zone, so you get
the date it shows.

## Errors

Every failed call throws `Invoist\InvoistError`:

```php
use Invoist\InvoistError;

try {
    $invoist->bills->markPaid($id);
} catch (InvoistError $e) {
    if ($e->isPermissionError()) {
        // the household didn't allow marking bills paid
    }
    error_log("$e->code ($e->status), request $e->requestId: {$e->getMessage()}");
}
```

| Property | |
|---|---|
| `code` | A stable code to branch on: `invalid_api_key`, `insufficient_scope`, `not_found`, `rate_limited`, ... |
| `status` | The HTTP status, or `0` when no response arrived |
| `getMessage()` | Text for people. It may change, so don't branch on it |
| `requestId` | Quote it when you contact support |
| `body` | The raw response body |

There are also `isAuthError()` (401), `isPermissionError()` (403),
`isNotFound()` (404), `isRateLimited()` (429) and `isRetryable()`. The library
sets its own codes too: `network_error` and `timeout` (no response),
`unexpected_response` (the response wasn't what the API sends), and
`extraction_timeout` (`waitForExtraction` ran out of time). An empty API key
throws `InvalidArgumentException` before any request is made.

## Retries and timeouts

If a request gets no response, a 429 or a 5xx, the library tries again, up to
twice. It waits as long as the server's `Retry-After` header says. Without
that header it waits 0.5 s, then 1 s (±20%, never more than 8 s). If the
server asks for more than 60 s, the call fails at once rather than leaving
your program waiting. Other 4xx errors are never retried. Uploads are retried
too, because the API recognises a file it has already seen.

```php
$invoist = new Invoist($key, timeout: 30, maxRetries: 0); // seconds per attempt; no retries
```

## Webhooks

Check that each delivery really came from Invoist before you act on it:

```php
use Invoist\InvoistError;
use Invoist\Webhooks;

try {
    $event = Webhooks::verify(
        file_get_contents('php://input'),            // the raw body, exactly as received
        $_SERVER['HTTP_X_INVOIST_SIGNATURE'] ?? null,
        getenv('INVOIST_WEBHOOK_SECRET'),
    );
} catch (InvoistError $e) {
    http_response_code(400);
    exit;
}

if ($event->type === Webhooks::EXTRACTION_COMPLETED) {
    $document = $invoist->getDocument($event->data['document_id']);
}
http_response_code(204);
```

Pass the **raw** request body. If you decode the JSON and encode it again,
the bytes change and the signature no longer matches. The check fails with
`invalid_signature`, `signature_too_old` (the signature is more than 5
minutes old or ahead) or `invalid_payload`. Event types are constants on
`Webhooks`: `DOCUMENT_CREATED`, `EXTRACTION_COMPLETED`, `EXTRACTION_FAILED`,
`REVIEW_ITEM_CREATED`, `REVIEW_ITEM_RESOLVED` and
`DOCUMENT_REQUEST_FULFILLED`. Invoist may deliver an event more than once,
so use `$event->id` to skip events you have already handled.

In **Laravel**, `$request->getContent()` is the raw body:

```php
Route::post('/invoist/webhook', function (Illuminate\Http\Request $request) {
    try {
        $event = Webhooks::verify(
            $request->getContent(),
            $request->header('X-Invoist-Signature'),
            config('services.invoist.webhook_secret'),
        );
    } catch (InvoistError) {
        abort(400);
    }
    ProcessInvoistEvent::dispatch($event->type, $event->data);

    return response()->noContent();
});
```

Leave the route out of CSRF protection. In **Symfony**,
`$request->getContent()` works the same way.

## HTTP clients

By default, requests go through PHP's curl extension. To use a PSR-18
client, pass it with PSR-17 factories:

```php
$invoist = new Invoist($key, httpClient: new GuzzleHttp\Client(['timeout' => 30]), requestFactory: new GuzzleHttp\Psr7\HttpFactory());
$invoist = new Invoist($key, httpClient: new Symfony\Component\HttpClient\Psr18Client()); // it is its own factory
```

The PSR-18 client's own settings decide the timeout. For tests, pass any
`Invoist\Transport\Transport` as `transport:`. Pass a `sleep:` closure if you
don't want the library to block while it waits between retries.

## Links

- API documentation: <https://invoi.st/docs>
- Changes: [CHANGELOG.md](CHANGELOG.md)
- Security: [SECURITY.md](SECURITY.md)
- Issues: <https://github.com/invoisthq/invoist-php/issues>

## Development

```bash
composer install
composer check     # drift check, style (PHPCS), PHPStan at max level, tests
composer generate  # rebuild src/Generated from openapi.yaml (INVOIST_OPENAPI=/path/to/openapi.yaml)
INVOIST_API_KEY=inv_sk_... vendor/bin/phpunit --testsuite live   # read-only calls to a real API
```

`src/Generated` is built from the API's OpenAPI description by
`scripts/generate.php`. Don't edit it by hand.

MIT licensed.
