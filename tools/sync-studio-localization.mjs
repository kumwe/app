#!/usr/bin/env node

import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const testkitRoot = fileURLToPath(
  new URL('../', import.meta.resolve('@kumwe/studio-testkit')),
);
const corpusPath = resolve(testkitRoot, 'fixtures/authoring-message-catalog.en.json');
const cataloguePath = resolve(root, 'resources/localization/messages/en-GB.xlf');
const releasePath = resolve(root, 'resources/studio-contract/studio-release.json');
const begin = '  <!-- BEGIN GENERATED STUDIO AUTHORING MESSAGES -->';
const end = '  <!-- END GENERATED STUDIO AUTHORING MESSAGES -->';

const xml = (value) => value
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&apos;');

const corpus = JSON.parse(await readFile(corpusPath, 'utf8'));
const releaseRecord = JSON.parse(await readFile(releasePath, 'utf8'));
if (releaseRecord.kind !== 'studio-release' || typeof releaseRecord.release !== 'string') {
  throw new Error('The canonical Studio release record is malformed.');
}
const entries = Object.entries(corpus.messages).sort(([left], [right]) => left.localeCompare(right));
if (entries.length !== 160) throw new Error(`Expected 160 Studio authoring messages; received ${entries.length}.`);

const units = entries.map(([wireId, message]) => {
  if (!wireId.startsWith('studio.shell/')) throw new Error(`Unexpected Studio namespace: ${wireId}`);
  const id = `core.studio.shell.${wireId.slice('studio.shell/'.length)}`;
  const context = `Exact @kumwe/studio ${xml(releaseRecord.release)} authoring message ${xml(wireId)}.`;
  return [
    `    <unit id="${xml(id)}">`,
    '      <notes>',
    `        <note category="context">${context}</note>`,
    '      </notes>',
    '      <segment>',
    `        <source>${xml(message.defaultMessage)}</source>`,
    '      </segment>',
    '    </unit>',
  ].join('\n');
}).join('\n');
const generated = `${begin}\n  <file id="core-studio-authoring">\n${units}\n  </file>\n${end}`;

const current = await readFile(cataloguePath, 'utf8');
let expected;
if (current.includes(begin) && current.includes(end)) {
  expected = current.replace(new RegExp(`${begin}[\\s\\S]*?${end}`), generated);
} else {
  expected = current.replace('\n</xliff>\n', `\n${generated}\n</xliff>\n`);
}
if (process.argv.includes('--check')) {
  if (expected !== current) {
    console.error('The App Studio localization corpus is stale; run node tools/sync-studio-localization.mjs.');
    process.exit(1);
  }
  console.log('The exact 160-key Studio authoring message corpus is present in App XLIFF.');
} else {
  await writeFile(cataloguePath, expected);
  console.log('Synchronized 160 Studio authoring messages into App XLIFF.');
}
