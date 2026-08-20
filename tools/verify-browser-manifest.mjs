/**
 * Hold the shared browser-manifest reader to the corpus both consumers answer.
 *
 * The manifest is the single definition of the browser matrix, and its worth rests entirely on both
 * consumers reading the same documents the same way. They did not: the Playwright configuration ran
 * every journey for any `specs` value that was not `right-to-left`, while the PHP seeder provisioned
 * identities only for exactly `all`, so one misspelled word ran the maker-checker journey on a project
 * with no approval identity — the once-per-account TOTP refusal the manifest exists to prevent,
 * reintroduced silently with every guard still green.
 *
 * The first attempt at this guard listed its cases here and the PHP half listed them again, which left
 * the two free to disagree in exactly the way the manifest was meant to stop: `{"retries":1.0}` was
 * accepted here, because `Number.isInteger(1)` is true, and refused there, because `json_decode` yields
 * a float. Neither list could see it. Both halves now run `tests/Browser/manifest-cases.json` and
 * neither owns a case, so a case cannot exist on one side alone.
 */

import { readFileSync } from 'node:fs';
import { parseBrowserMatrix } from '../tests/Browser/manifest.mjs';

const corpusPath = new URL('../tests/Browser/manifest-cases.json', import.meta.url);

/**
 * Read the shared corpus, refusing a corpus that could let a case pass without being run.
 *
 * A case with an unrecognised outcome, or an accepted case with no expected reading, would otherwise be
 * skipped in silence — and a guard that silently runs nothing is worse than no guard, because it
 * reports success.
 *
 * @returns {{ label: string, source: string, outcome: string, expected?: unknown }[]} Every case.
 */
function readCases() {
  const document = JSON.parse(readFileSync(corpusPath, 'utf8'));
  if (document === null || typeof document !== 'object' || !Array.isArray(document.cases)) {
    throw new Error('tests/Browser/manifest-cases.json needs a "cases" array.');
  }
  const labels = new Set();
  for (const item of document.cases) {
    if (item === null || typeof item !== 'object' || typeof item.label !== 'string' || item.label === '') {
      throw new Error('Every case in tests/Browser/manifest-cases.json needs a non-empty "label".');
    }
    if (labels.has(item.label)) {
      throw new Error(`tests/Browser/manifest-cases.json declares "${item.label}" twice.`);
    }
    labels.add(item.label);
    if (typeof item.source !== 'string') {
      throw new Error(`Case "${item.label}" needs its manifest as a raw "source" string.`);
    }
    if (item.outcome !== 'refused' && item.outcome !== 'accepted') {
      throw new Error(`Case "${item.label}" needs "outcome" to be refused or accepted.`);
    }
    if (item.outcome === 'accepted' && (item.expected === null || typeof item.expected !== 'object')) {
      throw new Error(`Accepted case "${item.label}" must state the reading both consumers produce.`);
    }
    if (item.outcome === 'refused' && 'expected' in item) {
      throw new Error(`Refused case "${item.label}" cannot state a reading; it is never read.`);
    }
  }
  if (document.cases.length === 0) {
    throw new Error('tests/Browser/manifest-cases.json declares no cases.');
  }

  return document.cases;
}

/**
 * Compare a reading against the corpus, distinguishing zero from negative zero.
 *
 * `-0` is the one value `JSON.stringify` hides: it prints `0` for both, so a reading that kept negative
 * zero where PHP produced plain zero would compare equal and the divergence would survive the guard.
 *
 * @param {{ retries: number, projects: { name: string, specs: string }[] }} actual Reading produced.
 * @param {{ retries: number, projects: { name: string, specs: string }[] }} expected Reading required.
 * @returns {string} The difference, or an empty string when the two readings are identical.
 */
function difference(actual, expected) {
  if (!Object.is(actual.retries, expected.retries)) {
    return `retries read as ${Object.is(actual.retries, -0) ? '-0' : actual.retries}, `
      + `corpus states ${Object.is(expected.retries, -0) ? '-0' : expected.retries}`;
  }
  const read = JSON.stringify(actual.projects);
  const stated = JSON.stringify(expected.projects);

  return read === stated ? '' : `projects read as ${read}, corpus states ${stated}`;
}

const cases = readCases();
let failures = 0;
let refusedCount = 0;
let acceptedCount = 0;

for (const item of cases) {
  if (item.outcome === 'refused') {
    refusedCount += 1;
    try {
      parseBrowserMatrix(item.source, 'fixture');
      process.stderr.write(`The manifest reader accepted ${item.label}, which the corpus refuses.\n`);
      failures += 1;
    } catch {
      // Refused, which is the contract.
    }
    continue;
  }

  acceptedCount += 1;
  let read;
  try {
    read = parseBrowserMatrix(item.source, 'fixture');
  } catch (cause) {
    process.stderr.write(
      `The manifest reader refused ${item.label}, which the corpus accepts: `
      + `${cause instanceof Error ? cause.message : cause}\n`,
    );
    failures += 1;
    continue;
  }
  const drift = difference(read, item.expected);
  if (drift !== '') {
    process.stderr.write(`The manifest reader read ${item.label} differently: ${drift}.\n`);
    failures += 1;
  }
}

if (refusedCount === 0 || acceptedCount === 0) {
  process.stderr.write('The corpus must hold both refused and accepted cases; a one-sided rule is untested.\n');
  failures += 1;
}

const committed = parseBrowserMatrix(
  readFileSync(new URL('../tests/Browser/projects.json', import.meta.url), 'utf8'),
);
if (committed.projects.length === 0) {
  process.stderr.write('The committed manifest declares no projects.\n');
  failures += 1;
}

if (failures > 0) {
  process.exit(1);
}

process.stdout.write(
  `Browser manifest verified: ${refusedCount} documents refused, ${acceptedCount} read exactly as the `
  + `corpus states, ${committed.projects.length} projects declared, ${committed.retries} retry budgeted.\n`,
);
