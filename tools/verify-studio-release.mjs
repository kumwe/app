/**
 * Hold the administrator build, vendored corpus, and release evidence to one
 * exact Studio release coordinate.
 *
 * A semantic range is not a qualification record. The App therefore accepts
 * either the exact published version or an immutable repository-vendored
 * tarball whose bytes and internal version are pinned in PIN.json. Both forms
 * resolve to the same complete release record and the lockfile must name the
 * exact package version actually installed.
 */

import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { basename, join } from "node:path";
import { fileURLToPath } from "node:url";
import { gunzipSync } from "node:zlib";

const repositoryRoot = fileURLToPath(new URL("../", import.meta.url));
const contractRoot = join(repositoryRoot, "resources/studio-contract");
const releasePath = join(contractRoot, "studio-release.json");
const pinPath = join(contractRoot, "PIN.json");
const corpusManifestPath = join(
  repositoryRoot,
  "tests/Fixtures/Studio/testkit/corpus-manifest.json",
);
const packagePath = join(repositoryRoot, "package.json");
const lockPath = join(repositoryRoot, "package-lock.json");

const packageNames = Object.freeze([
  "@kumwe/studio-core",
  "@kumwe/studio-media",
  "@kumwe/studio-preview",
  "@kumwe/studio-protocol",
  "@kumwe/studio-rich-text",
  "@kumwe/studio",
  "@kumwe/studio-testkit",
]);
const requiredDependencies = packageNames;
const semanticVersion =
  /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/;

const errors = [];
const releaseBytes = await readFile(releasePath);
const corpusManifestBytes = await readFile(corpusManifestPath);
const release = decode(
  releaseBytes,
  "resources/studio-contract/studio-release.json",
);
const pin = decode(
  await readFile(pinPath),
  "resources/studio-contract/PIN.json",
);
const manifest = decode(await readFile(packagePath), "package.json");
const lock = decode(await readFile(lockPath), "package-lock.json");

verifyReleaseRecord();
await verifyReleaseCopies();
await verifyPin();
verifyDependencyManifest();
verifyLockfile();

if (errors.length > 0) {
  process.stderr.write("The App does not consume one exact Studio release:\n");
  for (const error of errors) {
    process.stderr.write(` - ${error}\n`);
  }
  process.exit(1);
}

process.stdout.write(
  `Studio release ${release.release} verified: ${packageNames.length} exact packages, ` +
    "three identical release records, pinned tarball bytes, and a matching lockfile.\n",
);

/** Verify the closed release-record shape and coordinated versions. */
function verifyReleaseRecord() {
  const expectedMembers = [
    "claimedProfiles",
    "contractVersion",
    "corpusManifestDigest",
    "kind",
    "packages",
    "protocolVersion",
    "release",
  ];
  if (
    JSON.stringify(Object.keys(release).sort()) !==
    JSON.stringify(expectedMembers)
  ) {
    errors.push(
      "studio-release.json contains an unknown member or omits a required member.",
    );
  }
  if (
    release.kind !== "studio-release" ||
    typeof release.release !== "string" ||
    !semanticVersion.test(release.release)
  ) {
    errors.push(
      "studio-release.json must be a Studio release record with a release name.",
    );
    return;
  }
  if (!isPlainObject(release.packages)) {
    errors.push("studio-release.json must carry its package map.");
    return;
  }

  const actualNames = Object.keys(release.packages).sort();
  if (
    JSON.stringify(actualNames) !== JSON.stringify([...packageNames].sort())
  ) {
    errors.push(
      "studio-release.json must name exactly the seven public Studio packages.",
    );
  }
  for (const name of packageNames) {
    const version = release.packages[name];
    if (version !== release.release) {
      errors.push(
        `${name} is ${String(version)} in the release record, not ${release.release}.`,
      );
    }
  }
  if (
    typeof release.protocolVersion !== "string" ||
    typeof release.corpusManifestDigest !== "string" ||
    !Array.isArray(release.claimedProfiles)
  ) {
    errors.push(
      "studio-release.json lacks its protocol, corpus digest, or profile claims.",
    );
  }
  if (release.corpusManifestDigest !== sriSha256(corpusManifestBytes)) {
    errors.push(
      "studio-release.json corpusManifestDigest does not match the vendored corpus manifest bytes.",
    );
  }
}

