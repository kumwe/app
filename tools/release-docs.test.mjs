/** Verify reference drift is refused; these assertions do not generate release success evidence. */
import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { inventory, verifyCommands } from "./release-docs.mjs";

test("operator commands absent from the runtime inventory fail reference verification", () => {
  assert.deepEqual(verifyCommands('Run `php bin/kumwe queue:work --once` and `php bin/kumwe list`.',
    ["queue:work"], "test-guide"), ["list", "queue:work"]);
  assert.throws(() => verifyCommands('Run `php bin/kumwe retired-command`.', [], "test-guide"), /absent runtime command/);
});

test("every P7-H reference group has present runtime and guide inputs", () => {
  const model = JSON.parse(readFileSync("resources/release/documentation.json"));
  const records = inventory(model);
  assert.equal(records.length, 10);
  assert.ok(records.every((record) => record.runtime.length > 0 && record.guides.length > 0));
  const missing = structuredClone(model);
  missing.references[0].runtime = ["does-not-exist/runtime.json"];
  assert.throws(() => inventory(missing), /ENOENT/);
});
