import {
  ApiError,
  ConnectionError,
  FinFactoryError,
  OAuthError,
  TimeoutError,
  apiErrorFrom,
  parseRetryAfter,
} from "./errors.js";
import {
  AccessTokens,
  Disputes,
  PaymentIntents,
  PaymentMethods,
  Refunds,
  TestHelpers,
  WebhookEndpoints,
} from "./resources.js";
import type { AccessToken, List, ResponseInfo, WithResponse } from "./types.js";
import { Webhooks } from "./webhooks.js";

export const VERSION = "0.1.0";
export const DEFAULT_BASE_URL = "https://api.payments.finfactory.dev";

/** Where Access Tokens are kept between requests. Defaults to memory, for the life of the client. */
export interface TokenCache {
  get(key: string): Promise<CachedToken | null | undefined> | CachedToken | null | undefined;
  set(key: string, token: CachedToken): Promise<void> | void;
  delete?(key: string): Promise<void> | void;
}

export interface CachedToken {
  accessToken: string;
  /** When it expires, in milliseconds since the epoch. */
  expiresAt: number;
}

export interface ClientOptions {
  /** `ci_test_...` or `ci_live_...`. Defaults to the `FINFACTORY_CLIENT_ID` environment variable. */
  clientId?: string;
  /** `sk_test_...` or `sk_live_...`. Defaults to the `FINFACTORY_CLIENT_SECRET` environment variable. */
  clientSecret?: string;
  /**
   * An Access Token you manage yourself, or a function that returns one, instead of a client ID and
   * secret. The SDK then never calls `/oauth/token`.
   */
  accessToken?: string | (() => string | Promise<string>);
  /** Your platform's API host. Defaults to `FINFACTORY_BASE_URL`, then `https://api.payments.finfactory.dev`. */
  baseUrl?: string;
  /** Per-attempt timeout in milliseconds. Default 30000. */
  timeout?: number;
  /** How many times a failed request that is safe to retry is retried. Default 2. */
  maxRetries?: number;
  /** A `fetch` to use instead of the global one. */
  fetch?: typeof fetch;
  /** Keep Access Tokens across processes (e.g. in Redis), so each process need not fetch its own. */
  tokenCache?: TokenCache;
  /** Headers sent on every request. */
  defaultHeaders?: Record<string, string>;
  /**
   * This SDK holds your client secret, which must never reach a browser. It refuses to run in one
   * unless you set this, for example in a test harness.
   */
  dangerouslyAllowBrowser?: boolean;
}

/** Options every call takes, as its last argument. */
export interface RequestOptions {
  /**
   * The `Idempotency-Key` for a create, confirm, cancel, simulate or refund. Use a value unique to
   * the operation, such as your order number and attempt. When left out, the SDK makes a random one
   * and reuses it on its own retries.
   */
  idempotencyKey?: string;
  /** Overrides the client's `timeout` for this call. */
  timeout?: number;
  /** Overrides the client's `maxRetries` for this call. */
  maxRetries?: number;
  /** Cancel the call. */
  signal?: AbortSignal;
  /** Extra headers for this call. */
  headers?: Record<string, string>;
}

export interface RequestSpec {
  method: "GET" | "POST" | "DELETE" | "PATCH" | "PUT";
  path: string;
  query?: Record<string, unknown>;
  body?: unknown;
  /** The operation takes an `Idempotency-Key` header. */
  idempotent?: boolean;
  /**
   * Whether the call may be sent again after a network failure or a 5xx. False for a call that is
   * neither keyed nor safe to repeat, such as creating a webhook endpoint.
   */
  retryable?: boolean;
}

function env(name: string): string | undefined {
  const proc = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process;
  return proc?.env?.[name] || undefined;
}

function isBrowser(): boolean {
  const g = globalThis as { window?: unknown; document?: unknown };
  return typeof g.window !== "undefined" && typeof g.document !== "undefined";
}

function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) return reject(signal.reason);
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", onAbort);
      resolve();
    }, ms);
    const onAbort = () => {
      clearTimeout(timer);
      reject(signal!.reason);
    };
    signal?.addEventListener("abort", onAbort, { once: true });
  });
}

