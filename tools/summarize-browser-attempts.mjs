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
 *   node tools/summarize-browser-attempts.mjs [--results=PATH] [--out=PATH]
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

if (!existsSync(resultsPath)) {
  console.error(`No Playwright JSON report at ${resultsPath}; nothing to summarize.`);
  process.exit(0);
}

const report = JSON.parse(readFileSync(resultsPath, 'utf8'));
const journeys = [];

const walk = (suite, trail) => {
  const path = suite.title ? [...trail, suite.title] : trail;
  for (const spec of suite.specs ?? []) {
    for (const test of spec.tests ?? []) {
      const results = test.results ?? [];
      const first = results[0]?.status ?? 'unknown';
      journeys.push({
        title: [...path, spec.title].join(' › '),
        project: test.projectName ?? 'unknown',
        attempts: results.length,
        firstAttempt: first,
        outcome: test.status ?? 'unknown',
        retried: results.length > 1,
        passedOnlyAfterRetry: results.length > 1 && first !== 'passed' && test.status === 'expected',
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
const failed = journeys.filter((journey) => journey.outcome !== 'expected');
const rate = journeys.length === 0 ? 0 : (firstAttemptPassed / journeys.length) * 100;

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
for (const journey of summary.passedOnlyAfterRetry) {
  lines.push(`  - retried to green: ${journey}`);
}

console.log(lines.join('\n'));
if (process.env.GITHUB_STEP_SUMMARY) {
  appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${lines.join('\n')}\n`);
}
