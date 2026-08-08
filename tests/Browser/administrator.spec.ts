import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';
const limitedEmail = process.env.KUMWE_BROWSER_LIMITED_EMAIL ?? 'browser-limited@kumwe.test';
const limitedPassword = process.env.KUMWE_BROWSER_LIMITED_PASSWORD ?? 'browser limited password';

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

async function signIn(
  page: Page,
  email = administratorEmail,
  password = administratorPassword,
): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/);
}

async function ensureAnnouncementsActive(page: Page): Promise<void> {
  await page.goto('/administrator/extensions');
  const extension = page.locator('article').filter({ hasText: 'kumwe/announcements-example' }).first();
  const activate = extension.getByRole('button', { name: 'Activate' });
  if (await activate.count()) {
    await activate.click();
    await expect(page).toHaveURL(/\/administrator\/extensions$/);
  }
  await expect(extension).toContainText(/component · 2\.0\.0 · active/);
  await expect.poll(async () => {
    await page.goto('/administrator');
    return page.getByRole('link', { name: 'Announcements', exact: true }).count();
  }, {
    message: 'the active extension navigation to reach the local signed runtime map',
    timeout: 25_000,
  }).toBeGreaterThan(0);
  await page.goto('/administrator/extensions');
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

  test('business definitions publish through graphical compatibility gates', async ({ page }, testInfo) => {
    const suffix = `${Date.now()}_${Math.floor(Math.random() * 10000)}`;
    const handle = `site.default.browser_invoice_${suffix}`;
    await page.goto('/administrator/business-definitions?new=1');
    await expect(page.getByRole('heading', { name: 'Business definitions' })).toBeVisible();
    await expect(page.locator('textarea[name="definition_json"]')).toHaveCount(0);
    await page.locator('input[name="handle"]').fill(handle);
    await page.getByLabel('Singular label').fill('Browser invoice');
    await page.getByLabel('Plural label').fill('Browser invoices');
    await page.getByRole('button', { name: 'Add field' }).click();
    const field = page.locator('[data-row="field"]').last();
    await field.getByLabel('Handle').fill('reference');
    await field.getByLabel('Label').fill('Reference');
    await field.locator('select').first().selectOption('core.text');
    await field.getByLabel('Length').fill('120');
    await field.getByText('Required', { exact: true }).click();
    await page.getByRole('button', { name: 'Save and validate draft' }).press('Enter');
    await expect(page).toHaveURL(/\/administrator\/business-definitions\?definition=/);
    await expect(page.getByRole('heading', { name: 'Compatibility plan' })).toBeVisible();
    await expect(page.getByText('Draft checksum')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('business-definition-draft.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /Publish version 1/ }).press('Enter');
    await expect(page.getByRole('heading', { name: 'Version history' })).toBeVisible();
    await expect(page.getByText('Version 1', { exact: true })).toBeVisible();
    await expectAccessible(page);
  });

  test('schema plans are inspectable, capability-gated and visually stable', async ({ page }) => {
    await page.goto('/administrator/business-schema-plans');
    await expect(page.getByRole('heading', { name: 'Business schema plans' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Generated operations' })).toBeVisible();
    await expect(page.getByText('Plan checksum', { exact: true })).toBeVisible();
    await expect(page.getByText('Physical checksum', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Approve exact plan' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Schema plans', exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    );
    await expect(page.locator('textarea[name="sql"], textarea[name="plan"], input[name="sql"]')).toHaveCount(0);
    await expect(page.locator('.schema-operations-table tbody tr')).not.toHaveCount(0);
    const missingCsrf = await page.context().request.post('/administrator/business-schema-plans/plan', {
      form: { definition_id: '018f22e2-7c8b-7ab0-8f3a-88e8026bb401' },
    });
    expect(missingCsrf.status()).toBe(403);
    expect(await missingCsrf.text()).toContain('security token is invalid');
    await expectStylesLoaded(page);
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('schema-plans.png', {
      fullPage: true,
      mask: [page.locator('[data-visual-mask]')],
      maskColor: '#d9e2e8',
    });
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

  test('typed component navigation opens an accessible graphical page', async ({
    page,
    isMobile,
  }, testInfo) => {
    await ensureAnnouncementsActive(page);
    await page.goto('/administrator');
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    const workspace = page.locator('.navigation-group').filter({
      has: page.getByRole('heading', { name: 'Announcements', exact: true }),
    });
    await expect(workspace.getByRole('link', { name: 'Announcements' })).toBeVisible();
    await workspace.getByRole('link', { name: 'Announcements' }).click();
    await expect(page).toHaveURL(/\/administrator\/extensions\/kumwe\/announcements-example$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Announcements' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Component announcements' })).toBeVisible();
    await expect(page.getByText('Contribution contract active')).toBeVisible();
    await expectStylesLoaded(page);
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('announcements-contribution.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });
  });

  test('component navigation and guarded route are unavailable without its capability', async ({
    page,
    isMobile,
  }) => {
    await ensureAnnouncementsActive(page);
    await page.context().clearCookies();
    await signIn(page, limitedEmail, limitedPassword);
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    await expect(page.getByRole('link', { name: 'Announcements' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Schema plans', exact: true })).toHaveCount(0);
    const schemaResponse = await page.goto('/administrator/business-schema-plans');
    expect(schemaResponse?.status()).toBe(403);
    const response = await page.goto('/administrator/extensions/kumwe/announcements-example');
    expect(response?.status()).toBe(403);
  });

  test('disabling removes component navigation and reactivation restores it', async ({
    page,
    isMobile,
  }) => {
    test.setTimeout(90_000);
    await ensureAnnouncementsActive(page);
    try {
      const extension = page.locator('article').filter({ hasText: 'kumwe/announcements-example' }).first();
      await extension.getByRole('button', { name: 'Disable' }).click();
      await expect(page).toHaveURL(/\/administrator\/extensions$/);
      await expect(extension).toContainText(/component · 2\.0\.0 · disabled/);

      await expect.poll(async () => {
        await page.goto('/administrator');
        return page.getByRole('link', { name: 'Announcements', exact: true }).count();
      }, {
        message: 'the disabled extension navigation to leave the local signed runtime map',
        timeout: 25_000,
      }).toBe(0);
      if (isMobile) {
        await page.getByRole('button', { name: 'Open administrator navigation' }).click();
      }
      await expect(page.getByRole('link', { name: 'Announcements' })).toHaveCount(0);
    } finally {
      await ensureAnnouncementsActive(page);
    }
    await page.goto('/administrator');
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    await expect(page.getByRole('link', { name: 'Announcements' })).toBeVisible();
  });
});
