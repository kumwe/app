/** Release authority, substitution and stale-evidence refusal regression tests. @since 2.0.0-beta.1 */
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { test } from "node:test";
import { authority, digest, lanes, qualify, receipt, verify } from "./release-artifacts.mjs";

const commit = "a".repeat(40);
const version = "2.0.0-beta.1";
const merged = { number: 152, state: "closed", draft: false, merged_at: "2026-01-01T00:00:00Z",
  base: { ref: "master", repo: { full_name: "kumwe/app" } }, merge_commit_sha: commit };
const passed = { id: 1, run_attempt: 1, head_sha: commit, head_branch: "master", event: "push",
  status: "completed", conclusion: "success", path: ".github/workflows/ci.yml" };

test("Beta1 identity agrees across installer, Docker, npm and release declaration", () => {
  execFileSync(process.execPath, ["tools/release-artifacts.mjs", "identity", version]);
  execFileSync(process.execPath, ["tools/release-artifacts.mjs", "identity", "2.0.0-beta.2"]);
});

test("continuous release stamps the next beta without changing dependencies or introduction history", () => {
  const root = mkdtempSync(join(tmpdir(), "kumwe-stamp-test-"));
  try {
    for (const path of [".github/release-channel.json", "composer.json", "package.json", "package-lock.json",
      ".env.example", "bin/kumwe-install", "src/Kernel/Configuration/ConfigurationFactory.php", "docker/php/Dockerfile"]) {
      mkdirSync(dirname(join(root, path)), { recursive: true });
      copyFileSync(path, join(root, path));
    }
    mkdirSync(join(root, "dist"));
    const lockPath = join(root, "package-lock.json");
    const lock = JSON.parse(readFileSync(lockPath));
    lock.packages["node_modules/test-unchanged-version"] = { version };
    writeFileSync(lockPath, JSON.stringify(lock, null, 2));
    const configPath = join(root, "src/Kernel/Configuration/ConfigurationFactory.php");
    writeFileSync(configPath, `${readFileSync(configPath)}\n// @since 2.0.0-beta.1\n`);
    execFileSync(process.execPath, [resolve("tools/release-artifacts.mjs"), "stamp", "2.0.0-beta.2"], { cwd: root });
    const stamped = JSON.parse(readFileSync(lockPath));
    assert.equal(stamped.version, "2.0.0-beta.2");
    assert.equal(stamped.packages[""].version, "2.0.0-beta.2");
    assert.equal(stamped.packages["node_modules/test-unchanged-version"].version, version);
    assert.match(readFileSync(configPath, "utf8"), /@since 2\.0\.0-beta\.1/);
    const record = JSON.parse(readFileSync(join(root, "dist/release-version-stamp.json")));
    assert.equal(record.sourceVersion, version);
    assert.equal(record.version, "2.0.0-beta.2");
    assert.equal(record.transformations.length, 6);
    for (const entry of record.transformations) {
      assert.equal(entry.artifactSha256, digest(readFileSync(join(root, entry.path))));
    }
  } finally { rmSync(root, { recursive: true, force: true }); }
});

test("only merged PR source with successful complete master CI can publish", () => {
  assert.equal(authority(commit, [[merged]], [{ workflow_runs: [passed] }]).pullRequest, 152);
  for (const change of [{ draft: true }, { merged_at: null }, { state: "open" },
    { merge_commit_sha: "b".repeat(40) }, { base: { ref: "other" } }]) {
    assert.throws(() => authority(commit, [[{ ...merged, ...change }]], [{ workflow_runs: [passed] }]));
  }
  for (const change of [{ conclusion: "failure" }, { status: "in_progress" }, { event: "pull_request" },
    { head_sha: "b".repeat(40) }, { head_branch: "feature" }, { path: ".github/workflows/other.yml" }]) {
    assert.throws(() => authority(commit, [[merged]], [{ workflow_runs: [{ ...passed, ...change }] }]));
  }
});

/** Run assertions on disposable fake bytes, never production qualification evidence. */
function fixture(run) {
  const root = mkdtempSync(join(tmpdir(), "kumwe-release-test-"));
  try {
    const names = [`kumwe-app-${version}.zip`, `kumwe-composer-${version}.zip`, "composer-repository.json",
      "kumwe-app.cdx.json", "kumwe-archive.cdx.json", "kumwe-web.cdx.json",
      "kumwe-source.cdx.json", "kumwe-license-policy.json", "kumwe-license-evaluation.json"];
    const artifacts = names.map((name) => {
      const bytes = Buffer.from(`Test bytes for ${name}`);
      writeFileSync(join(root, name), bytes);
      return { name, sha256: digest(bytes), size: bytes.length };
    });
    const manifest = { kind: "kumwe-release-manifest", contractVersion: "2.0.0", release: version, artifacts,
      authority: { commit }, source: { commit, repository: "https://github.com/kumwe/app" },
      build: { run: "123", attempt: "1" }, images: {
        application: { reference: "ghcr.io/kumwe/app/app", digest: `sha256:${"b".repeat(64)}` },
        web: { reference: "ghcr.io/kumwe/app/web", digest: `sha256:${"c".repeat(64)}` },
      } };
    const save = () => writeFileSync(join(root, "kumwe-release-manifest.json"), JSON.stringify(manifest));
    save();
    run(root, manifest, save);
  } finally { rmSync(root, { recursive: true, force: true }); }
}