/** Prove the protocol, testkit, and host copied the same release bytes. */
async function verifyReleaseCopies() {
  for (const relative of [
    "resources/studio-contract/protocol/studio-release.json",
    "tests/Fixtures/Studio/testkit/studio-release.json",
  ]) {
    let bytes;
    try {
      bytes = await readFile(join(repositoryRoot, relative));
    } catch {
      errors.push(`${relative} is missing.`);
      continue;
    }
    if (!bytes.equals(releaseBytes)) {
      errors.push(
        `${relative} is not byte-identical to the vendored release record.`,
      );
    }
  }
}

/** Verify every tarball and record digest named by PIN.json. */
async function verifyPin() {
  const releasePin = pin.release_record;
  if (!isPlainObject(releasePin)) {
    errors.push("PIN.json lacks release_record evidence.");
  } else {
    if (
      releasePin.release !== release.release ||
      releasePin.file !== "studio-release.json"
    ) {
      errors.push(
        "PIN.json release_record does not identify the vendored release.",
      );
    }
    if (releasePin.sha256 !== sha256(releaseBytes)) {
      errors.push(
        "PIN.json release-record digest does not match the vendored bytes.",
      );
    }
  }

  if (!isPlainObject(pin.pinned)) {
    errors.push("PIN.json lacks its package pin map.");
    return;
  }
  const tarballNames = new Set();
  for (const name of packageNames) {
    const entry = pin.pinned[name];
    if (!isPlainObject(entry)) {
      errors.push(`PIN.json does not pin ${name}.`);
      continue;
    }
    if (entry.version !== release.packages?.[name]) {
      errors.push(
        `PIN.json version for ${name} differs from studio-release.json.`,
      );
    }
    if (typeof entry.file !== "string" || basename(entry.file) !== entry.file) {
      errors.push(`PIN.json must name one local tarball file for ${name}.`);
      continue;
    }
    if (tarballNames.has(entry.file)) {
      errors.push(`PIN.json reuses tarball ${entry.file} for more than one package.`);
      continue;
    }
    tarballNames.add(entry.file);
    let bytes;
    try {
      bytes = await readFile(join(contractRoot, "packages", entry.file));
    } catch {
      errors.push(`Pinned tarball ${entry.file} for ${name} is missing.`);
      continue;
    }
    if (entry.npm_tarball_sha256 !== sha256(bytes)) {
      errors.push(
        `Pinned tarball ${entry.file} for ${name} has different bytes.`,
      );
    }
    try {
      const packedManifest = readPackedManifest(bytes, entry.file);
      if (
        packedManifest.name !== name ||
        packedManifest.version !== release.packages?.[name]
      ) {
        errors.push(
          `Pinned tarball ${entry.file} contains ${String(packedManifest.name)}@${String(
            packedManifest.version,
          )}, not ${name}@${String(release.packages?.[name])}.`,
        );
      }
    } catch (failure) {
      errors.push(
        failure instanceof Error
          ? failure.message
          : `Pinned tarball ${entry.file} is unreadable.`,
      );
    }
  }
}

/** Require every Studio dependency to be exact and part of the release set. */
function verifyDependencyManifest() {
  const declarations = new Map();
  for (const section of [
    "dependencies",
    "devDependencies",
    "optionalDependencies",
    "peerDependencies",
  ]) {
    const values = manifest[section];
    if (!isPlainObject(values)) {
      continue;
    }
    for (const [name, specifier] of Object.entries(values)) {
      if (!name.startsWith("@kumwe/studio")) {
        continue;
      }
      if (declarations.has(name)) {
        errors.push(`${name} is declared in more than one dependency section.`);
      }
      declarations.set(name, specifier);
    }
  }

  for (const name of requiredDependencies) {
    if (!declarations.has(name)) {
      errors.push(
        `${name} is required by the Studio integration but is not declared.`,
      );
    }
  }
  for (const [name, specifier] of declarations) {
    if (!packageNames.includes(name)) {
      errors.push(`${name} is not part of the coordinated Studio release.`);
      continue;
    }
    const entry = pin.pinned?.[name];
    const version = release.packages?.[name];
    const vendored =
      isPlainObject(entry) && typeof entry.file === "string"
        ? `file:resources/studio-contract/packages/${entry.file}`
        : undefined;
    if (specifier !== version && specifier !== vendored) {
      errors.push(
        `${name} must use exact ${String(version)} or its pinned tarball, not ${String(specifier)}.`,
      );
    }
  }
}

