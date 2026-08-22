/**
 * Separate the journeys that passed on their first attempt from the ones that only passed on a retry.
 *
 * A retry is a useful mechanism and a misleading report. A run that shows "all green" while a third of its
 * critical journeys needed a second attempt is describing a product that works, and the product does not:
 * the acceptance the roadmap states is a first-attempt pass rate, so first-attempt results have to be
 * visible on their own. This reads Playwright's JSON report and writes both a machine-readable summary and
 * a step summary that names the commit, engine, browser project and locale the results came from, so the
 * evidence identifies its own run rather than being a number somebody has to place.
 *
 * Usage:
 *   node tools/summarize-browser-attempts.mjs [--results=PATH] [--out=PATH] [--critical=PATH]
 *
 * @since  2.0.0
 */

import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const argument = (name, fallback) => {
  const match = process.argv.slice(2).find((entry) => entry.startsWith(`--${name}=`));
  return match ? match.slice(name.length + 3) : fallback;
};

const resultsPath = argument('results', 'test-results/browser-results.json');
const outPath = argument('out', 'test-results/browser-attempts.json');
const criticalPath = argument('critical', '');

if (!existsSync(resultsPath)) {
  console.error(`No Playwright JSON report at ${resultsPath}; nothing to summarize.`);
  process.exit(0);
}

const report = JSON.parse(readFileSync(resultsPath, 'utf8'));
const journeys = [];

/** Load and validate the closed set of journeys that must pass on their first attempt. */
const readCriticalContract = (path) => {
  if (path === '') {
    return null;
  }
  if (!existsSync(path)) {
    throw new Error(`Critical browser journey contract ${path} does not exist.`);
  }

  const contract = JSON.parse(readFileSync(path, 'utf8'));
  if (contract === null || typeof contract !== 'object' || Array.isArray(contract)) {
    throw new Error(`Critical browser journey contract ${path} must be a JSON object.`);
  }
  if (contract.schema_version !== 1) {
    throw new Error(`Critical browser journey contract ${path} must declare schema_version 1.`);
  }

  const required = contract.required_obligations;
  if (!Array.isArray(required) || required.length === 0
    || required.some((value) => typeof value !== 'string' || value.trim() === '')) {
    throw new Error(`${path} required_obligations must be a non-empty string list.`);
  }
  if (new Set(required).size !== required.length) {
    throw new Error(`${path} required_obligations must not contain duplicates.`);
  }

  if (!Array.isArray(contract.journeys) || contract.journeys.length === 0) {
    throw new Error(`${path} journeys must be a non-empty list.`);
  }

  const expanded = [];
  const covered = new Set();
  const identities = new Set();
  for (const [index, journey] of contract.journeys.entries()) {
    if (journey === null || typeof journey !== 'object' || Array.isArray(journey)) {
      throw new Error(`${path} journey ${index} must be an object.`);
    }
    const keys = Object.keys(journey).sort();
    if (JSON.stringify(keys) !== JSON.stringify(['obligations', 'projects', 'title'])) {
      throw new Error(`${path} journey ${index} must contain only obligations, projects and title.`);
    }
    if (typeof journey.title !== 'string' || journey.title.trim() === '') {
      throw new Error(`${path} journey ${index} title must be a non-empty string.`);
    }
    if (!Array.isArray(journey.obligations) || journey.obligations.length === 0
      || journey.obligations.some((value) => typeof value !== 'string' || !required.includes(value))) {
      throw new Error(`${path} journey ${index} obligations must come from required_obligations.`);
    }
    if (new Set(journey.obligations).size !== journey.obligations.length) {
      throw new Error(`${path} journey ${index} obligations must not contain duplicates.`);
    }
    if (!Array.isArray(journey.projects) || journey.projects.length === 0
      || journey.projects.some((value) => typeof value !== 'string' || value.trim() === '')) {
      throw new Error(`${path} journey ${index} projects must be a non-empty string list.`);
    }
    if (new Set(journey.projects).size !== journey.projects.length) {
      throw new Error(`${path} journey ${index} projects must not contain duplicates.`);
    }

    journey.obligations.forEach((obligation) => covered.add(obligation));
    for (const project of journey.projects) {
      const identity = JSON.stringify([project, journey.title]);
      if (identities.has(identity)) {
        throw new Error(`${path} repeats critical project/title pair ${project} › ${journey.title}.`);
      }
      identities.add(identity);
      expanded.push({ project, title: journey.title });
    }
  }

  const uncovered = required.filter((obligation) => !covered.has(obligation));
  if (uncovered.length > 0) {
    throw new Error(`${path} has no journey for required obligations: ${uncovered.join(', ')}.`);
  }

  return {
    path,
    requiredObligations: required,
    expected: expanded,
  };
};

const criticalContract = readCriticalContract(criticalPath);

