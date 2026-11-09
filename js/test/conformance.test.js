// The shared cases in spec/conformance/helpers.json, which every FinFactory SDK passes.
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import * as sdk from "../dist/index.js";

const cases = JSON.parse(readFileSync(new URL("../../spec/conformance/helpers.json", import.meta.url), "utf8"));

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
