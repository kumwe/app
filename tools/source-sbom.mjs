/**
 * Produce a complete declared-source CycloneDX inventory and fail closed on licence policy violations.
 * Syft's independent filesystem/image inventories remain enabled; they are not complete lock inventories.
 * Build tooling only. Native input must first pass tools/source-sbom.sh's release archive checksum.
 * @since 2.0.0-beta.1
 */
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const read = (path) => readFileSync(path);
const json = (path) => JSON.parse(read(path));
const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");
const write = (path, value) => writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`);
const property = (name, value) => ({ name: `kumwe:${name}`, value: String(value) });

/** Include every locked occurrence, including optional platforms and packages omitted by no-dev installs. */
export function lockedComponents(composer, npm) {
  const components = [];
  for (const [section, packages] of Object.entries(composer)) {
    if (!["packages", "packages-dev"].includes(section)) continue;
    for (const entry of packages) {
      components.push({ type: "library", "bom-ref": `composer:${section}:${entry.name}`, name: entry.name,
        version: entry.version, purl: `pkg:composer/${entry.name}@${entry.version.replace(/^v/, "")}`,
        licenses: (entry.license ?? []).map((id) => ({ license: { id } })),
        externalReferences: [entry.dist?.url && { type: "distribution", url: entry.dist.url },
          entry.source?.url && { type: "vcs", url: `${entry.source.url}#${entry.source.reference}` }].filter(Boolean),
        properties: [property("lock-section", section), property("lock-entry-sha256", sha(JSON.stringify(entry)))],
      });
    }
  }
  for (const [path, entry] of Object.entries(npm.packages)) {
    if (!path) continue;
    assert.equal(entry.link, undefined, `Unresolved npm workspace link: ${path}`);
    const name = entry.name ?? path.slice(path.lastIndexOf("node_modules/") + "node_modules/".length);
    assert.ok(entry.version && entry.resolved && entry.integrity, `Incomplete locked npm distribution: ${path}`);
    components.push({ type: "library", "bom-ref": `npm:${path}`, name, version: entry.version,
      purl: `pkg:npm/${name.replace(/^@/, "%40")}@${entry.version}`,
      licenses: entry.license ? [{ license: { id: entry.license } }] : [],
      externalReferences: [{ type: "distribution", url: entry.resolved }],
      properties: [property("lock-path", path), property("integrity", entry.integrity),
        property("development", entry.dev === true), property("optional", entry.optional === true),
        property("lock-entry-sha256", sha(JSON.stringify(entry)))],
    });
  }
  return components;
}

/** Preserve native upstream notices from the checksum-verified source, including embedded third parties. */
function nativeComponents(root, pin) {
  assert.equal(pin.binding.sha256, json("resources/release/license-policy.json").nativeSourceArchiveSha256,
    "The native source licence review must match the admitted archive.");
  const embedded = json(`${root}/resources/engine-lock.json`);
  for (const [actual, expected] of [[embedded.commit, pin.engine.commit], [embedded.version, pin.engine.version],
    [embedded.archive_sha256, pin.engine.sha256]]) assert.equal(actual, expected);
  const engineRoot = `${root}/vendor/engine`;
  // The upstream lock is the authority for every embedded byte, not a guessed package version.
  for (const [path, expected] of Object.entries(embedded.files)) assert.equal(sha(read(`${engineRoot}/${path}`)), expected);
  assert.equal(json(`${root}/composer.json`).license, "Apache-2.0");
  const pcre = json(`${engineRoot}/resources/pcre2-source.json`);
  const unicode = json(`${engineRoot}/resources/unicode-source.json`);
  assert.equal(unicode.license, "Unicode-3.0");
  const rows = [
    ["kumwe/kumwe-engine", pin.binding.version, "Apache-2.0", "LICENSE", pin.binding.url],
    ["kumwe/engine", pin.engine.version, "Apache-2.0", "vendor/engine/LICENSE", embedded.repository],
    ["pcre2", pcre.commit, "BSD-3-Clause", "vendor/engine/third_party/pcre2/LICENCE", pcre.repository],
    ["sljit", pcre.commit, "BSD-2-Clause", "vendor/engine/third_party/pcre2/src/sljit/sljitLir.h", pcre.repository],
    ["unicode", "15.1.0+17.0.0", "Unicode-3.0", "vendor/engine/third_party/unicode/LICENSE", unicode.license_source],
    ["php-filter", pin.engine.commit, "PHP-3.01", "vendor/engine/third_party/php-filter-LICENSE",
      `${embedded.repository}/blob/${embedded.commit}/third_party/php-filter-LICENSE`],
  ];
  return rows.map(([name, version, id, path, url]) => ({ type: "library", "bom-ref": `native:${name}`, name, version,
    licenses: [{ license: { id, text: { contentType: "text/plain", encoding: "base64",
      content: read(`${root}/${path}`).toString("base64") } } }],
    externalReferences: [{ type: "distribution", url: pin.binding.url }, { type: "vcs", url }],
    properties: [property("source-archive-sha256", pin.binding.sha256), property("licence-path", path),
      property("licence-sha256", sha(read(`${root}/${path}`)))],
  }));
}