/** Exponential backoff with full jitter: about 0.5s, 1s, 2s ... up to 8s. */
function backoff(attempt: number): number {
  const base = Math.min(8000, 500 * 2 ** attempt);
  return base / 2 + Math.random() * (base / 2);
}

const MAX_RETRY_AFTER_SECONDS = 60;
const TOKEN_REFRESH_MARGIN_MS = 60_000;

class MemoryTokenCache implements TokenCache {
  private store = new Map<string, CachedToken>();
  get(key: string) {
    return this.store.get(key);
  }
  set(key: string, token: CachedToken) {
    this.store.set(key, token);
  }
  delete(key: string) {
    this.store.delete(key);
  }
}

/**
 * The FinFactory Payments Merchant API client.
 *
 * ```ts
 * const finfactory = new FinFactory({ clientId: "ci_test_...", clientSecret: "sk_test_..." });
 * const intent = await finfactory.paymentIntents.create({ amount: 10000, currency: "php" });
 * ```
 */
export class FinFactory {
  readonly baseUrl: string;
  readonly timeout: number;
  readonly maxRetries: number;

  readonly paymentIntents: PaymentIntents;
  readonly paymentMethods: PaymentMethods;
  readonly refunds: Refunds;
  readonly disputes: Disputes;
  readonly webhookEndpoints: WebhookEndpoints;
  readonly testHelpers: TestHelpers;
  readonly accessTokens: AccessTokens;
  /** Verify webhook deliveries. The same as the `Webhooks` export. */
  readonly webhooks = Webhooks;

  private readonly clientId: string | undefined;
  private readonly clientSecret: string | undefined;
  private readonly staticToken: ClientOptions["accessToken"];
  private readonly fetchImpl: typeof fetch;
  private readonly tokenCache: TokenCache;
  private readonly defaultHeaders: Record<string, string>;
  private pendingToken: Promise<string> | null = null;

  constructor(options: ClientOptions = {}) {
    this.clientId = options.clientId ?? env("FINFACTORY_CLIENT_ID");
    this.clientSecret = options.clientSecret ?? env("FINFACTORY_CLIENT_SECRET");
    this.staticToken = options.accessToken;

    if (!this.staticToken && (!this.clientId || !this.clientSecret)) {
      throw new FinFactoryError(
        "FinFactory: give a clientId and clientSecret (or set FINFACTORY_CLIENT_ID and FINFACTORY_CLIENT_SECRET), or an accessToken.",
      );
    }
    if (this.clientSecret && isBrowser() && !options.dangerouslyAllowBrowser) {
      throw new FinFactoryError(
        "FinFactory: this SDK is for your server. Your client secret must never be sent to a browser. " +
          "In the browser, use embedded checkout with a publishable key and a client token instead.",
      );
    }

    const fetchImpl = options.fetch ?? globalThis.fetch;
    if (typeof fetchImpl !== "function") {
      throw new FinFactoryError("FinFactory: no fetch() in this runtime. Use Node.js 20 or later, or pass options.fetch.");
    }
    this.fetchImpl = fetchImpl.bind(globalThis);
    this.baseUrl = (options.baseUrl ?? env("FINFACTORY_BASE_URL") ?? DEFAULT_BASE_URL).replace(/\/+$/, "");
    this.timeout = options.timeout ?? 30_000;
    this.maxRetries = options.maxRetries ?? 2;
    this.tokenCache = options.tokenCache ?? new MemoryTokenCache();
    this.defaultHeaders = options.defaultHeaders ?? {};

    this.paymentIntents = new PaymentIntents(this);
    this.paymentMethods = new PaymentMethods(this);
    this.refunds = new Refunds(this);
    this.disputes = new Disputes(this);
    this.webhookEndpoints = new WebhookEndpoints(this);
    this.testHelpers = new TestHelpers(this);
    this.accessTokens = new AccessTokens(this);
  }

  /** True for a live key (`ci_live_...`), false for a test key, null when unknown. */
  get livemode(): boolean | null {
    if (this.clientId?.startsWith("ci_live_")) return true;
    if (this.clientId?.startsWith("ci_test_")) return false;
    return null;
  }

  // -------------------------------------------------------------------------------------------
  // Access Tokens
  // -------------------------------------------------------------------------------------------

