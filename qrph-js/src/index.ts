/**
 * Draw a QR Ph code as SVG, with the QR Ph logo in the centre.
 *
 *   import { qrphSvg } from "@domdanao/finfactory-qrph";
 *   const svg = qrphSvg(payload, { logo: "/img/qrph-logo.png" });
 *
 * Works with any QR Ph payload: from FinFactory, or from any other provider that gives you the
 * payload text. Needs no API key and makes no network calls, so it runs on a server or in a
 * browser.
 */
import qrcode from "qrcode-generator";

export const VERSION = "0.1.0";

/** An image for the middle of the code. */
export type QrphLogo =
  /** A URL or `data:` URI, used as the image's `href` as it is. */
  | string
  /** The image's bytes and media type (`image/png`), embedded in the SVG as a `data:` URI. */
  | { data: Uint8Array | ArrayBuffer; type: string };

export interface QrphSvgOptions {
  /**
   * The QR Ph logo, drawn in the middle. Merchant (P2M) QR Ph codes carry it there, per the BSP's
   * QR Ph rebranding. This package doesn't ship the logo: it's the BSP's mark, not ours to
   * license. Without one, the code is drawn whole.
   */
  logo?: QrphLogo;
  /** Light border around the code, in modules. Default 4, what the QR standard asks for. */
  margin?: number;
  /** Colour of the dark modules. Default `#000`. Keep it dark on a light background. */
  foreground?: string;
  /** Colour of the background. Default `#fff`. */
  background?: string;
  /** Accessible name of the image. Default `"QR Ph code"`. */
  title?: string;
}

/**
 * A FinFactory payment intent, or anything shaped like one: the payload is read from
 * `next_action.qrph_display_qr_code.data`. No dependency on the FinFactory SDK.
 */
export interface QrphPaymentIntentLike {
  next_action?: { type?: string; qrph_display_qr_code?: { data?: string | null } | null } | null;
}

/**
 * The QR Ph code as an `<svg>` element, ready to put in your page. Pass the QR payload, or a
 * FinFactory payment intent (null is returned when it isn't waiting for a QR Ph payment).
 *
 * The SVG scales to whatever size you give it; show it at 250px or more across. Error
 * correction is level H, so scanners read the code past the logo.
 */
export function qrphSvg(source: string | QrphPaymentIntentLike, options: QrphSvgOptions = {}): string | null {
  const data = typeof source === "string" ? source : payloadOf(source);
  if (data === null) return null;
  if (data === "") throw new TypeError("finfactory-qrph: qrphSvg needs a QR payload; got an empty string.");

  const margin = options.margin ?? 4;
  if (!Number.isInteger(margin) || margin < 0) {
    throw new TypeError("finfactory-qrph: qrphSvg's margin must be a whole number of modules, 0 or more.");
  }

  const qr = qrcode(0, "H");
  qr.addData(data, "Byte");
  qr.make();
  const n = qr.getModuleCount();

  const href = options.logo === undefined ? null : logoHref(options.logo);
  const hole = href === null ? 0 : logoModules(n);
  const from = (n - hole) / 2;
  const to = from + hole;
  const inHole = (r: number, c: number) => r >= from && r < to && c >= from && c < to;

  // One path; each row's runs of dark modules become one rectangle each.
  let d = "";
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; ) {
      if (!qr.isDark(r, c) || inHole(r, c)) {
        c++;
        continue;
      }
      const start = c;
      while (c < n && qr.isDark(r, c) && !inHole(r, c)) c++;
      d += `M${start + margin} ${r + margin}h${c - start}v1h-${c - start}z`;
    }
  }

  const size = n + 2 * margin;
  const image = href === null
    ? ""
    // One module inside the cleared square, leaving a light border around the logo.
    : `<image x="${from + margin + 1}" y="${from + margin + 1}" width="${hole - 2}" height="${hole - 2}" href="${attr(href)}"/>`;
  const title = attr(options.title ?? "QR Ph code");

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges" role="img" aria-label="${title}">`
    + `<title>${title}</title>`
    + `<rect width="${size}" height="${size}" fill="${attr(options.background ?? "#fff")}"/>`
    + `<path d="${d}" fill="${attr(options.foreground ?? "#000")}"/>`
    + image
    + `</svg>`;
}

/** Side of the cleared middle square, in modules: about a quarter of the code, odd so it centers. */
export function logoModules(moduleCount: number): number {
  const side = Math.max(7, Math.round(moduleCount * 0.25));
  return side % 2 === 1 ? side : side - 1;
}

function payloadOf(intent: QrphPaymentIntentLike): string | null {
  const action = intent?.next_action;
  return action?.type === "qrph_display_qr_code" ? action.qrph_display_qr_code?.data ?? null : null;
}

function logoHref(logo: QrphLogo): string {
  if (typeof logo === "string") {
    if (logo === "") throw new TypeError("finfactory-qrph: qrphSvg's logo is an empty string.");
    return logo;
  }
  const ok = logo && typeof logo === "object" && typeof logo.type === "string"
    && (logo.data instanceof Uint8Array || logo.data instanceof ArrayBuffer);
  const bytes = ok ? (logo.data instanceof Uint8Array ? logo.data : new Uint8Array(logo.data)) : null;
  if (bytes === null || bytes.length === 0) {
    throw new TypeError('finfactory-qrph: qrphSvg\'s logo must be a URL, a data: URI, or { data: bytes, type: "image/png" }.');
  }
  let binary = "";
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  }
  return `data:${logo.type};base64,${btoa(binary)}`;
}

function attr(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}
