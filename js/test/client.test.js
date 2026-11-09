import { test } from "node:test";
import assert from "node:assert/strict";
import {
  FinFactory,
  Webhooks,
  ValidationError,
  DependencyFailureError,
  RateLimitError,
  OAuthError,
  ConnectionError,
  WebhookSignatureError,
  NotFoundError,
} from "../dist/index.js";

/** A fake fetch: answers from a list of handlers, and records every request. */
function mockFetch(...handlers) {
  const calls = [];
  const fn = async (url, init) => {
    const call = { url: new URL(url), method: init.method, headers: init.headers, body: init.body };
    calls.push(call);
    const handler = handlers.length > 1 ? handlers.shift() : handlers[0];
    const out = typeof handler === "function" ? await handler(call) : handler;
    if (out instanceof Error) throw out;
    return new Response(out.body === undefined ? "" : JSON.stringify(out.body), {
      status: out.status ?? 200,
      headers: { "content-type": "application/json", ...out.headers },
    });
  };
  fn.calls = calls;
  return fn;
}

const TOKEN = { status: 200, body: { access_token: "at_test_1", token_type: "Bearer", expires_in: 900 } };
const PI = {
  id: "pi_1",
  object: "payment_intent",
  amount: 10000,
  amount_received: 0,
  currency: "php",
  payment_method_types: ["card", "qrph"],
  channel: "ecommerce",
  status: "requires_action",
  next_action: { type: "redirect_to_url", redirect_to_url: { url: "https://checkout.example.com/pay/x", return_url: null } },
  last_payment_error: null,
  cancellation_reason: null,
  canceled_at: null,
  description: null,
  metadata: {},
  livemode: false,
  created: 1790411400,
};
const err = (status, type, code, headers = {}) => ({
  status,
  headers: { "x-request-id": "req_9", ...headers },
  body: { error: { type, code, message: `${code}!`, param: null, request_id: "req_9" } },
});

function client(fetch, extra = {}) {
  return new FinFactory({ clientId: "ci_test_a", clientSecret: "sk_test_b", fetch, maxRetries: 2, ...extra });
}

test("gets an Access Token with Basic auth, then calls with Bearer", async () => {
  const fetch = mockFetch(TOKEN, { status: 201, body: PI, headers: { "x-request-id": "req_1" } });
  const ff = client(fetch);
  const pi = await ff.paymentIntents.create({ amount: 10000, currency: "php" }, { idempotencyKey: "order-1" });

  const [tokenCall, createCall] = fetch.calls;
  assert.equal(tokenCall.url.pathname, "/oauth/token");
  assert.equal(tokenCall.headers.Authorization, `Basic ${btoa("ci_test_a:sk_test_b")}`);
  assert.equal(tokenCall.body, "grant_type=client_credentials");
  assert.equal(createCall.url.toString(), "https://api.payments.finfactory.dev/v1/payment_intents");
  assert.equal(createCall.headers.Authorization, "Bearer at_test_1");
  assert.equal(createCall.headers["Idempotency-Key"], "order-1");
  assert.deepEqual(JSON.parse(createCall.body), { amount: 10000, currency: "php" });
  assert.equal(pi.id, "pi_1");
  assert.equal(pi.lastResponse.requestId, "req_1");
  assert.equal(pi.lastResponse.statusCode, 201);
  assert.ok(!Object.keys(pi).includes("lastResponse"), "lastResponse is not enumerable");
});

test("reuses the token across calls, and shares one token request between concurrent calls", async () => {
  const fetch = mockFetch((call) => (call.url.pathname === "/oauth/token" ? TOKEN : { body: PI }));
  const ff = client(fetch);
  await Promise.all([ff.paymentIntents.retrieve("pi_1"), ff.paymentIntents.retrieve("pi_2"), ff.refunds.list()]);
  await ff.paymentIntents.retrieve("pi_3");
  assert.equal(fetch.calls.filter((c) => c.url.pathname === "/oauth/token").length, 1);
});

test("an expired token is replaced and the call sent again", async () => {
  const fetch = mockFetch(
    TOKEN,
    err(401, "authentication_failure", "invalid_access_token"),
    { ...TOKEN, body: { ...TOKEN.body, access_token: "at_test_2" } },
    { body: PI },
  );
  const pi = await client(fetch).paymentIntents.retrieve("pi_1");
  assert.equal(pi.id, "pi_1");
  assert.equal(fetch.calls[3].headers.Authorization, "Bearer at_test_2");
});

