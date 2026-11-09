# SDK design contract

What every FinFactory Payments SDK does, whatever its language. The JavaScript and PHP SDKs
follow it; the next ones (Python, Go, Java, .NET, Ruby, ...) should too, so a merchant moving
between languages finds the same behaviour, and so one set of tests describes them all.

The source of truth is the Merchant API's OpenAPI document, snapshotted in
[`spec/merchant-openapi.json`](../spec/merchant-openapi.json), and its guides at
<https://docs.payments.finfactory.dev/merchant/guides/overview/>.

## Scope

- **In:** the Merchant API, for a merchant's server: `/oauth/token` and everything under `/v1`.
- **Out:** the Terminal API (a different audience, POS vendors, over mutual TLS), the browser
  side of embedded checkout (the platform serves `embedded-checkout.js` itself, and asks that it
  not be copied), and anything a key cannot do over the API (dispute evidence, key management).

## Principles

1. **No runtime dependencies** beyond the language's standard library, or the one HTTP extension
   everybody has (PHP's ext-curl). An SDK for payments is a supply-chain target: every dependency
   is one more thing to audit. Drawing QR Ph codes needs a QR encoder, so it lives in a separate
   package (see [The QR Ph package](#the-qr-ph-package)).
2. **Forward compatible.** The API adds fields, enum values, `next_action` types and event types
   inside `v1`. Never fail on an unknown field or value; expose unknown fields as they came.
3. **Thin.** The SDK sends what you give it and returns what the API answers. No client-side
   validation of values the API validates (it answers `validation_failure` with `param`).
4. **Safe by default.** Retries never risk doing a write twice.
5. **The same features in every language.** Every SDK has every call, option, helper and error in
   this document, with the same names in the language's own case. Where a language can't express
   something the same way, the difference is listed here. Shared cases in
   [`spec/conformance/`](../spec/conformance) run in every SDK's tests.

## Surface

One client object, built from options, with one service per resource:

| Service | Methods |
| --- | --- |
| `paymentIntents` | `create`, `retrieve`, `confirm`, `cancel`, `list`, `all` |
| `paymentMethods` | `list`, `all`, `retrieve` |
| `refunds` | `create`, `retrieve`, `list`, `all` |
| `disputes` | `retrieve`, `list`, `all` |
| `webhookEndpoints` | `create`, `list`, `all`, `update`, `disable`, `rotateSecret` |
| `testHelpers.paymentIntents` | `simulate` |
| `accessTokens` | `create` |
| `webhooks` (also standalone) | `verify`, `isValid`, `constructEvent`, `generateTestHeader` |

Plus a public `request(method, path, query, body, options)` escape hatch that applies the same
auth, idempotency, retries and errors, for endpoints the SDK does not have yet.

Method shape: `method(id?, params?, options?)`. Params use the API's own snake_case field names,
unchanged, so the API reference applies word for word. Options are per call: idempotency key,
timeout, max retries, extra headers (and cancellation where the language has it).

Names follow the language's conventions (`rotateSecret` / `rotate_secret`), but the resource and
method names stay the same across SDKs.

## Configuration

| Option | Default |
| --- | --- |
| client ID, secret | env `FINFACTORY_CLIENT_ID`, `FINFACTORY_CLIENT_SECRET` |
| access token (instead of ID/secret) | none |
| base URL | env `FINFACTORY_BASE_URL`, then `https://api.payments.finfactory.dev` (platforms may run their own host) |
| timeout | 30 s per attempt |
| max retries | 2 |
| token cache | in memory |
| HTTP transport | the language's standard client, replaceable |

The client throws at construction if it has neither an ID and secret nor a token. Where the
language can run in a browser, it refuses to be built there with a secret unless explicitly told.

## Authentication

- `POST /oauth/token`, form-encoded `grant_type=client_credentials`, client ID and secret as an
  HTTP Basic header. Never send both Basic and body credentials (`invalid_request`).
- Cache the token for `expires_in` seconds, refreshing when less than **60 s** remain.
- Concurrent callers share one in-flight token request, where the language has concurrency.
- Cache key: `finfactory:access_token:<base URL>:<client ID>`. Never put the secret in a key.
- The cache is pluggable (get, set, delete), because short-lived processes (PHP-FPM, serverless)
  would otherwise fetch a token per request, and the token endpoint is rate limited.
- A `/v1` answer of `401` with code `invalid_access_token` clears the cached token, gets a new one
  and resends the call **once**. Not when the user supplied a static token.
- Token endpoint errors are a distinct error class, with the OAuth `error` code.

## Idempotency

- The operations that take an `Idempotency-Key` header: create, confirm and cancel a payment
  intent; simulate a payment intent; create a refund.
- Use the caller's key when given. Otherwise generate a random UUID v4, and **reuse the same key on
  every retry** of that call. Expose the key used on the response metadata.
- Document that an auto-generated key protects only the SDK's own retries; a key derived from the
  merchant's data (order number and attempt) also protects their retries.
- Empty bodies on keyed writes are sent as `{}`.

## Retries

Up to `maxRetries` extra attempts. Backoff: `min(8 s, 0.5 s × 2^attempt)`, with jitter in the
upper half.

| Failure | Retry? |
| --- | --- |
| No answer (network error, timeout) | Yes, unless the operation is marked not retryable |
| `5xx` | Yes, unless the operation is marked not retryable |
| `429` | Always: nothing was done. Wait `Retry-After` seconds; if it asks for more than 60 s, give up and throw |
| `409` `idempotency_key_in_use` | Yes (keyed operations) |
| `409` `refund_in_progress` | **No**: it can take long; the merchant waits for the refund's webhook |
| Any other `4xx` | No |

**Not retryable:** `POST /v1/webhook_endpoints` (create). It takes no idempotency key; a second
create makes a second endpoint. Updating, disabling and rotating are safe to repeat.

## Errors

One class per API error `type`, all under a common API error class, all under one SDK base class:

| `type` | Status | Class |
| --- | --- | --- |
| `validation_failure` | 400 | Validation |
| `authentication_failure` | 401 | Authentication |
| `authorization_failure` | 403 | Authorization |
| `not_found` | 404 | NotFound |
| `conflict` | 409 | Conflict |
| `timeout_or_in_progress` | 409 | InProgress |
| `policy_rejection` | 422 | PolicyRejection |
| `invariant_violation` | 422 | InvariantViolation |
| `rate_limited` | 429 | RateLimit (with `retryAfter`) |
| `dependency_failure` | 503 | DependencyFailure |

Each carries: HTTP status, `type`, `code`, `message`, `param`, `request_id` (falling back to the
`X-Request-ID` header), response headers and the decoded body. An unknown `type` maps to the
generic API error class; a body without the envelope maps by status. Separate classes for: token
endpoint refusals (OAuth), no answer (Connection, with a Timeout subclass), webhook signatures.

## Responses

- Return the API's object with its own field names. Unknown fields are kept and readable.
- Attach response metadata without polluting the object's fields: status, headers, request ID,
  `Idempotent-Replayed`, idempotency key sent.
- Lists: `list()` returns one page (`data`, `has_more`, `next_cursor`); `all()` is a lazy
  iterator that follows `next_cursor` as `cursor` until `has_more` is false.

## Helpers

Small readers of what the API returns, so merchants needn't walk `next_action` themselves. Where
results are objects with methods (PHP), they are methods; where results are plain data (JS),
they are functions taking the result. Same names, same answers:

| Helper | On | Answer |
| --- | --- | --- |
| `redirectUrl` | payment intent | `next_action.redirect_to_url.url`, else null |
| `clientToken` | payment intent | `next_action.mount_embedded_checkout.client_token`, else null |
| `qrphData` | payment intent | `next_action.qrph_display_qr_code.data`, else null |
| `qrphExpiresAt` | payment intent | `next_action.qrph_display_qr_code.expires_at`, else null |
| `isSucceeded` | payment intent | `status` is `succeeded` |
| `hasMore`, `nextCursor` | list page | `has_more`, `next_cursor` |
| `dataObject` | webhook event | `data.object`, typed by the event's `type` |

Cases: [`spec/conformance/helpers.json`](../spec/conformance/helpers.json).

## The QR Ph package

Drawing a QR Ph code is not part of the SDKs. It is a separate package per language,
`finfactory-qrph` (npm `@domdanao/finfactory-qrph`, Composer `domdanao/finfactory-qrph`), so:

- the SDKs keep no dependencies, and merchants who only use hosted checkout install nothing extra;
- it works with any QR Ph payload, from FinFactory or any other provider, without the SDK;
- it holds no secret and makes no network calls, so the JavaScript one can also run in a browser.

It does not depend on the SDK. It reads a FinFactory payment intent by shape:
`next_action.qrph_display_qr_code.data` when `next_action.type` is `qrph_display_qr_code`.

`qrphSvg(intentOrPayload, options)` (PHP `QrPh::svg(...)`) returns an SVG string, or null for an
intent not waiting for a QR Ph payment. `logoModules(n)` is public.

- Encoder: the package's one dependency (PHP `chillerlan/php-qrcode` 5, JS `qrcode-generator`
  2), byte mode, error correction level **H**.
- Options: `logo` (a URL or `data:` URI used as the image's `href`, or `{data: bytes, type}`
  embedded as a `data:` URI), `margin` in modules (default 4), `foreground` (`#000`),
  `background` (`#fff`), `title` (`QR Ph code`). Attribute values are escaped.
- With a logo: clear an odd square of `logoModules(n)` modules in the centre,
  `max(7, round(n × 0.25))` made odd, and draw the logo one module inside it. The BSP asks that
  merchant (P2M) codes carry the QR Ph logo in the centre. The package doesn't ship the logo,
  which is the BSP's mark.
- Output: one `<path>` of dark-module runs on a background `<rect>`, `viewBox` in modules,
  `shape-rendering="crispEdges"`, `role="img"` with the title.
- Refuse an empty payload, a margin that isn't a whole number ≥ 0, and a malformed logo.

Every package's tests decode what it draws, with and without a logo, at a real QR Ph length,
and run the cases in [`spec/conformance/qrph.json`](../spec/conformance/qrph.json).

## Language differences

What one language has and another can't, or names differently. Nothing else may differ.

| Feature | JS | PHP |
| --- | --- | --- |
| Live or test key | `finfactory.livemode` (getter) | `$finfactory->isLivemode()` |
| Custom transport | `fetch` option | `http_client` option (an `HttpClient`) |
| Cancelling a call | `signal` per-call option | none: PHP has no cancellation |
| Refuse to run with a secret or an Access Token where users could read them | in a browser, or a React Native app (Expo included), unless `dangerouslyAllowBrowser` | not applicable: PHP doesn't run in browsers or apps |
| Shared token caches | the `TokenCache` interface | the interface, plus `ApcuTokenCache` and `Psr16TokenCache` for PHP's per-request processes |
| Webhook raw body | string, `Uint8Array` or `ArrayBuffer` | string (PHP strings are bytes) |
| Helpers | functions: `redirectUrl(intent)` | methods: `$intent->redirectUrl()` |

## Webhooks

- Header: `Webhook-Signature: t=<unix>,v1=<hex>[,v1=<hex>...]`.
- Signed payload: `<t>.<raw body bytes>`. HMAC-SHA256, keyed with the full secret string
  including its `whsec_` prefix, hex-encoded.
- Accept if **any** `v1` matches **any** of the given secrets (rotation: both secrets sign for 24
  hours), compared in constant time.
- Reject when `|now − t| > tolerance`; default 300 s; 0 turns the check off.
- Take the raw body only (string or bytes). Refuse a parsed object with a clear message.
- `constructEvent` verifies then parses; the event's `data.object` is typed by `type` where the
  language allows.
- **Conformance vector** (from the Webhooks guide; every SDK's tests must include it): secret
  `whsec_Kf3vQ9m2Xc7LpR1tYb8dNw5sUa0eHj4ZgTq6iVoMxCk`, `t=1790434852`, the guide's example
  body, expected `v1=ba1753e225757778aff55cb9a81cac1eb2a5f0724c32055eb8fb5e6285d54094`.

## Headers sent

`Authorization`, `Accept: application/json`, `Content-Type: application/json` when there is a
body, `Idempotency-Key` where applicable, and `User-Agent: finfactory-payments-<lang>/<version>`.

## Tests every SDK has

Against a fake transport, no network:

1. Token fetched with Basic auth, then Bearer on the call; token reused; shared cache honoured.
2. `invalid_access_token` → new token → one resend.
3. 503 on a keyed write → retried with the **same** auto-generated key.
4. Gives up after `maxRetries`, with the typed error and request ID.
5. Webhook endpoint create is **not** retried on 503.
6. 400 is not retried; `param` exposed.
7. 429 honours `Retry-After`; a long one is thrown.
8. `idempotency_key_in_use` retried; `intent_state` and `refund_in_progress` not.
9. Network failure retried, then a connection error.
10. Token endpoint refusal → OAuth error.
11. Pagination follows `next_cursor`.
12. Every operation's method and path.
13. Static token skips `/oauth/token`.
14. Webhook conformance vector; tampered body, wrong secret, old timestamp, malformed header;
    rotation with two secrets.
15. Every case in [`spec/conformance/helpers.json`](../spec/conformance/helpers.json).

## Versioning the SDKs

Semantic versioning, independent per language. `0.x` until the first production merchants have
used it; `1.0.0` then. Adding methods for new API endpoints is a minor release. A new API
version (`/v2`) is a new major SDK version.
