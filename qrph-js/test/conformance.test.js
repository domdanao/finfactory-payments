// The shared cases in spec/conformance/qrph.json, which every finfactory-qrph package passes.
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { qrphSvg, logoModules } from "../dist/index.js";

const q = JSON.parse(readFileSync(new URL("../../spec/conformance/qrph.json", import.meta.url), "utf8"));
const bytes = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
const logoOption = (logo) => (logo && typeof logo === "object" ? { ...(logo.type ? { type: logo.type } : {}), data: bytes(logo.data_base64) } : logo);

test("size, logo square, embedded logo", () => {
  const size = q.expect_viewbox_size_margin_4;
  assert.match(qrphSvg(q.payload), new RegExp(`viewBox="0 0 ${size} ${size}"`));
  for (const [n, side] of Object.entries(q.logo_modules)) assert.equal(logoModules(Number(n)), side);
  const svg = qrphSvg(q.payload, { logo: { data: bytes(q.logo_bytes_base64), type: q.logo_type } });
  assert.ok(svg.includes(`href="data:${q.logo_type};base64,${q.logo_bytes_base64}"`));
});

test("reads a payment intent, or gives null", () => {
  assert.equal(qrphSvg(q.intent), qrphSvg(q.payload));
  for (const intent of q.not_qrph_intents) assert.equal(qrphSvg(intent), null);
});

test("refuses bad input", () => {
  assert.throws(() => qrphSvg(""), TypeError);
  for (const bad of q.bad_options) {
    const options = { ...bad.options, ...("logo" in bad.options ? { logo: logoOption(bad.options.logo) } : {}) };
    assert.throws(() => qrphSvg(q.payload, options), TypeError, bad.name);
  }
});