  private get tokenCacheKey(): string {
    return `finfactory:access_token:${this.baseUrl}:${this.clientId}`;
  }

  /**
   * An Access Token for the next call: the cached one while it has more than a minute left, else
   * a new one. Concurrent calls share a single token request.
   */
  async getAccessToken(): Promise<string> {
    if (this.staticToken) {
      return typeof this.staticToken === "function" ? await this.staticToken() : this.staticToken;
    }
    const cached = await this.tokenCache.get(this.tokenCacheKey);
    if (cached && cached.expiresAt - TOKEN_REFRESH_MARGIN_MS > Date.now()) return cached.accessToken;

    this.pendingToken ??= (async () => {
      try {
        const token = await this.requestAccessToken();
        await this.tokenCache.set(this.tokenCacheKey, {
          accessToken: token.access_token,
          expiresAt: Date.now() + token.expires_in * 1000,
        });
        return token.access_token;
      } finally {
        this.pendingToken = null;
      }
    })();
    return this.pendingToken;
  }

  /** Forget the cached token, so the next call fetches a new one. */
  async clearAccessToken(): Promise<void> {
    await this.tokenCache.delete?.(this.tokenCacheKey);
  }

  /** `POST /oauth/token`: exchange the client ID and secret for a new Access Token. Not cached. */
  async requestAccessToken(options: RequestOptions = {}): Promise<WithResponse<AccessToken>> {
    if (!this.clientId || !this.clientSecret) {
      throw new FinFactoryError("FinFactory: a clientId and clientSecret are needed to get an Access Token.");
    }
    const basic = btoa(`${this.clientId}:${this.clientSecret}`);
    const { response, body } = await this.send(
      "POST",
      `${this.baseUrl}/oauth/token`,
      async () => ({
        Authorization: `Basic ${basic}`,
        "Content-Type": "application/x-www-form-urlencoded",
      }),
      "grant_type=client_credentials",
      { retryable: true, oauth: true, ...options },
    );
    return withResponse(body as AccessToken, response, null);
  }

  // -------------------------------------------------------------------------------------------
  // Requests
  // -------------------------------------------------------------------------------------------

  /**
   * Send any request to the API, authenticated, with retries. The resources use this; you can too,
   * for an operation this SDK does not have a method for yet.
   */
  async request<T>(spec: RequestSpec, options: RequestOptions = {}): Promise<WithResponse<T>> {
    const url = new URL(this.baseUrl + spec.path);
    for (const [key, value] of Object.entries(spec.query ?? {})) {
      if (value !== undefined && value !== null) url.searchParams.set(key, String(value));
    }

    const idempotencyKey = spec.idempotent ? (options.idempotencyKey ?? globalThis.crypto.randomUUID()) : null;
    const hasBody = spec.body !== undefined;

    const { response, body } = await this.send(
      spec.method,
      url.toString(),
      async () => {
        const headers: Record<string, string> = { Authorization: `Bearer ${await this.getAccessToken()}` };
        if (hasBody) headers["Content-Type"] = "application/json";
        if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
        return headers;
      },
      hasBody ? JSON.stringify(spec.body) : undefined,
      { retryable: spec.retryable ?? true, ...options },
    );
    return withResponse(body as T, response, idempotencyKey);
  }

  /** Page through a list, one object at a time, fetching the next page as needed. */
  async *paginate<T>(path: string, params: Record<string, unknown> = {}, options: RequestOptions = {}): AsyncGenerator<T, void, undefined> {
    let cursor = params.cursor as string | undefined;
    for (;;) {
      const page = await this.request<List<T>>({ method: "GET", path, query: { ...params, cursor } }, options);
      for (const item of page.data) yield item;
      if (!page.has_more || !page.next_cursor) return;
      cursor = page.next_cursor;
    }
  }

