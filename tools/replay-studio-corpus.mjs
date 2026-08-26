/**
 * Replay the executable parts of the pinned Studio corpus against the exact
 * JavaScript packages used by the administrator build.
 *
 * Digest verification proves that vendored bytes were not changed. It does
 * not prove that the App and the installed Studio runtime interpret those
 * bytes alike. This lane independently executes every command and canonical
 * serialization vector, and asks the published protocol schemas to refuse
 * every negative fixture. Host-port vectors are exercised by the PHP adapter
 * suites because only those suites can prove that App policy and persistence
 * produce the published outcome.
 */

import { createHash } from "node:crypto";
import { readdir, readFile } from "node:fs/promises";
import { basename, join } from "node:path";
import { fileURLToPath } from "node:url";
import assert from "node:assert/strict";
import Ajv2020 from "ajv/dist/2020.js";
import addFormats from "ajv-formats";
import {
  applyCommand,
  canonicalStringify,
  canonicalUtf8Bytes,
  invertCommand,
  StudioSession,
} from "@kumwe/studio-core";
import {
  computePreviewDraftDigest,
  createPreviewMarkerInventory,
} from "@kumwe/studio-preview";
import { runRendererWebVector } from "@kumwe/studio-renderer-web";
import {
  parseRichTextDocument,
  projectRichText,
} from "@kumwe/studio-rich-text";

const repositoryRoot = fileURLToPath(new URL("../", import.meta.url));
const corpusRoot = join(repositoryRoot, "tests/Fixtures/Studio/testkit");

const positiveCount = await validatePositiveCorpus();
const commandCount = await replayCommands();
const canonicalCount = await replayCanonicalSerialization();
const previewCount = await replayPreviewIdentity();
const richTextCount = await replayRichTextProjection();
const rendererCount = await replayRendererConformance();
const invalidCount = await replayNegativeFixtures();

process.stdout.write(
  `Studio corpus replayed: ${positiveCount} positive schema documents, ${commandCount} commands, ` +
    `${canonicalCount} canonical serializations, ${previewCount} preview identities, ${richTextCount} ` +
    `rich-text projections, ${rendererCount} renderer-web conformance vectors, and ${invalidCount} ` +
    `negative fixtures.\n`,
);

/**
 * Validate every positive corpus document that has a published wrapper schema.
 * Rich-text renderer conformance records intentionally have no wrapper schema
 * and are executed independently below.
 *
 * @returns {Promise<number>} Number of positive documents validated.
 */
async function validatePositiveCorpus() {
  const { ajv, identifiers } = await schemaRegistry();
  const manifest = await readJson(join(corpusRoot, "corpus-manifest.json"));
  const manifestIdentifier = identifiers.get("corpus-manifest.schema.json");
  assert.notEqual(
    manifestIdentifier,
    undefined,
    "The published corpus-manifest schema is unavailable.",
  );
  const manifestValidator = ajv.getSchema(manifestIdentifier);
  assert.notEqual(manifestValidator, undefined);
  assert.equal(
    manifestValidator(manifest),
    true,
    `corpus-manifest.json is invalid: ${ajv.errorsText(manifestValidator.errors)}`,
  );

  const explicitSchemas = new Map([
    ["fixtures/media-upload-grant.example.json", "media-upload-grant.schema.json"],
    ["fixtures/rich-text.example.json", "rich-text.schema.json"],
    ["fixtures/studio-config.example.json", "studio-config.schema.json"],
  ]);
  const groupSchemas = new Map([
    ["conformance/renderer-web", "renderer-web-vector.schema.json"],
  ]);
  let count = 1;
  for (const group of manifest.groups) {
    if (group.path === "invalid" || group.path === "conformance/rich-text") {
      continue;
    }
    for (const entry of group.files) {
      const relative = `${group.path}/${entry.file}`;
      const document = await readJson(join(corpusRoot, relative));
      const schemaName =
        explicitSchemas.get(relative) ??
        groupSchemas.get(group.path) ??
        (typeof document.kind === "string"
          ? `${document.kind}.schema.json`
          : undefined);
      assert.notEqual(
        schemaName,
        undefined,
        `${relative} has no published schema identity.`,
      );
      const identifier = identifiers.get(schemaName);
      assert.notEqual(
        identifier,
        undefined,
        `${relative} names unavailable schema ${schemaName}.`,
      );
      const validate = ajv.getSchema(identifier);
      assert.notEqual(validate, undefined, `${schemaName} was not compiled.`);
      assert.equal(
        validate(document),
        true,
        `${relative} is invalid against ${schemaName}: ${ajv.errorsText(validate.errors)}`,
      );
      count += 1;
    }
  }
  return count;
}