test("auto-generates an Idempotency-Key and keeps it across retries of a 503", async () => {
  const fetch = mockFetch(TOKEN, err(503, "dependency_failure", "service_unavailable"), { status: 201, body: PI });
  const ff = client(fetch);
  const pi = await ff.paymentIntents.create({ amount: 100, currency: "php" }, { timeout: 1000 });
  const keys = fetch.calls.slice(1).map((c) => c.headers["Idempotency-Key"]);
  assert.equal(keys.length, 2);
  assert.match(keys[0], /^[0-9a-f-]{36}$/);
  assert.equal(keys[0], keys[1]);
  assert.equal(pi.lastResponse.idempotencyKey, keys[0]);
});

test("gives up after maxRetries with a typed error", async () => {
  const fetch = mockFetch(TOKEN, err(503, "dependency_failure", "service_unavailable"));
  const ff = client(fetch, { maxRetries: 1 });
  await assert.rejects(ff.paymentIntents.retrieve("pi_1"), (e) => {
    assert.ok(e instanceof DependencyFailureError);
    assert.equal(e.code, "service_unavailable");
    assert.equal(e.requestId, "req_9");
    return true;
  });
  assert.equal(fetch.calls.length, 3); // token + 2 attempts
});

test("does not retry a webhook endpoint create after a 503", async () => {
  const fetch = mockFetch(TOKEN, err(503, "dependency_failure", "service_unavailable"));
  await assert.rejects(
    client(fetch).webhookEndpoints.create({ url: "https://x.example/wh", enabled_events: ["*"] }),
    DependencyFailureError,
  );
  assert.equal(fetch.calls.length, 2);
  assert.equal(fetch.calls[1].headers["Idempotency-Key"], undefined);
});

test("does not retry a validation failure, and exposes code and param", async () => {
  const fetch = mockFetch(TOKEN, {
    status: 400,
    body: { error: { type: "validation_failure", code: "invalid_parameter", message: "bad", param: "amount", request_id: "req_2" } },
  });
  await assert.rejects(client(fetch).refunds.create({ payment_intent: "pi_1", amount: -1 }), (e) => {
    assert.ok(e instanceof ValidationError);
    assert.equal(e.param, "amount");
    assert.equal(e.statusCode, 400);
    return true;
  });
  assert.equal(fetch.calls.length, 2);
});

test("waits Retry-After on a 429", async () => {
  const fetch = mockFetch(TOKEN, err(429, "rate_limited", "rate_limited", { "retry-after": "0" }), { body: PI });
  const pi = await client(fetch).paymentIntents.retrieve("pi_1");
  assert.equal(pi.id, "pi_1");
});

test("a 429 with a long Retry-After is thrown, with retryAfter", async () => {
  const fetch = mockFetch(TOKEN, err(429, "rate_limited", "rate_limited", { "retry-after": "120" }));
  await assert.rejects(client(fetch).paymentIntents.retrieve("pi_1"), (e) => e instanceof RateLimitError && e.retryAfter === 120);
});

test("retries idempotency_key_in_use, but not other conflicts", async () => {
  const fetch = mockFetch(TOKEN, err(409, "timeout_or_in_progress", "idempotency_key_in_use"), { body: PI });
  assert.equal((await client(fetch).paymentIntents.confirm("pi_1", { payment_method_data: { type: "qrph" } })).id, "pi_1");

  const fetch2 = mockFetch(TOKEN, err(409, "conflict", "intent_state"));
  await assert.rejects(client(fetch2).paymentIntents.cancel("pi_1"), (e) => e.type === "conflict");
  assert.equal(fetch2.calls.length, 2);
});

test("retries a network failure on a read, then throws ConnectionError", async () => {
  const fetch = mockFetch(TOKEN, new TypeError("fetch failed"));
  await assert.rejects(client(fetch, { maxRetries: 1 }).disputes.retrieve("dp_1"), ConnectionError);
  assert.equal(fetch.calls.length, 3);
});

