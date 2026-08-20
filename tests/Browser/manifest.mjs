/**
 * The one reader for the browser project manifest, shared by every JavaScript consumer.
 *
 * The manifest exists so a project cannot run the portal journeys without an approval identity: the
 * Playwright configuration builds its matrix from it and the PHP seeder provisions fixtures from it.
 * That guarantee is only real if both sides refuse the same documents. They did not: the configuration
 * treated any `specs` value other than `right-to-left` as "run every journey", while the seeder
 * provisioned only for exactly `all`, so a misspelling ran the maker-checker journey on a project with
 * no identity — recreating the once-per-account TOTP refusal the manifest was introduced to prevent,
 * and doing it while every guard still passed. Nothing here is lenient for that reason.
 */

/** Every value `specs` may take. Anything else is refused rather than interpreted. */
export const specScopes = ['all', 'right-to-left'];

/**
 * Read and validate a browser project manifest, or throw naming the first thing wrong with it.
 *
 * @param {string} source Manifest JSON.
 * @param {string} origin Path reported in failures, so the message names the file to fix.
 * @returns {{ retries: number, projects: { name: string, specs: string }[] }} The validated manifest.
 */
export function parseBrowserMatrix(source, origin = 'tests/Browser/projects.json') {
  let raw;
  try {
    raw = JSON.parse(source);
  } catch (cause) {
    throw new Error(`${origin} is not valid JSON: ${cause instanceof Error ? cause.message : cause}`);
  }
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    throw new Error(`${origin} must be a JSON object.`);
  }

  const { retries, projects } = raw;
  if (typeof retries !== 'number' || !Number.isInteger(retries) || retries < 0) {
    throw new Error(`${origin} needs "retries" as a non-negative integer; found ${JSON.stringify(retries)}.`);
  }
  if (!Array.isArray(projects) || projects.length === 0) {
    throw new Error(`${origin} needs a non-empty "projects" array.`);
  }

  const seen = new Set();
  const validated = projects.map((entry, index) => {
    if (entry === null || typeof entry !== 'object' || Array.isArray(entry)) {
      throw new Error(`${origin} project ${index} must be a JSON object.`);
    }
    const { name, specs } = entry;
    if (typeof name !== 'string' || name.trim() === '') {
      throw new Error(`${origin} project ${index} needs a non-empty "name"; found ${JSON.stringify(name)}.`);
    }
    if (seen.has(name)) {
      throw new Error(`${origin} declares "${name}" more than once; project names address fixtures and must be unique.`);
    }
    seen.add(name);
    if (typeof specs !== 'string' || !specScopes.includes(specs)) {
      throw new Error(
        `${origin} project "${name}" needs "specs" to be one of ${specScopes.join(' | ')}; found `
        + `${JSON.stringify(specs)}. A value that is neither runs every journey in Playwright while the `
        + 'seeder provisions nothing, which is the drift this manifest exists to make impossible.',
      );
    }
    return { name, specs };
  });

  return { retries, projects: validated };
}
