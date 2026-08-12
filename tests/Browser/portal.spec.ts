import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL
  ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD
  ?? 'browser portal password';
const businessDefinitionHandle = 'site.default.session5_order';
const assetInspectionDefinition = 'kumwe.asset-inspection-example.inspection';
const assetInspectionReport = 'kumwe.asset-inspection-example.inspection-summary';
const windhoekOrderId = '019b40d9-8dd0-7ca2-a0db-9eae6a150511';

function base32Bytes(secret: string): Uint8Array {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const character of secret.toUpperCase().replace(/=+$/u, '')) {
    const value = alphabet.indexOf(character);
    if (value < 0) {
      throw new Error('The browser authenticator secret is not valid base32.');
    }
    bits += value.toString(2).padStart(5, '0');
  }

  const bytes: number[] = [];
  for (let offset = 0; offset + 8 <= bits.length; offset += 8) {
    bytes.push(Number.parseInt(bits.slice(offset, offset + 8), 2));
  }

  return Uint8Array.from(bytes);
}

function authenticatorCode(provisioningUri: string): string {
  const provisioning = new URL(provisioningUri);
  const secret = provisioning.searchParams.get('secret');
  if (!secret) {
    throw new Error('The browser authenticator URI has no secret.');
  }
  const period = Number.parseInt(provisioning.searchParams.get('period') ?? '30', 10);
  const digits = Number.parseInt(provisioning.searchParams.get('digits') ?? '6', 10);
  const algorithm = (provisioning.searchParams.get('algorithm') ?? 'SHA1').toLowerCase();
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / period)));
  const digest = createHmac(algorithm, base32Bytes(secret)).update(counter).digest();
  const lastByte = digest.at(-1);
  if (lastByte === undefined) {
    throw new Error('The browser authenticator digest is empty.');
  }
  const offset = lastByte & 0x0f;
  const binary = digest.readUInt32BE(offset) & 0x7fffffff;

  return (binary % (10 ** digits)).toString().padStart(digits, '0');
}

async function enrollPortalAuthenticator(page: Page): Promise<string> {
  await page.goto('/portal/security');
  await page.getByRole('button', { name: 'Start setup' }).click();
  const provisioningUri = await page.locator('.portal-uri').textContent();
  if (!provisioningUri) {
    throw new Error('The portal did not disclose its one-time authenticator URI.');
  }
  await page.getByLabel('Authenticator code').fill(authenticatorCode(provisioningUri.trim()));
  await page.getByRole('button', { name: 'Confirm authenticator' }).click();
  await expect(page.getByRole('heading', { name: 'Your recovery codes' })).toBeVisible();
  const recoveryCode = await page.locator('.portal-recovery-codes code').first().textContent();
  if (!recoveryCode) {
    throw new Error('The portal did not issue a recovery code for the browser actor.');
  }

  return recoveryCode.trim();
}

async function expectAccessible(page: Page, include?: string): Promise<void> {
  const builder = new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']);
  if (include !== undefined) {
    builder.include(include);
  }
  const scan = await builder.analyze();
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

/** Attach the unmodified live interface before any compatibility-only baseline normalization. */
async function attachLiveInterfaceScreenshot(
  page: Page,
  testInfo: TestInfo,
  name: string,
): Promise<void> {
  const path = testInfo.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(name, { path, contentType: 'image/png' });
}

/** Keep legacy comparisons scoped to their original shell; Session 6 has dedicated real screenshots. */
async function preservePreSession6VisualSnapshot(page: Page): Promise<void> {
  await page.locator('.portal-navigation').evaluate((navigation) => {
    const session6Paths = [
      '/portal/reports',
      '/portal/extensions/kumwe/asset-inspection-example',
    ];

    for (const link of navigation.querySelectorAll<HTMLAnchorElement>('a[href]')) {
      const href = link.getAttribute('href') ?? '';
      if (!session6Paths.some((path) => href === path || href.startsWith(`${path}/`))) {
        continue;
      }

      const section = link.closest('section');
      if (section !== null && section.querySelectorAll('a[href]').length === 1) {
        section.remove();
      } else {
        link.closest('li')?.remove();
      }
    }
  });
}

async function signIn(
  page: Page,
  email = portalEmail,
  password = portalPassword,
): Promise<void> {
  await page.goto('/portal/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/);
}

async function fillSession5OrderForm(page: Page, name: string): Promise<void> {
  await page.locator('[name="values[name]"]').fill(name);
  await page.locator('[name="values[status]"]').selectOption('ready');
  await page.locator('[name="values[enabled]"][type="checkbox"]').check();
  await page.locator('[name="values[amount]"]').fill('10.000000000000000000000000000000');
  await page.locator('[name="values[price][amount]"]').fill('25.000000000000000000000000000000');
  await page.locator('[name="values[price][currency]"]').fill('nad');
  await page.locator('[name="values[quantity][amount]"]').fill('2.000000000000000000000000000000');
  await page.locator('[name="values[quantity][unit]"]').fill('unit');
  await page.locator('[name="values[service_date]"]').fill('2026-08-10');
  await page.locator('[name="values[local_time]"]').fill('13:14:15.123456');
  await page.locator('[name="values[recorded_at]"]').fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][instant]"]')
    .fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][timezone]"]').fill('Africa/Windhoek');
  await page.locator('[name="values[credential]"]').fill('portal-secret-value');
}

