/** Source inventory and fail-closed licence decisions; fixture results are never release evidence. */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { evaluate, lockedComponents } from "./source-sbom.mjs";
const json = (path) => JSON.parse(readFileSync(path));
const policy = json("resources/release/license-policy.json");

test("all locked production, development and optional-platform dependencies enter the inventory", () => {
  const composer = json("composer.lock");
  const npm = json("package-lock.json");
  const components = lockedComponents(composer, npm);
  assert.equal(components.length, composer.packages.length + composer["packages-dev"].length
    + Object.keys(npm.packages).length - 1);
  assert.ok(components.some((entry) => entry["bom-ref"].startsWith("composer:packages-dev:")));
  assert.ok(components.some((entry) => entry.properties.some((p) => p.name === "kumwe:optional" && p.value === "true")));
  assert.ok(evaluate({ bomFormat: "CycloneDX", components }, policy).every((entry) => entry.result === "allowed"));
});

test("missing, unknown, compound and partially allowed licences never silently qualify", () => {
  for (const licenses of [[], [{ license: { name: "MIT" } }], [{ license: { id: "UNLICENSED" } }],
    [{ expression: "MIT OR GPL-3.0-only" }], [{ license: { id: "MIT" } }, { license: { id: "unknown" } }]]) {
    assert.equal(evaluate({ bomFormat: "CycloneDX", components: [{ "bom-ref": "test", licenses }] }, policy)[0].result,
      "denied");
  }
  assert.throws(() => evaluate({ bomFormat: "CycloneDX", components: [] }, policy));
  assert.throws(() => evaluate({ bomFormat: "CycloneDX", components: [{ "bom-ref": "a" }, { "bom-ref": "a" }] }, policy));
});

test("unresolved npm workspace links and incomplete distribution pins fail inventory generation", () => {
  for (const entry of [{ link: true }, { version: "1.0.0", license: "MIT" }]) {
    assert.throws(() => lockedComponents({ packages: [] }, { packages: { "node_modules/test": entry } }));
  }
});