test("one immutable manifest binds every image and distribution to all four lanes", () => fixture((root) => {
  const before = readFileSync(join(root, "kumwe-release-manifest.json"));
  const receipts = lanes.map((lane) => receipt(root, lane, commit, version, "123", "1"));
  assert.equal(qualify(root, receipts, commit, version, "123", "1").receipts.length, 4);
  assert.deepEqual(readFileSync(join(root, "kumwe-release-manifest.json")), before);
}));

test("substituted artifacts, missing subjects and wrong source cannot qualify", () => fixture((root, manifest, save) => {
  assert.throws(() => verify(root, "d".repeat(40), version));
  assert.throws(() => verify(root, commit, "2.0.0"));
  writeFileSync(join(root, `kumwe-composer-${version}.zip`), "substituted source ZIP");
  assert.throws(() => verify(root, commit, version), /digest mismatch/);
  manifest.artifacts.pop();
  save();
  assert.throws(() => verify(root, commit, version));
}));

test("changed image manifest and incomplete, failed or previous-attempt receipts refuse promotion", () => fixture(
  (root, manifest, save) => {
    const receipts = lanes.map((lane) => receipt(root, lane, commit, version, "123", "1"));
    assert.throws(() => qualify(root, receipts.slice(1), commit, version, "123", "1"));
    assert.throws(() => qualify(root, [receipts[0], ...receipts.slice(0, 3)], commit, version, "123", "1"));
    assert.throws(() => qualify(root, receipts, commit, version, "123", "2"));
    assert.throws(() => qualify(root, receipts, commit, version, "124", "1"));
    const failed = structuredClone(receipts);
    failed[0].result = "failed";
    assert.throws(() => qualify(root, failed, commit, version, "123", "1"));
    manifest.images.application.digest = `sha256:${"d".repeat(64)}`;
    save();
    assert.throws(() => qualify(root, receipts, commit, version, "123", "1"));
  },
));

test("promotion preserves digests, refuses occupied aliases and never adds stable aliases to Beta1", () => fixture(
  (root, manifest) => {
    const script = resolve("tools/promote-release-images.sh");
    const bin = join(root, "bin");
    mkdirSync(bin);
    mkdirSync(join(root, "dist"));
    writeFileSync(join(root, "dist/kumwe-release-manifest.json"), JSON.stringify(manifest));
    writeFileSync(join(bin, "curl"), `#!/bin/bash
set -eu
if [[ "$*" == *https://ghcr.io/token* ]]; then
  printf '{"token":"test-token"}'
  exit
fi
while [[ "$1" != --dump-header ]]; do shift; done
headers="$2"
image_digest="$APP_DIGEST"
[[ "\${!#}" == *'/web/manifests/'* ]] && image_digest="$WEB_DIGEST"
[[ "$MOCK_STATUS" == mismatch ]] && image_digest="sha256:$(printf 'd%.0s' {1..64})"
printf 'Docker-Content-Digest: %s\\r\\n' "$image_digest" > "$headers"
case "$MOCK_STATUS" in
  missing) printf 404 ;;
  unauthorized) printf 401 ;;
  *) printf 200 ;;
esac
`, { mode: 0o755 });
    writeFileSync(join(bin, "docker"), `#!/bin/bash
set -eu
printf '%s\\n' "$*" >> "$MOCK_TRACE"
if [[ "$*" == *'imagetools inspect'* ]]; then
  image_digest="$APP_DIGEST"
  [[ "$*" == *'/web:'* ]] && image_digest="$WEB_DIGEST"
  [[ "$MOCK_COPY" == changed ]] && image_digest="sha256:$(printf 'e%.0s' {1..64})"
  printf '"%s"' "$image_digest"
fi
`, { mode: 0o755 });
    const trace = join(root, "docker-calls");
    const env = { ...process.env, PATH: `${bin}:${process.env.PATH}`, GITHUB_ACTOR: "test",
      GH_TOKEN: "test-token", KUMWE_RELEASE_VERSION: version, KUMWE_RELEASE_COMMIT: commit,
      APP_DIGEST: manifest.images.application.digest, WEB_DIGEST: manifest.images.web.digest,
      MOCK_TRACE: trace, MOCK_COPY: "same" };
    for (const status of ["mismatch", "unauthorized"]) {
      assert.throws(() => execFileSync("bash", [script], { cwd: root, env: { ...env, MOCK_STATUS: status },
        stdio: "pipe" }));
      assert.equal(existsSync(trace), false, "Both image aliases must be checked before any promotion.");
    }
    for (const status of ["missing", "same"]) {
      execFileSync("bash", [script], { cwd: root, env: { ...env, MOCK_STATUS: status }, stdio: "pipe" });
      const calls = readFileSync(trace, "utf8");
      assert.ok(calls.includes("--prefer-index=false"));
      assert.ok(calls.includes(`app:${version}`));
      assert.ok(calls.includes(`web:${commit}`));
      assert.doesNotMatch(calls, /:(?:latest|2|2\.0)(?:\s|$)/);
    }
    assert.throws(() => execFileSync("bash", [script], { cwd: root,
      env: { ...env, MOCK_STATUS: "missing", MOCK_COPY: "changed" }, stdio: "pipe" }));
  },
));
