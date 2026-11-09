// The shared cases in spec/conformance/helpers.json, which every FinFactory SDK passes.
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import * as sdk from "../dist/index.js";
import { qrphSvg, logoModules } from "../dist/qrph.js";

const cases = JSON.parse(readFileSync(new URL("../../spec/conformance/helpers.json", import.meta.url), "utf8"));
const bytes = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
const logoOption = (logo) => (logo && typeof logo === "object" ? { ...(logo.type ? { type: logo.type } : {}), data: bytes(logo.data_base64) } : logo);

for (const c of cases.payment_intents) {
  test(`payment intent helpers: ${c.name}`, () => {
    for (const [helper, expected] of Object.entries(c.expect)) {
      assert.equal(sdk[helper](c.intent), expected, helper);
    }
  });
}

test("list helpers", () => {
  for (const c of cases.lists) {
    assert.equal(sdk.hasMore(c.page), c.expect.hasMore);
    assert.equal(sdk.nextCursor(c.page), c.expect.nextCursor);
  }
});

test("event helpers", () => {
  for (const c of cases.events) assert.equal(sdk.dataObject(c.event).id, c.expect.dataObjectId);
});

test("qrphSvg: size, logo square, embedded logo, and refusals", () => {
  const q = cases.qrph_svg;
  assert.match(qrphSvg(q.payload), new RegExp(`viewBox="0 0 ${q.expect_viewbox_size_margin_4} ${q.expect_viewbox_size_margin_4}"`));
  for (const [n, side] of Object.entries(q.logo_modules)) assert.equal(logoModules(Number(n)), side);
  assert.match(qrphSvg(q.payload, { logo: { data: bytes(q.logo_bytes_base64), type: q.logo_type } }), new RegExp(`href="data:${q.logo_type};base64,${q.logo_bytes_base64.replace("/", "\\/")}"`));
  assert.equal(qrphSvg(cases.payment_intents.find((c) => c.name === "succeeded").intent), null);
  assert.throws(() => qrphSvg(""), TypeError);
  for (const bad of q.bad_options) {
    const options = { ...bad.options, ...("logo" in bad.options ? { logo: logoOption(bad.options.logo) } : {}) };
    assert.throws(() => qrphSvg(q.payload, options), TypeError, bad.name);
  }
});
