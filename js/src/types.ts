/**
 * Types for the FinFactory Payments Merchant API (v1), taken from its OpenAPI 3.1 document.
 *
 * The API adds fields, enum values and event types inside v1 without notice, so every union of
 * string literals below also accepts any other string (`| (string & {})`): your code keeps
 * compiling, and should ignore values it does not know.
 */

/** A string literal union that still accepts unknown future values. */
export type OpenString<T extends string> = T | (string & {});

/** A three-letter ISO 4217 currency code, lower case in responses (`"php"`). */
export type Currency = string;

/** Up to 50 keys of up to 40 characters, each with a string value of up to 500 characters. */
export type Metadata = Record<string, string>;

// ---------------------------------------------------------------------------------------------
// Lists
// ---------------------------------------------------------------------------------------------

/** One page of a list. Pass `next_cursor` back as `cursor` for the next page. */
export interface List<T> {
  object: "list";
  data: T[];
  has_more: boolean;
  next_cursor: string | null;
}

export interface ListParams {
  /** How many objects to return, from 1 to 100. Default 10. */
  limit?: number;
  /** A `next_cursor` from the previous page. Opaque: never build one. */
  cursor?: string;
}

// ---------------------------------------------------------------------------------------------
// Payment intents
// ---------------------------------------------------------------------------------------------

export type PaymentIntentStatus = OpenString<
  | "requires_payment_method"
  | "requires_confirmation"
  | "requires_action"
  | "processing"
  | "succeeded"
  | "canceled"
>;

export type CancellationReason = OpenString<
  "requested_by_customer" | "duplicate" | "fraudulent" | "abandoned"
>;

export type PaymentMethodType = OpenString<"card" | "qrph" | "cash">;

export type CheckoutMode = OpenString<"hosted" | "embedded">;

export interface QrphDisplayQrCodeAction {
  type: "qrph_display_qr_code";
  qrph_display_qr_code: {
    /** Render this as a QR code for the customer to scan. */
    data: string;
    expires_at: number | null;
  };
}

export interface CashDisplayReferenceAction {
  type: "cash_display_reference";
  cash_display_reference: {
    reference: string;
    amount: number;
    currency: Currency;
    expires_at: number;
    instructions: string;
  };
}

export interface RedirectToUrlAction {
  type: "redirect_to_url";
  redirect_to_url: {
    /** Send the customer's whole page here. It cannot be shown in a frame. */
    url: string;
    return_url?: string | null;
  };
}

export interface MountEmbeddedCheckoutAction {
  type: "mount_embedded_checkout";
  mount_embedded_checkout: {
    /** `ct_...`: give it to your page, with your publishable key, to mount embedded checkout. */
    client_token: string;
    expires_at: number;
  };
}

/** A `next_action` type this SDK does not know yet. Ignore it, as the API asks. */
export interface UnknownNextAction {
  type: string & {};
  [key: string]: unknown;
}

export type NextAction =
  | QrphDisplayQrCodeAction
  | CashDisplayReferenceAction
  | RedirectToUrlAction
  | MountEmbeddedCheckoutAction;

export interface LastPaymentError {
  code: OpenString<
    | "card_declined"
    | "expired_card"
    | "payment_intent_authentication_failure"
    | "payment_intent_payment_attempt_failed"
    | "payment_intent_payment_attempt_expired"
  >;
  /** Test mode only. */
  decline_code?: OpenString<"insufficient_funds" | "do_not_honor" | "expired_card">;
  /** Words you can show the customer. */
  message: string;
}

export interface PaymentIntent {
  id: string;
  object: "payment_intent";
  /** In the currency's minor unit: `10000` in `php` is ₱100.00. */
  amount: number;
  amount_received: number;
  currency: Currency;
  /** The methods this payment can be paid with now; once paid, the method it was paid with. */
  payment_method_types: PaymentMethodType[] | null;
  channel: string;
  status: PaymentIntentStatus;
  next_action: NextAction | null;
  last_payment_error: LastPaymentError | null;
  cancellation_reason: CancellationReason | null;
  canceled_at: number | null;
  description?: string | null;
  metadata: Metadata;
  livemode: boolean;
  /** Unix timestamp, in seconds. */
  created: number;
}

export interface PaymentMethodData {
  type: PaymentMethodType;
}

export interface RiskSignals {
  ip_address?: string;
  /** 1 to 64 of `A-Z a-z 0-9 . _ : -`. */
  device_id?: string;
  email?: string;
}

