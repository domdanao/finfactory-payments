import { WebhookSignatureError } from "./errors.js";
import type { WebhookEvent } from "./types.js";

export interface VerifyOptions {
  /** Reject a signature whose `t` is further than this from now, in seconds. Default 300. `0` turns the check off. */
  tolerance?: number;
  /** "Now", as a Unix timestamp in seconds. For tests. */
  now?: number;
}

/** The raw request body: exactly the bytes that arrived, before any JSON parsing. */
export type RawBody = string | Uint8Array | ArrayBuffer;

const encoder = new TextEncoder();
const decoder = new TextDecoder();

function toBytes(body: RawBody): Uint8Array {
  if (typeof body === "string") return encoder.encode(body);
  if (body instanceof Uint8Array) return body;
  if (body instanceof ArrayBuffer) return new Uint8Array(body);
  throw new TypeError(
    "Webhooks: pass the raw request body as a string, Buffer or Uint8Array, not a parsed object. " +
      "Verify before you parse: re-serialized JSON does not match the signature.",
  );
}

function parseHeader(header: string): { t: number | null; signatures: string[] } {
  let t: number | null = null;
  const signatures: string[] = [];
  for (const part of header.split(",")) {
    const i = part.indexOf("=");
    if (i < 0) continue;
    const key = part.slice(0, i).trim();
    const value = part.slice(i + 1).trim();
    if (key === "t" && /^\d+$/.test(value)) t = Number(value);
    else if (key === "v1") signatures.push(value.toLowerCase());
  }
  return { t, signatures };
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

async function hmacSha256Hex(secret: string, message: Uint8Array): Promise<string> {
  const subtle = globalThis.crypto?.subtle;
  if (!subtle) throw new WebhookSignatureError("Webhooks: Web Crypto (crypto.subtle) is not available in this runtime.");
  const key = await subtle.importKey("raw", encoder.encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const mac = new Uint8Array(await subtle.sign("HMAC", key, message as Uint8Array<ArrayBuffer>));
  let hex = "";
  for (const byte of mac) hex += byte.toString(16).padStart(2, "0");
  return hex;
}

function concat(a: Uint8Array, b: Uint8Array): Uint8Array {
  const out = new Uint8Array(a.length + b.length);
  out.set(a, 0);
  out.set(b, a.length);
  return out;
}

/**
 * Verifies webhook deliveries. Works in Node.js, Deno, Bun, Cloudflare Workers and any runtime
 * with Web Crypto. Every method is static; `client.webhooks` is the same object.
 */
export const Webhooks = {
  /**
   * Check a delivery's `Webhook-Signature` header against its raw body. Throws
   * `WebhookSignatureError` with the reason when it does not verify.
   *
   * `secret` may be a list: during a rotation, pass both the new and the old secret.
   */
  async verify(payload: RawBody, signatureHeader: string | null | undefined, secret: string | string[], options: VerifyOptions = {}): Promise<void> {
    const secrets = (Array.isArray(secret) ? secret : [secret]).filter(Boolean);
    if (secrets.length === 0) throw new WebhookSignatureError("No webhook signing secret given.");
    if (!signatureHeader) throw new WebhookSignatureError("No Webhook-Signature header.");

    const { t, signatures } = parseHeader(signatureHeader);
    if (t === null || signatures.length === 0) {
      throw new WebhookSignatureError("The Webhook-Signature header is not shaped like t=<timestamp>,v1=<signature>.");
    }

    const tolerance = options.tolerance ?? 300;
    const now = options.now ?? Math.floor(Date.now() / 1000);
    if (tolerance > 0 && Math.abs(now - t) > tolerance) {
      throw new WebhookSignatureError(`The signature's timestamp is more than ${tolerance} seconds from now.`);
    }

    const signed = concat(encoder.encode(`${t}.`), toBytes(payload));
    for (const s of secrets) {
      const expected = await hmacSha256Hex(s, signed);
      if (signatures.some((sig) => timingSafeEqual(expected, sig))) return;
    }
    throw new WebhookSignatureError("No signature matches the body and secret. Check you pass the raw body and this endpoint's secret.");
  },

  /** Like `verify`, but answers `true` or `false` instead of throwing. */
  async isValid(payload: RawBody, signatureHeader: string | null | undefined, secret: string | string[], options: VerifyOptions = {}): Promise<boolean> {
    try {
      await Webhooks.verify(payload, signatureHeader, secret, options);
      return true;
    } catch (e) {
      if (e instanceof WebhookSignatureError) return false;
      throw e;
    }
  },

  /**
   * Verify a delivery, then parse it. Narrow on `event.type`:
   *
   * ```ts
   * const event = await Webhooks.constructEvent(rawBody, req.headers["webhook-signature"], secret);
   * if (event.type === "payment_intent.succeeded") fulfil(event.data.object.id);
   * ```
   */
  async constructEvent(payload: RawBody, signatureHeader: string | null | undefined, secret: string | string[], options: VerifyOptions = {}): Promise<WebhookEvent> {
    await Webhooks.verify(payload, signatureHeader, secret, options);
    const text = typeof payload === "string" ? payload : decoder.decode(toBytes(payload));
    return JSON.parse(text) as WebhookEvent;
  },

  /**
   * Make a `Webhook-Signature` header for a body. For your own tests only: the platform signs real
   * deliveries.
   */
  async generateTestHeader(payload: RawBody, secret: string, timestamp: number = Math.floor(Date.now() / 1000)): Promise<string> {
    const signature = await hmacSha256Hex(secret, concat(encoder.encode(`${timestamp}.`), toBytes(payload)));
    return `t=${timestamp},v1=${signature}`;
  },
};
