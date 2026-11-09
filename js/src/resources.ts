import type { FinFactory, RequestOptions } from "./client.js";
import type {
  AccessToken,
  Dispute,
  DisputeListParams,
  List,
  PaymentIntent,
  PaymentIntentCancelParams,
  PaymentIntentConfirmParams,
  PaymentIntentCreateParams,
  PaymentIntentListParams,
  PaymentIntentSimulateParams,
  PaymentMethod,
  PaymentMethodListParams,
  Refund,
  RefundCreateParams,
  RefundListParams,
  WebhookEndpoint,
  WebhookEndpointCreateParams,
  WebhookEndpointListParams,
  WebhookEndpointUpdateParams,
  WithResponse,
} from "./types.js";

type Result<T> = Promise<WithResponse<T>>;

function id(value: string, what: string): string {
  if (typeof value !== "string" || value.length === 0) {
    throw new TypeError(`FinFactory: a ${what} id is required.`);
  }
  return encodeURIComponent(value);
}

abstract class Resource {
  constructor(protected readonly client: FinFactory) {}
}

/** Payments, whatever the method. See the Payment intents guide. */
export class PaymentIntents extends Resource {
  /**
   * `POST /v1/payment_intents`. Without a method, the customer chooses one at checkout: send them
   * to `next_action.redirect_to_url.url`. Pass `options.idempotencyKey`, such as `order-1042-1`.
   */
  create(params: PaymentIntentCreateParams, options?: RequestOptions): Result<PaymentIntent> {
    return this.client.request({ method: "POST", path: "/v1/payment_intents", body: params, idempotent: true }, options);
  }

  /** `GET /v1/payment_intents/{id}`. Read this before you fulfil an order. */
  retrieve(paymentIntentId: string, options?: RequestOptions): Result<PaymentIntent> {
    return this.client.request({ method: "GET", path: `/v1/payment_intents/${id(paymentIntentId, "payment intent")}` }, options);
  }

  /** `POST /v1/payment_intents/{id}/confirm`, with the method the customer chose. `return_url` is required for card. */
  confirm(paymentIntentId: string, params: PaymentIntentConfirmParams = {}, options?: RequestOptions): Result<PaymentIntent> {
    return this.client.request(
      { method: "POST", path: `/v1/payment_intents/${id(paymentIntentId, "payment intent")}/confirm`, body: params, idempotent: true },
      options,
    );
  }

  /** `POST /v1/payment_intents/{id}/cancel`. Not once it is `processing` or `succeeded`. */
  cancel(paymentIntentId: string, params: PaymentIntentCancelParams = {}, options?: RequestOptions): Result<PaymentIntent> {
    return this.client.request(
      { method: "POST", path: `/v1/payment_intents/${id(paymentIntentId, "payment intent")}/cancel`, body: params, idempotent: true },
      options,
    );
  }

  /** `GET /v1/payment_intents`: one page. */
  list(params: PaymentIntentListParams = {}, options?: RequestOptions): Result<List<PaymentIntent>> {
    return this.client.request({ method: "GET", path: "/v1/payment_intents", query: { ...params } }, options);
  }

  /** Every payment intent, page after page: `for await (const pi of finfactory.paymentIntents.all()) ...` */
  all(params: PaymentIntentListParams = {}, options?: RequestOptions): AsyncGenerator<PaymentIntent, void, undefined> {
    return this.client.paginate<PaymentIntent>("/v1/payment_intents", { ...params }, options);
  }
}

/** What your account can take, and why a method cannot take a payment now. */
export class PaymentMethods extends Resource {
  /** `GET /v1/payment_methods`: one page. */
  list(params: PaymentMethodListParams = {}, options?: RequestOptions): Result<List<PaymentMethod>> {
    return this.client.request({ method: "GET", path: "/v1/payment_methods", query: { ...params } }, options);
  }

  /** Every payment method, page after page. */
  all(params: PaymentMethodListParams = {}, options?: RequestOptions): AsyncGenerator<PaymentMethod, void, undefined> {
    return this.client.paginate<PaymentMethod>("/v1/payment_methods", { ...params }, options);
  }

  /** `GET /v1/payment_methods/{id}`. */
  retrieve(paymentMethodId: string, options?: RequestOptions): Result<PaymentMethod> {
    return this.client.request({ method: "GET", path: `/v1/payment_methods/${id(paymentMethodId, "payment method")}` }, options);
  }
}

/** Return money from a payment that succeeded, in full or in part. */
export class Refunds extends Resource {
  /** `POST /v1/refunds`. Leave `amount` out to refund what remains. Pass `options.idempotencyKey`. */
  create(params: RefundCreateParams, options?: RequestOptions): Result<Refund> {
    return this.client.request({ method: "POST", path: "/v1/refunds", body: params, idempotent: true }, options);
  }

  /** `GET /v1/refunds/{id}`. */
  retrieve(refundId: string, options?: RequestOptions): Result<Refund> {
    return this.client.request({ method: "GET", path: `/v1/refunds/${id(refundId, "refund")}` }, options);
  }