const walk = (suite, trail) => {
  const path = suite.title ? [...trail, suite.title] : trail;
  for (const spec of suite.specs ?? []) {
    for (const test of spec.tests ?? []) {
      const results = test.results ?? [];
      const first = results[0]?.status ?? 'unknown';
      journeys.push({
        title: [...path, spec.title].join(' › '),
        specTitle: spec.title,
        project: test.projectName ?? 'unknown',
        attempts: results.length,
        firstAttempt: first,
        outcome: test.status ?? 'unknown',
        retried: results.length > 1,
        passedOnlyAfterRetry: results.length > 1
          && first !== 'passed'
          && results.at(-1)?.status === 'passed'
          && test.status === 'flaky',
        errors: results.flatMap((result) => [
          ...(result.error ? [result.error] : []),
          ...(result.errors ?? []),
        ].map((error) => error?.message ?? '')).filter(Boolean),
      });
    }
  }
  for (const child of suite.suites ?? []) {
    walk(child, path);
  }
};

for (const suite of report.suites ?? []) {
  walk(suite, []);
}

const firstAttemptPassed = journeys.filter((journey) => journey.firstAttempt === 'passed').length;
const retriedToGreen = journeys.filter((journey) => journey.passedOnlyAfterRetry);
// Playwright calls a test that reaches its expected result only on retry `flaky`, not `expected`.
// Both outcomes are final-green to the runner; first-attempt acceptance remains a separate figure.
const finalGreenOutcomes = new Set(['expected', 'flaky']);
const failed = journeys.filter((journey) => !finalGreenOutcomes.has(journey.outcome));
const rate = journeys.length === 0 ? 0 : (firstAttemptPassed / journeys.length) * 100;

let critical = null;
if (criticalContract !== null) {
  const selected = [];
  const missing = [];
  for (const expected of criticalContract.expected) {
    const matches = journeys.filter((journey) => (
      journey.project === expected.project && journey.specTitle === expected.title
    ));
    if (matches.length === 0) {
      missing.push(`${expected.project} › ${expected.title}`);
      continue;
    }
    if (matches.length > 1) {
      throw new Error(
        `Critical journey ${expected.project} › ${expected.title} appeared ${matches.length} times.`,
      );
    }
    selected.push(matches[0]);
  }

  critical = {
    contract: criticalContract.path,
    requiredObligations: criticalContract.requiredObligations,
    totalExpected: criticalContract.expected.length,
    firstAttemptPassed: selected.filter((journey) => journey.firstAttempt === 'passed').length,
    passedOnlyAfterRetry: selected
      .filter((journey) => (
        journey.firstAttempt !== 'passed' && finalGreenOutcomes.has(journey.outcome)
      ))
      .map((journey) => `${journey.project} › ${journey.title}`),
    failed: selected
      .filter((journey) => !finalGreenOutcomes.has(journey.outcome))
      .map((journey) => `${journey.project} › ${journey.title}`),
    missing,
  };
}

const summary = {
  commit: process.env.GITHUB_SHA ?? 'local',
  engine: process.env.KUMWE_BROWSER_ENGINE ?? process.env.DB_DRIVER ?? 'unknown',
  locale: process.env.KUMWE_BROWSER_LOCALE ?? 'en-NA',
  projects: [...new Set(journeys.map((journey) => journey.project))],
  total: journeys.length,
  firstAttemptPassed,
  firstAttemptPassRatePercent: Number(rate.toFixed(2)),
  passedOnlyAfterRetry: retriedToGreen.map((journey) => `${journey.project} › ${journey.title}`),
  failed: failed.map((journey) => `${journey.project} › ${journey.title}`),
  ...(critical === null ? {} : { critical }),
};

mkdirSync(dirname(outPath), { recursive: true });
writeFileSync(outPath, `${JSON.stringify(summary, null, 2)}\n`);

const lines = [
  '### Browser journeys',
  '',
  `- engine: \`${summary.engine}\`, projects: \`${summary.projects.join(', ')}\`, locale: \`${summary.locale}\``,
  `- first-attempt pass rate: **${summary.firstAttemptPassRatePercent}%** of ${summary.total} journeys`,
  `- passed only after a retry: **${summary.passedOnlyAfterRetry.length}**`,
  `- failed: **${summary.failed.length}**`,
];
if (critical !== null) {
  lines.push(
    `- critical first-attempt passes: **${critical.firstAttemptPassed}** of ${critical.totalExpected}`,
    `- critical passed only after retry: **${critical.passedOnlyAfterRetry.length}**`,
    `- critical failed: **${critical.failed.length}**`,
    `- critical missing: **${critical.missing.length}**`,
  );
}
for (const journey of summary.passedOnlyAfterRetry) {
  lines.push(`  - retried to green: ${journey}`);
}

// The line reporter prints failure detail mid-log, where a bounded log window cannot reach it.
// Repeating each failure's error text here places the evidence at the end of the job log, so the
// failure is diagnosable from the log tail alone even when the report artifact is unreachable.
const stripAnsi = (value) => value.replaceAll(/\[[0-9;]*m/gu, '');
for (const journey of failed) {
  lines.push('', `#### Failed: ${journey.project} › ${journey.title}`);
  for (const error of journey.errors.slice(0, 2)) {
    lines.push('```', ...stripAnsi(error).split('\n').slice(0, 40), '```');
  }
}

console.log(lines.join('\n'));
if (process.env.GITHUB_STEP_SUMMARY) {
  appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${lines.join('\n')}\n`);
}
