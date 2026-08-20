/**
 * Hold the shared browser-manifest reader to the documents it must refuse.
 *
 * The manifest is the single definition of the browser matrix, and its worth rests entirely on both
 * consumers refusing the same things. They did not: the Playwright configuration ran every journey for
 * any `specs` value that was not `right-to-left`, while the PHP seeder provisioned identities only for
 * exactly `all`, so one misspelled word ran the maker-checker journey on a project with no approval
 * identity — the once-per-account TOTP refusal the manifest exists to prevent, reintroduced silently
 * with every guard still green. These cases are the JavaScript half of that promise; the PHP half is
 * `BrowserProjectManifestTest`, and the two lists are deliberately identical.
 */

import { readFileSync } from 'node:fs';
import { parseBrowserMatrix } from '../tests/Browser/manifest.mjs';

const refused = [
  ['a specs value that is neither scope', { retries: 1, projects: [{ name: 'a', specs: 'al' }] }],
  ['a specs value that is absent', { retries: 1, projects: [{ name: 'a' }] }],
  ['a specs value that is not a string', { retries: 1, projects: [{ name: 'a', specs: true }] }],
  ['a duplicated project name', {
    retries: 1,
    projects: [{ name: 'a', specs: 'all' }, { name: 'a', specs: 'right-to-left' }],
  }],
  ['an empty project name', { retries: 1, projects: [{ name: '   ', specs: 'all' }] }],
  ['a project name that is not a string', { retries: 1, projects: [{ name: 7, specs: 'all' }] }],
  ['a negative retry budget', { retries: -1, projects: [{ name: 'a', specs: 'all' }] }],
  ['a fractional retry budget', { retries: 1.5, projects: [{ name: 'a', specs: 'all' }] }],
  ['a missing retry budget', { projects: [{ name: 'a', specs: 'all' }] }],
  ['an empty project list', { retries: 1, projects: [] }],
  ['a projects value that is not an array', { retries: 1, projects: {} }],
  ['a document that is not an object', [{ name: 'a', specs: 'all' }]],
];

let failures = 0;
for (const [label, document] of refused) {
  try {
    parseBrowserMatrix(JSON.stringify(document), 'fixture');
    process.stderr.write(`The manifest reader accepted ${label}, which both consumers read differently.\n`);
    failures += 1;
  } catch {
    // Refused, which is the contract.
  }
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
  `Browser manifest verified: ${refused.length} malformed documents refused, `
  + `${committed.projects.length} projects declared, ${committed.retries} retry budgeted.\n`,
);
