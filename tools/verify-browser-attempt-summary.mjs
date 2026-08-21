/**
 * Exercise the browser-attempt summarizer against Playwright's four aggregate outcomes.
 *
 * This is intentionally dependency-free: frontend static checks can prove retry accounting before a
 * browser or application server exists. In Playwright JSON, a test that fails first and passes on retry
 * is `flaky`; `unexpected` is a final failure, while `skipped` is also unacceptable evidence because the
 * journey did not run.
 *
 * @since  2.0.0
 */

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const temporary = mkdtempSync(join(tmpdir(), 'kumwe-browser-attempt-summary-'));
const reportPath = join(temporary, 'report.json');
const outputPath = join(temporary, 'summary.json');
const criticalPath = join(temporary, 'critical.json');

const test = (title, status, results) => ({
  title,
  tests: [{
    projectName: 'desktop-firefox',
    status,
    results: results.map((resultStatus) => ({ status: resultStatus })),
  }],
});

const report = {
  suites: [{
    title: 'Browser attempt semantics',
    specs: [
      test('passes as expected', 'expected', ['passed']),
      test('passes only after retry', 'flaky', ['failed', 'passed']),
      test('remains unexpected', 'unexpected', ['failed', 'failed']),
      test('is skipped', 'skipped', ['skipped']),
    ],
  }],
};

try {
  writeFileSync(reportPath, `${JSON.stringify(report)}\n`);
  writeFileSync(criticalPath, `${JSON.stringify({
    schema_version: 1,
    required_obligations: ['expected', 'retry', 'failure', 'skip', 'missing'],
    journeys: [
      {
        title: 'passes as expected',
        obligations: ['expected'],
        projects: ['desktop-firefox'],
      },
      {
        title: 'passes only after retry',
        obligations: ['retry'],
        projects: ['desktop-firefox'],
      },
      {
        title: 'remains unexpected',
        obligations: ['failure'],
        projects: ['desktop-firefox'],
      },
      {
        title: 'is skipped',
        obligations: ['skip'],
        projects: ['desktop-firefox'],
      },
      {
        title: 'is absent',
        obligations: ['missing'],
        projects: ['desktop-firefox'],
      },
    ],
  })}\n`);
  const result = spawnSync(
    process.execPath,
    [
      join(root, 'tools/summarize-browser-attempts.mjs'),
      `--results=${reportPath}`,
      `--out=${outputPath}`,
      `--critical=${criticalPath}`,
    ],
    { encoding: 'utf8' },
  );
  assert.equal(result.status, 0, result.stderr || result.stdout);

  const summary = JSON.parse(readFileSync(outputPath, 'utf8'));
  assert.equal(summary.total, 4);
  assert.equal(summary.firstAttemptPassed, 1);
  assert.equal(summary.firstAttemptPassRatePercent, 25);
  assert.deepEqual(summary.passedOnlyAfterRetry, [
    'desktop-firefox › Browser attempt semantics › passes only after retry',
  ]);
  assert.deepEqual(summary.failed, [
    'desktop-firefox › Browser attempt semantics › remains unexpected',
    'desktop-firefox › Browser attempt semantics › is skipped',
  ]);
  assert.equal(summary.critical.totalExpected, 5);
  assert.equal(summary.critical.firstAttemptPassed, 1);
  assert.deepEqual(summary.critical.passedOnlyAfterRetry, [
    'desktop-firefox › Browser attempt semantics › passes only after retry',
  ]);
  assert.deepEqual(summary.critical.failed, [
    'desktop-firefox › Browser attempt semantics › remains unexpected',
    'desktop-firefox › Browser attempt semantics › is skipped',
  ]);
  assert.deepEqual(summary.critical.missing, [
    'desktop-firefox › is absent',
  ]);
} finally {
  rmSync(temporary, { recursive: true, force: true });
}

console.log(
  'Browser attempt summary semantics verified: expected, flaky, unexpected, skipped and missing critical.',
);
