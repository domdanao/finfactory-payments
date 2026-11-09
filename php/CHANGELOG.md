# Changelog

All notable changes to `domdanao/finfactory-payments` are recorded here. The project follows
[Semantic Versioning](https://semver.org/).

## 0.1.1 — 2026-11-09

First public release, for Merchant API `v1`. (`v0.1.0` was an earlier private tag on the
mirror, before the helpers below.) An early release: the API of the SDK may change before 1.0.

- Payment intents: create, retrieve, confirm, cancel, list, auto-paginate.
- Payment methods: list, retrieve.
- Refunds: create, retrieve, list.
- Disputes: retrieve, list.
- Webhook endpoints: create, list, update, disable, rotate secret.
- Test helpers: simulate a payment intent's outcome.
- Access Tokens fetched, cached (pluggable `token_cache`: APCu, PSR-16) and refreshed automatically.
- Automatic `Idempotency-Key`, reused across retries; safe retries with backoff and `Retry-After`.
- Typed exceptions for every error type; `OAuthException`, `ConnectionException`, `TimeoutException`.
- Webhook signature verification including secret rotation.
- Helpers: `PaymentIntent::redirectUrl()`, `clientToken()`, `qrphData()`, `qrphExpiresAt()`,
  `isSucceeded()`; `Collection::hasMore()`, `nextCursor()`; `Event::dataObject()`. The same as the
  JavaScript SDK's, which pass the same shared cases.
- Drawing QR Ph codes is in the separate package `domdanao/finfactory-qrph`.
- No Composer dependencies; PHP 8.1+ with ext-curl.
