# FinFactory Payments SDKs

SDKs for the [FinFactory Payments Merchant API](https://docs.payments.finfactory.dev/merchant/guides/overview/).

> **v0.1: early release.** Both SDKs cover the whole Merchant API v1 and are tested, but have not
> yet been used in production. Method names and options may still change before 1.0.

| Language | Package | Folder | Docs |
| --- | --- | --- | --- |
| JavaScript / TypeScript (Node.js, Deno, Bun, Workers) | `npm install @domdanao/finfactory-payments` | [`js/`](js/) | [js/README.md](js/README.md) |
| PHP 8.1+ | `composer require domdanao/finfactory-payments` | [`php/`](php/) | [php/README.md](php/README.md) |

Drawing QR Ph codes, with the QR Ph logo in the centre, is a separate package that works with
any QR Ph payload, with or without the SDK:

| Language | Package | Folder | Docs |
| --- | --- | --- | --- |
| JavaScript / TypeScript (also in the browser) | `npm install @domdanao/finfactory-qrph` | [`qrph-js/`](qrph-js/) | [qrph-js/README.md](qrph-js/README.md) |
| PHP 8.1+ | `composer require domdanao/finfactory-qrph` | [`qrph-php/`](qrph-php/) | [qrph-php/README.md](qrph-php/README.md) |

Both cover the whole Merchant API v1: payment intents (hosted checkout, embedded checkout,
API-only, QR Ph, card, cash), payment methods, refunds, disputes, webhook endpoints, test helpers
and Access Tokens, plus webhook signature verification. Neither has runtime dependencies.

They behave the same way, by design: Access Tokens are fetched and refreshed for you, every write
that takes an `Idempotency-Key` gets one (reused across retries), only safe calls are retried, and
every refusal is a typed error carrying `type`, `code`, `param` and `request_id`.

## In this repository

- [`js/`](js/), [`php/`](php/): the SDKs, each with tests, examples, README, CHANGELOG and LICENSE.
- [`qrph-js/`](qrph-js/), [`qrph-php/`](qrph-php/): the QR Ph drawing packages.
- [`sample-js/`](sample-js/), [`sample-php/`](sample-php/): the same small shop in each language,
  taking QR Ph on its own page.
- [`spec/conformance/`](spec/conformance): cases every SDK and every QR Ph package must pass.
- [`spec/merchant-openapi.json`](spec/merchant-openapi.json): the OpenAPI document the SDKs were
  built from. CI compares it with the published one.
- [`docs/sdk-design.md`](docs/sdk-design.md): the behaviour every FinFactory SDK follows, for
  the languages that come next.
- [`docs/publishing.md`](docs/publishing.md): how to release to npm and Packagist.

## Developing

```sh
cd js && npm install && npm test
cd php && composer install && vendor/bin/phpunit
cd qrph-js && npm install && npm test
cd qrph-php && composer install && vendor/bin/phpunit
```