  /** `GET /v1/refunds`: one page. Pass `payment_intent` for one payment's refunds. */
  list(params: RefundListParams = {}, options?: RequestOptions): Result<List<Refund>> {
    return this.client.request({ method: "GET", path: "/v1/refunds", query: { ...params } }, options);
  }

  /** Every refund, page after page. */
  all(params: RefundListParams = {}, options?: RequestOptions): AsyncGenerator<Refund, void, undefined> {
    return this.client.paginate<Refund>("/v1/refunds", { ...params }, options);
  }
}

/** Chargebacks on card payments. Read-only: respond from the Merchant Dashboard. */
export class Disputes extends Resource {
  /** `GET /v1/disputes/{id}`. */
  retrieve(disputeId: string, options?: RequestOptions): Result<Dispute> {
    return this.client.request({ method: "GET", path: `/v1/disputes/${id(disputeId, "dispute")}` }, options);
  }

  /** `GET /v1/disputes`: one page, by `payment_intent` or `status` if you like. */
  list(params: DisputeListParams = {}, options?: RequestOptions): Result<List<Dispute>> {
    return this.client.request({ method: "GET", path: "/v1/disputes", query: { ...params } }, options);
  }

  /** Every dispute, page after page. */
  all(params: DisputeListParams = {}, options?: RequestOptions): AsyncGenerator<Dispute, void, undefined> {
    return this.client.paginate<Dispute>("/v1/disputes", { ...params }, options);
  }
}

/** HTTPS addresses events are sent to. */
export class WebhookEndpoints extends Resource {
  /**
   * `POST /v1/webhook_endpoints`. The answer's `secret` is shown here only: store it.
   *
   * This call takes no `Idempotency-Key`, so the SDK does **not** retry it after a network failure
   * or a 5xx: a second create would make a second endpoint. If it fails that way, `list()` your
   * endpoints, and `rotateSecret()` the one that was made to read its secret.
   */
  create(params: WebhookEndpointCreateParams, options?: RequestOptions): Result<WebhookEndpoint> {
    return this.client.request({ method: "POST", path: "/v1/webhook_endpoints", body: params, retryable: false }, options);
  }

  /** `GET /v1/webhook_endpoints`: one page. */
  list(params: WebhookEndpointListParams = {}, options?: RequestOptions): Result<List<WebhookEndpoint>> {
    return this.client.request({ method: "GET", path: "/v1/webhook_endpoints", query: { ...params } }, options);
  }

  /** Every webhook endpoint, page after page. */
  all(params: WebhookEndpointListParams = {}, options?: RequestOptions): AsyncGenerator<WebhookEndpoint, void, undefined> {
    return this.client.paginate<WebhookEndpoint>("/v1/webhook_endpoints", { ...params }, options);
  }

  /** `POST /v1/webhook_endpoints/{id}`. Set `status: "enabled"` to turn a disabled endpoint back on. */
  update(webhookEndpointId: string, params: WebhookEndpointUpdateParams, options?: RequestOptions): Result<WebhookEndpoint> {
    return this.client.request(
      { method: "POST", path: `/v1/webhook_endpoints/${id(webhookEndpointId, "webhook endpoint")}`, body: params },
      options,
    );
  }

  /** `POST /v1/webhook_endpoints/{id}/disable`. */
  disable(webhookEndpointId: string, options?: RequestOptions): Result<WebhookEndpoint> {
    return this.client.request(
      { method: "POST", path: `/v1/webhook_endpoints/${id(webhookEndpointId, "webhook endpoint")}/disable` },
      options,
    );
  }

  /**
   * `POST /v1/webhook_endpoints/{id}/rotate_secret`. The new `secret` is in this answer only. For 24
   * hours deliveries are signed with both secrets: verify with `[newSecret, oldSecret]`.
   */
  rotateSecret(webhookEndpointId: string, options?: RequestOptions): Result<WebhookEndpoint> {
    return this.client.request(
      { method: "POST", path: `/v1/webhook_endpoints/${id(webhookEndpointId, "webhook endpoint")}/rotate_secret` },
      options,
    );
  }
}

class TestHelperPaymentIntents extends Resource {
  /**
   * `POST /v1/test_helpers/payment_intents/{id}/simulate`: give a test payment its outcome. Test keys
   * only; a live key is refused with `test_mode_only`.
   */
  simulate(paymentIntentId: string, params: PaymentIntentSimulateParams, options?: RequestOptions): Result<PaymentIntent> {
    return this.client.request(
      {
        method: "POST",
        path: `/v1/test_helpers/payment_intents/${id(paymentIntentId, "payment intent")}/simulate`,
        body: params,
        idempotent: true,
      },
      options,
    );
  }
}

/** Calls that only work with a test key. */
export class TestHelpers extends Resource {
  readonly paymentIntents = new TestHelperPaymentIntents(this.client);
}

/**
 * `POST /oauth/token`. The client gets and refreshes tokens by itself; call this only if you want
 * a token for something else.
 */
export class AccessTokens extends Resource {
  create(options?: RequestOptions): Result<AccessToken> {
    return this.client.requestAccessToken(options);
  }
}
