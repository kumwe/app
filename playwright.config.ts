import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/Browser',
  outputDir: './test-results/browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI
    ? [['line'], ['html', { outputFolder: 'test-results/playwright-report', open: 'never' }]]
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
  },
  projects: [
    {
      name: 'desktop-chromium',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 960 } },
    },
    {
      name: 'mobile-chromium',
      use: { ...devices['Pixel 7'] },
    },
  ],
});
