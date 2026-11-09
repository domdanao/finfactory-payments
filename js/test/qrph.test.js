import { test } from "node:test";
import assert from "node:assert/strict";
import qrcode from "qrcode-generator";
import { qrphSvg, logoModules } from "../dist/qrph.js";
import { qrphData, qrphExpiresAt } from "../dist/index.js";

// Shaped like a real QR Ph payload (EMVCo), 197 characters.
const PAYLOAD =
  "00020101021228760011ph.ppmi.p2m0111ABCDPHM1XXX03150000123456789010415ABCDEF1234567890503031520400005303608540425005802PH5913ACACIA STORE 6011MANDALUYONG62310010ph.ppmi.p2m0513REF01A1BF6674A6304ABCD";

const intent = (next_action) => ({
  id: "pi_1",
  object: "payment_intent",
  amount: 2500,
  amount_received: 0,
  currency: "php",
  payment_method_types: ["qrph"],
  status: "requires_action",
  next_action,
});
const QR_INTENT = intent({ type: "qrph_display_qr_code", qrph_display_qr_code: { data: PAYLOAD, expires_at: 1794214000 } });

/** Dark modules drawn by the SVG's path, as a set of "x,y" in SVG units. */
function drawn(svg) {
  const d = svg.match(/<path d="([^"]*)"/)[1];
  const cells = new Set();
  for (const [, x, y, w] of d.matchAll(/M(\d+) (\d+)h(\d+)v1h-\d+z/g)) {
    for (let i = 0; i < Number(w); i++) cells.add(`${Number(x) + i},${y}`);
  }
  return cells;
}

function matrix(data) {
  const qr = qrcode(0, "H");
  qr.addData(data, "Byte");
  qr.make();
  return qr;
}

test("draws every dark module of a level-H code, with the margin", () => {
  const svg = qrphSvg(PAYLOAD);
  const qr = matrix(PAYLOAD);
  const n = qr.getModuleCount();
  assert.match(svg, new RegExp(`viewBox="0 0 ${n + 8} ${n + 8}"`));
  const cells = drawn(svg);
  let dark = 0;
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      assert.equal(cells.has(`${c + 4},${r + 4}`), qr.isDark(r, c), `module ${r},${c}`);
      if (qr.isDark(r, c)) dark++;
    }
  }
  assert.equal(cells.size, dark);
  assert.doesNotMatch(svg, /<image/);
});

test("takes the payload from a payment intent, or gives null", () => {
  assert.equal(qrphSvg(QR_INTENT), qrphSvg(PAYLOAD));
  assert.equal(qrphData(QR_INTENT), PAYLOAD);
  assert.equal(qrphExpiresAt(QR_INTENT), 1794214000);
  const card = intent({ type: "redirect_to_url", redirect_to_url: { url: "https://example.test" } });
  assert.equal(qrphSvg(card), null);
  assert.equal(qrphData(card), null);
  assert.equal(qrphSvg(intent(null)), null);
});

test("clears a centered square for the logo and draws it one module inside", () => {
  const svg = qrphSvg(PAYLOAD, { logo: "/img/qrph-logo.png", margin: 2 });
  const n = matrix(PAYLOAD).getModuleCount();
  const hole = logoModules(n);
  assert.equal(hole % 2, 1);
  const from = (n - hole) / 2;
  for (const cell of drawn(svg)) {
    const [x, y] = cell.split(",").map((v) => Number(v) - 2);
    assert.ok(!(x >= from && x < from + hole && y >= from && y < from + hole), `module ${cell} is under the logo`);
  }
  assert.match(svg, new RegExp(`<image x="${from + 3}" y="${from + 3}" width="${hole - 2}" height="${hole - 2}" href="/img/qrph-logo.png"/>`));
});

test("the cleared square stays near a quarter of the code's width", () => {
  assert.equal(logoModules(21), 7);
  assert.equal(logoModules(37), 9);
  assert.equal(logoModules(77), 19);
  assert.equal(logoModules(177), 43);
});

test("embeds logo bytes as a data URI", () => {
  const bytes = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0, 255]);
  const svg = qrphSvg(PAYLOAD, { logo: { data: bytes, type: "image/png" } });
  assert.match(svg, /href="data:image\/png;base64,iVBORwD\/"/);
  assert.equal(qrphSvg(PAYLOAD, { logo: { data: bytes.buffer, type: "image/png" } }), svg);
});

test("escapes what goes into attributes", () => {
  const svg = qrphSvg(PAYLOAD, { logo: 'x"><script>', title: "A & B", foreground: "#123", background: "red" });
  assert.match(svg, /href="x&quot;&gt;&lt;script&gt;"/);
  assert.match(svg, /aria-label="A &amp; B"><title>A &amp; B<\/title>/);
  assert.match(svg, /fill="red"/);
  assert.match(svg, /fill="#123"/);
  assert.doesNotMatch(svg, /<script/);
});

test("refuses an empty payload or a bad margin", () => {
  assert.throws(() => qrphSvg(""), TypeError);
  assert.throws(() => qrphSvg(PAYLOAD, { margin: -1 }), TypeError);
  assert.throws(() => qrphSvg(PAYLOAD, { margin: 1.5 }), TypeError);
  assert.throws(() => qrphSvg(PAYLOAD, { logo: "" }), TypeError);
});

test("the main entry point doesn't load the QR library", async () => {
  const { readFile } = await import("node:fs/promises");
  for (const file of ["index.js", "client.js", "resources.js", "types.js", "webhooks.js", "errors.js"]) {
    assert.doesNotMatch(await readFile(new URL(`../dist/${file}`, import.meta.url), "utf8"), /qrcode-generator|qrph\.js/, file);
  }
});
