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

export default defineConfig({
  testDir: './tests/Browser',
  outputDir: './test-results/browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
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
  projects: [
    {
      name: 'desktop-chromium',
      testIgnore: rightToLeftSpec,
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 } },
    },
    {
      name: 'mobile-chromium',
      testIgnore: rightToLeftSpec,
      use: { ...devices['Pixel 7'] },
    },
    {
      name: 'desktop-chromium-he',
      testMatch: rightToLeftSpec,
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 }, locale: 'he-IL' },
    },
    {
      name: 'desktop-chromium-ar',
      testMatch: rightToLeftSpec,
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 }, locale: 'ar-EG' },
    },
    {
      name: 'mobile-chromium-he',
      testMatch: rightToLeftSpec,
      use: { ...devices['Pixel 7'], locale: 'he-IL' },
    },
    {
      name: 'mobile-chromium-ar',
      testMatch: rightToLeftSpec,
      use: { ...devices['Pixel 7'], locale: 'ar-EG' },
    },
    // The nightly breadth projects. `ignoreSnapshots` is deliberate and is not a weakening: a pixel
    // baseline belongs to the browser that recorded it, so comparing a Firefox or WebKit render against a
    // Chromium baseline reports font hinting rather than the product. Behaviour and accessibility are
    // asserted identically here; only the pixel comparison stays with the browser that owns the baselines.
    {
      name: 'desktop-firefox',
      testIgnore: rightToLeftSpec,
      ignoreSnapshots: true,
      use: { ...devices['Desktop Firefox'], viewport: { width: 1440, height: 960 } },
    },
    {
      name: 'desktop-webkit',
      testIgnore: rightToLeftSpec,
      ignoreSnapshots: true,
      use: { ...devices['Desktop Safari'], viewport: { width: 1440, height: 960 } },
    },
  ],
});
