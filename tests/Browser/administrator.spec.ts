import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';

async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

async function expectStylesLoaded(page: Page): Promise<void> {
  const failedStylesheets = await page.evaluate(() =>
    [...document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]')]
      .filter((stylesheet) => stylesheet.sheet === null)
      .map((stylesheet) => stylesheet.href),
  );
  expect(failedStylesheets).toEqual([]);
  expect(await page.locator('link[rel="stylesheet"]').count()).toBeGreaterThan(0);
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/);
}

test('login is accessible and visually stable', async ({ page }) => {
  await page.goto('/administrator/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
  await expect(page).toHaveScreenshot('login.png', { fullPage: true });
});

test('public presentation is responsive, substantial, and ready', async ({ page, request }, testInfo) => {
  const readiness = await request.get('/health/ready');
  expect(readiness.status()).toBe(200);

  await page.goto('/');
  await expect(
    page.getByRole('heading', { level: 1, name: /Content systems ready for what comes next/ }),
  ).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Structure once. Publish with confidence.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'One content core. Every delivery surface.' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open administrator/ }).first()).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);

  const visualContract = await page.evaluate(() => {
    const element = (selector: string): HTMLElement => {
      const match = document.querySelector(selector);
      if (!(match instanceof HTMLElement)) {
        throw new Error(`Missing visual-contract element: ${selector}`);
      }

      return match;
    };
    const columns = (selector: string): number =>
      getComputedStyle(element(selector)).gridTemplateColumns.trim().split(/\s+/).length;

    return {
      viewportWidth: window.innerWidth,
      horizontalOverflow: document.documentElement.scrollWidth - window.innerWidth,
      headerHeight: Math.round(element('.site-header').getBoundingClientRect().height),
      heroColumns: columns('.welcome-hero-grid'),
      capabilityColumns: columns('.welcome-card-grid'),
      surfaceColumns: columns('.welcome-surface-list'),
      headingSize: Math.round(Number.parseFloat(getComputedStyle(element('h1')).fontSize) * 10) / 10,
      bodyBackground: getComputedStyle(document.body).backgroundColor,
      platformBackground: getComputedStyle(element('.welcome-platform')).backgroundColor,
      primaryBackground: getComputedStyle(element('.welcome-primary')).backgroundColor,
    };
  });
  const mobile = testInfo.project.name.startsWith('mobile-');
  expect(visualContract).toEqual({
    viewportWidth: mobile ? 412 : 1440,
    horizontalOverflow: 0,
    headerHeight: mobile ? 73 : 81,
    heroColumns: mobile ? 1 : 2,
    capabilityColumns: mobile ? 1 : 3,
    surfaceColumns: mobile ? 1 : 2,
    headingSize: mobile ? 61.8 : 97.9,
    bodyBackground: 'rgb(245, 248, 251)',
    platformBackground: 'rgb(7, 24, 45)',
    primaryBackground: 'rgb(7, 24, 45)',
  });

  await page.screenshot({
    path: testInfo.outputPath('public-home.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
});

test.describe('authenticated administrator', () => {
  test.beforeEach(async ({ page }) => signIn(page));

  test('dashboard supports desktop and responsive navigation', async ({ page, isMobile }) => {
    await expect(page.getByRole('heading', { name: 'Good work starts with a clear view.' })).toBeVisible();
    await expectStylesLoaded(page);
    if (isMobile) {
      const toggle = page.getByRole('button', { name: 'Open administrator navigation' });
      await toggle.click();
      await expect(page.getByRole('navigation', { name: 'Administrator navigation' })).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(toggle).toBeFocused();
    }
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('dashboard.png', { fullPage: true });
  });

  test('content discovery and graphical editor work without raw JSON', async ({ page }) => {
    await page.goto('/administrator/content');
    await expect(page.getByRole('heading', { name: 'Content', exact: true })).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search' }).fill('launch');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/q=launch/);
    await expectAccessible(page);

    await page.goto('/administrator/content/new');
    await expect(page.getByRole('heading', { name: 'Create content' })).toBeVisible();
    await expect(page.getByText('JSON', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'Rich text editor' })).toBeVisible();
    await expect(page.getByRole('toolbar', { name: 'Text formatting' })).toBeVisible();
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('content-editor.png', { fullPage: true });
  });

  test('media library is usable and accessible', async ({ page }) => {
    await page.goto('/administrator/media');
    await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
    await expect(page.getByLabel('File type')).toBeVisible();
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('media-library.png', { fullPage: true });
  });

  test('automation uses generated job controls rather than JSON', async ({ page }) => {
    await page.goto('/administrator/automation');
    await expect(page.getByRole('heading', { name: 'Automation' })).toBeVisible();
    await expect(page.locator('textarea[name="payload"]')).toHaveCount(0);
    await expect(page.getByLabel('Job type')).toBeVisible();
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('automation.png', {
      fullPage: true,
      mask: [page.locator('[data-visual-dynamic]')],
      maskColor: '#ffffff',
    });
  });
});