/** A policy evaluation is not a success record until every complete inventory component is accepted. */
export function evaluate(bom, policy) {
  assert.equal(bom.bomFormat, "CycloneDX");
  assert.ok(bom.components?.length > 0, "The source dependency inventory is empty.");
  assert.equal(policy.kind, "kumwe-source-license-policy");
  const refs = new Set();
  return bom.components.map((component) => {
    assert.ok(component["bom-ref"] && !refs.has(component["bom-ref"]), "Missing or duplicate component identity.");
    refs.add(component["bom-ref"]);
    const licenses = (component.licenses ?? []).map((entry) => entry.license?.id ?? entry.expression ?? "UNKNOWN");
    const accepted = licenses.length > 0 && licenses.every((id) => policy.allowed.includes(id));
    return { component: component["bom-ref"], licenses, result: accepted ? "allowed" : "denied" };
  });
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [nativeRoot, output] = process.argv.slice(2);
  assert.ok(nativeRoot && output, "Usage: node tools/source-sbom.mjs VERIFIED_NATIVE_ROOT OUTPUT_DIRECTORY");
  const source = process.env.KUMWE_RELEASE_COMMIT ?? process.env.GITHUB_SHA;
  assert.match(source ?? "", /^[a-f0-9]{40}$/);
  const inputs = ["composer.lock", "package-lock.json", "resources/native-runtime/source.json"];
  const bom = { bomFormat: "CycloneDX", specVersion: "1.6", version: 1,
    metadata: { component: { type: "application", name: "kumwe/app", version: source },
      properties: inputs.map((path) => property(`input:${path}:sha256`, sha(read(path)))) },
    components: [...lockedComponents(json("composer.lock"), json("package-lock.json")),
      ...nativeComponents(nativeRoot, json("resources/native-runtime/source.json"))],
  };
  const policyBytes = read("resources/release/license-policy.json");
  const policy = JSON.parse(policyBytes);
  const decisions = evaluate(bom, policy);
  mkdirSync(output, { recursive: true });
  write(`${output}/kumwe-source.cdx.json`, bom);
  writeFileSync(`${output}/kumwe-license-policy.json`, policyBytes);
  const report = { kind: "kumwe-source-license-evaluation", version: "1.0.0", source,
    policySha256: sha(policyBytes), sbomSha256: sha(read(`${output}/kumwe-source.cdx.json`)),
    scope: policy.scope, result: decisions.every((entry) => entry.result === "allowed") ? "passed" : "failed",
    componentCount: decisions.length, decisions };
  write(`${output}/kumwe-license-evaluation.json`, report);
  assert.equal(report.result, "passed", `Source licence policy failed: ${JSON.stringify(decisions.filter(
    (entry) => entry.result === "denied"))}`);
  console.log(`Source licence policy passed for ${decisions.length} locked components at ${source}.`);
}
