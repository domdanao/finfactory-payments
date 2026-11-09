<?php

// Acacia 15 SVC: a one-page site that takes payment by QR Ph on its own page, through the
// FinFactory API. (FinFactory's hosted checkout is still reachable at /pay, for other methods.)
//
//   cp .env.example .env    # then fill in a test key
//   composer install
//   composer serve          # http://acacia.localhost:8000
//
// One front controller for every page, so it runs the same under `php -S`, Apache or nginx.

declare(strict_types=1);

use FinFactory\Payments\Exception\ApiErrorException;
use FinFactory\Payments\Exception\FinFactoryException;
use FinFactory\Payments\FinFactory;
use FinFactory\Payments\Resource\PaymentIntent;
use FinFactory\Payments\TokenCache\ApcuTokenCache;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/FileTokenCache.php';

const PRODUCT_NAME = 'Acacia 15 SVC';
const PRODUCT_AMOUNT = 2500; // in centavos: ₱25.00
const PRODUCT_CURRENCY = 'php';

loadEnv(__DIR__ . '/../' . basename(getenv('ENV_FILE') ?: '.env')); // ENV_FILE=.env.live picks another file
$publicUrl = rtrim(getenv('PUBLIC_URL') ?: 'http://acacia.localhost:8000', '/');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // Reads FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET from the environment. Each PHP
    // request starts from nothing, and the QR Ph page polls every few seconds, so keep the
    // Access Token between requests: in APCu when it's installed, otherwise in a file.
    $finfactory = fn (): FinFactory => new FinFactory(['token_cache' => \function_exists('apcu_fetch') && apcu_enabled()
        ? new ApcuTokenCache()
        : new FileTokenCache()]);

    match (true) {
        $method === 'GET' && $path === '/' => home(),
        $method === 'POST' && $path === '/qrph' => startQrph($finfactory()),
        $method === 'GET' && $path === '/qrph' => showQrph($finfactory()),
        $method === 'GET' && $path === '/qrph/status' => qrphStatus($finfactory()),
        $method === 'POST' && $path === '/qrph/simulate' => simulateQrph($finfactory()),
        $method === 'GET' && $path === '/pay' => pay($finfactory(), $publicUrl),
        $method === 'GET' && $path === '/return' => paymentReturn($finfactory()),
        default => render(404, 'Not found', '<p>There is nothing here. <a href="/">Back to ' . esc(PRODUCT_NAME) . '</a></p>'),
    };
} catch (FinFactoryException $e) {
    error_log((string) $e);
    $detail = $e instanceof ApiErrorException
        ? $e->getType() . ': ' . $e->getMessage() . ' (request ' . ($e->getRequestId() ?? 'n/a') . ')'
        : $e->getMessage();
    if ($path === '/qrph/status') {
        json(502, ['error' => $detail]);
    } else {
        render(502, 'Something went wrong', '<p>We couldn\'t start the payment.</p><p class="muted">' . esc($detail) . '</p><p><a href="/">Back</a></p>');
    }
}

