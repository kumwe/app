import { readFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

/**
 * The browser matrix has two axes: the device a page is drawn on, and the language it is drawn in.
 *
 * The device axis existed from the start. The language axis is here because a right-to-left page
 * compared against a left-to-right baseline proves nothing: the two are meant to differ, so such a
 * comparison is either a false failure or a green run that checked nothing. Baselines are stored per
 * project and the project name carries the locale, which gives `he` and `ar` a place of their own to
 * be compared against rather than a shared one they would always disagree with.
 *
 * The source-language projects keep their original names, because their committed baselines are
 * filed under those names and renaming a project would silently orphan every one of them.
 */
const rightToLeftSpec = /right-to-left\.spec\.ts/;

/**
 * The browser matrix is defined once, in tests/Browser/projects.json, because two languages read it.
 *
 * This file builds its projects from that manifest and tests/Support/prepare-browser-contribution.php
 * seeds its fixtures from the same file, so a project cannot exist in one and be missing from the other.
 * It used to be possible: the maker-checker journey needs an approval identity per project, the seeder
 * named its projects in PHP, and nothing connected the two — two projects silently shared one account,
 * and because TOTP enrollment cannot be repeated the second met a refusal that surfaced only as a
 * ninety-second timeout. Deriving both from one file is what makes that unrepresentable rather than
 * merely tested for.
 */
interface BrowserProject {
  readonly name: string;
  readonly specs: 'all' | 'right-to-left';
}

const matrix = JSON.parse(
  readFileSync(new URL('./tests/Browser/projects.json', import.meta.url), 'utf8'),
) as { readonly retries: number; readonly projects: readonly BrowserProject[] };

/** Emulation and comparison options per project; the manifest decides which projects exist. */
const projectOptions: Record<string, Record<string, unknown>> = {
  'desktop-chromium': { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 } },
  'mobile-chromium': { ...devices['Pixel 7'] },
  'desktop-chromium-he': { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 }, locale: 'he-IL' },
  'desktop-chromium-ar': { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 }, locale: 'ar-EG' },
  'mobile-chromium-he': { ...devices['Pixel 7'], locale: 'he-IL' },
  'mobile-chromium-ar': { ...devices['Pixel 7'], locale: 'ar-EG' },
  // `ignoreSnapshots` on the breadth projects is deliberate and is not a weakening: a pixel baseline
  // belongs to the browser that recorded it, so comparing a Firefox or WebKit render against a Chromium
  // baseline reports font hinting rather than the product. Behaviour and accessibility are asserted
  // identically on all three engines; only the pixel comparison stays with the browser that owns them.
  'desktop-firefox': { ...devices['Desktop Firefox'], viewport: { width: 1440, height: 960 } },
  'desktop-webkit': { ...devices['Desktop Safari'], viewport: { width: 1440, height: 960 } },
};

const snapshotIgnoringProjects = new Set(['desktop-firefox', 'desktop-webkit']);

export default defineConfig({
  testDir: './tests/Browser',
  outputDir: './test-results/browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? matrix.retries : 0,
  workers: 1,
  // The JSON report is what tools/summarize-browser-attempts.mjs reads to separate a journey that passed
  // on its first attempt from one that only passed on a retry. Reporting the two together hides the
  // difference, and the difference is the whole signal.
  reporter: process.env.CI
    ? [
        ['line'],
        ['html', { outputFolder: 'test-results/playwright-report', open: 'never' }],
        ['json', { outputFile: 'test-results/browser-results.json' }],
      ]
    : 'list',
  expect: {
    timeout: 10_000,
    toHaveScreenshot: {
      animations: 'disabled',
      caret: 'hide',
      maxDiffPixelRatio: 0.003,
    },
  },
  snapshotPathTemplate: '{testDir}/screenshots/{projectName}/{arg}{ext}',
  webServer: process.env.KUMWE_BROWSER_START_SERVER === '1'
    ? {
        command: 'sh tools/development-server.sh',
        url: 'http://127.0.0.1:8080/health/ready',
        reuseExistingServer: false,
        timeout: 30_000,
      }
    : undefined,
  use: {
    baseURL: process.env.KUMWE_BROWSER_BASE_URL ?? 'http://127.0.0.1:8080',
    colorScheme: 'light',
    locale: 'en-NA',
    timezoneId: 'Africa/Windhoek',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    // A pre-provisioned Chromium (for example an offline CI or development container) may not
    // match the pinned Playwright revision; naming its binary here avoids a network install.
    ...(process.env.KUMWE_BROWSER_CHROMIUM
      ? { launchOptions: { executablePath: process.env.KUMWE_BROWSER_CHROMIUM } }
      : {}),
  },
  projects: matrix.projects.map((project) => ({
    name: project.name,
    ...(project.specs === 'right-to-left'
      ? { testMatch: rightToLeftSpec }
      : { testIgnore: rightToLeftSpec }),
    ...(snapshotIgnoringProjects.has(project.name) ? { ignoreSnapshots: true } : {}),
    use: projectOptions[project.name] ?? {},
  })),
});