export interface PaymentIntentCreateParams {
  /** In the currency's minor unit, at least 1. */
  amount: number;
  /** Three-letter ISO 4217 code, either case. */
  currency: Currency;
  description?: string;
  /** The method, when the customer has chosen already. Leave out to let them choose at checkout. */
  payment_method_data?: PaymentMethodData;
  /** Limit which methods may be used. */
  payment_method_types?: PaymentMethodType[];
  metadata?: Metadata;
  /** Create and confirm in one call. Needs `payment_method_data`. */
  confirm?: boolean;
  return_url?: string;
  /** Hosted checkout only. */
  cancel_url?: string;
  /** Fills in the receipt field at checkout. */
  customer_email?: string;
  /** `hosted` (default) or `embedded`. Only on a create with no method and without `confirm`. */
  checkout_mode?: CheckoutMode;
  risk_signals?: RiskSignals;
  [key: string]: unknown;
}

export interface PaymentIntentConfirmParams {
  payment_method_data?: PaymentMethodData;
  /** Required for card. */
  return_url?: string;
  risk_signals?: RiskSignals;
  [key: string]: unknown;
}

export interface PaymentIntentCancelParams {
  cancellation_reason?: CancellationReason;
  [key: string]: unknown;
}

export type PaymentIntentListParams = ListParams;

export type SimulateOutcome = OpenString<
  | "paid"
  | "expired"
  | "approved"
  | "declined"
  | "three_d_secure_passed"
  | "three_d_secure_failed"
  | "abandoned"
>;

export interface PaymentIntentSimulateParams {
  /**
   * `paid` and `expired` for QR Ph and cash; `approved`, `declined`, `three_d_secure_passed`,
   * `three_d_secure_failed` and `abandoned` for card.
   */
  outcome: SimulateOutcome;
  /** With `declined` only. */
  decline_reason?: OpenString<"insufficient_funds" | "do_not_honor" | "expired_card"> | null;
}

// ---------------------------------------------------------------------------------------------
// Payment methods
// ---------------------------------------------------------------------------------------------

export interface PaymentMethod {
  id: string;
  object: "payment_method";
  method: PaymentMethodType;
  channel: string;
  available: boolean;
  unavailable_reason: OpenString<
    | "account_inactive"
    | "not_permitted_for_account"
    | "method_not_enabled"
    | "method_not_set_up"
    | "method_suspended"
    | "not_available"
  > | null;
  livemode: boolean;
}

export type PaymentMethodListParams = ListParams;

// ---------------------------------------------------------------------------------------------
// Refunds
// ---------------------------------------------------------------------------------------------

export type RefundStatus = OpenString<
  "created" | "processing" | "awaiting_approval" | "succeeded" | "failed"
>;

export type RefundReason = OpenString<"requested_by_customer" | "duplicate" | "fraudulent">;

export type RefundFailureReason = OpenString<
  | "declined"
  | "refund_not_possible"
  | "refund_not_yet_available"
  | "refunds_not_enabled"
  | "restricted"
  | "risk_declined"
  | "unknown"
>;

export interface Refund {
  id: string;
  object: "refund";
  payment_intent: string;
  amount: number;
  currency: Currency;
  status: RefundStatus;
  reason: RefundReason | null;
  failure_reason: RefundFailureReason | null;
  metadata: Metadata;
  livemode: boolean;
  created: number;
}

export interface RefundCreateParams {
  payment_intent: string;
  /** In the currency's minor unit. Leave out to refund what remains. */
  amount?: number;
  reason?: RefundReason;
  metadata?: Metadata;
  [key: string]: unknown;
}

export interface RefundListParams extends ListParams {
  /** Only this payment intent's refunds. */
  payment_intent?: string;
}

// ---------------------------------------------------------------------------------------------
// Disputes
// ---------------------------------------------------------------------------------------------

export type DisputeStatus = OpenString<"open" | "under_review" | "won" | "lost" | "accepted">;

export interface Dispute {
  id: string;
  object: "dispute";
  amount: number;
  currency: Currency;
  payment_intent: string;
  status: DisputeStatus;
  reason: OpenString<"fraud" | "authorization" | "processing_error" | "consumer_dispute" | "other">;
  network_reason_code: string;
  case_reference: string;
  chargeback_cost: number;
  admin_fee: number;
  cost_returned: number;
  debited: number;
  charged_to: OpenString<"you" | "platform">;
  /** A calendar date, `YYYY-MM-DD`, in your platform's time zone. */
  evidence_due_date: string | null;
  evidence_request: string | null;
  livemode: boolean;
  created: number;
  closed: number | null;
}

