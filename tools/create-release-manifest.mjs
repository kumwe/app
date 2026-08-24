/**
 * Build the deterministic release manifest whose checksum is signed by the
 * release workflow.
 *
 * The manifest binds the released App commit and artifacts to the exact
 * Studio release the administrator bundle was qualified against. SHA256SUMS
 * includes this file, so the existing keyless signature covers every field
 * without creating a second signing mechanism.
 */

import { createHash } from "node:crypto";
import { readFile, stat, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const repositoryRoot = fileURLToPath(new URL("../", import.meta.url));
const distributionRoot = join(repositoryRoot, "dist");
const release = requiredEnvironment("KUMWE_RELEASE_VERSION");
const commit = requiredEnvironment("KUMWE_RELEASE_COMMIT");
const applicationImage = requiredEnvironment("KUMWE_APPLICATION_IMAGE");
const applicationDigest = requiredEnvironment("KUMWE_APPLICATION_IMAGE_DIGEST");
const webImage = requiredEnvironment("KUMWE_WEB_IMAGE");
const webDigest = requiredEnvironment("KUMWE_WEB_IMAGE_DIGEST");

if (!/^2\.[0-9]+\.[0-9]+(?:[+-][0-9A-Za-z.-]+)?$/.test(release)) {
  throw new Error(
    `KUMWE_RELEASE_VERSION is not a Version 2 release: ${release}`,
  );
}
if (!/^[0-9a-f]{40}$/.test(commit)) {
  throw new Error(
    "KUMWE_RELEASE_COMMIT must be one full lower-case Git commit identifier.",
  );
}
for (const [name, digest] of [
  ["application", applicationDigest],
  ["web", webDigest],
]) {
  if (!/^sha256:[0-9a-f]{64}$/.test(digest)) {
    throw new Error(`The ${name} image digest is not an exact SHA-256 digest.`);
  }
}

const studioBytes = await readFile(
  join(repositoryRoot, "resources/studio-contract/studio-release.json"),
);
const studio = JSON.parse(studioBytes.toString("utf8"));
if (studio?.kind !== "studio-release" || typeof studio.release !== "string") {
  throw new Error("The vendored Studio release record is malformed.");
}
for (const [name, version] of Object.entries(studio.packages ?? {})) {
  if (version !== studio.release) {
    throw new Error(
      `${name} is not coordinated at Studio release ${studio.release}.`,
    );
  }
}

const artifactNames = [
  `kumwe-app-${release}.zip`,
  "kumwe-app.cdx.json",
  "kumwe-archive.cdx.json",
  "kumwe-web.cdx.json",
];
const artifacts = [];
for (const name of artifactNames) {
  const path = join(distributionRoot, name);
  const bytes = await readFile(path);
  const metadata = await stat(path);
  artifacts.push({
    name,
    sha256: createHash("sha256").update(bytes).digest("hex"),
    size: metadata.size,
  });
}

const manifest = {
  artifacts,
  contractVersion: "1.0.0",
  images: {
    application: { digest: applicationDigest, reference: applicationImage },
    web: { digest: webDigest, reference: webImage },
  },
  kind: "kumwe-release-manifest",
  release,
  source: {
    commit,
    repository: "https://github.com/kumwe/app",
  },
  studio: {
    record: studio,
    recordSha256: createHash("sha256").update(studioBytes).digest("hex"),
  },
};

await writeFile(
  join(distributionRoot, "kumwe-release-manifest.json"),
  `${JSON.stringify(manifest, null, 2)}\n`,
  {
    flag: "wx",
  },
);
process.stdout.write(
  `Release manifest created for Kumwe ${release} and Studio ${studio.release}.\n`,
);

/** Return one mandatory non-empty environment value. */
function requiredEnvironment(name) {
  const value = process.env[name];
  if (typeof value !== "string" || value === "") {
    throw new Error(`${name} is required.`);
  }
  return value;
}