test("token endpoint refusals are OAuthError", async () => {
  const fetch = mockFetch({ status: 401, body: { error: "invalid_client", error_description: "Client authentication failed." } });
  await assert.rejects(client(fetch).paymentIntents.list(), (e) => {
    assert.ok(e instanceof OAuthError);
    assert.equal(e.error, "invalid_client");
    return true;
  });
});

test("404 maps to NotFoundError", async () => {
  const fetch = mockFetch(TOKEN, err(404, "not_found", "resource_missing"));
  await assert.rejects(client(fetch).refunds.retrieve("re_x"), NotFoundError);
});

test("list sends limit and cursor; all() follows next_cursor", async () => {
  const page1 = { object: "list", data: [{ id: "re_1" }, { id: "re_2" }], has_more: true, next_cursor: "c2" };
  const page2 = { object: "list", data: [{ id: "re_3" }], has_more: false, next_cursor: null };
  const fetch = mockFetch(TOKEN, { body: page1 }, { body: page2 });
  const ids = [];
  for await (const refund of client(fetch).refunds.all({ limit: 2, payment_intent: "pi_1" })) ids.push(refund.id);
  assert.deepEqual(ids, ["re_1", "re_2", "re_3"]);
  assert.equal(fetch.calls[1].url.search, "?limit=2&payment_intent=pi_1");
  assert.equal(fetch.calls[2].url.searchParams.get("cursor"), "c2");
});

test("every endpoint has the right method and path", async () => {
  const fetch = mockFetch((call) => (call.url.pathname === "/oauth/token" ? TOKEN : { body: { object: "list", data: [], has_more: false, next_cursor: null } }));
  const ff = client(fetch, { baseUrl: "https://api.platform.example/" });
  await ff.paymentIntents.list({ limit: 5 });
  await ff.paymentIntents.cancel("pi_1", { cancellation_reason: "duplicate" });
  await ff.paymentMethods.list();
  await ff.paymentMethods.retrieve("pm_1");
  await ff.refunds.retrieve("re_1");
  await ff.disputes.list({ status: "open" });
  await ff.webhookEndpoints.list();
  await ff.webhookEndpoints.update("we_1", { status: "enabled" });
  await ff.webhookEndpoints.disable("we_1");
  await ff.webhookEndpoints.rotateSecret("we_1");
  await ff.testHelpers.paymentIntents.simulate("pi_1", { outcome: "paid" });
  const seen = fetch.calls.slice(1).map((c) => `${c.method} ${c.url.pathname}${c.url.search}`);
  assert.deepEqual(seen, [
    "GET /v1/payment_intents?limit=5",
    "POST /v1/payment_intents/pi_1/cancel",
    "GET /v1/payment_methods",
    "GET /v1/payment_methods/pm_1",
    "GET /v1/refunds/re_1",
    "GET /v1/disputes?status=open",
    "GET /v1/webhook_endpoints",
    "POST /v1/webhook_endpoints/we_1",
    "POST /v1/webhook_endpoints/we_1/disable",
    "POST /v1/webhook_endpoints/we_1/rotate_secret",
    "POST /v1/test_helpers/payment_intents/pi_1/simulate",
  ]);
  assert.equal(fetch.calls[0].url.origin, "https://api.platform.example");
  assert.ok(fetch.calls[11].headers["Idempotency-Key"], "simulate sends an Idempotency-Key");
});

test("a static accessToken skips /oauth/token", async () => {
  const fetch = mockFetch({ body: PI });
  const ff = new FinFactory({ accessToken: "at_test_static", fetch });
  await ff.paymentIntents.retrieve("pi_1");
  assert.equal(fetch.calls.length, 1);
  assert.equal(fetch.calls[0].headers.Authorization, "Bearer at_test_static");
});

test("a shared tokenCache is read before fetching a token", async () => {
  const store = new Map();
  const tokenCache = { get: (k) => store.get(k), set: (k, v) => void store.set(k, v), delete: (k) => void store.delete(k) };
  const fetch = mockFetch((call) => (call.url.pathname === "/oauth/token" ? TOKEN : { body: PI }));
  await client(fetch, { tokenCache }).paymentIntents.retrieve("pi_1");
  await client(fetch, { tokenCache }).paymentIntents.retrieve("pi_1");
  assert.equal(fetch.calls.filter((c) => c.url.pathname === "/oauth/token").length, 1);
});

