# Changelog

All notable changes to `invoist/invoist`. This project follows
[Semantic Versioning](https://semver.org).

## 1.0.1 - 2026-11-02

- No change to the API or to how the library behaves.
- The repository now holds its own copy of the API's OpenAPI spec in
  `testdata/openapi.yaml`. The models are generated from it, and the tests
  that compare the models with the spec run in this repository too.
- Test data uses made-up company names.

## [1.0.0] - 2026-11-01

The first stable release, following the shared Invoist SDK specification.

### Added

- Household methods: `$invoist->bills->list()`, `get()`, `getFile()`,
  `summary()`, `stats()`, `markPaid()`, `markUnpaid()`, and
  `$invoist->heldEmails->list()`.
- Webhook endpoints: `listWebhookEndpoints()`, `createWebhookEndpoint()`,
  `deleteWebhookEndpoint()`.
- Typed, read-only response classes in `Invoist\Generated`, generated from
  the API's OpenAPI description, with constants for its enums. Money is
  always an `int`.
- `Upload` for files from bytes, paths, streams and PSR-7 streams;
  `DownloadedFile` for downloads, with their content type.
- Any PSR-18 client can send the requests (`httpClient:`), as an
  alternative to the built-in curl transport.
- `Webhooks::verify()` returns a `WebhookEvent`, accepts any matching `v1`
  while a secret is rotated, and has constants for every event type and
  header.
- A `User-Agent` of `invoist-php/<version> (php <version>)`.

### Changed

- Requires PHP 8.3 or newer.
- Methods return objects instead of arrays.
- `resolveReviewQueueItem()` is now `resolveReviewItem()`.
- Retries wait the server's `Retry-After` (up to 60 s), otherwise back off
  with jitter, capped at 8 s.
- `InvoistError::$body` is the raw response body. A timeout has the code
  `timeout`.
- MIT licensed.