/** Require npm's root declaration and installed package entries to agree. */
function verifyLockfile() {
  if (!isPlainObject(lock.packages) || !isPlainObject(lock.packages[""])) {
    errors.push("package-lock.json lacks its root package record.");
    return;
  }
  const root = lock.packages[""];
  const rootDependencies = {
    ...(root.dependencies ?? {}),
    ...(root.devDependencies ?? {}),
    ...(root.optionalDependencies ?? {}),
    ...(root.peerDependencies ?? {}),
  };

  const declaredNames = new Set();
  for (const section of [
    "dependencies",
    "devDependencies",
    "optionalDependencies",
    "peerDependencies",
  ]) {
    for (const name of Object.keys(manifest[section] ?? {})) {
      if (name.startsWith("@kumwe/studio")) {
        declaredNames.add(name);
      }
    }
  }

  for (const name of [...declaredNames].sort()) {
    const manifestSpecifier =
      manifest.dependencies?.[name] ??
      manifest.devDependencies?.[name] ??
      manifest.optionalDependencies?.[name] ??
      manifest.peerDependencies?.[name];
    if (rootDependencies[name] !== manifestSpecifier) {
      errors.push(
        `package-lock.json root specifier for ${name} differs from package.json.`,
      );
    }
    const installed = lock.packages[`node_modules/${name}`];
    if (
      !isPlainObject(installed) ||
      installed.version !== release.packages?.[name]
    ) {
      errors.push(
        `package-lock.json does not install ${name}@${String(release.packages?.[name])}.`,
      );
    }
  }
}

/** Decode one JSON document while retaining a useful file identity. */
function decode(bytes, label) {
  try {
    const value = JSON.parse(bytes.toString("utf8"));
    if (!isPlainObject(value)) {
      throw new Error("root is not an object");
    }
    return value;
  } catch (cause) {
    throw new Error(`${label} is not a JSON object.`, { cause });
  }
}

/** Return a lower-case hexadecimal SHA-256 digest. */
function sha256(bytes) {
  return createHash("sha256").update(bytes).digest("hex");
}

/** Return the SRI SHA-256 digest used by the Studio release record. */
function sriSha256(bytes) {
  return `sha256-${createHash("sha256").update(bytes).digest("base64")}`;
}

/**
 * Read package/package.json directly from one npm gzip tar archive.
 *
 * Parsing the small POSIX header subset npm emits keeps this check independent
 * of globally installed package-manager internals. The pinned digest covers
 * every other byte; this read proves those bytes name the claimed coordinate.
 */
function readPackedManifest(bytes, label) {
  let archive;
  try {
    archive = gunzipSync(bytes);
  } catch {
    throw new Error(`Pinned tarball ${label} is not a gzip archive.`);
  }

  for (let offset = 0; offset + 512 <= archive.length; ) {
    const header = archive.subarray(offset, offset + 512);
    if (header.every((value) => value === 0)) {
      break;
    }
    const name = tarText(header.subarray(0, 100));
    const prefix = tarText(header.subarray(345, 500));
    const path = prefix === "" ? name : `${prefix}/${name}`;
    const sizeField = tarText(header.subarray(124, 136)).trim();
    if (!/^[0-7]+$/.test(sizeField)) {
      throw new Error(`Pinned tarball ${label} contains an invalid entry size.`);
    }
    const size = Number.parseInt(sizeField, 8);
    const start = offset + 512;
    const end = start + size;
    if (!Number.isSafeInteger(size) || end > archive.length) {
      throw new Error(`Pinned tarball ${label} contains a truncated entry.`);
    }
    if (path === "package/package.json") {
      return decode(
        archive.subarray(start, end),
        `package/package.json in ${label}`,
      );
    }
    offset = start + Math.ceil(size / 512) * 512;
  }

  throw new Error(
    `Pinned tarball ${label} does not contain package/package.json.`,
  );
}

/** Decode one NUL-padded UTF-8 tar header field. */
function tarText(bytes) {
  const nul = bytes.indexOf(0);
  return bytes
    .subarray(0, nul === -1 ? bytes.length : nul)
    .toString("utf8");
}

/** Distinguish JSON objects from arrays and null. */
function isPlainObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}
