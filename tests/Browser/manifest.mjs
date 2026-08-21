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
 *
 * This reader and `Kumwe\App\Tests\Support\BrowserProjectManifest` are held to one corpus,
 * `tests/Browser/manifest-cases.json`, which carries raw sources rather than structured documents so
 * that the forms the two languages read differently survive into the cases.
 */

/** Every value `specs` may take. Anything else is refused rather than interpreted. */
export const specScopes = ['all', 'right-to-left', 'breadth'];

/**
 * The largest retry budget the manifest may declare.
 *
 * The ceiling is a correctness device, not a preference. Above it the two languages stop agreeing:
 * `9007199254740993` is held exactly by PHP and rounded by JavaScript, and `1e21` is an integer to
 * `Number.isInteger` but a float to `is_int`. Refusing every such magnitude keeps the two readings
 * identical for every document either side accepts, and no real matrix reruns a journey a hundred
 * times.
 */
export const maxRetries = 100;

/**
 * Whether a decoded value is a retry budget both consumers read the same way.
 *
 * JSON has one number type, so `1`, `1.0` and `1e0` are the same value written three ways. JavaScript
 * cannot tell them apart after parsing and PHP can, which makes a rule keyed on the written form one
 * the two sides cannot both implement. The rule is therefore keyed on the value.
 *
 * @param {unknown} value Decoded `retries` value.
 * @returns {boolean} Whether it is a whole number within the budget ceiling.
 */
function isRetryBudget(value) {
  return typeof value === 'number'
    && Number.isFinite(value)
    && Number.isInteger(value)
    && value >= 0
    && value <= maxRetries;
}

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
  if (!isRetryBudget(retries)) {
    throw new Error(
      `${origin} needs "retries" as a whole number from 0 to ${maxRetries}; found `
      + `${JSON.stringify(retries) ?? String(retries)}.`,
    );
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

  // `-0` decodes to negative zero here and to plain zero in PHP; both mean no retries, and normalising
  // is what lets the corpus assert one reading for the two consumers rather than two equal-but-distinct
  // ones.
  return { retries: retries === 0 ? 0 : retries, projects: validated };
}
