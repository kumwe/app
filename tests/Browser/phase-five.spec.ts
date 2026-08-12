import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL
  ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';
const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';

const administratorSurfaces = [
  ['/administrator', 'core.administrator.dashboard'],
  ['/administrator/content', 'core.administrator.content-collection'],
  ['/administrator/content/new', 'core.administrator.content-editor'],
  ['/administrator/content-models', 'core.administrator.content-models'],
  ['/administrator/navigation', 'core.administrator.navigation'],
  ['/administrator/extensions', 'core.administrator.extensions'],
  ['/administrator/automation', 'core.administrator.automation'],
  ['/administrator/media', 'core.administrator.media'],
  ['/administrator/settings', 'core.administrator.settings'],
] as const;

/** Sign into the administrator through the production server-rendered form. */
async function signInAdministrator(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Sign into the ordinary portal workspace without bypassing membership selection. */
async function signInPortal(page: Page): Promise<void> {
  await page.goto('/portal/login');
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/u);
}

/** Require WCAG 2.2 AA automated rules while the deterministic layout checks cover geometry. */
async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

/** Attach one reviewable screenshot without turning the state into an unapproved visual baseline. */
async function attachScreenshot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  const path = testInfo.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(name, { path, contentType: 'image/png' });
}

test('Phase 5 administrator surfaces use one KIS task shell without route or payload drift', async ({
  page,
}, testInfo) => {
  await page.goto('/administrator/login');
  await expect(page.locator('[data-kis-surface="core.administrator.login"]')).toBeVisible();
  await signInAdministrator(page);

  for (const [path, surface] of administratorSurfaces) {
    await page.goto(path);
    await expect(page.locator(`[data-kis-surface="${surface}"]`)).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
  }

  await page.goto('/administrator/settings');
  await expect(page.locator('kumwe-dirty-form')).toHaveCount(1);
  await expect(page.locator('[data-kis-dirty-status]')).toContainText('saved when you submit');
  await page.getByLabel('Footer text').fill('Phase 5 unsaved-state evidence');
  await expect(page.locator('kumwe-dirty-form')).toHaveAttribute('data-dirty', '');
  await expect(page.locator('[data-kis-dirty-status]')).toHaveText('Unsaved changes');
  await expectAccessible(page);
  await attachScreenshot(page, testInfo, 'phase-five-settings');
});

test('Phase 5 portal home exposes plain-language access readiness and secure recovery boundaries', async ({
  page,
}, testInfo) => {
  await page.goto('/portal/login');
  await expect(page.locator('[data-kis-surface="core.portal.login"]')).toBeVisible();
  await signInPortal(page);

  await expect(page.locator('[data-kis-surface="core.portal.home"]')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Access context and readiness' })).toBeVisible();
  await expect(page.getByText('north', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('acme', { exact: true }).first()).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#portal-main', detectControlOverlaps: false });

  await page.goto('/portal/security');
  await expect(page.locator('[data-kis-surface="core.portal.security"]')).toBeVisible();
  await expect(page.locator('[data-copy-value]')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Start setup' })).toBeVisible();
  await expectAccessible(page);
  await attachScreenshot(page, testInfo, 'phase-five-portal-security');
});

test('Phase 5 public presentation keeps the configured home identity and long labels bounded', async ({
  page,
}, testInfo) => {
  await page.goto('/');
  await expect(page.locator('[data-kis-surface="core.public.home"]')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  await page.getByRole('heading', { level: 1 }).evaluate((heading) => {
    heading.textContent = 'A localized public heading with deliberately extended operational context '
      .repeat(4)
      .trim();
  });
  await expectNoDocumentOverflow(page, { root: '#site-content', detectControlOverlaps: false });
  await expectAccessible(page);
  await attachScreenshot(page, testInfo, 'phase-five-public-long-label');
});

test('Phase 5 presentation modes preserve focus, touch, zoom, high contrast, motion, and print', async ({
  page,
  isMobile,
}) => {
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce', forcedColors: 'active' });
  await signInAdministrator(page);
  await page.goto('/administrator/settings');

  const modeContract = await page.evaluate(() => ({
    dark: matchMedia('(prefers-color-scheme: dark)').matches,
    reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
    forcedColors: matchMedia('(forced-colors: active)').matches,
  }));
  expect(modeContract).toEqual({ dark: true, reducedMotion: true, forcedColors: true });

  const firstSectionLink = page.locator('.kis-phase-five-section-nav a').first();
  await firstSectionLink.focus();
  await expect(firstSectionLink).toBeFocused();
  if (isMobile) {
    expect((await firstSectionLink.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
  }

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '200%';
  });
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });

  await page.goto('/administrator/content-models');
  await expect(page.locator('#create-content-type')).toHaveAttribute('open', '');
  const defaultContentModels = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(
    defaultContentModels.findings,
    JSON.stringify(defaultContentModels, null, 2),
  ).toEqual([]);

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '200%';
  });
  const contentTypeEditor = page.locator(
    '#content-type-catalog > details:not(#create-content-type)',
  ).first();
  await contentTypeEditor.evaluate((details) => details.setAttribute('open', ''));
  await contentTypeEditor.scrollIntoViewIfNeeded();
  const zoomedContentType = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(
    zoomedContentType.findings,
    JSON.stringify(zoomedContentType, null, 2),
  ).toEqual([]);

  const workflowEditor = page.locator('#create-workflow');
  await workflowEditor.evaluate((details) => details.setAttribute('open', ''));
  await workflowEditor.scrollIntoViewIfNeeded();
  const zoomedWorkflow = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(
    zoomedWorkflow.findings,
    JSON.stringify(zoomedWorkflow, null, 2),
  ).toEqual([]);

  await page.goto('/administrator/settings');
  await page.emulateMedia({ media: 'print', colorScheme: 'light', forcedColors: 'none' });
  await expect(page.locator('.kis-phase-five-section-nav')).toBeHidden();
  const printableSettings = page.locator('form[data-kis-dirty-form]');
  await expect(printableSettings).toBeVisible();
  await expect(printableSettings.getByRole('group', { name: 'Public identity' })).toBeVisible();
  await expect(printableSettings.getByRole('group', { name: 'Design system' })).toBeVisible();
  await expect(printableSettings.getByLabel('Active color scheme')).toBeVisible();
  await expect(printableSettings.getByRole('button', { name: 'Save settings and design' }))
    .toBeHidden();
  await expect(printableSettings.getByRole('button', { name: 'Choose media' })).toBeHidden();
  const printDiagnostics = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(printDiagnostics.findings, JSON.stringify(printDiagnostics, null, 2)).toEqual([]);
});
