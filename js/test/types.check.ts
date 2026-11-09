// Compile-only: checks the public types read well. `npm run typecheck`.
import FinFactory, { Webhooks, ApiError, type PaymentIntent, type Refund } from "../dist/index.js";

async function main() {
  const ff = new FinFactory({ clientId: "ci_test_x", clientSecret: "sk_test_y" });
  const pi = await ff.paymentIntents.create({ amount: 10000, currency: "php", checkout_mode: "embedded" }, { idempotencyKey: "order-1" });
  const id: string = pi.id;
  const rid: string | null = pi.lastResponse.requestId;
  if (pi.next_action?.type === "redirect_to_url") {
    const url: string = pi.next_action.redirect_to_url.url;
  }
  if (pi.next_action?.type === "mount_embedded_checkout") {
    const token: string = pi.next_action.mount_embedded_checkout.client_token;
  }
  for await (const r of ff.refunds.all({ payment_intent: id })) {
    const refund: Refund = r;
  }
  const event = await Webhooks.constructEvent("{}", "t=1,v1=x", "whsec_x");
  if (event.type === "refund.failed") {
    const reason = event.data.object.failure_reason;
  }
  if (event.type === "payment_intent.succeeded") {
    const p: PaymentIntent = event.data.object;
  }
  try { await ff.refunds.create({ payment_intent: id }); } catch (e) {
    if (e instanceof ApiError && e.code === "amount_exceeds_refundable") {}
  }
  // @ts-expect-error amount is required
  await ff.paymentIntents.create({ currency: "php" });
}
void main;