/**
 * Replay every command through the published reducer or mode-aware session.
 *
 * @returns {Promise<number>} Number of vectors replayed.
 */
async function replayCommands() {
  const files = await jsonFiles("vectors/command");
  assert.notEqual(
    files.length,
    0,
    "The pinned Studio command corpus is empty.",
  );

  for (const file of files) {
    const vector = await readJson(file);
    const pristine = structuredClone(vector.initial);

    if ("errorCode" in vector.expect) {
      const run = () => executeCommandVector(vector);
      assert.throws(
        run,
        (failure) =>
          failure instanceof Error && failure.code === vector.expect.errorCode,
        `${basename(file)} did not fail with ${vector.expect.errorCode}.`,
      );
      assert.deepStrictEqual(
        vector.initial,
        pristine,
        `${basename(file)} mutated its refused input.`,
      );
      continue;
    }

    const result = executeCommandVector(vector);
    assert.deepStrictEqual(
      result,
      vector.expect.document,
      `${basename(file)} produced a different document.`,
    );
    assert.deepStrictEqual(
      vector.initial,
      pristine,
      `${basename(file)} mutated its source document.`,
    );

    const computed = invertCommand(vector.initial, vector.command, {
      id: vector.inverse?.id ?? `${vector.id}.inverse`,
    });
    assert.deepStrictEqual(
      applyCommand(result, computed),
      vector.initial,
      `${basename(file)} computed inverse did not restore its source.`,
    );
    if (vector.inverse !== undefined) {
      assert.deepStrictEqual(
        computed,
        vector.inverse,
        `${basename(file)} published a different inverse.`,
      );
      assert.deepStrictEqual(
        applyCommand(result, vector.inverse),
        vector.initial,
        `${basename(file)} published inverse did not restore its source.`,
      );
    }
  }

  return files.length;
}

/**
 * Execute one command using the boundary its vector names.
 *
 * @param {Record<string, any>} vector Command vector.
 * @returns {Record<string, any>} Reduced document.
 */
function executeCommandVector(vector) {
  if (vector.mode === undefined) {
    return applyCommand(vector.initial, vector.command);
  }

  const session = new StudioSession({
    document: vector.initial,
    mode: vector.mode,
    sessionGeneration: vector.command.sessionGeneration,
  });
  const result = session.execute(vector.command);
  assert.equal(
    session.stateVersion,
    1,
    `${vector.id} did not advance its session exactly once.`,
  );

  return result;
}

/**
 * Replay the independent canonical byte and digest expectations.
 *
 * @returns {Promise<number>} Number of vectors replayed.
 */
async function replayCanonicalSerialization() {
  const files = await jsonFiles("vectors/canonical");
  assert.notEqual(
    files.length,
    0,
    "The pinned Studio canonical corpus is empty.",
  );

  for (const file of files) {
    const vector = await readJson(file);
    const options =
      vector.maximumDepth === undefined
        ? {}
        : { maximumDepth: vector.maximumDepth };

    if ("rejected" in vector.expect) {
      assert.throws(
        () => canonicalStringify(vector.value, options),
        undefined,
        `${basename(file)} was accepted although the corpus refuses it.`,
      );
      continue;
    }

    const canonical = canonicalStringify(vector.value, options);
    assert.equal(
      canonical,
      vector.expect.canonical,
      `${basename(file)} canonical bytes drifted.`,
    );
    const bytes = canonicalUtf8Bytes(vector.value, options);
    assert.equal(
      Buffer.from(bytes).toString("utf8"),
      canonical,
      `${basename(file)} byte encoding drifted.`,
    );
    assert.equal(
      `sha256-${createHash("sha256").update(bytes).digest("base64")}`,
      vector.expect.digest,
      `${basename(file)} canonical digest drifted.`,
    );
  }

  return files.length;
}

/** Replay the portable preview digest and canonical marker inventory vectors. */
async function replayPreviewIdentity() {
  const files = await jsonFiles("vectors/preview");
  assert.notEqual(files.length, 0, "The pinned Studio preview corpus is empty.");

  for (const file of files) {
    const vector = await readJson(file);
    const pristine = structuredClone(vector.draft);
    assert.equal(
      await computePreviewDraftDigest(vector.draft),
      vector.expect.draftDigest,
      `${basename(file)} produced a different preview digest.`,
    );
    assert.deepStrictEqual(
      createPreviewMarkerInventory(vector.draft, vector.expect.draftDigest),
      {
        markerMap: vector.expect.markerMap,
        markers: vector.expect.markers,
      },
      `${basename(file)} produced a different marker inventory.`,
    );
    assert.deepStrictEqual(vector.draft, pristine, `${basename(file)} mutated its draft.`);
  }

  return files.length;
}