test("refuses to run in a browser with a secret", () => {
  globalThis.window = {};
  globalThis.document = {};
  try {
    assert.throws(() => client(mockFetch(TOKEN)), /never be sent to a browser/);
    assert.doesNotThrow(() => client(mockFetch(TOKEN), { dangerouslyAllowBrowser: true }));
  } finally {
    delete globalThis.window;
    delete globalThis.document;
  }
});

test("requires credentials", () => {
  const saved = { ...process.env };
  delete process.env.FINFACTORY_CLIENT_ID;
  delete process.env.FINFACTORY_CLIENT_SECRET;
  try {
    assert.throws(() => new FinFactory({ fetch: mockFetch(TOKEN) }), /clientId and clientSecret/);
  } finally {
    Object.assign(process.env, saved);
  }
});

test("an empty id is refused before any request", async () => {
  const fetch = mockFetch(TOKEN);
  assert.throws(() => client(fetch).paymentIntents.retrieve(""), TypeError);
  assert.equal(fetch.calls.length, 0);
});

// --- Webhooks: the worked example from the Webhooks guide -------------------------------------

const SECRET = "whsec_Kf3vQ9m2Xc7LpR1tYb8dNw5sUa0eHj4ZgTq6iVoMxCk";
const T = 1790434852;
const BODY =
  '{"id":"evt_01j9x4b2c3d4e5f6g7h8j9k0m1","object":"event","type":"payment_intent.succeeded","created":1790434850,"api_version":"v1","livemode":false,"tenant_id":"7d2e4f61-3a8b-4c95-b0e7-2f1a9c6d8e43","merchant_id":"c4a91e2b-6f37-4d08-9b5c-e0d2f8a17b36","data":{"object":{"id":"pi_01j9x2m8k4q7r5t3v6w8y0z2a4","object":"payment_intent","amount":10000,"amount_received":10000,"currency":"php","payment_method_types":["qrph"],"channel":"ecommerce","status":"succeeded","next_action":null,"last_payment_error":null,"cancellation_reason":null,"canceled_at":null,"description":"Order 1042","metadata":{"order_id":"1042"},"livemode":false,"created":1790434700}}}';
const HEADER = "t=1790434852,v1=ba1753e225757778aff55cb9a81cac1eb2a5f0724c32055eb8fb5e6285d54094";

test("webhooks: verifies the guide's worked example", async () => {
  const event = await Webhooks.constructEvent(BODY, HEADER, SECRET, { now: T + 10 });
  assert.equal(event.type, "payment_intent.succeeded");
  assert.equal(event.data.object.id, "pi_01j9x2m8k4q7r5t3v6w8y0z2a4");
  // Buffer / Uint8Array bodies too
  assert.ok(await Webhooks.isValid(Buffer.from(BODY), HEADER, SECRET, { now: T }));
  assert.equal(await Webhooks.generateTestHeader(BODY, SECRET, T), HEADER);
});

test("webhooks: rejects a changed body, wrong secret, old timestamp, bad header", async () => {
  await assert.rejects(Webhooks.verify(BODY.replace("10000", "10001"), HEADER, SECRET, { now: T }), WebhookSignatureError);
  await assert.rejects(Webhooks.verify(BODY, HEADER, "whsec_other", { now: T }), WebhookSignatureError);
  await assert.rejects(Webhooks.verify(BODY, HEADER, SECRET, { now: T + 301 }), /300 seconds/);
  await assert.rejects(Webhooks.verify(BODY, "garbage", SECRET, { now: T }), WebhookSignatureError);
  await assert.rejects(Webhooks.verify(BODY, undefined, SECRET), WebhookSignatureError);
  await assert.rejects(Webhooks.verify(JSON.parse(BODY), HEADER, SECRET, { now: T }), TypeError);
});

test("webhooks: during rotation any v1 for any secret matches", async () => {
  const header = `t=${T},v1=${"0".repeat(64)},v1=ba1753e225757778aff55cb9a81cac1eb2a5f0724c32055eb8fb5e6285d54094`;
  assert.ok(await Webhooks.isValid(BODY, header, ["whsec_new", SECRET], { now: T }));
  const ff = client(mockFetch(TOKEN));
  assert.equal(ff.webhooks, Webhooks);
});
