// Acacia 15 SVC: a one-page site that charges ₱15 through FinFactory hosted checkout.
//
//   cp .env.example .env    # then fill in a test key
//   npm install
//   npm start               # http://localhost:3000
//
// Uses only Node's built-in http module and the FinFactory SDK.
import { createServer } from "node:http";
import FinFactory, { ApiError, FinFactoryError } from "@domdanao/finfactory-payments";

const PRODUCT = {
  name: "Acacia 15 SVC",
  amount: 1500, // in centavos: ₱15.00
  currency: "php",
};

const port = Number(process.env.PORT ?? 3000);
const publicUrl = (process.env.PUBLIC_URL ?? `http://acacia.localhost:${port}`).replace(/\/+$/, "");

// Reads FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET from the environment.
const finfactory = new FinFactory();

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? "/", publicUrl);
  try {
    if (req.method === "GET" && url.pathname === "/") return home(res, url);
    if (req.method === "GET" && url.pathname === "/pay") return await pay(res);
    if (req.method === "GET" && url.pathname === "/return") return await paymentReturn(res, url);
    send(res, 404, page("Not found", `<p>There is nothing here. <a href="/">Back to ${esc(PRODUCT.name)}</a></p>`));
  } catch (e) {
    console.error(e);
    const detail = e instanceof ApiError ? `${e.type}: ${e.message} (request ${e.requestId ?? "n/a"})` : e instanceof FinFactoryError ? e.message : "Unexpected error.";
    send(res, 502, page("Something went wrong", `<p>We couldn't start the payment.</p><p class="muted">${esc(detail)}</p><p><a href="/">Back</a></p>`));
  }
});

server.listen(port, () => {
  console.log(`${PRODUCT.name} sample on ${publicUrl} (${finfactory.livemode === false ? "test mode" : "LIVE mode"})`);
});

// The product page, with the link that charges ₱15.
function home(res, url) {
  const canceled = url.searchParams.has("canceled")
    ? `<p class="notice">Payment canceled. You weren't charged.</p>`
    : "";
  send(res, 200, page(PRODUCT.name, `
    ${canceled}
    <div class="card">
      <h1>${esc(PRODUCT.name)}</h1>
      <p class="price">${peso(PRODUCT.amount)}</p>
      <a class="button" href="/pay">Pay ${peso(PRODUCT.amount)}</a>
    </div>`));
}

// Creates a payment intent with no payment method, so the customer picks one (card, QR Ph, ...)
// on FinFactory's hosted checkout page, then sends them there.
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

  res.writeHead(303, { Location: checkoutUrl }).end();
}

// Hosted checkout sends the customer back here with ?payment_intent=pi_...
// Coming back proves nothing by itself, so read the intent to see how it went.
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

  const again = intent.status === "succeeded" || intent.status === "processing" ? "" : `<a class="button" href="/pay">Try again</a>`;
  send(res, 200, page(title, `
    <div class="card">
      <h1>${esc(title)}</h1>
      <p>${message}</p>
      <p class="muted">Reference ${esc(intent.id)} · ${esc(intent.status)}</p>
      ${again}
      <p><a href="/">Back to ${esc(PRODUCT.name)}</a></p>
    </div>`));
}

function peso(centavos) {
  return "₱" + (centavos / 100).toFixed(2);
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

function send(res, status, html) {
  res.writeHead(status, { "Content-Type": "text/html; charset=utf-8" }).end(html);
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
  .button { display: inline-block; background: #2f6b3a; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; }
  .button:hover { background: #24552d; }
  .notice { max-width: 360px; margin: 0 auto 16px; background: #fff6dc; border-radius: 8px; padding: 12px 16px; text-align: center; }
  .muted { color: #6b776d; font-size: .9rem; }
  a { color: #2f6b3a; }
</style>
</head>
<body>${body}</body>
</html>`;
}