// The product page. Paying is a POST, so link prefetchers and crawlers can't create payments.
function home(): void
{
    $canceled = isset($_GET['canceled']) ? '<p class="notice">Payment canceled. You weren\'t charged.</p>' : '';
    render(200, PRODUCT_NAME, $canceled . '
    <div class="card">
      <h1>' . esc(PRODUCT_NAME) . '</h1>
      <p class="price">' . peso(PRODUCT_AMOUNT) . '</p>
      ' . payForm('Pay ' . peso(PRODUCT_AMOUNT) . ' with QR Ph') . '
      <p class="muted small"><a href="/pay">Other ways to pay</a></p>
    </div>');
}

// A form that starts a QR Ph payment. The random attempt id becomes the idempotency key, so a
// double click or a resubmitted form gives back the same payment instead of a second one.
function payForm(string $label): string
{
    return '<form method="post" action="/qrph">
        <input type="hidden" name="attempt" value="' . bin2hex(random_bytes(16)) . '">
        <button class="button" type="submit">' . esc($label) . '</button>
      </form>';
}

// Creates the payment intent with QR Ph as its method and confirms it in the same call. The
// answer's next_action carries the QR payload. Then redirect, so reloading the QR page doesn't
// post the form again.
function startQrph(FinFactory $finfactory): void
{
    $attempt = (string) ($_POST['attempt'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $attempt)) {
        redirect('/');
        return;
    }

    $intent = $finfactory->paymentIntents->create([
        'amount' => PRODUCT_AMOUNT,
        'currency' => PRODUCT_CURRENCY,
        'description' => PRODUCT_NAME,
        'metadata' => ['product' => PRODUCT_NAME],
        'payment_method_data' => ['type' => 'qrph'],
        'confirm' => true,
    ], ['idempotency_key' => 'acacia-qrph-' . $attempt]);

    redirect('/qrph?payment_intent=' . urlencode($intent->id));
}

// Our own QR Ph page: the code, the amount, a countdown, and a script that asks /qrph/status
// every few seconds and moves on once the payment is done.
function showQrph(FinFactory $finfactory): void
{
    $intent = $finfactory->paymentIntents->retrieve(intentIdFromQuery());

    if (\in_array($intent->status, [PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING], true)) {
        redirect('/return?payment_intent=' . urlencode($intent->id));
        return;
    }

    // The SDK draws the code (it needs chillerlan/php-qrcode), with the QR Ph logo in the middle
    // as the BSP asks of merchant codes. null when the intent isn't waiting for a QR Ph payment.
    $svg = $intent->qrphSvg([
        'logo' => ['data' => (string) file_get_contents(__DIR__ . '/../assets/qrph-logo.png'), 'type' => 'image/png'],
        'margin' => 2,
    ]);

    if ($intent->status !== PaymentIntent::STATUS_REQUIRES_ACTION || $svg === null) {
        // Expired (requires_payment_method), canceled, or anything else: offer a new code.
        $why = $intent->status === PaymentIntent::STATUS_CANCELED
            ? 'This payment was canceled.'
            : 'This QR code has expired or can no longer be paid.';
        render(200, 'QR code expired', '
        <div class="card">
          <h1>No longer payable</h1>
          <p>' . esc($why) . ' You weren\'t charged.</p>
          ' . payForm('Get a new QR code') . '
          <p class="muted">Reference ' . esc($intent->id) . ' · ' . esc($intent->status) . '</p>
          <p><a href="/">Back to ' . esc(PRODUCT_NAME) . '</a></p>
        </div>');
        return;
    }

    $simulate = $intent->livemode ? '' : '
      <form method="post" action="/qrph/simulate" class="test">
        <input type="hidden" name="payment_intent" value="' . esc($intent->id) . '">
        <span>Test mode:</span>
        <button name="outcome" value="paid">Simulate payment</button>
        <button name="outcome" value="expired">Simulate expiry</button>
      </form>';

    $config = json_encode([
        'id' => $intent->id,
        'expiresAt' => $intent->qrphExpiresAt(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

    render(200, 'Pay with QR Ph', '
    <div class="card">
      <h1>Scan to pay</h1>
      <p class="price">' . peso((int) $intent->amount) . '</p>
      <div class="qr">' . $svg . '</div>
      <p>Open your bank or e-wallet app, choose <strong>Scan QR</strong>, and scan this code.</p>
      <p id="state" class="muted" aria-live="polite">Waiting for your payment<span id="left"></span></p>
      <p class="muted">' . esc(PRODUCT_NAME) . ' · Reference ' . esc($intent->id) . '</p>
      ' . $simulate . '
      <p><a href="/">Cancel</a></p>
    </div>
    <script>
    const cfg = ' . $config . ';
    const left = document.getElementById("left");
    const state = document.getElementById("state");
    function tick() {
      if (!cfg.expiresAt) return;
      const s = Math.max(0, cfg.expiresAt - Math.floor(Date.now() / 1000));
      left.textContent = s > 0
        ? " · code expires in " + Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0")
        : " · code expired";
    }
    async function poll() {
      try {
        const r = await fetch("/qrph/status?payment_intent=" + encodeURIComponent(cfg.id), { cache: "no-store" });
        const { status } = await r.json();
        if (status === "succeeded" || status === "processing") {
          state.textContent = "Payment received. One moment…";
          location.assign("/return?payment_intent=" + encodeURIComponent(cfg.id));
          return;
        }
        if (status && status !== "requires_action") { location.reload(); return; }
      } catch (e) { /* network blip: try again */ }
      setTimeout(poll, 3000);
    }
    tick(); setInterval(tick, 1000); setTimeout(poll, 3000);
    </script>');
}

// What the QR page polls. Only the status; the page itself reads nothing else.
function qrphStatus(FinFactory $finfactory): void
{
    $intent = $finfactory->paymentIntents->retrieve(intentIdFromQuery());
    json(200, ['status' => $intent->status]);
}

// Test mode only: pay or expire the code without a banking app. A live key is refused by the API.
function simulateQrph(FinFactory $finfactory): void
{
    $id = (string) ($_POST['payment_intent'] ?? '');
    $outcome = (string) ($_POST['outcome'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $id) || !\in_array($outcome, ['paid', 'expired'], true)) {
        redirect('/');
        return;
    }
    $finfactory->testHelpers->paymentIntents->simulate($id, ['outcome' => $outcome]);
    redirect('/qrph?payment_intent=' . urlencode($id));
}

// FinFactory's hosted checkout, for card and the other methods: create an intent with no
// method and send the customer to the checkout page.
function pay(FinFactory $finfactory, string $publicUrl): void
{
    $intent = $finfactory->paymentIntents->create([
        'amount' => PRODUCT_AMOUNT,
        'currency' => PRODUCT_CURRENCY,
        'description' => PRODUCT_NAME,
        'metadata' => ['product' => PRODUCT_NAME],
        'return_url' => $publicUrl . '/return',
        'cancel_url' => $publicUrl . '/?canceled=1',
    ]);

    $checkoutUrl = $intent->redirectUrl();
    if ($checkoutUrl === null) {
        throw new FinFactoryException("Payment intent {$intent->id} has no checkout link (status {$intent->status}).");
    }
    redirect($checkoutUrl);
}

// Where both flows end. Arriving here proves nothing by itself, so read the intent.
function paymentReturn(FinFactory $finfactory): void
{
    $id = (string) ($_GET['payment_intent'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $id)) {
        render(400, 'Missing payment', '<p>No payment to show. <a href="/">Back</a></p>');
        return;
    }

    $intent = $finfactory->paymentIntents->retrieve($id);
    [$title, $message] = match ($intent->status) {
        PaymentIntent::STATUS_SUCCEEDED => ['Thank you!', 'We received ' . peso((int) $intent->amount_received) . ' for ' . esc(PRODUCT_NAME) . '.'],
        PaymentIntent::STATUS_PROCESSING => ['Payment processing', 'Your payment is being confirmed. Refresh this page in a moment.'],
        PaymentIntent::STATUS_CANCELED => ['Payment canceled', 'This payment was canceled. You weren\'t charged.'],
        default => ['Not paid yet', 'This payment hasn\'t been completed.'],
    };

    $again = \in_array($intent->status, [PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING], true)
        ? ''
        : payForm('Try again with QR Ph');
    render(200, $title, '
    <div class="card">
      <h1>' . esc($title) . '</h1>
      <p>' . $message . '</p>
      <p class="muted">Reference ' . esc($intent->id) . ' · ' . esc($intent->status) . '</p>
      ' . $again . '
      <p><a href="/">Back to ' . esc(PRODUCT_NAME) . '</a></p>
    </div>');
}

function intentIdFromQuery(): string
{
    $id = (string) ($_GET['payment_intent'] ?? '');
    if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $id)) {
        throw new FinFactoryException('No payment reference in the link.');
    }
    return $id;
}

function peso(int $centavos): string
{
    return '₱' . number_format($centavos / 100, 2);
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
}

function json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
}

function render(int $status, string $title, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $title = esc($title);
    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <style>
      body { font-family: system-ui, sans-serif; background: #f4f6f3; color: #1d2b1f; margin: 0; padding: 48px 16px; }
      .card { max-width: 360px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 4px rgb(0 0 0 / 8%); text-align: center; }
      h1 { font-size: 1.4rem; margin: 0 0 8px; }
      .price { font-size: 2rem; font-weight: 600; margin: 8px 0 24px; }
      .button { display: inline-block; background: #2f6b3a; color: #fff; text-decoration: none; padding: 12px 28px; border: 0; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
      .button:hover { background: #24552d; }
      form { margin: 0 0 16px; }
      .qr { width: 280px; max-width: 100%; aspect-ratio: 1; margin: 0 auto 16px; }
      .qr svg { width: 100%; height: 100%; display: block; }
      .test { border: 1px dashed #c9a227; border-radius: 8px; padding: 10px; font-size: .85rem; display: flex; gap: 8px; align-items: center; justify-content: center; flex-wrap: wrap; }
      .notice { max-width: 360px; margin: 0 auto 16px; background: #fff6dc; border-radius: 8px; padding: 12px 16px; text-align: center; }
      .muted { color: #6b776d; font-size: .9rem; }
      .small { font-size: .85rem; }
      a { color: #2f6b3a; }
    </style>
    </head>
    <body>{$body}</body>
    </html>
    HTML;
}

// A minimal .env reader: KEY=value lines, # comments. Real environment variables win.
function loadEnv(string $file): void
{
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($name) === false) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}