/** Replay the renderer-neutral rich-text projection records. */
async function replayRichTextProjection() {
  const files = await jsonFiles("conformance/rich-text");
  assert.notEqual(files.length, 0, "The pinned Studio rich-text corpus is empty.");

  for (const file of files) {
    const fixture = await readJson(file);
    const pristine = structuredClone(fixture.document);
    assert.deepStrictEqual(
      projectRichText(fixture.document),
      fixture.projection,
      `${basename(file)} produced a different rich-text projection.`,
    );
    assert.doesNotThrow(
      () => parseRichTextDocument(fixture.document),
      `${basename(file)} left the portable rich-text profile.`,
    );
    assert.deepStrictEqual(
      fixture.document,
      pristine,
      `${basename(file)} mutated its rich-text document.`,
    );
  }

  return files.length;
}

/**
 * Replay every published renderer-web conformance vector through the exact
 * installed renderer, using the vector runner the package publishes for
 * host-independent replay. The authoring-web vectors are schema-validated
 * above and exercised interactively by the browser suites, which are the only
 * lane able to prove focus, selection, and input-modality behaviour.
 *
 * @returns {Promise<number>} Number of vectors replayed.
 */
async function replayRendererConformance() {
  const files = await jsonFiles("conformance/renderer-web");
  assert.notEqual(
    files.length,
    0,
    "The pinned Studio renderer-web corpus is empty.",
  );

  for (const file of files) {
    const vector = await readJson(file);
    const result = await runRendererWebVector(vector);
    assert.equal(
      result.passed,
      true,
      `${basename(file)} failed renderer conformance: ${result.failures.join("; ")}`,
    );
  }

  return files.length;
}

/**
 * Prove every published negative fixture remains outside its named schema.
 *
 * @returns {Promise<number>} Number of fixtures replayed.
 */
async function replayNegativeFixtures() {
  const files = await jsonFiles("invalid");
  assert.notEqual(
    files.length,
    0,
    "The pinned Studio negative corpus is empty.",
  );

  const { ajv, identifiers } = await schemaRegistry();

  for (const file of files) {
    const fixture = await readJson(file);
    const identifier = identifiers.get(fixture.schema);
    assert.notEqual(
      identifier,
      undefined,
      `${basename(file)} names unavailable schema ${fixture.schema}.`,
    );
    const validate = ajv.getSchema(identifier);
    assert.notEqual(
      validate,
      undefined,
      `${basename(file)} schema ${fixture.schema} was not compiled.`,
    );
    assert.equal(
      validate(fixture.value),
      false,
      `${basename(file)} was accepted by ${fixture.schema}: ${fixture.description}`,
    );
  }

  return files.length;
}

/** Compile the exact installed schema set and index it by published filename. */
async function schemaRegistry() {
  const ajv = new Ajv2020({ allErrors: true, strict: true });
  addFormats(ajv);
  const identifiers = new Map();
  const schemaRoot = join(
    repositoryRoot,
    "resources/studio-contract/protocol/schemas",
  );
  const files = (await readdir(schemaRoot))
    .filter((name) => name.endsWith(".schema.json"))
    .sort();
  for (const file of files) {
    const schema = await readJson(join(schemaRoot, file));
    assert.equal(
      typeof schema.$id,
      "string",
      "Every published Studio schema needs an identifier.",
    );
    ajv.addSchema(schema);
    identifiers.set(file, schema.$id);
  }
  return { ajv, identifiers };
}

/**
 * List JSON files in one corpus directory in stable order.
 *
 * @param {string} relativeDirectory Corpus-relative directory.
 * @returns {Promise<string[]>} Absolute file paths.
 */
async function jsonFiles(relativeDirectory) {
  const directory = join(corpusRoot, relativeDirectory);
  const names = (await readdir(directory))
    .filter((name) => name.endsWith(".json"))
    .sort();

  return names.map((name) => join(directory, name));
}

/**
 * Decode one corpus document.
 *
 * @param {string} file Absolute path.
 * @returns {Promise<Record<string, any>>} Decoded document.
 */
async function readJson(file) {
  return JSON.parse(await readFile(file, "utf8"));
}