export interface DisputeListParams extends ListParams {
  payment_intent?: string;
  status?: DisputeStatus;
}

// ---------------------------------------------------------------------------------------------
// Webhook endpoints
// ---------------------------------------------------------------------------------------------

export interface WebhookEndpoint {
  id: string;
  object: "webhook_endpoint";
  url: string;
  enabled_events: Array<WebhookEventType | "*">;
  status: OpenString<"enabled" | "disabled">;
  /** `whsec_...`: only on the create and rotate_secret answers. Store it. */
  secret?: string;
  livemode: boolean;
  created: number;
}

export interface WebhookEndpointCreateParams {
  /** `https://`, resolving only to public addresses. */
  url: string;
  /** Event types to send, or `["*"]` for every type, including those added later. */
  enabled_events: Array<WebhookEventType | "*">;
}

export interface WebhookEndpointUpdateParams {
  url?: string;
  enabled_events?: Array<WebhookEventType | "*">;
  status?: "enabled" | "disabled";
}

export type WebhookEndpointListParams = ListParams;

// ---------------------------------------------------------------------------------------------
// Access tokens
// ---------------------------------------------------------------------------------------------

export interface AccessToken {
  /** `at_test_...` or `at_live_...`. */
  access_token: string;
  token_type: "Bearer";
  /** Seconds until it expires: 900. */
  expires_in: number;
}

// ---------------------------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------------------------

export interface EventObjectMap {
  "payment_intent.created": PaymentIntent;
  "payment_intent.requires_action": PaymentIntent;
  "payment_intent.processing": PaymentIntent;
  "payment_intent.succeeded": PaymentIntent;
  "payment_intent.payment_failed": PaymentIntent;
  "payment_intent.canceled": PaymentIntent;
  "refund.created": Refund;
  "refund.updated": Refund;
  "refund.succeeded": Refund;
  "refund.failed": Refund;
  "dispute.created": Dispute;
  "dispute.updated": Dispute;
  "dispute.closed": Dispute;
}

export type KnownWebhookEventType = keyof EventObjectMap;
export type WebhookEventType = OpenString<KnownWebhookEventType>;

interface EventBase {
  /** Deduplicate on this: the same event may arrive more than once. */
  id: string;
  object: "event";
  created: number;
  api_version: "v1";
  livemode: boolean;
  tenant_id?: string;
  merchant_id?: string | null;
}

/** A known event, narrowed by `type`: `if (event.type === "refund.failed") event.data.object // Refund`. */
export type KnownWebhookEvent = {
  [K in KnownWebhookEventType]: EventBase & { type: K; data: { object: EventObjectMap[K] } };
}[KnownWebhookEventType];

/** The shape of an event type this SDK does not know yet. */
export interface UnknownWebhookEvent extends EventBase {
  type: string;
  data: { object: Record<string, unknown> };
}

/**
 * An event, narrowed by `type`. The API may add event types inside v1, and an endpoint subscribed
 * to `["*"]` receives them: keep a `default:` branch that ignores types you do not handle.
 */
export type WebhookEvent = KnownWebhookEvent;

// ---------------------------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------------------------

export type ErrorType = OpenString<
  | "validation_failure"
  | "authentication_failure"
  | "authorization_failure"
  | "not_found"
  | "conflict"
  | "timeout_or_in_progress"
  | "policy_rejection"
  | "invariant_violation"
  | "rate_limited"
  | "dependency_failure"
>;

export interface ErrorBody {
  error: {
    type: ErrorType;
    code: string;
    message: string;
    param: string | null;
    request_id: string | null;
  };
}

// ---------------------------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------------------------

/** What the SDK knows about the HTTP response an object came from. */
export interface ResponseInfo {
  statusCode: number;
  headers: Headers;
  /** The `X-Request-ID` header. Quote it when you contact support. */
  requestId: string | null;
  /** True when a retry with the same `Idempotency-Key` was answered with the first result. */
  idempotentReplayed: boolean;
  /** The `Idempotency-Key` that was sent, if any. */
  idempotencyKey: string | null;
}

/** Every object a call returns carries a non-enumerable `lastResponse`. */
export type WithResponse<T> = T & { readonly lastResponse: ResponseInfo };
