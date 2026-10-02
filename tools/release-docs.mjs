/**
 * Verify runtime-derived references and ship the exact documentation with the release byte set.
 * The archive preserves guides and source contracts; generated indexes cannot invent passed evidence.
 * Usage: node tools/release-docs.mjs --check | --build
 * @since 2.0.0-beta.1
 */
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { cpSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { basename, dirname, join, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const read = (path) => readFileSync(path);
const json = (path) => JSON.parse(read(path));
const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");
const write = (path, value) => writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`);
const cliContract = () => json("src/Delivery/Console/Contract/cli-v3.json");

/** Expand reviewed paths without following links outside the source or dependency artifact. */
function files(path) {
  const info = lstatSync(path);
  assert.ok(!info.isSymbolicLink(), `Reference may not follow a symlink: ${path}`);
  if (info.isFile()) return [path];
  assert.ok(info.isDirectory(), `Unexpected reference input: ${path}`);
  return readdirSync(path).sort().flatMap((name) => files(join(path, name)));
}

/** Check operator command references against the live-verified frozen CLI inventory. */
export function verifyCommands(text, commands, path) {
  const documented = [...text.matchAll(/\bphp\s+bin\/kumwe\s+([a-z][a-z0-9:-]*)(?=[\s`]|$)/g)].map((match) => match[1]);
  for (const name of documented) {
    assert.ok(name === "list" || commands.includes(name), `${path} references an absent runtime command: ${name}`);
  }
  return [...new Set(documented)].sort();
}

/** Index all runtime contract inputs and the corresponding reviewed prose without editing those inputs. */
export function inventory(model) {
  const commands = cliContract().commands.map((command) => command.name);
  const records = model.references.map((reference) => {
    assert.ok(reference.guides.length > 0 && reference.runtime.length > 0);
    return { id: reference.id,
      guides: reference.guides.flatMap(files).map((path) => ({ path, sha256: sha(read(path)),
        commands: verifyCommands(read(path).toString(), commands, path) })),
      runtime: reference.runtime.flatMap(files).map((path) => ({ path, sha256: sha(read(path)) })),
    };
  });
  assert.equal(new Set(records.map((record) => record.id)).size, records.length);
  return records;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const mode = process.argv[2];
  assert.ok(["--check", "--build"].includes(mode), "Use --check or --build.");
  const model = json("resources/release/documentation.json");
  const references = inventory(model);
  // Reuse the existing canonical generators/verifiers; never create a second contract authority.
  for (const script of model.verification) {
    execFileSync("composer", ["--no-interaction", "run", script], { stdio: "inherit" });
  }
  if (mode === "--check") {
    console.log(`Verified ${references.length} release reference groups against their runtime authorities.`);
  } else {
    const version = process.env.KUMWE_RELEASE_VERSION;
    const commit = process.env.KUMWE_RELEASE_COMMIT;
    assert.match(version ?? "", /^2\.[0-9]+\.[0-9]+(?:-[a-z]+\.[0-9]+)?$/);
    assert.match(commit ?? "", /^[a-f0-9]{40}$/);
    const root = "dist/documentation";
    assert.ok(!existsSync(root), "Refusing to replace an existing documentation candidate.");
    mkdirSync(root, { recursive: true });
    const inputs = ["docs", "api", "resources", "examples", "deploy", "README.md", "SECURITY.md", "LICENSE",
      "CHANGELOG.md", "compose.production.yaml"];
    for (const entry of json("composer.lock").packages.filter((entry) => entry.name.startsWith("kumwe/"))) {
      const packageRoot = `vendor/${entry.name}`;
      for (const name of ["README.md", "CHARTER.md", "LICENSE", "resources", "docs", "examples"]) {
        if (existsSync(`${packageRoot}/${name}`)) inputs.push(`${packageRoot}/${name}`);
      }
    }
    const copied = [...new Set(inputs.flatMap(files))].sort();
    for (const path of copied) {
      const target = join(root, path);
      mkdirSync(dirname(target), { recursive: true });
      cpSync(path, target);
    }
    const cli = cliContract();
    const mcp = json("docs/machine-contract/mcp-v2.json");
    const index = { kind: "kumwe-release-documentation-index", contractVersion: "1.0.0", release: version,
      sourceCommit: commit, referenceModelSha256: sha(read("resources/release/documentation.json")),
      verification: model.verification.map((command) => ({ command: `composer ${command}`, result: "passed" })),
      references, files: copied.map((path) => ({ path, sha256: sha(read(path)) })),
      cli: { generation: cli.generation, commands: cli.commands },
      mcp: { generation: mcp.generation, inventory: mcp.inventory, intentionalExclusions: mcp.intentional_exclusions },
      limits: ["This index verifies references and packages guides; it is not a Gate B acceptance record.",
        "Preserved findings and acceptance records state outstanding work. Only actual workflow receipts qualify artifacts."],
    };
    write(`${root}/reference-index.json`, index);
    const lines = [`# Kumwe ${version} release reference`, "", `Source: ${commit}`, "",
      "Generated from the shipped runtime contracts. See reference-index.json for hashes and verifier results.", "",
      "| Reference | Guides | Runtime inputs |", "|---|---|---|"];
    for (const reference of references) lines.push(`| ${reference.id} | ${reference.guides.map((guide) =>
      `[${basename(guide.path)}](${guide.path})`).join(", ")} | ${reference.runtime.length} hashed inputs |`);
    lines.push("", "## Console commands", "", "| Command | Actions |", "|---|---|");
    for (const command of cli.commands) lines.push(`| ${command.name} | ${(command.actions ?? []).map((action) => action.name).join(", ")} |`);
    lines.push("", "Known residuals and supported limits remain in docs/roadmap and docs/operations.", "");
    writeFileSync(`${root}/RELEASE-REFERENCE.md`, lines.join("\n"));
    const archive = resolve(`dist/kumwe-documentation-${version}.zip`);
    assert.ok(!existsSync(archive), "Refusing to replace a built documentation archive.");
    execFileSync("zip", ["-qr", archive, "."], { cwd: root });
    write("dist/kumwe-documentation-index.json", index);
    rmSync(root, { recursive: true });
    console.log(`Built ${archive} with ${copied.length} exact reference files.`);
  }
}