  private async send(
    method: string,
    url: string,
    headersFor: () => Promise<Record<string, string>>,
    body: string | undefined,
    options: RequestOptions & { retryable: boolean; oauth?: boolean },
  ): Promise<{ response: Response; body: unknown }> {
    const maxRetries = options.maxRetries ?? this.maxRetries;
    const timeout = options.timeout ?? this.timeout;
    let refreshedToken = false;

    for (let attempt = 0; ; attempt++) {
      const headers = {
        Accept: "application/json",
        "User-Agent": `finfactory-payments-js/${VERSION}`,
        ...this.defaultHeaders,
        ...(await headersFor()),
        ...options.headers,
      };

      let response: Response;
      let text: string;
      try {
        ({ response, text } = await this.fetchWithTimeout(url, { method, headers, body }, timeout, options.signal));
      } catch (error) {
        if (options.signal?.aborted) throw error;
        if (options.retryable && attempt < maxRetries) {
          await sleep(backoff(attempt), options.signal);
          continue;
        }
        throw error;
      }

      const parsed = parseJson(text);
      if (response.ok) return { response, body: parsed };

      if (options.oauth) {
        const err = parsed as { error?: string; error_description?: string } | null;
        const retry = attempt < maxRetries && (response.status === 429 || response.status >= 500);
        const delay = retryDelay(response, attempt);
        if (retry && delay !== null) {
          await sleep(delay, options.signal);
          continue;
        }
        throw new OAuthError(response.status, err?.error ?? "unknown", err?.error_description ?? "", response.headers);
      }

      const error = apiErrorFrom(response.status, response.headers, parsed);

      // An expired or revoked token: get a new one and send the call again, once.
      if (response.status === 401 && error.code === "invalid_access_token" && !this.staticToken && !refreshedToken) {
        refreshedToken = true;
        await this.clearAccessToken();
        attempt--;
        continue;
      }

      if (attempt < maxRetries && shouldRetry(error, options.retryable)) {
        const delay = retryDelay(response, attempt);
        if (delay !== null) {
          await sleep(delay, options.signal);
          continue;
        }
      }
      throw error;
    }
  }

  private async fetchWithTimeout(
    url: string,
    init: RequestInit,
    timeout: number,
    signal: AbortSignal | undefined,
  ): Promise<{ response: Response; text: string }> {
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeout);
    const onAbort = () => controller.abort(signal!.reason);
    if (signal?.aborted) controller.abort(signal.reason);
    signal?.addEventListener("abort", onAbort, { once: true });

    try {
      const response = await this.fetchImpl(url, { ...init, signal: controller.signal });
      const text = await response.text();
      return { response, text };
    } catch (cause) {
      if (signal?.aborted) throw signal.reason ?? cause;
      if (timedOut) throw new TimeoutError(`Request timed out after ${timeout} ms: ${init.method} ${url}`, { cause });
      throw new ConnectionError(`Could not reach the API: ${init.method} ${url}: ${(cause as Error)?.message ?? cause}`, { cause });
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener("abort", onAbort);
    }
  }
}

function shouldRetry(error: ApiError, retryable: boolean): boolean {
  // Refused before any work was done: always safe to send again.
  if (error.statusCode === 429) return true;
  if (!retryable) return false;
  if (error.statusCode >= 500) return true;
  // The first request with this Idempotency-Key is still running.
  return error.statusCode === 409 && error.code === "idempotency_key_in_use";
}

/** Milliseconds to wait before the next attempt, or null when `Retry-After` asks for too long. */
function retryDelay(response: Response, attempt: number): number | null {
  const retryAfter = parseRetryAfter(response.headers.get("retry-after"));
  if (retryAfter === null) return backoff(attempt);
  if (retryAfter > MAX_RETRY_AFTER_SECONDS) return null;
  return retryAfter * 1000;
}

function parseJson(text: string): unknown {
  if (!text) return {};
  try {
    return JSON.parse(text);
  } catch {
    return { raw: text };
  }
}

function withResponse<T>(body: T, response: Response, idempotencyKey: string | null): WithResponse<T> {
  const info: ResponseInfo = {
    statusCode: response.status,
    headers: response.headers,
    requestId: response.headers.get("x-request-id"),
    idempotentReplayed: response.headers.get("idempotent-replayed") === "true",
    idempotencyKey,
  };
  const target = (body !== null && typeof body === "object" ? body : {}) as object;
  Object.defineProperty(target, "lastResponse", { value: info, enumerable: false, configurable: true });
  return target as WithResponse<T>;
}
