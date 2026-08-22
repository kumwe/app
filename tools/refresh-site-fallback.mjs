import {
  lstatSync,
  readFileSync,
  realpathSync,
  renameSync,
  rmSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { dirname, extname, isAbsolute, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const targetPath = resolve(root, 'public/assets/site.css');
const siteEntry = 'assets/site/main.ts';
const manifestFields = new Set([
  'file',
  'name',
  'names',
  'src',
  'isEntry',
  'isDynamicEntry',
  'imports',
  'dynamicImports',
  'css',
  'assets',
]);
const arguments_ = process.argv.slice(2);
const check = arguments_.length === 1 && arguments_[0] === '--check';

if (arguments_.length !== 0 && !check) {
  process.stderr.write('Usage: node tools/refresh-site-fallback.mjs [--check]\n');
  process.exit(64);
}

const fail = (message) => {
  throw new Error(message);
};

const normalizedPath = (value, kind) => {
  if (
    typeof value !== 'string'
    || value === ''
    || value.includes('\0')
    || value.includes('\\')
    || value.startsWith('/')
    || /^[A-Za-z]:/.test(value)
    || value.split('/').some((segment) => segment === '' || segment === '.' || segment === '..')
  ) {
    fail(`The ${kind} path ${String(value)} is not a normalized relative POSIX path.`);
  }
  return value;
};

const regularFile = (base, value, kind) => {
  const relativePath = normalizedPath(value, kind);
  let current = base;
  for (const segment of relativePath.split('/')) {
    current = resolve(current, segment);
    let status;
    try {
      status = lstatSync(current);
    } catch {
      fail(`The ${kind} path ${relativePath} is missing.`);
    }
    if (status.isSymbolicLink()) {
      fail(`The ${kind} path ${relativePath} contains a symlink.`);
    }
  }
  if (!statSync(current).isFile()) {
    fail(`The ${kind} path ${relativePath} is not a regular file.`);
  }
  const fromBase = relative(realpathSync(base), realpathSync(current));
  if (isAbsolute(fromBase) || fromBase === '..' || fromBase.startsWith(`..${sep}`)) {
    fail(`The ${kind} path ${relativePath} resolves outside its owned directory.`);
  }
  return current;
};

const stringList = (record, field, key) => {
  if (!(field in record)) {
    return [];
  }
  const values = record[field];
  if (!Array.isArray(values)) {
    fail(`The Vite manifest field ${key}.${field} must be a list.`);
  }
  const seen = new Set();
  for (const value of values) {
    if (typeof value !== 'string' || value === '' || seen.has(value)) {
      fail(`The Vite manifest field ${key}.${field} must contain unique non-empty strings.`);
    }
    seen.add(value);
  }
  return [...seen];
};

const outputPath = (value, field) => {
  normalizedPath(value, field);
  const path = regularFile(root, `public/assets/build/${value}`, field);
  if (extname(value).toLowerCase() === '.css' && (!value.endsWith('.css') || !value.startsWith('css/'))) {
    fail(`The CSS output ${value} named by ${field} must be a lowercase .css file below build/css.`);
  }
  return path;
};

const readManifest = () => {
  const source = readFileSync(
    regularFile(root, 'public/assets/build/.vite/manifest.json', 'Vite manifest'),
    'utf8',
  );
  let decoded;
  try {
    decoded = JSON.parse(source);
  } catch (error) {
    fail(`The Vite manifest is invalid JSON: ${error instanceof Error ? error.message : String(error)}`);
  }
  if (decoded === null || typeof decoded !== 'object' || Array.isArray(decoded) || Object.keys(decoded).length === 0) {
    fail('The Vite manifest must contain a non-empty object.');
  }

  const manifest = Object.create(null);
  for (const [key, value] of Object.entries(decoded)) {
    normalizedPath(key, 'Vite manifest key');
    if (value === null || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length === 0) {
      fail(`The Vite manifest record ${key} must be a non-empty object.`);
    }
    for (const field of Object.keys(value)) {
      if (!manifestFields.has(field)) {
        fail(`The Vite manifest record ${key} has unsupported field ${field}.`);
      }
    }
    if (typeof value.file !== 'string' || value.file === '') {
      fail(`The Vite manifest record ${key} has no output file.`);
    }
    outputPath(value.file, `${key}.file`);
    const css = stringList(value, 'css', key);
    for (const stylesheet of css) {
      if (!stylesheet.endsWith('.css')) {
        fail(`The Vite manifest field ${key}.css names non-CSS output ${stylesheet}.`);
      }
      outputPath(stylesheet, `${key}.css`);
    }
    const assets = stringList(value, 'assets', key);
    for (const asset of assets) {
      outputPath(asset, `${key}.assets`);
    }
    const imports = stringList(value, 'imports', key);
    const dynamicImports = stringList(value, 'dynamicImports', key);
    const names = stringList(value, 'names', key);
    if ('src' in value) {
      normalizedPath(value.src, `${key}.src`);
    }
    if ('name' in value && (typeof value.name !== 'string' || value.name === '')) {
      fail(`The Vite manifest field ${key}.name must be a non-empty string.`);
    }
    for (const booleanField of ['isEntry', 'isDynamicEntry']) {
      if (booleanField in value && typeof value[booleanField] !== 'boolean') {
        fail(`The Vite manifest field ${key}.${booleanField} must be boolean.`);
      }
    }
    manifest[key] = {
      ...value,
      css,
      assets,
      imports,
      dynamicImports,
      names,
    };
  }
  for (const [key, record] of Object.entries(manifest)) {
    for (const dependency of [...record.imports, ...record.dynamicImports]) {
      if (!Object.hasOwn(manifest, dependency)) {
        fail(`The Vite manifest record ${key} references missing chunk ${dependency}.`);
      }
    }
  }
  const entry = manifest[siteEntry];
  if (!Object.hasOwn(manifest, siteEntry) || entry.isEntry !== true || entry.src !== siteEntry) {
    fail(`The Vite manifest must carry the owned entry ${siteEntry} with matching src and isEntry.`);
  }
  regularFile(root, siteEntry, `${siteEntry}.src`);
  return manifest;
};

const recordCss = (record, seen, ordered) => {
  const values = [...record.css];
  if (record.file.endsWith('.css')) {
    values.push(record.file);
  }
  for (const asset of record.assets) {
    if (asset.endsWith('.css')) {
      values.push(asset);
    }
  }
  for (const value of values) {
    if (seen.has(value)) {
      continue;
    }
    seen.add(value);
    ordered.push(value);
  }
};

const siteStylesheets = (manifest) => {
  const state = new Map();
  const seen = new Set();
  const ordered = [];
  const visit = (key, entry = false) => {
    if (state.get(key) === 1) {
      fail(`The Vite site manifest import closure contains a cycle at ${key}.`);
    }
    if (state.get(key) === 2) {
      return;
    }
    state.set(key, 1);
    const record = manifest[key];
    if (entry) {
      recordCss(record, seen, ordered);
    }
    for (const dependency of record.imports) {
      visit(dependency);
    }
    if (!entry) {
      recordCss(record, seen, ordered);
    }
    state.set(key, 2);
  };
  visit(siteEntry, true);
  if (ordered.length === 0) {
    fail('The recursive Vite site entry closure declares no CSS.');
  }
  return ordered;
};

const publicUrl = (value) => value.startsWith('/')
  || value.startsWith('#')
  || /^[a-z][a-z0-9+.-]*:/i.test(value);

const canonicalCssForInspection = (source, stylesheet) => {
  const contents = source.toString('utf8');
  if (contents.includes('\\')) {
    fail(`The built stylesheet ${stylesheet} contains a CSS escape that can hide a relocation-sensitive identifier.`);
  }
  let canonical = '';
  let quote = null;
  for (let index = 0; index < contents.length; index += 1) {
    const character = contents[index];
    if (quote !== null) {
      canonical += character;
      if (character === quote) quote = null;
      continue;
    }
    if (character === "'" || character === '"') {
      quote = character;
      canonical += character;
      continue;
    }
    if (character !== '/' || contents[index + 1] !== '*') {
      canonical += character;
      continue;
    }
    const end = contents.indexOf('*/', index + 2);
    if (end === -1) {
      fail(`The built stylesheet ${stylesheet} contains an unterminated comment.`);
    }
    if (/[A-Za-z0-9_-]/.test(contents[index - 1] ?? '')
      && /[A-Za-z0-9_-]/.test(contents[end + 2] ?? '')) {
      fail(`The built stylesheet ${stylesheet} contains a comment that splits a CSS identifier.`);
    }
    canonical += contents.slice(index, end + 2).replace(/[^\r\n]/g, '');
    index = end + 1;
  }
  if (quote !== null) {
    fail(`The built stylesheet ${stylesheet} contains an unterminated string.`);
  }
  return canonical;
};

const rejectUnrelocatableCss = (source, stylesheet) => {
  const contents = canonicalCssForInspection(source, stylesheet);
  if (/@import\b[^;]*(?:['"(]\s*)data\s*:/i.test(contents)) {
    fail(`The built stylesheet ${stylesheet} contains a data: CSS @import.`);
  }
  if (/@import\b/i.test(contents)) {
    fail(`The built stylesheet ${stylesheet} retains an @import outside the emitted manifest graph.`);
  }
  for (const match of contents.matchAll(/url\(\s*(?:(['"])(.*?)\1|([^)]*))\s*\)/gis)) {
    const value = (match[2] ?? match[3] ?? '').trim();
    if (value === '' || !publicUrl(value)) {
      fail(
        `The built stylesheet ${stylesheet} contains relative url() value ${value} that the fallback cannot relocate.`,
      );
    }
  }
};

const buildFallback = () => {
  const manifest = readManifest();
  const stylesheets = siteStylesheets(manifest);
  const chunks = stylesheets.map((stylesheet) => {
    const path = outputPath(stylesheet, 'site manifest stylesheet');
    const source = readFileSync(path);
    rejectUnrelocatableCss(source, stylesheet);
    return source;
  });
  const pieces = [];
  for (const [index, chunk] of chunks.entries()) {
    if (index !== 0) {
      pieces.push(Buffer.from('\n'));
    }
    pieces.push(chunk);
  }
  return { expected: Buffer.concat(pieces), stylesheets };
};

try {
  const { expected, stylesheets } = buildFallback();
  if (check) {
    const actual = readFileSync(regularFile(root, 'public/assets/site.css', 'site runtime fallback'));
    if (!actual.equals(expected)) {
      fail('The committed public/assets/site.css fallback is stale. Run npm run build.');
    }
    process.stdout.write(`The site fallback is current (${stylesheets.length} recursive manifest stylesheet(s)).\n`);
  } else {
    const parent = regularFile(root, 'public/assets/portal.css', 'runtime fallback anchor');
    if (dirname(parent) !== dirname(targetPath)) {
      fail('The site fallback target directory is not the owned public/assets directory.');
    }
    try {
      const targetStatus = lstatSync(targetPath);
      if (targetStatus.isSymbolicLink() || !targetStatus.isFile()) {
        fail('The site fallback target is linked or not a regular file.');
      }
    } catch (error) {
      if (!(error instanceof Error) || !('code' in error) || error.code !== 'ENOENT') {
        throw error;
      }
    }
    const temporary = `${targetPath}.tmp-${process.pid}`;
    let created = false;
    try {
      writeFileSync(temporary, expected, { flag: 'wx', mode: 0o644 });
      created = true;
      renameSync(temporary, targetPath);
    } finally {
      if (created) {
        rmSync(temporary, { force: true });
      }
    }
    process.stdout.write(
      `Refreshed public/assets/site.css from ${stylesheets.length} recursive manifest stylesheet(s).\n`,
    );
  }
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 1;
}
