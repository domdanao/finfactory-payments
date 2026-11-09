# Changelog

All notable changes to `@domdanao/finfactory-payments` are recorded here. The project follows
[Semantic Versioning](https://semver.org/).

## 0.1.0 — unreleased

First release, for Merchant API `v1`.

- Payment intents: create, retrieve, confirm, cancel, list, auto-paginate.
- Payment methods: list, retrieve.
- Refunds: create, retrieve, list.
- Disputes: retrieve, list.
- Webhook endpoints: create, list, update, disable, rotate secret.
- Test helpers: simulate a payment intent's outcome.
- Access Tokens fetched, cached (pluggable `tokenCache`) and refreshed automatically.
- Automatic `Idempotency-Key`, reused across retries; safe retries with backoff and `Retry-After`.
- Typed errors for every error type; `OAuthError`, `ConnectionError`, `TimeoutError`.
- Webhook signature verification with Web Crypto, including secret rotation.
- Zero runtime dependencies; Node.js 20.19+, Deno, Bun, Cloudflare Workers.