async function expectPortalRecordName(page: Page, value: string): Promise<void> {
  const field = page.locator('.portal-business-details > div').filter({
    has: page.getByText('Name', { exact: true }),
  });
  await expect(field.locator('dd')).toHaveText(value);
}

async function expectPortalRecordRow(page: Page, value: string): Promise<void> {
  await expect(page.locator('.portal-business-table tbody tr:visible, .kis-business-result-card:visible').filter({ hasText: value }).first())
    .toBeVisible();
}

test('portal login is accessible, centered and visually bounded', async ({ page }, testInfo) => {
  await page.goto('/portal/login');
  await expect(page.getByRole('heading', { name: 'Sign in to the portal' })).toBeVisible();
  await expect(page.locator('.portal-shell')).toHaveClass(/\bportal-shell--guest\b/u);
  await expect(page.locator('.portal-navigation')).toHaveCount(0);
  await page.getByText('What access do I need?').click();
  await expect(page.getByText('Your own active user identity', { exact: false })).toBeVisible();
  await expect(page.getByText('does not reveal which identity or access prerequisite is missing', {
    exact: false,
  })).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
  const visualContract = await page.evaluate(() => {
    const shell = document.querySelector<HTMLElement>('.portal-shell');
    const main = document.querySelector<HTMLElement>('#portal-main');
    const login = document.querySelector<HTMLElement>('.portal-login');
    if (shell === null || main === null || login === null) {
      throw new Error('The guest portal visual contract is incomplete.');
    }
    const shellBounds = shell.getBoundingClientRect();
    const mainBounds = main.getBoundingClientRect();
    const loginBounds = login.getBoundingClientRect();

    return {
      shellColumns: getComputedStyle(shell).gridTemplateColumns.trim().split(/\s+/u).length,
      shellWidth: Math.round(shellBounds.width),
      mainWidth: Math.round(mainBounds.width),
      cardWidth: Math.round(loginBounds.width),
      cardCenterOffset: Math.round(
        Math.abs((loginBounds.left + loginBounds.right) / 2 - window.innerWidth / 2) * 10,
      ) / 10,
      viewportWidth: window.innerWidth,
    };
  });
  expect(visualContract.shellColumns).toBe(1);
  expect(visualContract.shellWidth).toBe(visualContract.viewportWidth);
  expect(visualContract.mainWidth).toBe(visualContract.viewportWidth);
  expect(visualContract.cardWidth).toBeLessThanOrEqual(480);
  expect(visualContract.cardWidth).toBeGreaterThanOrEqual(320);
  expect(visualContract.cardCenterOffset).toBeLessThanOrEqual(1);
  const diagnostics = await expectNoDocumentOverflow(page, {
    root: '#portal-main',
    detectControlOverlaps: true,
  });
  expect(diagnostics.findings.filter((finding) => finding.kind === 'control-overlap')).toEqual([]);
  const loginCookie = (await page.context().cookies())
    .find((cookie) => cookie.name === 'kumwe_portal_login_csrf');
  expect(loginCookie).toMatchObject({ path: '/portal/login', httpOnly: true, sameSite: 'Strict' });
  await page.screenshot({
    path: testInfo.outputPath('portal-login.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
});

test('portal login rejects a forged pre-authentication token', async ({ request }) => {
  const response = await request.post('/portal/login', {
    form: {
      email: portalEmail,
      password: portalPassword,
      workspace: 'north',
      _csrf: 'forged',
    },
  });
  expect(response.status()).toBe(403);
  expect(await response.text()).toContain('portal security token is invalid');
  const cookies = response.headersArray()
    .filter((header) => header.name.toLowerCase() === 'set-cookie')
    .map((header) => header.value);
  expect(cookies.some((cookie) => cookie.startsWith('kumwe_portal_login_csrf='))).toBe(true);
  expect(cookies.some((cookie) => cookie.startsWith('kumwe_portal='))).toBe(false);
});

test('portal shell keeps sessions isolated and protects mutations', async ({ page, isMobile }, testInfo) => {
  await signIn(page);
  await expect(page.getByRole('heading', { name: 'Welcome to Kumwe Portal' })).toBeVisible();
  const navigation = page.getByRole('navigation', { name: 'Portal navigation' });
  await expect(navigation.getByRole('link', { name: 'Overview' })).toHaveAttribute('aria-current', 'page');
  await expect(navigation.getByRole('link', { name: 'Approvals' })).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Account security' })).toBeVisible();
  await expect(page.getByLabel('Active portal context')).toContainText('default');
  await expect(page.getByLabel('Active portal context')).toContainText('acme');
  await expect(page.getByLabel('Active portal context')).toContainText('north');
  await expectStylesLoaded(page);
  await expectAccessible(page);

  const visualContract = await page.evaluate(() => ({
    horizontalOverflow: document.documentElement.scrollWidth - window.innerWidth,
    shellColumns: getComputedStyle(document.querySelector<HTMLElement>('.portal-shell')!)
      .gridTemplateColumns.trim().split(/\s+/).length,
    headerBackground: getComputedStyle(document.querySelector<HTMLElement>('.portal-header')!)
      .backgroundColor,
  }));
  expect(visualContract.horizontalOverflow).toBe(0);
  expect(visualContract.shellColumns).toBe(isMobile ? 1 : 2);
  expect(visualContract.headerBackground).not.toBe('rgba(0, 0, 0, 0)');

  const cookies = await page.context().cookies();
  const portalCookie = cookies.find((cookie) => cookie.name === 'kumwe_portal');
  expect(portalCookie).toMatchObject({ path: '/portal', httpOnly: true, sameSite: 'Strict' });
  expect(cookies.find((cookie) => cookie.name === 'kumwe_portal_login_csrf')).toBeUndefined();
  expect(cookies.find((cookie) => cookie.name === 'kumwe_administrator')).toBeUndefined();
  const administratorCookies = await page.context().cookies(new URL('/administrator', page.url()).toString());
  expect(administratorCookies.find((cookie) => cookie.name === 'kumwe_portal')).toBeUndefined();

  await page.goto('/administrator');
  await expect(page).toHaveURL(/\/administrator\/login$/);
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  const administratorDenial = page.waitForResponse((response) =>
    response.url().endsWith('/administrator/login')
      && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  expect((await administratorDenial).status()).toBe(403);
  await expect(page).toHaveURL(/\/administrator\/login$/);
  await expect(page.locator('body')).toContainText('not authorized for this operation');
  expect((await page.context().cookies())
    .find((cookie) => cookie.name === 'kumwe_administrator')).toBeUndefined();
  await page.goto('/portal');
  await expect(page.getByRole('heading', { name: 'Welcome to Kumwe Portal' })).toBeVisible();

  const rejected = await page.evaluate(async () => {
    const response = await fetch('/portal/logout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: '',
    });
    return { status: response.status, body: await response.text() };
  });
  expect(rejected.status).toBe(403);
  expect(rejected.body).toContain('portal security token is invalid');
  await expect(page.getByRole('heading', { name: 'Welcome to Kumwe Portal' })).toBeVisible();

  await page.screenshot({
    path: testInfo.outputPath('portal-shell.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/portal\/login$/);
  expect((await page.context().cookies()).find((cookie) => cookie.name === 'kumwe_portal')).toBeUndefined();
  await page.goto('/portal');
  await expect(page).toHaveURL(/\/portal\/login$/);
});

test('opt-in business workspaces use the portal shell on desktop and mobile', async ({
  page,
  isMobile,
}, testInfo) => {
  await signIn(page);
  await page.goto('/portal/business');
  await expect(page.locator('a[href="/portal/business"]')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: 'Business records' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open session 5 orders/i })).toBeVisible();
  await page.goto(`/portal/business/${businessDefinitionHandle}`);
  await expect(page.locator(isMobile ? '.kis-business-result-card' : '.portal-business-table tbody tr').first()).toBeVisible();
  await expect(page.getByRole('link', { name: 'Report', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Export', exact: true })).toBeVisible();
  await page.locator('input[name="bulk_records[]"]:visible').first().check();
  await page.getByLabel('Bulk operation').selectOption('archive');
  await page.getByRole('button', { name: 'Review bulk operation' }).click();
  await expect(page.getByRole('heading', { name: 'Archive selected records' })).toBeVisible();
  await expect(page.locator('input[name="operation_id"]')).not.toHaveValue('');
  await expect(page.locator('input[name="confirmed"]')).toHaveValue('1');
  await page.goto(`/portal/business/${businessDefinitionHandle}`);
  await page.getByLabel('Search records').fill('Windhoek order');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await expect(page.locator(isMobile ? '.kis-business-result-card' : '.portal-business-table tbody tr').filter({ hasText: 'Windhoek order' }).first()).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
  const liveNavigation = page.locator('.portal-navigation');
  await expect(liveNavigation.locator('a[href="/portal/approvals"]')).toBeVisible();
  await expect(liveNavigation.locator('a[href="/portal/reports"]')).toBeVisible();
  await expect(liveNavigation.locator('a[href="/portal/security"]')).toBeVisible();
  await expect(liveNavigation.locator(
    'a[href^="/portal/extensions/kumwe/asset-inspection-example"]',
  )).toBeVisible();
  await attachLiveInterfaceScreenshot(page, testInfo, 'portal-business-records');
  await preservePreSession6VisualSnapshot(page);
  await expect(page).toHaveScreenshot('portal-generated-business-list.png', {
    fullPage: true,
    mask: [page.locator('.portal-business-table tbody tr')],
    maskColor: '#d9e2e8',
  });
  await page.goto(
    `/portal/business/${businessDefinitionHandle}/019b40d9-8dd0-7ca2-a0db-9eae6a150511`,
  );
  await expect(page.getByRole('heading', { name: 'Record details' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
  await expectAccessible(page);
  await attachLiveInterfaceScreenshot(page, testInfo, 'portal-business-detail-live');
  await preservePreSession6VisualSnapshot(page);
  await expect(page).toHaveScreenshot('portal-generated-business-detail.png', {
    fullPage: true,
    mask: [page.locator('time')],
    maskColor: '#d9e2e8',
  });
});

test('asset-inspection portal workspace is policy-filtered and bounded', async ({
  page,
}, testInfo) => {
  await signIn(page);
  await page.goto('/portal/extensions/kumwe/asset-inspection-example');
  const surface = page.locator(
    '[data-kis-surface="kumwe.asset-inspection-example.portal.status"]',
  );
  await expect(surface).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: 'Asset inspection example' })).toBeVisible();
  const visibleInspections = surface.getByRole('region', {
    name: 'Visible inspections',
    exact: true,
  });
  await expect(visibleInspections).toHaveCount(1);
  await expect(visibleInspections).toBeVisible();
  await expect(visibleInspections.getByRole('heading', {
    level: 2,
    name: 'Visible inspections',
    exact: true,
  })).toBeVisible();
  await expect(page.getByRole('region', {
    name: 'Inspection status',
    exact: true,
  })).toHaveCount(1);
  await expect(page.getByText('EXAMPLE-INSPECTION-001')).toBeVisible();
  await expect(page.getByText('ROW-POLICY-DENIED')).toHaveCount(0);
  await expect(page.getByText('FOREIGN-SITE-ROW')).toHaveCount(0);
  await expect(page.getByText(/receives no restricted internal-note field/u)).toBeVisible();
  await expectAccessible(page);
  const diagnostics = await expectNoDocumentOverflow(page, {
    root: '#portal-main',
    detectControlOverlaps: false,
  });
  expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
  await attachLiveInterfaceScreenshot(page, testInfo, 'asset-inspection-portal-workspace');
});

test('portal operation status and custom views are accessible and bounded', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  const expectBoundedGeneratedSurface = async (attachment: string): Promise<void> => {
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#portal-main',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, attachment);
  };

  await signIn(page);
  await page.goto(`/portal/business/${businessDefinitionHandle}?new=1`);
  const name = `Portal operation evidence ${testInfo.project.name} ${Date.now()}`;
  await fillSession5OrderForm(page, name);
  await page.getByRole('button', { name: 'Create record' }).click();
  await expect(page).toHaveURL(new RegExp(
    `/portal/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
  ));
  await page.getByRole('link', { name: 'View operation status' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Operation status' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Operation completed' })).toBeVisible();
  await expect(page.locator('[data-kis-component="generated-operation-status"]'))
    .toHaveAttribute('data-kis-surface', 'core.portal.generated-operation');
  await expect(page.getByRole('link', { name: 'Return to record', exact: true })).toBeVisible();
  await expectBoundedGeneratedSurface('portal-operation-status');

  await page.goto(`/portal/business/${assetInspectionDefinition}`);
  const customView = page.getByRole('link', { name: 'Inspection risk summary' });
  await expect(customView).toBeVisible();
  await customView.click();
  await expect(page.getByRole('heading', { level: 1, name: 'Inspection risk summary' })).toBeVisible();
  await expect(page.locator('[data-kis-component="generated-custom-view"]'))
    .toHaveAttribute('data-kis-surface', 'core.portal.generated-custom-view');
  await expect(page.getByRole('heading', { name: 'View result' })).toBeVisible();
  await page.getByLabel('Rows available to the view').selectOption('25');
  await page.getByRole('button', { name: 'Run view' }).click();
  await expect(page).toHaveURL(/page_size=25/u);
  await expect(page.getByText('Policy-filtered inspection risk summary')).toBeVisible();
  await expect(page.getByText('BROWSER-INSPECT-001')).toBeVisible();
  await expect(page.getByText('BROWSER-POLICY-DENIED', { exact: true })).toHaveCount(0);
  await expect(page.getByText('Browser report restricted note', { exact: true })).toHaveCount(0);
  const restricted = page.locator('.kis-business-custom-object > div').filter({
    has: page.getByText('Restricted fields disclosed', { exact: true }),
  });
  await expect(restricted.locator('dd')).toHaveText('No');
  await expectBoundedGeneratedSurface('portal-custom-view');
});

test('portal relationship choices and owned lines are accessible and bounded', async ({
  page,
}, testInfo) => {
  test.setTimeout(90_000);
  await signIn(page);
  const recordPath = `/portal/business/${businessDefinitionHandle}/${windhoekOrderId}`;
  await page.goto(recordPath);
  let tags = page.locator('article.portal-business-relation').filter({
    has: page.getByRole('heading', { name: 'Tags', exact: true }),
  });
  await tags.getByRole('link', { name: 'View and manage' }).click();
  tags = page.locator('article.portal-business-relation').filter({
    has: page.getByRole('heading', { name: 'Tags', exact: true }),
  });
  await tags.getByRole('link', { name: 'Search available records' }).click();
  await expect(page.getByRole('heading', { name: 'Choose tags' })).toBeVisible();
  await expect(page.locator('[data-kis-component="generated-choice-browser"]'))
    .toHaveAttribute('data-kis-surface', 'core.portal.generated-choices');
  await expect(page.getByRole('region', { name: 'Available choices table' })).toBeVisible();
  await expectAccessible(page);
  const choiceDiagnostics = await expectNoDocumentOverflow(page, {
    root: '#portal-main',
    detectControlOverlaps: false,
  });
  expect(choiceDiagnostics.findings, JSON.stringify(choiceDiagnostics, null, 2)).toEqual([]);
  await attachLiveInterfaceScreenshot(page, testInfo, 'portal-relationship-chooser');

  await page.goto(recordPath);
  let lines = page.locator('article.portal-business-relation').filter({
    has: page.getByRole('heading', { name: 'Lines', exact: true }),
  });
  await lines.getByRole('link', { name: 'View and manage' }).click();
  lines = page.locator('article.portal-business-relation').filter({
    has: page.getByRole('heading', { name: 'Lines', exact: true }),
  });
  await expect(lines.locator('form.portal-business-owned-line-form')).toBeVisible();
  await expectAccessible(page);
  const ownedLineDiagnostics = await expectNoDocumentOverflow(page, {
    root: '#portal-main',
    detectControlOverlaps: false,
  });
  expect(ownedLineDiagnostics.findings, JSON.stringify(ownedLineDiagnostics, null, 2)).toEqual([]);
  await attachLiveInterfaceScreenshot(page, testInfo, 'portal-owned-lines');
});

test('opt-in portal reports execute and expose queued export status', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  await signIn(page);
  await page.goto('/portal/reports');
  await expect(page.getByRole('heading', { level: 1, name: 'Business reports' })).toBeVisible();
  await expect(page.locator('a[href="/portal/reports"]')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('link', { name: 'Inspection status' })).toBeVisible();
  const report = page.locator(`form[action="/portal/reports/${assetInspectionReport}"]`);
  await expect(report.getByRole('heading', { name: 'Asset inspection example summary' })).toBeVisible();
  await report.locator('[name="parameters[minimum_score]"]').fill('70');
  await report.getByRole('button', { name: 'Run report', exact: true }).click();

  const results = page.getByRole('region', { name: 'Report results', exact: true });
  await expect(results).toBeVisible();
  const resultsTable = results.getByRole('region', { name: 'Scrollable report results table' });
  await expect(resultsTable).toBeVisible();
  await expect(resultsTable.getByRole('columnheader', { name: 'Reference' })).toBeVisible();
  await expect(resultsTable.getByRole('columnheader', { name: 'Risk score' })).toBeVisible();
  const accepted = resultsTable.getByRole('row').filter({ hasText: 'BROWSER-INSPECT-001' });
  await expect(accepted).toBeVisible();
  await expect(accepted).toContainText('79');
  await expect(resultsTable.getByText('BROWSER-POLICY-DENIED', { exact: true })).toHaveCount(0);
  await expect(page.getByText('Browser report restricted note', { exact: true })).toHaveCount(0);
  await expectStylesLoaded(page);
  await expectAccessible(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
  if ((page.viewportSize()?.width ?? 0) >= 900) {
    const dimensions = await page.locator('.kis-business-report-grid').evaluate((layout) => ({
      catalogWidth: layout.children.item(0)?.getBoundingClientRect().width ?? 0,
      workspaceWidth: layout.children.item(1)?.getBoundingClientRect().width ?? 0,
    }));
    expect(dimensions.workspaceWidth).toBeGreaterThan(dimensions.catalogWidth);
  }
  await page.screenshot({
    path: testInfo.outputPath('portal-report-results.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });

  await report.getByRole('button', { name: 'Queue CSV export', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
  await expect(page.getByText('Queued', { exact: true })).toBeVisible();
  const queuedExportDiagnostics = await expectNoDocumentOverflow(page, {
    root: 'section[aria-labelledby="portal-export-history-title"]',
    detectControlOverlaps: false,
  });
  expect(
    queuedExportDiagnostics.findings,
    JSON.stringify(queuedExportDiagnostics, null, 2),
  ).toEqual([]);
  const status = page.getByRole('link', { name: 'Refresh status' });
  await expect(status).toHaveAttribute('href', /^\/portal\/reports\/exports\/[0-9a-f-]{36}$/u);
  await expect(page.getByRole('link', { name: 'Download verified CSV' })).toHaveCount(0);
  await status.click();
  await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
  const refreshedExportDiagnostics = await expectNoDocumentOverflow(page, {
    root: 'section[aria-labelledby="portal-export-history-title"]',
    detectControlOverlaps: false,
  });
  expect(
    refreshedExportDiagnostics.findings,
    JSON.stringify(refreshedExportDiagnostics, null, 2),
  ).toEqual([]);
  await expectAccessible(page, 'section[aria-labelledby="portal-export-history-title"]');
  await page.locator('section[aria-labelledby="portal-export-history-title"]').screenshot({
    path: testInfo.outputPath('portal-export-status.png'),
    animations: 'disabled',
    caret: 'hide',
  });
});

test('portal maker-checker approval requires a distinct step-up identity', async ({
  page,
  browser,
}, testInfo) => {
  // KIS-EVIDENCE-BEGIN p6-001-maker-checker
  test.setTimeout(90_000);
  const project = testInfo.project.name.startsWith('mobile-') ? 'mobile' : 'desktop';
  const retry = Math.min(testInfo.retry, 1);
  const makerEmail = `browser-maker-${project}-${retry}@kumwe.test`;
  const makerPassword = `browser ${project} maker password ${retry}`;
  await signIn(page, makerEmail, makerPassword);
  const makerRecoveryCode = await enrollPortalAuthenticator(page);
  await page.goto(`/portal/business/${businessDefinitionHandle}`);
  await page.getByRole('link', { name: /Create session 5 order/i }).click();
  const name = `Portal approval order ${testInfo.project.name} ${Date.now()}`;
  await fillSession5OrderForm(page, name);
  await page.getByRole('button', { name: 'Create record' }).click();
  await page.getByRole('link', { name: 'Request approval for Approve' }).click();
  await page.locator('input[type="checkbox"][required]').check();
  await page.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.getByText('Approval requested.', { exact: false })).toBeVisible();
  const approvalRequestId = await page.locator('.portal-notice code').textContent();
  if (!approvalRequestId) {
    throw new Error('The generated action did not return an approval request identity.');
  }
  expect(approvalRequestId).toMatch(/^[0-9a-f-]{36}$/u);
  await expect(page.locator('[name="approval_request_id"]')).toHaveValue(approvalRequestId);

  const approverEmail = `browser-approver-${project}-${retry}@kumwe.test`;
  const approverPassword = `browser ${project} approver password ${retry}`;
  const viewport = page.viewportSize();
  const approverContext = await browser.newContext({
    baseURL: process.env.KUMWE_BROWSER_BASE_URL ?? 'http://127.0.0.1:8080',
    viewport: viewport ?? { width: 1280, height: 720 },
    isMobile: project === 'mobile',
    hasTouch: project === 'mobile',
  });
  const approverPage = await approverContext.newPage();
  try {
    await signIn(approverPage, approverEmail, approverPassword);
    const recoveryCode = await enrollPortalAuthenticator(approverPage);
    await approverPage.goto(`/portal/approvals/${approvalRequestId}`);
    await expect(approverPage.getByRole('heading', { name: 'Approval request' })).toBeVisible();
    await expect(approverPage.locator('[data-kis-component="review-workspace"]'))
      .toHaveAttribute('data-kis-surface', 'core.portal.approvals');
    await expect(approverPage.getByRole('heading', { name: 'Review target and consequence' }))
      .toBeVisible();
    await expectAccessible(approverPage);
    const reviewDiagnostics = await expectNoDocumentOverflow(approverPage, {
      root: '#portal-main',
      detectControlOverlaps: false,
    });
    expect(reviewDiagnostics.findings, JSON.stringify(reviewDiagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(
      approverPage,
      testInfo,
      'portal-approval-detail-before-decision',
    );
    await approverPage.getByLabel('Recovery code').check();
    await approverPage.getByLabel('Verification code').fill(recoveryCode);
    await approverPage.getByLabel('Reason').fill('Browser maker-checker acceptance');
    await approverPage.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(approverPage.getByText('approved', { exact: true })).toBeVisible();
    await approverPage.getByRole('tab', { name: /Decision history/u }).click();
    const history = approverPage.getByRole('tabpanel', { name: /Decision history/u });
    await expect(history.getByRole('heading', { name: 'Decision history' })).toBeVisible();
    await expect(history.getByRole('listitem')).toContainText('Browser maker-checker acceptance');
    await expectAccessible(approverPage);
    const historyDiagnostics = await expectNoDocumentOverflow(approverPage, {
      root: '#portal-main',
      detectControlOverlaps: false,
    });
    expect(historyDiagnostics.findings, JSON.stringify(historyDiagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(
      approverPage,
      testInfo,
      'portal-approval-decision-history',
    );
  } finally {
    await approverContext.close();
  }

  await page.getByLabel('Recovery code').check();
  await page.getByLabel('Verification code').fill(makerRecoveryCode);
  await page.locator('input[type="checkbox"][required]').check();
  await page.getByRole('button', { name: 'Approve', exact: true }).click();
  await expectPortalRecordName(page, name);
  await expect(page.locator('.portal-business-status')).toContainText('Approved');
  await expectAccessible(page);
  // KIS-EVIDENCE-END p6-001-maker-checker
});

test('portal generated forms complete a no-JavaScript lifecycle', async ({ browser }, testInfo) => {
  test.slow();
  test.setTimeout(300_000);
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await signIn(page);
    await page.goto(`/portal/business/${businessDefinitionHandle}`);
    await page.getByRole('link', { name: /Create session 5 order/i }).click();
    const form = page.locator('form.portal-business-form');
    await expect(form).toHaveAttribute('method', 'post');
    await expect(form.locator('input[name="_csrf"]')).not.toHaveValue('');
    await expect(form.locator('input[name="operation_id"]')).not.toHaveValue('');
    await expect(form.getByRole('button', { name: 'Create record' })).toBeVisible();
    const name = `No JS portal order ${testInfo.project.name} ${Date.now()}`;
    await fillSession5OrderForm(page, name);
    await form.getByRole('button', { name: 'Create record' }).click();
    await expect(page).toHaveURL(new RegExp(
      `/portal/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
    ));
    await expectPortalRecordName(page, name);
    await expect(page.getByText('portal-secret-value', { exact: true })).toHaveCount(0);
    await page.getByRole('link', { name: 'View operation status' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Operation status' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Operation completed' })).toBeVisible();
    await page.getByRole('link', { name: 'Return to record', exact: true }).click();
    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    const updatedName = `${name} updated`;
    await page.locator('[name="values[name]"]').fill(updatedName);
    await page.locator('[name="values[credential]"]').fill('portal-secret-updated');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expectPortalRecordName(page, updatedName);
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Record history' })).toBeVisible();
    await expect(page.getByText('update', { exact: true }).first()).toBeVisible();
    await page.goto(`/portal/business/${businessDefinitionHandle}`);
    await page.getByLabel('Search records').fill(updatedName);
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expectPortalRecordRow(page, updatedName);
    await page.locator('input[name="bulk_records[]"]:visible').first().check();
    await page.getByLabel('Bulk operation').selectOption('archive');
    await page.getByRole('button', { name: 'Review bulk operation' }).click();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Archive selected records' }).click();
    await expect(page).toHaveURL(new RegExp(`/portal/business/${businessDefinitionHandle}\\?saved=1&bulk_count=1$`));
    await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
    await page.getByLabel('Search records').fill(updatedName);
    await page.getByText('Filters, sorting and lifecycle', { exact: true }).click();
    await expect(page.getByLabel('Include archived')).toBeVisible();
    await page.getByLabel('Include archived').check();
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expectPortalRecordRow(page, updatedName);
    await page.locator('input[name="bulk_records[]"]:visible').first().check();
    await page.getByLabel('Bulk operation').selectOption('restore');
    await page.getByRole('button', { name: 'Review bulk operation' }).click();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Restore selected records' }).click();
    await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
    await page.goto(`/portal/business/${businessDefinitionHandle}`);
    await page.getByLabel('Search records').fill(updatedName);
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    const visibleResult = page
      .locator('tbody tr:visible, li.kis-business-result-card:visible')
      .filter({ hasText: updatedName });
    await expect(visibleResult).toHaveCount(1);
    await visibleResult.getByRole('link', { name: /^(?:View|Open record)$/ }).click();
    let tags = page.locator('article.portal-business-relation').filter({
      has: page.getByRole('heading', { name: 'Tags', exact: true }),
    });
    await tags.getByRole('link', { name: 'View and manage' }).click();
    tags = page.locator('article.portal-business-relation').filter({
      has: page.getByRole('heading', { name: 'Tags', exact: true }),
    });
    await tags.getByRole('link', { name: 'Search available records' }).click();
    await page.getByRole('row', { name: /Walvis Bay relationship target/ })
      .getByRole('link', { name: 'Choose' })
      .click();
    await tags.locator('select[name="target_record_id"]')
      .selectOption('019b40d9-8dd0-7ca2-a0db-9eae6a150522');
    await tags.getByRole('button', { name: 'Add relationship' }).click();
    await expect(tags).toContainText('Walvis Bay relationship target');
    await page.getByRole('link', { name: 'Back to record' }).click();
    let lines = page.locator('article.portal-business-relation').filter({
      has: page.getByRole('heading', { name: 'Lines', exact: true }),
    });
    await lines.getByRole('link', { name: 'View and manage' }).click();
    lines = page.locator('article.portal-business-relation').filter({
      has: page.getByRole('heading', { name: 'Lines', exact: true }),
    });
    const lineForm = lines.locator('form.portal-business-owned-line-form');
    await lineForm.locator('[name="target_values[description]"]').fill('Portal no-JavaScript line');
    await lineForm.locator('[name="target_values[units]"]').fill('4.000');
    await lineForm.getByRole('button', { name: 'Add Neutral line' }).click();
    const orderControls = lines.locator('select[name="ordered_record_ids[]"]');
    await expect(orderControls).toHaveCount(1);
    await expect(orderControls.first()).not.toHaveValue('');
  } finally {
    await context.close();
  }
});
