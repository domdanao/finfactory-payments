// ATSI web svc: a one-page site that takes payment by QR Ph on its own page, through the
// FinFactory API. (FinFactory's hosted checkout is still reachable at /pay, for other methods.)
// The same site, route for route, as ../sample-php.
//
//   cp .env.example .env    # then fill in a test key
//   npm install
//   npm start               # http://acacia.localhost:3000
//
// Uses only Node's built-in http module, the FinFactory SDK, and the finfactory-qrph package,
// which draws the QR Ph code.
import { createServer } from "node:http";
import { readFileSync } from "node:fs";
import { randomBytes } from "node:crypto";
import FinFactory, { ApiError, FinFactoryError, qrphExpiresAt } from "@domdanao/finfactory-payments";
import { qrphSvg } from "@domdanao/finfactory-qrph";

const PRODUCT = {
  name: "ATSI web svc",
  amount: 500, // in centavos: ₱5.00
  currency: "php",
};

// The QR Ph logo, drawn in the middle of the code as the BSP asks of merchant codes.
const QRPH_LOGO = { data: readFileSync(new URL("./assets/qrph-logo.png", import.meta.url)), type: "image/png" };

const port = Number(process.env.PORT ?? 3000);
const publicUrl = (process.env.PUBLIC_URL ?? `http://acacia.localhost:${port}`).replace(/\/+$/, "");

// Reads FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET from the environment. The client keeps
// its Access Token in memory, which lasts as long as this process, so polling is cheap.
const finfactory = new FinFactory();

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? "/", publicUrl);
  const route = `${req.method} ${url.pathname}`;
  try {
    if (route === "GET /") return home(res, url);
    if (route === "POST /qrph") return await startQrph(res, await form(req));
    if (route === "GET /qrph") return await showQrph(res, url);
    if (route === "GET /qrph/status") return await qrphStatus(res, url);
    if (route === "POST /qrph/simulate") return await simulateQrph(res, await form(req));
    if (route === "GET /pay") return await pay(res);
    if (route === "GET /return") return await paymentReturn(res, url);
    send(res, 404, page("Not found", `<p>There is nothing here. <a href="/">Back to ${esc(PRODUCT.name)}</a></p>`));
  } catch (e) {
    console.error(e);
    const detail = e instanceof ApiError ? `${e.type}: ${e.message} (request ${e.requestId ?? "n/a"})` : e instanceof FinFactoryError ? e.message : "Unexpected error.";
    if (url.pathname === "/qrph/status") return json(res, 502, { error: detail });
    send(res, 502, page("Something went wrong", `<p>We couldn't start the payment.</p><p class="muted">${esc(detail)}</p><p><a href="/">Back</a></p>`));
  }
});

server.listen(port, () => {
  console.log(`${PRODUCT.name} sample on ${publicUrl} (${finfactory.livemode === false ? "test mode" : "LIVE mode"})`);
});

// The product page. Paying is a POST, so link prefetchers and crawlers can't create payments.
function home(res, url) {
  const canceled = url.searchParams.has("canceled")
    ? `<p class="notice">Payment canceled. You weren't charged.</p>`
    : "";
  send(res, 200, page(PRODUCT.name, `
    ${canceled}
    <div class="card">
      <h1>${esc(PRODUCT.name)}</h1>
      <p class="price">${peso(PRODUCT.amount)}</p>
      ${payForm(`Pay ${peso(PRODUCT.amount)} with QR Ph`)}
      <p class="muted small"><a href="/pay">Other ways to pay</a></p>
    </div>`));
}

// A form that starts a QR Ph payment. The random attempt id becomes the idempotency key, so a
// double click or a resubmitted form gives back the same payment instead of a second one.
function payForm(label) {
  return `<form method="post" action="/qrph">
        <input type="hidden" name="attempt" value="${randomBytes(16).toString("hex")}">
        <button class="button" type="submit">${esc(label)}</button>
      </form>`;
}

// Creates the payment intent with QR Ph as its method and confirms it in the same call. The
// answer's next_action carries the QR payload. Then redirect, so reloading the QR page doesn't
// post the form again.
async function startQrph(res, body) {
  const attempt = body.get("attempt") ?? "";
  if (!/^[a-f0-9]{32}$/.test(attempt)) return redirect(res, "/");

  const intent = await finfactory.paymentIntents.create(
    {
      amount: PRODUCT.amount,
      currency: PRODUCT.currency,
      description: PRODUCT.name,
      metadata: { product: PRODUCT.name },
      payment_method_data: { type: "qrph" },
      confirm: true,
    },
    { idempotencyKey: `atsi-qrph-${attempt}` },
  );

  redirect(res, `/qrph?payment_intent=${encodeURIComponent(intent.id)}`);
}

