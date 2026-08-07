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

test('login is accessible and visually stable', async ({ page }, testInfo) => {
  await page.goto('/administrator/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
  await page.screenshot({ path: testInfo.outputPath('login.png'), fullPage: true });
});

test('database-backed public presentation is responsive and ready', async ({ page, request }, testInfo) => {
  const readiness = await request.get('/health/ready');
  expect(readiness.status()).toBe(200);

  await page.goto('/');
  await expect(
    page.getByRole('heading', { level: 1, name: /Content systems ready for what comes next/ }),
  ).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Structure once. Publish with confidence.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'One content core. Every delivery surface.' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open administrator/ }).first()).toBeVisible();
  await expect(page.locator('nav[aria-label="Main navigation"]')).toContainText('Capabilities');
  await expect(page.locator('img[src*="kumwe-wordmark.svg"]')).toBeVisible();
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
      heroColumns: columns('.managed-hero-grid'),
      sectionColumns: columns('.managed-section-grid'),
      headingSize: Math.round(Number.parseFloat(getComputedStyle(element('h1')).fontSize) * 10) / 10,
      bodyBackground: getComputedStyle(document.body).backgroundColor,
      primaryBackground: getComputedStyle(element('.site-button')).backgroundColor,
    };
  });
  const mobile = testInfo.project.name.startsWith('mobile-');
  expect(visualContract.viewportWidth).toBe(mobile ? 412 : 1440);
  expect(visualContract.horizontalOverflow).toBe(0);
  expect(visualContract.headerHeight).toBeGreaterThanOrEqual(68);
  expect(visualContract.heroColumns).toBe(mobile ? 1 : 2);
  expect(visualContract.sectionColumns).toBe(mobile ? 1 : 2);
  expect(visualContract.headingSize).toBeGreaterThan(mobile ? 34 : 52);
  expect(visualContract.bodyBackground).not.toBe('rgba(0, 0, 0, 0)');
  expect(visualContract.primaryBackground).not.toBe('rgba(0, 0, 0, 0)');

  await page.screenshot({
    path: testInfo.outputPath('public-home.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
});

test.describe('authenticated administrator', () => {
  test.beforeEach(async ({ page }) => signIn(page));

  test('dashboard supports desktop and responsive navigation', async ({ page, isMobile }, testInfo) => {
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
    await page.screenshot({ path: testInfo.outputPath('dashboard.png'), fullPage: true });
  });

  test('content discovery and graphical editor work without raw JSON', async ({ page }, testInfo) => {
    await page.goto('/administrator/content');
    await expect(page.getByRole('heading', { name: 'Content', exact: true })).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search' }).fill('launch');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/q=launch/);
    await expectAccessible(page);

    await page.goto('/administrator/content/new');
    await expect(page.getByRole('heading', { name: 'Create content' })).toBeVisible();
    await expect(page.getByText('JSON', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'Rich text editor' }).first()).toBeVisible();
    await expect(page.getByRole('toolbar', { name: 'Text formatting' }).first()).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('content-editor.png'), fullPage: true });
  });

  test('published content links through a typed menu to its canonical path', async ({ page }) => {
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
    const title = `Browser About ${suffix}`;
    const slug = `browser-about-${suffix}`;

    await page.goto('/administrator/content/new');
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('URL slug').fill(slug);
    await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(`Published ${title}`);
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/);

    await page.getByRole('button', { name: 'Move to Review' }).click();
    await page.getByRole('button', { name: 'Move to Published' }).click();
    await expect(page.getByRole('link', { name: 'View page' })).toHaveAttribute('href', `/${slug}`);

    await page.goto('/administrator/navigation');
    const addItem = page.locator('details').filter({ hasText: 'Add a menu item' }).first();
    await addItem.locator('summary').click();
    const form = addItem.locator('form');
    await form.getByLabel('Link type').selectOption('content');
    await form.locator('select[name="content_id"]').selectOption({ label: `${title} · Published` });
    await form.getByLabel('Link label').fill(title);
    await form.getByLabel('URL segment').fill(slug);
    await form.getByRole('button', { name: 'Add link' }).click();
    await expect(page.getByText(`Calculated menu path: /${slug}`)).toBeVisible();

    await page.goto(`/${slug}`);
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
    await expect(page.getByRole('link', { name: title, includeHidden: true })).toHaveAttribute(
      'href',
      `/${slug}`,
    );
    await expectAccessible(page);
  });

  test('media library is usable and accessible', async ({ page }, testInfo) => {
    await page.goto('/administrator/media');
    await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
    await expect(page.getByLabel('File type')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('media-library.png'), fullPage: true });
  });

  test('site identity and corporate schemes are managed graphically', async ({ page }, testInfo) => {
    const handle = `browser_${testInfo.project.name.replaceAll('-', '_')}`;
    await page.goto('/administrator/settings');
    await expect(page.getByRole('heading', { name: 'Site settings' })).toBeVisible();
    await expect(page.getByLabel('Active color scheme')).toHaveValue(/corporate|browser_/);

    await page.getByRole('button', { name: 'Choose media' }).click();
    const mediaDialog = page.getByRole('dialog', { name: 'Choose the site logo' });
    await expect(mediaDialog).toBeVisible();
    await mediaDialog.getByLabel('Filter media').fill('kumwe-symbol');
    await mediaDialog.getByRole('button', { name: /kumwe-symbol\.svg/ }).click();
    await expect(page.locator('#presentation-logo')).toHaveValue(/kumwe-symbol\.svg$/);

    await page.getByRole('button', { name: 'Add color scheme' }).click();
    const scheme = page.locator('[data-presentation-scheme-row]').last();
    await scheme.getByLabel('Name').fill(`Browser ${testInfo.project.name}`);
    await scheme.getByLabel('Handle').fill(handle);
    await page.getByLabel('Active color scheme').selectOption(handle);
    await page.getByLabel('Button treatment').selectOption('soft');
    await page.getByLabel('Button shape').selectOption('pill');
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Save settings and design' }).click();
    await expect(page).toHaveURL(/\/administrator\/settings\?saved=1$/);

    await page.goto('/');
    await expect(page.locator('body')).toHaveAttribute('data-presentation-scheme', handle);
    await expect(page.locator('body')).toHaveAttribute('data-button-style', 'soft');
    await expect(page.locator('body')).toHaveAttribute('data-button-shape', 'pill');
    await expectAccessible(page);

    await page.goto('/administrator/settings');
    await page.getByLabel('Active color scheme').selectOption('corporate');
    await page.getByLabel('Button treatment').selectOption('solid');
    await page.getByLabel('Button shape').selectOption('rounded');
    await page.getByRole('button', { name: 'Save settings and design' }).click();
    await expect(page).toHaveURL(/\/administrator\/settings\?saved=1$/);
  });

  test('automation uses generated job controls rather than JSON', async ({ page }, testInfo) => {
    await page.goto('/administrator/automation');
    await expect(page.getByRole('heading', { name: 'Automation' })).toBeVisible();
    await expect(page.locator('textarea[name="payload"]')).toHaveCount(0);
    await expect(page.getByLabel('Job type')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('automation.png'),
      fullPage: true,
      mask: [page.locator('[data-visual-dynamic]')],
      maskColor: '#ffffff',
    });
  });
});
