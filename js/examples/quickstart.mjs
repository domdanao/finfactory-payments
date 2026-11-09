// End to end in test mode: a QR Ph payment and a card payment with a refund.
//
//   FINFACTORY_CLIENT_ID=ci_test_... FINFACTORY_CLIENT_SECRET=sk_test_... node examples/quickstart.mjs
//
// Run from the package folder after `npm run build`, or replace the import with "@domdanao/finfactory-payments".
import FinFactory, { ApiError } from "../dist/index.js";

const finfactory = new FinFactory();
if (finfactory.livemode !== false) {
  console.error("Use a test key (ci_test_...): this example simulates payments.");
  process.exit(1);
}

const run = `quickstart-${Date.now()}`;

try {
  // 1. QR Ph: create and confirm in one call, then play the customer.
  const qr = await finfactory.paymentIntents.create(
    { amount: 10000, currency: "php", description: "Quickstart QR Ph", payment_method_data: { type: "qrph" }, confirm: true, metadata: { run } },
    { idempotencyKey: `${run}-qr` },
  );
  console.log("QR Ph intent", qr.id, qr.status, qr.next_action?.type);

  const paid = await finfactory.testHelpers.paymentIntents.simulate(qr.id, { outcome: "paid" }, { idempotencyKey: `${run}-qr-paid` });
  console.log("after simulate:", paid.status, "received", paid.amount_received);

  // 2. Card: confirm with a return_url, approve it, then refund it in full.
  const card = await finfactory.paymentIntents.create(
    { amount: 25000, currency: "php", description: "Quickstart card", payment_method_data: { type: "card" }, confirm: true, return_url: "https://shop.example.com/return" },
    { idempotencyKey: `${run}-card` },
  );
  console.log("card intent", card.id, card.status, card.next_action?.type);

  await finfactory.testHelpers.paymentIntents.simulate(card.id, { outcome: "approved" }, { idempotencyKey: `${run}-card-approved` });
  const settled = await finfactory.paymentIntents.retrieve(card.id);
  console.log("card status:", settled.status);

  const refund = await finfactory.refunds.create({ payment_intent: card.id, reason: "requested_by_customer" }, { idempotencyKey: `${run}-refund` });
  console.log("refund", refund.id, refund.status, refund.amount);

  // 3. The five most recent intents.
  const page = await finfactory.paymentIntents.list({ limit: 5 });
  for (const pi of page.data) console.log(" ", pi.id, pi.status, pi.amount, pi.currency);
} catch (e) {
  if (e instanceof ApiError) console.error(`${e.name}: ${e.type}/${e.code} param=${e.param} request=${e.requestId}\n${e.message}`);
  else console.error(e);
  process.exit(1);
}
