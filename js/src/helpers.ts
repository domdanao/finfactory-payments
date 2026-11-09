/**
 * Small readers for what the API returns, the same in every FinFactory SDK. In PHP they are
 * methods on the result (`$intent->redirectUrl()`); here, results are plain objects, so they are
 * functions (`redirectUrl(intent)`). See docs/sdk-design.md, "Helpers".
 */
import type { List, PaymentIntent, WebhookEvent } from "./types.js";

/** The hosted checkout or card page URL to send the customer to, when next_action is redirect_to_url. */
export function redirectUrl(intent: PaymentIntent): string | null {
  const a = intent.next_action;
  return a?.type === "redirect_to_url" ? (a as { redirect_to_url?: { url?: string } }).redirect_to_url?.url ?? null : null;
}

/** The embedded checkout client token (ct_...), when next_action is mount_embedded_checkout. */
export function clientToken(intent: PaymentIntent): string | null {
  const a = intent.next_action;
  return a?.type === "mount_embedded_checkout"
    ? (a as { mount_embedded_checkout?: { client_token?: string } }).mount_embedded_checkout?.client_token ?? null
    : null;
}

/** The QR payload to show, when next_action is qrph_display_qr_code. */
export function qrphData(intent: PaymentIntent): string | null {
  const a = intent.next_action;
  return a?.type === "qrph_display_qr_code"
    ? (a as { qrph_display_qr_code?: { data?: string } }).qrph_display_qr_code?.data ?? null
    : null;
}

/** When the QR code stops being payable (Unix timestamp), when next_action is qrph_display_qr_code. */
export function qrphExpiresAt(intent: PaymentIntent): number | null {
  const a = intent.next_action;
  return a?.type === "qrph_display_qr_code"
    ? (a as { qrph_display_qr_code?: { expires_at?: number | null } }).qrph_display_qr_code?.expires_at ?? null
    : null;
}

export function isSucceeded(intent: PaymentIntent): boolean {
  return intent.status === "succeeded";
}

/** The PaymentIntent, Refund or Dispute the event is about. */
export function dataObject<E extends WebhookEvent>(event: E): E["data"]["object"] | null {
  return event.data?.object ?? null;
}

export function hasMore(page: List<unknown>): boolean {
  return Boolean(page.has_more);
}

export function nextCursor(page: List<unknown>): string | null {
  return page.next_cursor ?? null;
}
