# FinFactory Payments for PHP

A PHP SDK for the [FinFactory Payments Merchant API](https://docs.payments.finfactory.dev/merchant/guides/overview/).
Take payments by card, QR Ph and cash, through hosted checkout, embedded checkout or your own
page; refund them, follow disputes, and verify webhooks.

> **v0.1: early release.** It covers the whole Merchant API v1 and is tested, but it has not
> yet been used in production. Method names and options may still change before 1.0; pin the
> version and read the [changelog](CHANGELOG.md) when you upgrade.

- **No Composer dependencies.** Only `ext-curl` and `ext-json`, which nearly every PHP install has.
- **PHP 8.1 or later.** Works in Laravel, Symfony, WordPress/WooCommerce and plain PHP.
- **IDE-friendly.** Typed services, and result classes with `@property` docs, status constants
  and helpers.
- **Safe by default.** Gets and caches Access Tokens, sends an `Idempotency-Key` on every write
  that takes one, and retries only what is safe to retry.

```sh
composer require domdanao/finfactory-payments
```

```php
use FinFactory\Payments\FinFactory;

$finfactory = new FinFactory([
    'client_id' => getenv('FINFACTORY_CLIENT_ID'),         // ci_test_...
    'client_secret' => getenv('FINFACTORY_CLIENT_SECRET'), // sk_test_...
]);

$intent = $finfactory->paymentIntents->create([
    'amount' => 10000, // ₱100.00, in centavos
    'currency' => 'php',
    'description' => 'Order 1042',
    'return_url' => 'https://shop.example.com/orders/1042/paid',
    'cancel_url' => 'https://shop.example.com/cart',
], ['idempotency_key' => 'order-1042-1']);

header('Location: ' . $intent->redirectUrl(), true, 303); // hosted checkout
```

---

## Contents

- [Requirements](#requirements)
- [Configuration](#configuration)
- [Authentication and Access Tokens](#authentication-and-access-tokens) — **read this if you run PHP-FPM**
- [Working with results](#working-with-results)
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
- [Errors](#errors)
- [Idempotency and retries](#idempotency-and-retries)
- [Pagination](#pagination)
- [Test mode](#test-mode)
- [Laravel](#laravel)
- [Symfony](#symfony)
- [Custom HTTP client, proxies and tests](#custom-http-client-proxies-and-tests)
- [Calling an endpoint the SDK does not have yet](#calling-an-endpoint-the-sdk-does-not-have-yet)
- [API reference](#api-reference)
- [Support](#support)

---

## Requirements

- PHP 8.1 or later (tested in CI on 8.1, 8.2, 8.3 and 8.4)
- `ext-curl` and `ext-json`
- Optional: `ext-apcu`, to share Access Tokens between requests with `ApcuTokenCache`

## Configuration

```php
$finfactory = new FinFactory([
    'client_id' => 'ci_test_...',
    'client_secret' => 'sk_test_...',
    'base_url' => 'https://api.payments.finfactory.dev', // your platform's API host
    'timeout' => 30,     // seconds, per attempt
    'max_retries' => 2,  // retries of a request that is safe to retry
]);
```

| Option | Default | |
| --- | --- | --- |
| `client_id` | `FINFACTORY_CLIENT_ID` env | `ci_test_...` or `ci_live_...` |
| `client_secret` | `FINFACTORY_CLIENT_SECRET` env | `sk_test_...` or `sk_live_...`. Keep it out of version control. |
| `access_token` | none | A token you manage yourself (string or callable), instead of the key. |
| `base_url` | `FINFACTORY_BASE_URL` env, then `https://api.payments.finfactory.dev` | Your platform's API host, if it gave you another. |
| `timeout` | `30` | Seconds per attempt. |
| `max_retries` | `2` | See [Idempotency and retries](#idempotency-and-retries). |
| `token_cache` | `InMemoryTokenCache` | Where Access Tokens are kept. See below. |
| `http_client` | `CurlHttpClient` | Any `FinFactory\Payments\Http\HttpClient`. |
| `default_headers` | `[]` | Headers sent on every request. |

Every method takes **request options** as its last argument:

```php
$finfactory->paymentIntents->retrieve('pi_...', ['timeout' => 5, 'max_retries' => 0, 'headers' => ['X-Trace' => 'abc']]);
```

## Authentication and Access Tokens

You give the SDK your client ID and secret; it exchanges them at `POST /oauth/token` for an
Access Token that lasts 15 minutes, sends only that token on `/v1` calls, and fetches a new one a
minute before it expires. If a token stops working early (the key was revoked or rotated), the
SDK gets a new one and sends the call again, once.

**Under PHP-FPM, Apache mod_php or any server where each request is a fresh PHP process, the
default in-memory cache lasts one request**, so every request would fetch a new token. The token
endpoint is rate limited. Share tokens across requests with a cache:

```php
use FinFactory\Payments\TokenCache\ApcuTokenCache;
use FinFactory\Payments\TokenCache\Psr16TokenCache;

// One server, with APCu:
$finfactory = new FinFactory(['token_cache' => new ApcuTokenCache()]);

// Any PSR-16 cache: Redis, Memcached, Laravel, Symfony...
$finfactory = new FinFactory(['token_cache' => new Psr16TokenCache($psr16Cache)]);
```

`Psr16TokenCache` takes any object with PSR-16 `get`, `set` and `delete` methods; the SDK does
not depend on `psr/simple-cache`. You can also implement `FinFactory\Payments\TokenCache\TokenCache`
yourself. An Access Token is a credential: keep the cache private to your servers.

Long-running workers (Laravel Octane, RoadRunner, Swoole, queue workers) keep one client, and its
token, for their whole life: build the client once and reuse it.

To get a token yourself, for another tool: `$finfactory->accessTokens->create()`.

## Working with results

Calls return objects you read as properties or as array keys. Nested objects are objects too:

```php
$intent = $finfactory->paymentIntents->retrieve('pi_...');

$intent->status;                              // 'requires_action'
$intent['status'];                            // the same
$intent->next_action->redirect_to_url->url;   // nested
$intent->metadata->order_id;                  // your metadata
$intent->some_field_added_later;              // null: unknown fields never throw

$intent->toArray();                           // plain nested array
json_encode($intent);                         // the API's JSON
$intent->getLastResponse()->requestId;        // X-Request-ID, for support
```

Result classes in `FinFactory\Payments\Resource` (`PaymentIntent`, `Refund`, `Dispute`,
`PaymentMethod`, `WebhookEndpoint`, `Event`) document every field for your IDE, and carry
constants and helpers:

```php
use FinFactory\Payments\Resource\PaymentIntent;

if ($intent->status === PaymentIntent::STATUS_SUCCEEDED) { /* ... */ }
$intent->isSucceeded();
$intent->redirectUrl();    // hosted checkout / card page URL, or null
$intent->clientToken();    // embedded checkout client token, or null
$intent->qrphData();       // QR Ph payload to show, or null
$intent->qrphExpiresAt();  // when the QR code stops being payable, or null
$page->hasMore();          // on a list page; and $page->nextCursor()
$event->dataObject();      // the PaymentIntent, Refund or Dispute a webhook event is about
```

The JavaScript SDK has the same helpers, as functions: `redirectUrl(intent)`.

Objects are read-only. Use `toArray()` for a copy you can change.

## Taking a payment

A **payment intent** is one payment to take. Its `status` moves through
`requires_payment_method` → `requires_confirmation` → `requires_action` → `processing` →
`succeeded`, or ends `canceled`. While it is `requires_action`, `next_action->type` says what the
customer is to do. A failed attempt does not end it: it goes back to `requires_payment_method`,
with `last_payment_error`, and can be confirmed again.

Amounts are integers in the currency's minor unit: `10000` in `php` is ₱100.00.

### Hosted checkout

```php
$intent = $finfactory->paymentIntents->create([
    'amount' => 10000,
    'currency' => 'php',
    'description' => 'Order 1042',
    'return_url' => 'https://shop.example.com/orders/1042/paid',
    'cancel_url' => 'https://shop.example.com/cart',
    'customer_email' => 'maria@example.com', // pre-fills the receipt field
    'metadata' => ['order_id' => '1042'],
], ['idempotency_key' => 'order-1042-1']);

header('Location: ' . $intent->redirectUrl(), true, 303);
exit;
```

The link is good for one checkout session (30 minutes by default) and is the customer's
credential for this payment: send it only to them. Webhooks never carry it; retrieve the intent
to get it again.

When the customer comes back to `return_url`, `payment_intent` and `checkout_session` are added
to the query string. **That proves nothing**: [check the outcome](#checking-the-outcome) before
you fulfil the order.

### Embedded checkout

The same checkout, inside your own page. On your server:

```php
$intent = $finfactory->paymentIntents->create([
    'amount' => 10000,
    'currency' => 'php',
    'checkout_mode' => 'embedded',
    'return_url' => 'https://shop.example.com/orders/1042/paid', // needed for card
], ['idempotency_key' => 'order-1042-1']);

$clientToken = $intent->clientToken(); // ct_...: store it with the order
```

In your page, with the platform's library from your checkout host and your publishable key:

```html
<script src="https://checkout.example.com/embedded-checkout.js"></script>
<div id="checkout"></div>
<script>
  EmbeddedCheckout("pk_test_...").mount("#checkout", {
    clientToken: "<?= htmlspecialchars($clientToken) ?>",
    onComplete: () => location.assign("/orders/1042/thanks"),
    onCancel: () => location.assign("/cart"),
  });
</script>
```

Register your page's origin in the Merchant Dashboard first. `onComplete` is not proof of payment:
check on your server.

### Your own payment page (API-only)

```php
use FinFactory\Payments\Resource\PaymentIntent;

$intent = $finfactory->paymentIntents->create(
    ['amount' => 10000, 'currency' => 'php'],
    ['idempotency_key' => 'order-1042-1'],
);
// $intent->payment_method_types: the methods that can take it now, e.g. ['card', 'qrph', 'cash']

$intent = $finfactory->paymentIntents->confirm(
    $intent->id,
    ['payment_method_data' => ['type' => 'qrph']],
    ['idempotency_key' => 'order-1042-confirm-1'],
);

switch ($intent->next_action?->type) {
    case PaymentIntent::NEXT_ACTION_QRPH_DISPLAY_QR_CODE:
        // Render as a QR code, with how long it has left.
        $qr = $intent->next_action->qrph_display_qr_code;
        render_qr($qr->data, $qr->expires_at);
        break;
    case PaymentIntent::NEXT_ACTION_CASH_DISPLAY_REFERENCE:
        $cash = $intent->next_action->cash_display_reference;
        show_reference($cash->reference, $cash->amount, $cash->expires_at, $cash->instructions);
        break;
    case PaymentIntent::NEXT_ACTION_REDIRECT_TO_URL:
        // Card: send the whole page to the card page. return_url is required for card.
        header('Location: ' . $intent->redirectUrl(), true, 303);
        break;
    default:
        // A next action this version does not know: ignore it.
}
```

Create and confirm in one call with `'confirm' => true` and `payment_method_data`. For card, add
`return_url`.

### Drawing the QR Ph code

The SDK gives you the payload (`$intent->qrphData()`). To draw it as a QR code with the QR Ph logo in
the centre, as the BSP asks of merchant codes, use the separate package
[`domdanao/finfactory-qrph`](https://github.com/domdanao/finfactory-payments/tree/main/qrph-php):

```sh
composer require domdanao/finfactory-qrph
```

```php
use FinFactory\QrPh\QrPh;

$svg = QrPh::svg($intent, ['logo' => $logo]); // null unless waiting for QR Ph
$expiresAt = $intent->qrphExpiresAt();        // for a countdown
```

It is separate so the SDK keeps no dependencies, and so it can draw any QR Ph payload, from
FinFactory or another provider, without the SDK. While the code is showing, check the intent's
status every few seconds (from your page, through your server) to move on once it is paid, and
fulfil from the `payment_intent.succeeded` webhook.

### Cash

Confirm with `['payment_method_data' => ['type' => 'cash']]`. The answer's
`next_action->cash_display_reference` has the `reference`, the exact `amount` and `currency`, when
it `expires_at` (48 hours by default) and `instructions` to show. Once paid at a collection
location the intent moves to `processing`, then `succeeded`. An unpaid reference that expires
fails the attempt (`payment_intent_payment_attempt_expired`); confirm again for a new one.

### Checking the outcome

```php
$intent = $finfactory->paymentIntents->retrieve($_GET['payment_intent']);

match ($intent->status) {
    PaymentIntent::STATUS_SUCCEEDED => fulfil($intent->metadata->order_id),
    PaymentIntent::STATUS_PROCESSING => show_pending(),          // paid; outcome not known yet
    PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD => offer_retry($intent->last_payment_error?->message),
    PaymentIntent::STATUS_CANCELED => show_canceled(),
    default => show_pending(),
};
```

### Cancelling

```php
$finfactory->paymentIntents->cancel(
    'pi_...',
    ['cancellation_reason' => 'requested_by_customer'], // optional
    ['idempotency_key' => 'order-1042-cancel'],
);
```

Not once it is `processing` or `succeeded` (`ConflictException`, code `intent_state`).

## Payment methods

```php
foreach ($finfactory->paymentMethods->list() as $method) {
    echo $method->method, ': ', $method->available ? 'available' : $method->unavailable_reason, PHP_EOL;
}
```

## Refunds

```php
$refund = $finfactory->refunds->create([
    'payment_intent' => 'pi_...',
    'amount' => 2500,                     // leave out to refund what remains
    'reason' => 'requested_by_customer',  // optional
], ['idempotency_key' => 'order-1042-refund-1']);

$refund->status; // Refund::STATUS_CREATED, _PROCESSING, _AWAITING_APPROVAL, _SUCCEEDED, _FAILED
```

- **Card:** all of it, or part of it. Part of a card payment cannot be refunded on the day it was
  made (`refund_not_yet_available`).
- **Cash:** the whole payment only; the refund stays `processing` until the operator records the
  cash handed back.
- **QR Ph:** not refundable through the API (`refund_not_possible` or
  `refund_not_supported_by_provider`).
- Fees are not returned by a refund.
- `refund_in_progress` (`InProgressException`): another refund of the same payment is processing.
  Wait for its `refund.succeeded` or `refund.failed`, then send the same request with the
  **same** idempotency key.

```php
$refund = $finfactory->refunds->retrieve('re_...');
$page = $finfactory->refunds->list(['payment_intent' => 'pi_...']);
```

## Disputes

Read-only: you respond to a dispute from the Merchant Dashboard.

```php
$open = $finfactory->disputes->list(['status' => 'open']);
$dispute = $finfactory->disputes->retrieve('dp_...');
$dispute->evidence_due_date; // '2026-11-20'
$dispute->debited;           // what came off your balance
```

## Webhooks

### Managing endpoints

```php
$endpoint = $finfactory->webhookEndpoints->create([
    'url' => 'https://shop.example.com/webhooks',
    'enabled_events' => ['payment_intent.succeeded', 'payment_intent.payment_failed', 'refund.failed'],
    // or ['*'] for every type, including ones added later
]);
save_secret($endpoint->secret); // whsec_...: shown only now

$finfactory->webhookEndpoints->update($endpoint->id, ['status' => 'enabled']);
$finfactory->webhookEndpoints->disable($endpoint->id);
$rotated = $finfactory->webhookEndpoints->rotateSecret($endpoint->id);
```

> Creating an endpoint takes no idempotency key, so the SDK **does not retry it** after a network
> failure or a 5xx: a second create would make a second endpoint. If a create fails that way,
> `list()` your endpoints; if it was made, `rotateSecret()` it to read a secret.

### Receiving and verifying events

Verify the `Webhook-Signature` header against the **raw body, before decoding it**:

```php
use FinFactory\Payments\Exception\SignatureVerificationException;
use FinFactory\Payments\Resource\Event;
use FinFactory\Payments\Webhook;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null;

try {
    $event = Webhook::constructEvent($payload, $signature, getenv('FINFACTORY_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

switch ($event->type) {
    case Event::PAYMENT_INTENT_SUCCEEDED:
        fulfil($event->data->object->id);   // a PaymentIntent
        break;
    case Event::REFUND_FAILED:
        alert($event->data->object->failure_reason); // a Refund
        break;
    case Event::DISPUTE_CREATED:
        open_case($event->data->object->payment_intent); // a Dispute
        break;
    default:
        // A type you did not handle, or one added later: ignore it.
}

http_response_code(200);
```

- `constructEvent` throws `SignatureVerificationException` if the signature does not match or its
  timestamp is more than 5 minutes off (the fourth argument changes that). `Webhook::verify`
  only checks; `Webhook::isValid` answers a boolean.
- Answer any 2xx within 10 seconds. Queue slow work.
- Deliveries arrive **at least once and in any order**: deduplicate on `$event->id`, and go by
  the object's `status`, not by the order events arrive in.
- `$event->dataObject()` returns the `PaymentIntent`, `Refund` or `Dispute`.
- In tests, sign a body with `Webhook::generateTestHeader($body, 'whsec_test')`.

**Rotating the secret.** For 24 hours after a rotation, deliveries carry a signature from each
secret. Pass both: `Webhook::constructEvent($payload, $signature, [$newSecret, $oldSecret])`.

## Errors

Every exception the SDK throws extends `FinFactory\Payments\Exception\FinFactoryException`. A
refusal from the API is an `ApiErrorException` subclass, one per error type:

| Exception | `getType()` | Status | Meaning |
| --- | --- | --- | --- |
| `ValidationException` | `validation_failure` | 400 | A parameter is missing or not valid. `getParam()` names it. |
| `AuthenticationException` | `authentication_failure` | 401 | No token, or one not valid. (The SDK refreshes expired tokens itself.) |
| `AuthorizationException` | `authorization_failure` | 403 | The key may not do this, e.g. `test_mode_only`. |
| `NotFoundException` | `not_found` | 404 | No such object for this key. |
| `ConflictException` | `conflict` | 409 | Not in a state that allows this, e.g. `intent_state`, `idempotency_key_reused`. |
| `InProgressException` | `timeout_or_in_progress` | 409 | A duplicate still running, or `refund_in_progress`. Retry later. |
| `PolicyRejectionException` | `policy_rejection` | 422 | Your account may not do this now: `getErrorCode()` says why. |
| `InvariantViolationException` | `invariant_violation` | 422 | E.g. `amount_exceeds_refundable`. |
| `RateLimitException` | `rate_limited` | 429 | Too many requests. `getRetryAfter()` is in seconds. |
| `DependencyFailureException` | `dependency_failure` | 503 | Failure on the platform's side. See below. |

Others: `OAuthException` (the token endpoint refused your key; `getError()` is `invalid_client`,
`invalid_request`, `unsupported_grant_type` or `rate_limited`), `ConnectionException` and
`TimeoutException` (no answer received), `SignatureVerificationException`.

```php
use FinFactory\Payments\Exception\ApiErrorException;
use FinFactory\Payments\Exception\PolicyRejectionException;

try {
    $finfactory->refunds->create(['payment_intent' => $id], ['idempotency_key' => "refund-{$id}"]);
} catch (PolicyRejectionException $e) {
    if ($e->getErrorCode() === 'refund_not_yet_available') {
        // Partial card refunds open tomorrow.
    }
} catch (ApiErrorException $e) {
    error_log(sprintf('%s/%s param=%s request=%s: %s', $e->getType(), $e->getErrorCode(), $e->getParam(), $e->getRequestId(), $e->getMessage()));
    throw $e;
}
```

Branch on the type or code. Never on the message: it is for the developer reading a log and may
be reworded. Quote `getRequestId()` when you contact support. (`getErrorCode()`, not
`getCode()`: PHP's own `getCode()` is the HTTP status here.)

## Idempotency and retries

Creating, confirming and cancelling a payment intent, simulating an outcome, and creating a
refund require an `Idempotency-Key`. Pass your own, unique to the operation:

```php
$finfactory->paymentIntents->create($params, ['idempotency_key' => "order-{$order->id}-attempt-{$order->attempt}"]);
```

If you leave it out, the SDK generates a random UUID. That makes the SDK's own retries safe, but
not a retry your code makes later (after a crash, or from a queue): only a key derived from your
own data does that. Keys are remembered for 7 days.

**What the SDK retries, `max_retries` times (default 2), with exponential backoff:**

| Failure | Reads | Keyed writes | Webhook endpoint update, disable, rotate | Webhook endpoint create |
| --- | --- | --- | --- | --- |
| Network error, timeout | ✓ | ✓ same key | ✓ | ✗ |
| 5xx (`dependency_failure`) | ✓ | ✓ same key | ✓ | ✗ |
| 429 `rate_limited` | ✓ honours `Retry-After` (up to 60 s) | ✓ | ✓ | ✓ |
| 409 `idempotency_key_in_use` | | ✓ | | |
| 401 `invalid_access_token` | ✓ once, with a new token | ✓ | ✓ | ✓ |
| anything else | ✗ | ✗ | ✗ | ✗ |

**When a write still fails with `DependencyFailureException` or `ConnectionException`, whether it
took effect is not known.** Retry it later with the **same** idempotency key, never a new one: a
new key is a new request, and can pay or refund twice. If that retry answers `ConflictException`
with code `intent_state`, retrieve the intent rather than send it again.

`$intent->getLastResponse()->idempotentReplayed` is `true` when the answer is a replay of an
earlier success with the same key.

## Pagination

`list()` returns one page, a `Collection` you can iterate and count:

```php
$page = $finfactory->paymentIntents->list(['limit' => 100]);
foreach ($page as $intent) { /* this page only */ }
count($page);
$page->hasMore();
$next = $finfactory->paymentIntents->list(['limit' => 100, 'cursor' => $page->nextCursor()]);
```

`all()` returns a generator that goes through every page for you:

```php
foreach ($finfactory->paymentIntents->all(['limit' => 100]) as $intent) {
    echo $intent->id, ' ', $intent->status, PHP_EOL;
}

$refunds = iterator_to_array($finfactory->refunds->all(['payment_intent' => 'pi_...']), false);
```

`list` and `all` exist on `paymentIntents`, `paymentMethods`, `refunds`, `disputes` and
`webhookEndpoints`.

## Test mode

Test keys (`ci_test_...`, `sk_test_...`) never move money. `$finfactory->isLivemode()` tells you
which kind of key the client has.

Give a test payment its outcome with the test helper:

```php
$intent = $finfactory->paymentIntents->create([
    'amount' => 10000,
    'currency' => 'php',
    'payment_method_data' => ['type' => 'qrph'],
    'confirm' => true,
], ['idempotency_key' => 'test-1']);

$paid = $finfactory->testHelpers->paymentIntents->simulate($intent->id, ['outcome' => 'paid']);
$paid->status; // 'succeeded'
```

| Method | Outcomes |
| --- | --- |
| QR Ph, cash | `paid`, `expired` |
| Card | `approved`, `declined` (with an optional `decline_reason`: `insufficient_funds`, `do_not_honor`, `expired_card`), `three_d_secure_passed`, `three_d_secure_failed`, `abandoned` |

A live key is refused with `AuthorizationException`, code `test_mode_only`. A test card payment
of **PHP 404.00** (`'amount' => 40400`) is charged back once it succeeds, opening a test dispute.
See [Testing your integration](https://docs.payments.finfactory.dev/merchant/guides/testing-your-integration/).

A runnable end-to-end example is in [`examples/quickstart.php`](examples/quickstart.php):

```sh
FINFACTORY_CLIENT_ID=ci_test_... FINFACTORY_CLIENT_SECRET=sk_test_... php examples/quickstart.php
```

## Laravel

Bind one client in a service provider, with Laravel's cache for tokens:

```php
// config/services.php
'finfactory' => [
    'client_id' => env('FINFACTORY_CLIENT_ID'),
    'client_secret' => env('FINFACTORY_CLIENT_SECRET'),
    'webhook_secret' => env('FINFACTORY_WEBHOOK_SECRET'),
],

// app/Providers/AppServiceProvider.php
use FinFactory\Payments\FinFactory;
use FinFactory\Payments\TokenCache\Psr16TokenCache;
use Illuminate\Support\Facades\Cache;

public function register(): void
{
    $this->app->singleton(FinFactory::class, fn () => new FinFactory([
        'client_id' => config('services.finfactory.client_id'),
        'client_secret' => config('services.finfactory.client_secret'),
        'token_cache' => new Psr16TokenCache(Cache::store()),
    ]));
}
```

A webhook route, excluded from CSRF protection:

```php
// routes/web.php (add 'webhooks/finfactory' to the CSRF exceptions in bootstrap/app.php)
use FinFactory\Payments\Exception\SignatureVerificationException;
use FinFactory\Payments\Webhook;
use Illuminate\Http\Request;

Route::post('/webhooks/finfactory', function (Request $request) {
    try {
        $event = Webhook::constructEvent(
            $request->getContent(),
            $request->header('Webhook-Signature'),
            config('services.finfactory.webhook_secret'),
        );
    } catch (SignatureVerificationException) {
        abort(400);
    }
    HandleFinFactoryEvent::dispatch($event->toArray()); // a queued job
    return response()->noContent();
});
```

## Symfony

```yaml
# config/services.yaml
services:
    FinFactory\Payments\TokenCache\Psr16TokenCache:
        arguments: ['@Symfony\Component\Cache\Psr16Cache']
    Symfony\Component\Cache\Psr16Cache:
        arguments: ['@cache.app']
    FinFactory\Payments\FinFactory:
        arguments:
            - client_id: '%env(FINFACTORY_CLIENT_ID)%'
              client_secret: '%env(FINFACTORY_CLIENT_SECRET)%'
              token_cache: '@FinFactory\Payments\TokenCache\Psr16TokenCache'
```

In a controller, `$request->getContent()` is the raw body and
`$request->headers->get('Webhook-Signature')` the signature.

## Custom HTTP client, proxies and tests

The SDK sends requests through a one-method interface, `FinFactory\Payments\Http\HttpClient`.

```php
use FinFactory\Payments\Http\CurlHttpClient;

// A proxy, or any other cURL option:
$finfactory = new FinFactory(['http_client' => new CurlHttpClient(curlOptions: [CURLOPT_PROXY => 'http://proxy:3128'])]);
```

Implement the interface to use Guzzle or a PSR-18 client, or to fake the API in your tests:

```php
use FinFactory\Payments\Http\HttpClient;
use FinFactory\Payments\Http\HttpResponse;

final class FakeApi implements HttpClient
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        return str_ends_with($url, '/oauth/token')
            ? new HttpResponse(200, [], '{"access_token":"at_test_x","token_type":"Bearer","expires_in":900}')
            : new HttpResponse(200, [], '{"id":"pi_1","object":"payment_intent","status":"succeeded"}');
    }
}
```

Throw `FinFactory\Payments\Exception\ConnectionException` from `send()` when no answer was
received, so retries work.

## Calling an endpoint the SDK does not have yet

The API adds resources inside `/v1`. Until the SDK has a method for one, call it with the same
authentication, retries and errors:

```php
$result = $finfactory->request('POST', '/v1/new_things', body: ['name' => 'x'], opts: ['idempotency_key' => 'new-thing-1'], idempotent: true);
```

## API reference

| Call | HTTP |
| --- | --- |
| `paymentIntents->create($params, $opts)` | `POST /v1/payment_intents` |
| `paymentIntents->retrieve($id, $opts)` | `GET /v1/payment_intents/{id}` |
| `paymentIntents->confirm($id, $params, $opts)` | `POST /v1/payment_intents/{id}/confirm` |
| `paymentIntents->cancel($id, $params, $opts)` | `POST /v1/payment_intents/{id}/cancel` |
| `paymentIntents->list($params, $opts)` / `->all()` | `GET /v1/payment_intents` |
| `paymentMethods->list($params, $opts)` / `->all()` | `GET /v1/payment_methods` |
| `paymentMethods->retrieve($id, $opts)` | `GET /v1/payment_methods/{id}` |
| `refunds->create($params, $opts)` | `POST /v1/refunds` |
| `refunds->retrieve($id, $opts)` | `GET /v1/refunds/{id}` |
| `refunds->list($params, $opts)` / `->all()` | `GET /v1/refunds` |
| `disputes->retrieve($id, $opts)` | `GET /v1/disputes/{id}` |
| `disputes->list($params, $opts)` / `->all()` | `GET /v1/disputes` |
| `webhookEndpoints->create($params, $opts)` | `POST /v1/webhook_endpoints` |
| `webhookEndpoints->list($params, $opts)` / `->all()` | `GET /v1/webhook_endpoints` |
| `webhookEndpoints->update($id, $params, $opts)` | `POST /v1/webhook_endpoints/{id}` |
| `webhookEndpoints->disable($id, $opts)` | `POST /v1/webhook_endpoints/{id}/disable` |
| `webhookEndpoints->rotateSecret($id, $opts)` | `POST /v1/webhook_endpoints/{id}/rotate_secret` |
| `testHelpers->paymentIntents->simulate($id, $params, $opts)` | `POST /v1/test_helpers/payment_intents/{id}/simulate` |
| `accessTokens->create($opts)` | `POST /oauth/token` |
| `Webhook::constructEvent / verify / isValid / generateTestHeader` | — |

Every parameter and field is described in the
[Merchant API reference](https://docs.payments.finfactory.dev/merchant/reference/).

## Support

- Guides and reference: <https://docs.payments.finfactory.dev/merchant/guides/overview/>
- Bugs in this SDK: open an issue on this repository.
- Questions about a payment: contact your platform operator, quoting the request ID.

## License

MIT. See [LICENSE](LICENSE).
