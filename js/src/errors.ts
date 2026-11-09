import type { ErrorBody, ErrorType } from "./types.js";

/** The base class of every error this SDK throws. `catch (e) { if (e instanceof FinFactoryError) ... }` */
export class FinFactoryError extends Error {
  override name = "FinFactoryError";
}

export interface ApiErrorInit {
  statusCode: number;
  type: ErrorType;
  code: string;
  message: string;
  param: string | null;
  requestId: string | null;
  headers: Headers;
  body: unknown;
}

/**
 * The API refused the request with its error envelope. Branch on `type` or `code`, never on
 * `message`, which may be reworded.
 */
export class ApiError extends FinFactoryError {
  override name = "ApiError";
  /** The HTTP status. */
  readonly statusCode: number;
  /** One of ten categories, each always sent on the same status. */
  readonly type: ErrorType;
  /** A stable name for this particular refusal, such as `amount_exceeds_refundable`. */
  readonly code: string;
  /** The field at fault, when there is one. */
  readonly param: string | null;
  /** Quote it when you contact support. */
  readonly requestId: string | null;
  readonly headers: Headers;
  /** The parsed response body. */
  readonly body: unknown;

  constructor(init: ApiErrorInit) {
    super(init.message);
    this.statusCode = init.statusCode;
    this.type = init.type;
    this.code = init.code;
    this.param = init.param;
    this.requestId = init.requestId;
    this.headers = init.headers;
    this.body = init.body;
  }
}

/** 400 `validation_failure`: a parameter is missing or not valid. `param` names it. */
export class ValidationError extends ApiError {
  override name = "ValidationError";
}
/** 401 `authentication_failure`: no Access Token, or one that is not valid or has expired. */
export class AuthenticationError extends ApiError {
  override name = "AuthenticationError";
}
/** 403 `authorization_failure`: the key may not act on what the request names. */
export class AuthorizationError extends ApiError {
  override name = "AuthorizationError";
}
/** 404 `not_found`: no such object for this key, or no such route. */
export class NotFoundError extends ApiError {
  override name = "NotFoundError";
}
/** 409 `conflict`: the object is not in a state that allows this. */
export class ConflictError extends ApiError {
  override name = "ConflictError";
}
/** 409 `timeout_or_in_progress`: a duplicate still running, or an outcome not known yet. Retry later. */
export class InProgressError extends ApiError {
  override name = "InProgressError";
}
/** 422 `policy_rejection`: your account may not do this now, for the reason in `code`. */
export class PolicyRejectionError extends ApiError {
  override name = "PolicyRejectionError";
}
/** 422 `invariant_violation`: the request would break a rule that always holds. */
export class InvariantViolationError extends ApiError {
  override name = "InvariantViolationError";
}
/** 429 `rate_limited`: too many requests. `retryAfter` is in seconds, when the API sent one. */
export class RateLimitError extends ApiError {
  override name = "RateLimitError";
  get retryAfter(): number | null {
    return parseRetryAfter(this.headers.get("retry-after"));
  }
}
/**
 * 503 `dependency_failure`. On a read nothing changed. On a write **whether it took effect is not
 * known**: retry with the same `Idempotency-Key` (the SDK already did, `maxRetries` times).
 */
export class DependencyFailureError extends ApiError {
  override name = "DependencyFailureError";
}

/** The token endpoint, `POST /oauth/token`, refused the client ID and secret. */
export class OAuthError extends FinFactoryError {
  override name = "OAuthError";
  /** `invalid_client`, `invalid_request`, `unsupported_grant_type` or `rate_limited`. */
  readonly error: string;
  readonly statusCode: number;
  readonly requestId: string | null;
  readonly headers: Headers;

  constructor(statusCode: number, error: string, description: string, headers: Headers) {
    super(description || error);
    this.statusCode = statusCode;
    this.error = error;
    this.headers = headers;
    this.requestId = headers.get("x-request-id");
  }
}

/** No answer was received: a network failure or a timeout. For a write, whether it took effect is not known. */
export class ConnectionError extends FinFactoryError {
  override name = "ConnectionError";
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
  }
}

/** The request took longer than `timeout`. */
export class TimeoutError extends ConnectionError {
  override name = "TimeoutError";
}

/** A webhook's `Webhook-Signature` did not verify, or its timestamp is outside the tolerance. */
export class WebhookSignatureError extends FinFactoryError {
  override name = "WebhookSignatureError";
}

const ERROR_CLASSES: Record<string, typeof ApiError> = {
  validation_failure: ValidationError,
  authentication_failure: AuthenticationError,
  authorization_failure: AuthorizationError,
  not_found: NotFoundError,
  conflict: ConflictError,
  timeout_or_in_progress: InProgressError,
  policy_rejection: PolicyRejectionError,
  invariant_violation: InvariantViolationError,
  rate_limited: RateLimitError,
  dependency_failure: DependencyFailureError,
};

const STATUS_TYPES: Record<number, ErrorType> = {
  400: "validation_failure",
  401: "authentication_failure",
  403: "authorization_failure",
  404: "not_found",
  409: "conflict",
  422: "policy_rejection",
  429: "rate_limited",
  503: "dependency_failure",
};

/** Build the right `ApiError` subclass from a refused response. */
export function apiErrorFrom(statusCode: number, headers: Headers, body: unknown): ApiError {
  const envelope = (body as ErrorBody | null)?.error;
  const type: ErrorType =
    envelope?.type ?? STATUS_TYPES[statusCode] ?? (statusCode >= 500 ? "dependency_failure" : "unknown");
  const Cls = ERROR_CLASSES[type] ?? ApiError;
  return new Cls({
    statusCode,
    type,
    code: envelope?.code ?? "unknown",
    message: envelope?.message ?? `The API answered HTTP ${statusCode}.`,
    param: envelope?.param ?? null,
    requestId: envelope?.request_id ?? headers.get("x-request-id"),
    headers,
    body,
  });
}

export function parseRetryAfter(value: string | null): number | null {
  if (!value) return null;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return Math.max(0, seconds);
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, (date - Date.now()) / 1000);
}
