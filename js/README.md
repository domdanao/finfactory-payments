# FinFactory Payments for JavaScript and TypeScript

A server-side SDK for the [FinFactory Payments Merchant API](https://docs.payments.finfactory.dev/merchant/guides/overview/).
Take payments by card, QR Ph and cash, through hosted checkout, embedded checkout or your own
page; refund them, follow disputes, and verify webhooks.

> **v0.1: early release.** It covers the whole Merchant API v1 and is tested, but it has not
> yet been used in production. Method names and options may still change before 1.0; pin the
> version and read the [changelog](CHANGELOG.md) when you upgrade.

- **No dependencies.** Built on `fetch` and Web Crypto.
- **Runs anywhere JavaScript runs on a server:** Node.js 20.19+, Deno, Bun, Cloudflare Workers,
  Vercel and Netlify functions, AWS Lambda.
- **Fully typed.** Written in TypeScript; every request, response, event and error is typed.
- **Safe by default.** Gets and refreshes Access Tokens for you, sends an `Idempotency-Key` on
  every write that takes one, and retries only what is safe to retry.

```sh
npm install @domdanao/finfactory-payments
```

```ts
import FinFactory from "@domdanao/finfactory-payments";

const finfactory = new FinFactory({
  clientId: process.env.FINFACTORY_CLIENT_ID,     // ci_test_...
  clientSecret: process.env.FINFACTORY_CLIENT_SECRET, // sk_test_...
});

const intent = await finfactory.paymentIntents.create(
  {
    amount: 10000, // ₱100.00, in centavos
    currency: "php",
    description: "Order 1042",
    return_url: "https://shop.example.com/orders/1042/paid",
    cancel_url: "https://shop.example.com/cart",
  },
  { idempotencyKey: "order-1042-1" },
);

if (intent.next_action?.type === "redirect_to_url") {
  // Send the customer to hosted checkout.
  redirect(intent.next_action.redirect_to_url.url);
}
```

---

## Contents

- [Requirements](#requirements)
- [Do I need a different package for Node.js, or for the browser?](#do-i-need-a-different-package-for-nodejs-or-for-the-browser)
- [Configuration](#configuration)
- [Authentication and Access Tokens](#authentication-and-access-tokens)
- [Taking a payment](#taking-a-payment)
  - [Hosted checkout](#hosted-checkout)
  - [Embedded checkout](#embedded-checkout)
  - [Your own payment page (API-only)](#your-own-payment-page-api-only)
  - [Drawing the QR Ph code](#drawing-the-qr-ph-code)
  - [Cash](#cash)
  - [Checking the outcome](#checking-the-outcome)
  - [Cancelling](#cancelling)
- [Payment methods](#payment-methods)
- [Refunds](#refunds)
- [Disputes](#disputes)
- [Webhooks](#webhooks)
  - [Managing endpoints](#managing-endpoints)
  - [Receiving and verifying events](#receiving-and-verifying-events)
  - [Framework recipes](#framework-recipes)
  - [Rotating the secret](#rotating-the-secret)
- [Errors](#errors)
- [Idempotency and retries](#idempotency-and-retries)
- [Pagination](#pagination)
- [Test mode](#test-mode)
- [Helpers](#helpers)
- [Response metadata](#response-metadata)
- [Calling an endpoint the SDK does not have yet](#calling-an-endpoint-the-sdk-does-not-have-yet)
- [TypeScript](#typescript)
- [API reference](#api-reference)
- [Support](#support)

---

## Requirements

| Runtime | Version |
| --- | --- |
| Node.js | 20.19 or later (22 LTS or 24 LTS recommended). Tested in CI on 20, 22 and 24. |
| Deno | 2.x, as `npm:@domdanao/finfactory-payments` |
| Bun | current releases |
| Cloudflare Workers, Vercel Edge, Netlify Edge | any runtime with `fetch` and `crypto.subtle` |

The package is ES modules only. `import` works everywhere; `require("@domdanao/finfactory-payments")`
works on Node.js 20.19+ and 22.12+, which load ES modules from CommonJS.

## Do I need a different package for Node.js, or for the browser?

**Node.js: no.** This one package is for every server-side JavaScript runtime. It uses only the
web-standard `fetch` and `crypto.subtle`, which Node.js, Deno, Bun and edge runtimes all have.

**The browser: you do not use this SDK there, and you should not.** The Merchant API is called
with your client secret, which must never reach a browser or a mobile app. The SDK refuses to run
in a browser with a secret. What a browser needs is already provided by the platform:

| Where | What to use |
| --- | --- |
| Your server | This SDK |
| Your web page, hosted checkout | Nothing: redirect to `next_action.redirect_to_url.url` |
| Your web page, embedded checkout | The platform's `embedded-checkout.js`, loaded from your checkout host, with your **publishable key** (`pk_...`) and the intent's **client token** (`ct_...`) |
| A mobile app | A WebView of your page, as for embedded checkout |

## Configuration

```ts
const finfactory = new FinFactory({
  clientId: "ci_test_...",
  clientSecret: "sk_test_...",
  baseUrl: "https://api.payments.finfactory.dev", // your platform's API host
  timeout: 30_000,  // ms, per attempt
  maxRetries: 2,    // retries of a request that is safe to retry
});
```

| Option | Default | |
| --- | --- | --- |
| `clientId` | `FINFACTORY_CLIENT_ID` env | `ci_test_...` or `ci_live_...` |
| `clientSecret` | `FINFACTORY_CLIENT_SECRET` env | `sk_test_...` or `sk_live_...`. Keep it in your secret store. |
| `accessToken` | none | A token you manage yourself (a string, or a function returning one), instead of `clientId`/`clientSecret`. |
| `baseUrl` | `FINFACTORY_BASE_URL` env, then `https://api.payments.finfactory.dev` | Your platform's API host, if it gave you another. |
| `timeout` | `30000` | Milliseconds per attempt. |
| `maxRetries` | `2` | See [Idempotency and retries](#idempotency-and-retries). |
| `tokenCache` | in memory | Share Access Tokens between processes. See below. |
| `fetch` | `globalThis.fetch` | A custom `fetch`, for a proxy or for tests. |
| `defaultHeaders` | `{}` | Headers sent on every request. |
| `dangerouslyAllowBrowser` | `false` | Lets the client be built in a browser. Only for test harnesses. |

With the environment variables set, `new FinFactory()` needs no arguments.

Every method takes **request options** as its last argument:

```ts
await finfactory.paymentIntents.retrieve("pi_...", {
  timeout: 5_000,
  maxRetries: 0,
  signal: AbortSignal.timeout(10_000),
  headers: { "X-Trace": "abc" },
});
```

## Authentication and Access Tokens

You give the SDK your client ID and secret; it exchanges them at `POST /oauth/token` for an
Access Token that lasts 15 minutes, sends only that token on `/v1` calls, and fetches a new one a
minute before it expires. Concurrent calls share one token request. If a token stops working early
(the key was revoked or rotated), the SDK gets a new one and sends the call again, once.

The token endpoint is rate limited, so a token should be shared as widely as it can be. In a
long-running server one client does that by itself: **create one client and reuse it.** Where
every invocation starts fresh (serverless functions), share tokens through a `tokenCache`:

```ts
import { createClient } from "redis";
const redis = await createClient().connect();

const finfactory = new FinFactory({
  tokenCache: {
    async get(key) {
      const value = await redis.get(key);
      return value ? JSON.parse(value) : null;
    },
    async set(key, token) {
      await redis.set(key, JSON.stringify(token), { PXAT: token.expiresAt });
    },
    async delete(key) {
      await redis.del(key);
    },
  },
});
```

An Access Token is a credential: keep the cache private to your servers.

To get a token yourself, for another tool: `await finfactory.accessTokens.create()`.

## Taking a payment

A **payment intent** is one payment to take. Its `status` moves through
`requires_payment_method` → `requires_confirmation` → `requires_action` → `processing` →
`succeeded`, or ends `canceled`. While it is `requires_action`, `next_action.type` says what the
customer is to do. A failed attempt does not end it: it goes back to `requires_payment_method`,
with `last_payment_error`, and can be confirmed again.

Amounts are integers in the currency's minor unit: `10000` in `php` is ₱100.00.

### Hosted checkout

Create the intent without a method. The customer chooses one on the platform's checkout page.

```ts
const intent = await finfactory.paymentIntents.create(
  {
    amount: 10000,
    currency: "php",
    description: "Order 1042",
    return_url: "https://shop.example.com/orders/1042/paid",
    cancel_url: "https://shop.example.com/cart",
    customer_email: "maria@example.com", // pre-fills the receipt field
    metadata: { order_id: "1042" },
  },
  { idempotencyKey: "order-1042-1" },
);

// next_action.type is "redirect_to_url"
res.redirect(303, intent.next_action.redirect_to_url.url);
```

The link is good for one checkout session (30 minutes by default) and is the customer's
credential for this payment: send it only to them. Webhooks never carry it; retrieve the intent
to get it again.

When the customer comes back to `return_url`, `payment_intent` and `checkout_session` are added
to it. **That proves nothing**: [check the outcome](#checking-the-outcome) before you fulfil the
order.

### Embedded checkout

The same checkout, inside your own page. On your server:

```ts
const intent = await finfactory.paymentIntents.create(
  {
    amount: 10000,
    currency: "php",
    checkout_mode: "embedded",
    return_url: "https://shop.example.com/orders/1042/paid", // needed for card
  },
  { idempotencyKey: "order-1042-1" },
);

if (intent.next_action?.type === "mount_embedded_checkout") {
  const clientToken = intent.next_action.mount_embedded_checkout.client_token;
  // Store it with the order, and give it to the page paying this order.
}
```

On your page (not this SDK: the platform's library, from your checkout host):

```html
<script src="https://checkout.example.com/embedded-checkout.js"></script>
<div id="checkout"></div>
<script>
  const checkout = EmbeddedCheckout("pk_test_...");
  checkout.mount("#checkout", {
    clientToken: "ct_...",
    onComplete: ({ payment_intent }) => location.assign("/orders/1042/thanks"),
    onCancel: ({ payment_intent }) => location.assign("/cart"),
  });
</script>
```

Register your page's origin in the Merchant Dashboard first. `onComplete` is not proof of payment
either: check on your server.

### Your own payment page (API-only)

Show the methods yourself, then confirm with the one the customer chose:

```ts
const intent = await finfactory.paymentIntents.create(
  { amount: 10000, currency: "php" },
  { idempotencyKey: "order-1042-1" },
);
// intent.payment_method_types: the methods that can take this payment now, e.g. ["card", "qrph", "cash"]

const confirmed = await finfactory.paymentIntents.confirm(
  intent.id,
  { payment_method_data: { type: "qrph" } },
  { idempotencyKey: "order-1042-confirm-1" },
);

switch (confirmed.next_action?.type) {
  case "qrph_display_qr_code":
    // Render as a QR code; show how long it has left.
    showQr(qrphData(confirmed), qrphExpiresAt(confirmed)); // or draw it: see below
    break;
  case "cash_display_reference":
    showCashReference(confirmed.next_action.cash_display_reference);
    break;
  case "redirect_to_url":
    // Card: send the whole page to the card page. return_url is required for card.
    redirect(confirmed.next_action.redirect_to_url.url);
    break;
  default:
    // A next action this version does not know. Ignore it, or fall back to hosted checkout.
}
```

Create and confirm in one call with `confirm: true` and `payment_method_data`. For card, add
`return_url` (to the create, or to the confirm).

### Drawing the QR Ph code

The SDK can draw the code for you as SVG, with the QR Ph logo in the middle. The BSP asks that
merchant (P2M) QR Ph codes show the logo there ([QR Ph Rebranding FAQ](https://www.bsp.gov.ph/Media_And_Research/Primers%20Faqs/FAQs-QR-Ph-Rebranding.pdf), item 4).
It lives in its own entry point, `@domdanao/finfactory-payments/qrph`, with one dependency the
rest of the SDK does not have:

```sh
npm install qrcode-generator
```

```ts
import { readFile } from "node:fs/promises";
import { qrphExpiresAt } from "@domdanao/finfactory-payments";
import { qrphSvg } from "@domdanao/finfactory-payments/qrph";

const logo = { data: await readFile("qrph-logo.png"), type: "image/png" };

const svg = qrphSvg(intent, { logo });     // null unless waiting for QR Ph
const expiresAt = qrphExpiresAt(intent);   // Unix timestamp, for a countdown

const html = `<div style="width: 280px">${svg}</div>`;
```

The SDK does not include the logo: it is the BSP's mark, so get the image from your acquirer or
the BSP. The modules under the logo are cleared and the code uses error correction level H, so
banking apps read it past the logo. Without a logo, the code is drawn whole.

| Option | Default | |
| --- | --- | --- |
| `logo` | none | A URL or `data:` URI, used as it is; or `{ data, type }` with `data` a `Uint8Array` or `ArrayBuffer`, embedded |
| `margin` | `4` | Light border around the code, in modules |
| `foreground`, `background` | `#000`, `#fff` | Colours. Keep the code dark on light |
| `title` | `QR Ph code` | Accessible name of the image |

The SVG scales to its container; show it at 250px or more across. `qrphSvg(payload, options)` draws a payload
you already have. While the code is showing, check the intent's status every few seconds (from
your page, through your server) to move on once it is paid, and fulfil from the
`payment_intent.succeeded` webhook. A runnable page in PHP is in
[`sample-php`](https://github.com/domdanao/finfactory-payments/tree/main/sample-php).

### Cash

Confirm with `{ payment_method_data: { type: "cash" } }`. The answer's
`next_action.cash_display_reference` has the `reference`, the exact `amount` and `currency`, when
it `expires_at` (48 hours by default) and `instructions` to show. Once paid at a collection
location the intent moves to `processing`, then `succeeded`. An unpaid reference that expires
fails the attempt (`payment_intent_payment_attempt_expired`); confirm again for a new one.

### Checking the outcome

Before you fulfil an order, read the intent, or wait for its webhook:

```ts
const intent = await finfactory.paymentIntents.retrieve("pi_...");

switch (intent.status) {
  case "succeeded":
    await fulfil(intent.metadata.order_id);
    break;
  case "processing":
    // Paid; outcome not known yet. Not a failure.
    break;
  case "requires_payment_method":
    // The attempt failed. Show intent.last_payment_error?.message and offer to try again.
    break;
  case "canceled":
    break;
}
```

### Cancelling

```ts
await finfactory.paymentIntents.cancel(
  "pi_...",
  { cancellation_reason: "requested_by_customer" }, // optional
  { idempotencyKey: "order-1042-cancel" },
);
```

Possible from `requires_payment_method`, `requires_confirmation` or `requires_action`; not once it
is `processing` or `succeeded` (`ConflictError`, code `intent_state`).

## Payment methods

What your account can take, and why a method cannot take a payment now:

```ts
const methods = await finfactory.paymentMethods.list();
for (const m of methods.data) {
  console.log(m.method, m.available ? "available" : m.unavailable_reason);
}
```

## Refunds

```ts
const refund = await finfactory.refunds.create(
  {
    payment_intent: "pi_...",
    amount: 2500,                    // leave out to refund what remains
    reason: "requested_by_customer", // optional
  },
  { idempotencyKey: "order-1042-refund-1" },
);

refund.status; // "created" | "processing" | "awaiting_approval" | "succeeded" | "failed"
```

- **Card:** all of it, or part of it. Part of a card payment cannot be refunded on the day it was
  made (`refund_not_yet_available`).
- **Cash:** the whole payment only; the refund stays `processing` until the operator records the
  cash handed back.
- **QR Ph:** not refundable through the API (`refund_not_possible` or
  `refund_not_supported_by_provider`).
- Fees are not returned by a refund.
- `refund_in_progress` (`InProgressError`): another refund of the same payment is processing.
  Wait for its `refund.succeeded` or `refund.failed`, then send the same request with the
  **same** idempotency key.

```ts
const one = await finfactory.refunds.retrieve("re_...");
const page = await finfactory.refunds.list({ payment_intent: "pi_..." });
```

## Disputes

Read-only: you respond to a dispute from the Merchant Dashboard.

```ts
const open = await finfactory.disputes.list({ status: "open" });
const dispute = await finfactory.disputes.retrieve("dp_...");
dispute.evidence_due_date; // "2026-11-20"
dispute.debited;           // what came off your balance
```

## Webhooks

### Managing endpoints

```ts
const endpoint = await finfactory.webhookEndpoints.create({
  url: "https://shop.example.com/webhooks",
  enabled_events: ["payment_intent.succeeded", "payment_intent.payment_failed", "refund.failed"],
  // or ["*"] for every type, including ones added later
});
await saveSecret(endpoint.secret); // whsec_...: shown only now

await finfactory.webhookEndpoints.update(endpoint.id, { status: "enabled" });
await finfactory.webhookEndpoints.disable(endpoint.id);
const rotated = await finfactory.webhookEndpoints.rotateSecret(endpoint.id);
```

> Creating an endpoint takes no idempotency key, so the SDK **does not retry it** after a network
> failure or a 5xx: a second create would make a second endpoint. If a create fails that way,
> `list()` your endpoints; if it was made, `rotateSecret()` it to read a secret.

### Receiving and verifying events

Each delivery is a `POST` with a `Webhook-Signature` header. Verify it against the **raw body,
before parsing it**: re-serialized JSON does not match.

```ts
import { Webhooks, WebhookSignatureError } from "@domdanao/finfactory-payments";

const event = await Webhooks.constructEvent(rawBody, signatureHeader, process.env.FINFACTORY_WEBHOOK_SECRET!);

switch (event.type) {
  case "payment_intent.succeeded":
    await fulfil(event.data.object.id); // event.data.object is a PaymentIntent
    break;
  case "payment_intent.payment_failed":
    await notifyFailed(event.data.object.last_payment_error);
    break;
  case "refund.failed":
    await alert(event.data.object.failure_reason); // a Refund
    break;
  case "dispute.created":
    await openCase(event.data.object.payment_intent); // a Dispute
    break;
  default:
    // A type you did not handle, or one added later. Ignore it.
}
```

- `constructEvent` throws `WebhookSignatureError` if the signature does not match or its
  timestamp is more than 5 minutes off (`{ tolerance: seconds }` to change). `Webhooks.verify`
  only checks; `Webhooks.isValid` answers a boolean.
- Answer any 2xx within 10 seconds. Do slow work after answering, or in a queue.
- Deliveries arrive **at least once and in any order**: deduplicate on `event.id`, and go by the
  object's `status`, not by the order events arrive in. When in doubt, retrieve the object.
- `finfactory.webhooks` is the same as `Webhooks`, if you prefer.

### Framework recipes

**Express.** Use `express.raw` on the webhook route only:

```ts
app.post("/webhooks", express.raw({ type: "application/json" }), async (req, res) => {
  let event;
  try {
    event = await Webhooks.constructEvent(req.body, req.get("webhook-signature"), secret);
  } catch (e) {
    return res.status(400).send("Bad signature");
  }
  res.sendStatus(200);
  await handle(event);
});
```

**Next.js (App Router), Remix, Hono, Cloudflare Workers, Deno, Bun** — anything with a web
`Request`:

```ts
export async function POST(request: Request) {
  const body = await request.text();
  try {
    const event = await Webhooks.constructEvent(body, request.headers.get("webhook-signature"), secret);
    await handle(event);
    return new Response(null, { status: 200 });
  } catch (e) {
    if (e instanceof WebhookSignatureError) return new Response("Bad signature", { status: 400 });
    throw e;
  }
}
```

**Fastify.** Keep the raw body for the route:

```ts
fastify.addContentTypeParser("application/json", { parseAs: "buffer" }, (req, body, done) => done(null, body));
fastify.post("/webhooks", async (req, reply) => {
  const event = await Webhooks.constructEvent(req.body as Buffer, req.headers["webhook-signature"] as string, secret);
  // ...
});
```

**Testing your handler.** Sign a body yourself:

```ts
const body = JSON.stringify(fakeEvent);
const header = await Webhooks.generateTestHeader(body, "whsec_test_secret");
```

### Rotating the secret

For 24 hours after a rotation, deliveries carry a signature from each secret. Pass both:

```ts
await Webhooks.constructEvent(body, header, [newSecret, oldSecret]);
```

## Errors

Every error the SDK throws extends `FinFactoryError`. A refusal from the API is an `ApiError`
subclass, one per error `type`:

| Class | `type` | Status | Meaning |
| --- | --- | --- | --- |
| `ValidationError` | `validation_failure` | 400 | A parameter is missing or not valid. `param` names it. |
| `AuthenticationError` | `authentication_failure` | 401 | No token, or one not valid. (The SDK refreshes expired tokens itself.) |
| `AuthorizationError` | `authorization_failure` | 403 | The key may not do this, e.g. `test_mode_only`. |
| `NotFoundError` | `not_found` | 404 | No such object for this key. |
| `ConflictError` | `conflict` | 409 | Not in a state that allows this, e.g. `intent_state`, `idempotency_key_reused`. |
| `InProgressError` | `timeout_or_in_progress` | 409 | A duplicate still running, or `refund_in_progress`. Retry later. |
| `PolicyRejectionError` | `policy_rejection` | 422 | Your account may not do this now: `code` says why. |
| `InvariantViolationError` | `invariant_violation` | 422 | E.g. `amount_exceeds_refundable`. |
| `RateLimitError` | `rate_limited` | 429 | Too many requests. `retryAfter` is in seconds. |
| `DependencyFailureError` | `dependency_failure` | 503 | Failure on the platform's side. See below. |

Other errors: `OAuthError` (the token endpoint refused your key: `error` is `invalid_client`,
`invalid_request`, `unsupported_grant_type` or `rate_limited`), `ConnectionError` and
`TimeoutError` (no answer received), `WebhookSignatureError`.

```ts
import { ApiError, PolicyRejectionError } from "@domdanao/finfactory-payments";

try {
  await finfactory.refunds.create({ payment_intent: id }, { idempotencyKey: `refund-${id}` });
} catch (e) {
  if (e instanceof PolicyRejectionError && e.code === "refund_not_yet_available") {
    // Partial card refunds open tomorrow.
  } else if (e instanceof ApiError) {
    log.error({ type: e.type, code: e.code, param: e.param, requestId: e.requestId }, e.message);
    throw e;
  } else {
    throw e;
  }
}
```

Branch on `type` or `code`. Never on `message`: it is for the developer reading a log and may be
reworded. Quote `requestId` when you contact support.

## Idempotency and retries

Creating, confirming and cancelling a payment intent, simulating an outcome, and creating a
refund require an `Idempotency-Key`. Pass your own, unique to the operation:

```ts
await finfactory.paymentIntents.create(params, { idempotencyKey: `order-${order.id}-attempt-${order.attempt}` });
```

If you leave it out, the SDK generates a random UUID. That makes the SDK's own retries safe, but
not a retry your code makes later (for example after a crash): only a key derived from your own
data does that. Keys are remembered for 7 days.

**What the SDK retries, `maxRetries` times (default 2), with exponential backoff:**

| Failure | Reads | Keyed writes | Webhook endpoint update, disable, rotate | Webhook endpoint create |
| --- | --- | --- | --- | --- |
| Network error, timeout | ✓ | ✓ same key | ✓ | ✗ |
| 5xx (`dependency_failure`) | ✓ | ✓ same key | ✓ | ✗ |
| 429 `rate_limited` | ✓ honours `Retry-After` (up to 60 s) | ✓ | ✓ | ✓ |
| 409 `idempotency_key_in_use` | | ✓ | | |
| 401 `invalid_access_token` | ✓ once, with a new token | ✓ | ✓ | ✓ |
| anything else | ✗ | ✗ | ✗ | ✗ |

**When a write still fails with `DependencyFailureError` or `ConnectionError`, whether it took
effect is not known.** Retry it later with the **same** idempotency key, never a new one: a new
key is a new request, and can pay or refund twice. If that retry answers `ConflictError` with code
`intent_state`, retrieve the intent rather than send it again.

`intent.lastResponse.idempotentReplayed` is `true` when the answer is a replay of an earlier
success with the same key.

## Pagination

Lists are paged by cursor. `list()` returns one page:

```ts
const page = await finfactory.paymentIntents.list({ limit: 100 });
page.data;        // PaymentIntent[]
page.has_more;    // boolean
page.next_cursor; // pass as `cursor` for the next page

const next = await finfactory.paymentIntents.list({ limit: 100, cursor: page.next_cursor! });
```

`all()` goes through every page for you:

```ts
for await (const intent of finfactory.paymentIntents.all({ limit: 100 })) {
  console.log(intent.id, intent.status);
}

// or, on Node.js 22+:
const refunds = await Array.fromAsync(finfactory.refunds.all({ payment_intent: "pi_..." }));
```

`list` and `all` exist on `paymentIntents`, `paymentMethods`, `refunds`, `disputes` and
`webhookEndpoints`.

## Test mode

Test keys (`ci_test_...`, `sk_test_...`) never move money. `finfactory.livemode` tells you which
kind of key the client has.

In test mode, give a payment its outcome with the test helper:

```ts
const intent = await finfactory.paymentIntents.create(
  { amount: 10000, currency: "php", payment_method_data: { type: "qrph" }, confirm: true },
  { idempotencyKey: "test-1" },
);

const paid = await finfactory.testHelpers.paymentIntents.simulate(intent.id, { outcome: "paid" });
paid.status; // "succeeded"
```

| Method | Outcomes |
| --- | --- |
| QR Ph, cash | `paid`, `expired` |
| Card | `approved`, `declined` (with an optional `decline_reason`: `insufficient_funds`, `do_not_honor`, `expired_card`), `three_d_secure_passed`, `three_d_secure_failed`, `abandoned` |

A live key is refused with `AuthorizationError`, code `test_mode_only`. A test card payment of
**PHP 404.00** (`amount: 40400`) is charged back once it succeeds, opening a test dispute. See
[Testing your integration](https://docs.payments.finfactory.dev/merchant/guides/testing-your-integration/).

A runnable end-to-end example is in [`examples/quickstart.mjs`](examples/quickstart.mjs):

```sh
FINFACTORY_CLIENT_ID=ci_test_... FINFACTORY_CLIENT_SECRET=sk_test_... node examples/quickstart.mjs
```

## Helpers

Results are plain objects, so the SDK gives you functions to read them. The PHP SDK has the same
ones, as methods (`$intent->redirectUrl()`):

```ts
import { redirectUrl, clientToken, qrphData, qrphExpiresAt, isSucceeded, hasMore, nextCursor, dataObject } from "@domdanao/finfactory-payments";

isSucceeded(intent);
redirectUrl(intent);    // hosted checkout / card page URL, or null
clientToken(intent);    // embedded checkout client token, or null
qrphData(intent);       // QR Ph payload to show, or null
qrphExpiresAt(intent);  // when the QR code stops being payable, or null
hasMore(page);          // on a list page; and nextCursor(page)
dataObject(event);      // the PaymentIntent, Refund or Dispute a webhook event is about
```

## Response metadata

Every object a call returns has a non-enumerable `lastResponse` (it does not appear in
`JSON.stringify` or `Object.keys`):

```ts
const intent = await finfactory.paymentIntents.retrieve("pi_...");
intent.lastResponse.requestId;          // X-Request-ID, for support
intent.lastResponse.statusCode;         // 200
intent.lastResponse.idempotentReplayed; // true on a replay
intent.lastResponse.idempotencyKey;     // the key sent, if any
intent.lastResponse.headers;            // Headers
```

## Calling an endpoint the SDK does not have yet

The API adds resources inside `/v1`. Until the SDK has a method for one, call it with the same
authentication, retries and errors:

```ts
const result = await finfactory.request<{ id: string }>(
  { method: "POST", path: "/v1/new_things", body: { name: "x" }, idempotent: true },
  { idempotencyKey: "new-thing-1" },
);
```

## TypeScript

Types are included. Useful ones:

```ts
import type {
  PaymentIntent, PaymentIntentCreateParams, NextAction,
  Refund, Dispute, PaymentMethod, WebhookEndpoint,
  WebhookEvent, KnownWebhookEventType, List, ResponseInfo,
} from "@domdanao/finfactory-payments";
```

String enums (`status`, `type`, `code`, ...) list the known values and still accept any string,
because the API adds values inside `v1`. Handle the values you know and ignore the rest.

## API reference

| Call | HTTP |
| --- | --- |
| `paymentIntents.create(params, options?)` | `POST /v1/payment_intents` |
| `paymentIntents.retrieve(id, options?)` | `GET /v1/payment_intents/{id}` |
| `paymentIntents.confirm(id, params?, options?)` | `POST /v1/payment_intents/{id}/confirm` |
| `paymentIntents.cancel(id, params?, options?)` | `POST /v1/payment_intents/{id}/cancel` |
| `paymentIntents.list(params?, options?)` / `.all()` | `GET /v1/payment_intents` |
| `paymentMethods.list(params?, options?)` / `.all()` | `GET /v1/payment_methods` |
| `paymentMethods.retrieve(id, options?)` | `GET /v1/payment_methods/{id}` |
| `refunds.create(params, options?)` | `POST /v1/refunds` |
| `refunds.retrieve(id, options?)` | `GET /v1/refunds/{id}` |
| `refunds.list(params?, options?)` / `.all()` | `GET /v1/refunds` |
| `disputes.retrieve(id, options?)` | `GET /v1/disputes/{id}` |
| `disputes.list(params?, options?)` / `.all()` | `GET /v1/disputes` |
| `webhookEndpoints.create(params, options?)` | `POST /v1/webhook_endpoints` |
| `webhookEndpoints.list(params?, options?)` / `.all()` | `GET /v1/webhook_endpoints` |
| `webhookEndpoints.update(id, params, options?)` | `POST /v1/webhook_endpoints/{id}` |
| `webhookEndpoints.disable(id, options?)` | `POST /v1/webhook_endpoints/{id}/disable` |
| `webhookEndpoints.rotateSecret(id, options?)` | `POST /v1/webhook_endpoints/{id}/rotate_secret` |
| `testHelpers.paymentIntents.simulate(id, params, options?)` | `POST /v1/test_helpers/payment_intents/{id}/simulate` |
| `accessTokens.create(options?)` | `POST /oauth/token` |
| `Webhooks.constructEvent / verify / isValid / generateTestHeader` | — |
| `qrphSvg(intentOrPayload, options?)` from `@domdanao/finfactory-payments/qrph` | — (draws locally) |
| `redirectUrl`, `clientToken`, `qrphData`, `qrphExpiresAt`, `isSucceeded`, `hasMore`, `nextCursor`, `dataObject` | — (read a result) |

Every parameter and field is described in the
[Merchant API reference](https://docs.payments.finfactory.dev/merchant/reference/).

## Support

- Guides and reference: <https://docs.payments.finfactory.dev/merchant/guides/overview/>
- Bugs in this SDK: open an issue on this repository.
- Questions about a payment: contact your platform operator, quoting the `requestId`.

## License

MIT. See [LICENSE](LICENSE).