// Our own QR Ph page: the code, the amount, a countdown, and a script that asks /qrph/status
// every few seconds and moves on once the payment is done.
async function showQrph(res, url) {
  const intent = await finfactory.paymentIntents.retrieve(intentIdFrom(url));

  if (intent.status === "succeeded" || intent.status === "processing") {
    return redirect(res, `/return?payment_intent=${encodeURIComponent(intent.id)}`);
  }

  // finfactory-qrph draws the code, with the QR Ph logo in the middle. null when the intent
  // isn't waiting for a QR Ph payment.
  const svg = qrphSvg(intent, { logo: QRPH_LOGO, margin: 2 });

  if (intent.status !== "requires_action" || svg === null) {
    // Expired (requires_payment_method), canceled, or anything else: offer a new code.
    const why = intent.status === "canceled"
      ? "This payment was canceled."
      : "This QR code has expired or can no longer be paid.";
    return send(res, 200, page("QR code expired", `
    <div class="card">
      <h1>No longer payable</h1>
      <p>${esc(why)} You weren't charged.</p>
      ${payForm("Get a new QR code")}
      <p class="muted">Reference ${esc(intent.id)} · ${esc(intent.status)}</p>
      <p><a href="/">Back to ${esc(PRODUCT.name)}</a></p>
    </div>`));
  }

  const simulate = intent.livemode ? "" : `
      <form method="post" action="/qrph/simulate" class="test">
        <input type="hidden" name="payment_intent" value="${esc(intent.id)}">
        <span>Test mode:</span>
        <button name="outcome" value="paid">Simulate payment</button>
        <button name="outcome" value="expired">Simulate expiry</button>
      </form>`;

  const config = JSON.stringify({ id: intent.id, expiresAt: qrphExpiresAt(intent) }).replace(/</g, "\\u003c").replace(/&/g, "\\u0026");

  send(res, 200, page("Pay with QR Ph", `
    <div class="card">
      <h1>Scan to pay</h1>
      <p class="price">${peso(intent.amount)}</p>
      <div class="qr">${svg}</div>
      <p>Open your bank or e-wallet app, choose <strong>Scan QR</strong>, and scan this code.</p>
      <p id="state" class="muted" aria-live="polite">Waiting for your payment<span id="left"></span></p>
      <p class="muted">${esc(PRODUCT.name)} · Reference ${esc(intent.id)}</p>
      ${simulate}
      <p><a href="/">Cancel</a></p>
    </div>
    <script>
    const cfg = ${config};
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
    </script>`));
}

// What the QR page polls. Only the status; the page itself reads nothing else.
async function qrphStatus(res, url) {
  const intent = await finfactory.paymentIntents.retrieve(intentIdFrom(url));
  json(res, 200, { status: intent.status });
}

// Test mode only: pay or expire the code without a banking app. A live key is refused by the API.
async function simulateQrph(res, body) {
  const id = body.get("payment_intent") ?? "";
  const outcome = body.get("outcome") ?? "";
  if (!/^pi_[A-Za-z0-9_]+$/.test(id) || !["paid", "expired"].includes(outcome)) return redirect(res, "/");
  await finfactory.testHelpers.paymentIntents.simulate(id, { outcome });
  redirect(res, `/qrph?payment_intent=${encodeURIComponent(id)}`);
}

// FinFactory's hosted checkout, for card and the other methods: create an intent with no
// method and send the customer to the checkout page.
async function pay(res) {
  const intent = await finfactory.paymentIntents.create({
    amount: PRODUCT.amount,
    currency: PRODUCT.currency,
    description: PRODUCT.name,
    metadata: { product: PRODUCT.name },
    return_url: `${publicUrl}/return`,
    cancel_url: `${publicUrl}/?canceled=1`,
  });

  const checkoutUrl = intent.next_action?.type === "redirect_to_url" ? intent.next_action.redirect_to_url.url : null;
  if (!checkoutUrl) throw new FinFactoryError(`Payment intent ${intent.id} has no checkout link (status ${intent.status}).`);
  redirect(res, checkoutUrl);
}

// Where both flows end. Arriving here proves nothing by itself, so read the intent.
async function paymentReturn(res, url) {
  const id = url.searchParams.get("payment_intent") ?? "";
  if (!/^pi_[A-Za-z0-9_]+$/.test(id)) {
    return send(res, 400, page("Missing payment", `<p>No payment to show. <a href="/">Back</a></p>`));
  }

  const intent = await finfactory.paymentIntents.retrieve(id);
  const [title, message] = {
    succeeded: ["Thank you!", `We received ${peso(intent.amount_received)} for ${esc(PRODUCT.name)}.`],
    processing: ["Payment processing", "Your payment is being confirmed. Refresh this page in a moment."],
    canceled: ["Payment canceled", "This payment was canceled. You weren't charged."],
  }[intent.status] ?? ["Not paid yet", "This payment hasn't been completed."];

  const again = intent.status === "succeeded" || intent.status === "processing" ? "" : payForm("Try again with QR Ph");
  send(res, 200, page(title, `
    <div class="card">
      <h1>${esc(title)}</h1>
      <p>${message}</p>
      <p class="muted">Reference ${esc(intent.id)} · ${esc(intent.status)}</p>
      ${again}
      <p><a href="/">Back to ${esc(PRODUCT.name)}</a></p>
    </div>`));
}

function intentIdFrom(url) {
  const id = url.searchParams.get("payment_intent") ?? "";
  if (!/^pi_[A-Za-z0-9_]+$/.test(id)) throw new FinFactoryError("No payment reference in the link.");
  return id;
}

// Reads a small urlencoded form body.
async function form(req) {
  let body = "";
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 10_000) break;
  }
  return new URLSearchParams(body);
}

function peso(centavos) {
  return "₱" + (centavos / 100).toFixed(2);
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

function redirect(res, to) {
  res.writeHead(303, { Location: to }).end();
}

function json(res, status, body) {
  res.writeHead(status, { "Content-Type": "application/json", "Cache-Control": "no-store" }).end(JSON.stringify(body));
}

function send(res, status, html) {
  res.writeHead(status, { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" }).end(html);
}

function page(title, body) {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
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
<body>${body}</body>
</html>`;
}
