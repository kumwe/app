/**
 * Bind release bytes, authority and completed deployment lanes without rebuilding artifacts.
 * Run with identity, authority, composer, verify, receipt or qualify in the release workflow.
 * This is build tooling; Node.js is never a production dependency.
 * @since 2.0.0-beta.1
 */
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

export const lanes = ["distribution", "mariadb", "mysql", "pgsql"];
export const digest = (bytes) => createHash("sha256").update(bytes).digest("hex");
const read = (path) => readFileSync(path);
const json = (path) => JSON.parse(read(path));
const write = (path, value) => writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`, { flag: "wx" });

/** Require the actual merged PR and successful push CI on precisely this commit. */
export function authority(commit, pulls, runs) {
  assert.match(commit, /^[a-f0-9]{40}$/);
  const pr = pulls.flat().find((item) => item.merged_at && item.state === "closed" && !item.draft
    && item.base?.ref === "master" && item.base?.repo?.full_name === "kumwe/app"
    && item.merge_commit_sha === commit);
  assert.ok(pr, "The release commit must be the result of a merged PR into kumwe/app master.");
  const ci = runs.flatMap((page) => page.workflow_runs).find((item) => item.head_sha === commit
    && item.head_branch === "master" && item.event === "push" && item.status === "completed"
    && item.conclusion === "success" && item.path === ".github/workflows/ci.yml");
  assert.ok(ci, "Successful complete master push CI is required on the exact release commit.");
  return { commit, pullRequest: pr.number, mergedAt: pr.merged_at, ciRun: ci.id, ciAttempt: ci.run_attempt };
}

/** Check coherent source defaults and the generated tag without changing continuous release numbering. */
function identity(version) {
  assert.match(version, /^2\.[0-9]+\.[0-9]+(?:-[a-z]+\.[0-9]+)?$/);
  const sourceVersion = json("package.json").version;
  assert.equal(json("package-lock.json").version, sourceVersion);
  assert.equal(json("package-lock.json").packages[""].version, sourceVersion);
  const channel = json(".github/release-channel.json");
  if (version.includes("-")) {
    assert.equal(version.slice(0, version.lastIndexOf(".")), `${channel.base}-${channel.channel}`);
  }
  assert.equal(json("composer.json").version, undefined, "Composer's release version must come from its tag.");
  for (const [path, needle] of versionSurfaces(sourceVersion)) {
    assert.ok(read(path).toString().includes(needle), `${path} has a different release identity.`);
  }
}

/** Narrow version fields only; historical introduction annotations must never change. */
function versionSurfaces(version) {
  return [
    ["package.json", `"version": "${version}"`],
    ["package-lock.json", `"version": "${version}"`],
    [".env.example", `KUMWE_RELEASE=${version}\n`],
    ["bin/kumwe-install", `$packageRelease = '${version}';`],
    ["src/Kernel/Configuration/ConfigurationFactory.php", `'KUMWE_RELEASE', '${version}'`],
    ["docker/php/Dockerfile", `ARG KUMWE_RELEASE=${version}\n`],
  ];
}

/** Stamp build copies once and disclose every before/after digest in signed release provenance. */
function stamp(version) {
  identity(version);
  const sourceVersion = json("package.json").version;
  const target = versionSurfaces(version);
  const transformations = versionSurfaces(sourceVersion).map(([path, needle], index) => {
    const before = read(path);
    let after = Buffer.from(before.toString().replaceAll(needle, target[index][1]));
    if (path === "package.json" || path === "package-lock.json") {
      const metadata = JSON.parse(before);
      metadata.version = version;
      if (path === "package-lock.json") metadata.packages[""].version = version;
      after = Buffer.from(`${JSON.stringify(metadata, null, 2)}\n`);
    }
    writeFileSync(path, after);
    return { path, sourceSha256: digest(before), artifactSha256: digest(after) };
  });
  write("dist/release-version-stamp.json", { kind: "kumwe-release-version-stamp", sourceVersion, version,
    transformations });
  identity(version);
}

/** Verify the exact candidate and all artifact bytes before a lane or publication consumes them. */
export function verify(root, expectedCommit, expectedVersion) {
  const manifest = json(`${root}/kumwe-release-manifest.json`);
  assert.equal(manifest.kind, "kumwe-release-manifest");
  assert.equal(manifest.contractVersion, "2.0.0");
  assert.equal(manifest.release, expectedVersion);
  assert.equal(manifest.source.commit, expectedCommit);
  assert.equal(manifest.authority.commit, expectedCommit);
  assert.equal(manifest.source.repository, "https://github.com/kumwe/app");
  const names = [`kumwe-app-${expectedVersion}.zip`, `kumwe-composer-${expectedVersion}.zip`,
    "composer-repository.json", "kumwe-app.cdx.json", "kumwe-archive.cdx.json", "kumwe-web.cdx.json",
    "kumwe-source.cdx.json", "kumwe-license-policy.json", "kumwe-license-evaluation.json"];
  assert.deepEqual(manifest.artifacts.map((artifact) => artifact.name).sort(), names.sort());
  for (const artifact of manifest.artifacts) {
    const bytes = read(`${root}/${artifact.name}`);
    assert.equal(digest(bytes), artifact.sha256, `${artifact.name} digest mismatch.`);
    assert.equal(bytes.length, artifact.size, `${artifact.name} size mismatch.`);
  }
  for (const [name, image] of Object.entries(manifest.images)) {
    assert.equal(image.reference, `ghcr.io/kumwe/app/${name === "application" ? "app" : "web"}`);
    assert.match(image.digest, /^sha256:[a-f0-9]{64}$/);
  }
  assert.deepEqual(Object.keys(manifest.images).sort(), ["application", "web"]);
  return manifest;
}

/** A receipt is emitted only by a successful lane's final workflow step. */
export function receipt(root, lane, commit, version, run, attempt) {
  assert.ok(lanes.includes(lane), "Unknown qualification lane.");
  const manifest = verify(root, commit, version);
  assert.equal(manifest.build.run, run);
  assert.equal(manifest.build.attempt, attempt);
  return { lane, commit, release: version, run, attempt,
    manifestSha256: digest(read(`${root}/kumwe-release-manifest.json`)), result: "passed" };
}

/** Refuse absent, substituted, failed or stale lane evidence rather than implying acceptance. */
export function qualify(root, receipts, commit, version, run, attempt) {
  verify(root, commit, version);
  assert.deepEqual(receipts.map((entry) => entry.lane).sort(), [...lanes].sort());
  for (const entry of receipts) assert.deepEqual(entry, receipt(root, entry.lane, commit, version, run, attempt));
  return { kind: "kumwe-release-qualification", contractVersion: "1.0.0", receipts };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [mode, ...args] = process.argv.slice(2);
  const commit = process.env.KUMWE_RELEASE_COMMIT;
  const version = process.env.KUMWE_RELEASE_VERSION;
  const run = process.env.GITHUB_RUN_ID;
  const attempt = process.env.GITHUB_RUN_ATTEMPT;
  if (mode === "identity") identity(args[0]);
  else if (mode === "stamp") stamp(args[0]);
  else if (mode === "authority") {
    write("dist/release-authority.json",
      authority(args[0], json("dist/release-pulls.json"), json("dist/release-ci.json")));
  } else if (mode === "composer") {
    const name = `kumwe-composer-${version}.zip`;
    const metadata = { ...json("composer.json"), version, dist: { type: "zip", reference: commit,
      url: `https://github.com/kumwe/app/releases/download/v${version}/${name}`,
      shasum: createHash("sha1").update(read(`dist/${name}`)).digest("hex") } };
    write("dist/composer-repository.json", { packages: { "kumwe/app": { [version]: metadata } } });
  } else if (mode === "verify") verify("dist", commit, version);
  else if (mode === "receipt") write(`dist/qualification-${args[0]}.json`,
    receipt("dist", args[0], commit, version, run, attempt));
  else if (mode === "qualify") write("dist/kumwe-release-qualification.json",
    qualify("dist", lanes.map((lane) => json(`dist/qualification-${lane}.json`)), commit, version, run, attempt));
  else throw new Error(`Unknown release command: ${mode}`);
}
