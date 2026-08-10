import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';

const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL
  ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD
  ?? 'browser portal password';
const businessDefinitionHandle = 'site.default.session5_order';

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
  await expect(page.locator('.portal-business-table tbody tr').filter({ hasText: value }).first())
    .toBeVisible();
}

test('portal login is accessible and visually bounded', async ({ page }, testInfo) => {
  await page.goto('/portal/login');
  await expect(page.getByRole('heading', { name: 'Sign in to the portal' })).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
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
}, testInfo) => {
  await signIn(page);
  await page.goto('/portal/business');
  await expect(page.locator('a[href="/portal/business"]')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: 'Business records' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open session 5 orders/i })).toBeVisible();
  await page.goto(`/portal/business/${businessDefinitionHandle}`);
  await expect(page.locator('.portal-business-table tbody tr').first()).toBeVisible();
  await expect(page.getByRole('link', { name: 'Report', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Export', exact: true })).toBeVisible();
  await page.getByRole('checkbox', { name: /Select record/ }).first().check();
  await page.getByLabel('Bulk operation').selectOption('archive');
  await page.getByRole('button', { name: 'Review bulk operation' }).click();
  await expect(page.getByRole('heading', { name: 'Archive selected records' })).toBeVisible();
  await expect(page.locator('input[name="operation_id"]')).not.toHaveValue('');
  await expect(page.locator('input[name="confirmed"]')).toHaveValue('1');
  await page.goto(`/portal/business/${businessDefinitionHandle}`);
  await expectStylesLoaded(page);
  await expectAccessible(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
  await expect(page).toHaveScreenshot('portal-generated-business-list.png', {
    fullPage: true,
    mask: [page.locator('.portal-business-table tbody tr')],
    maskColor: '#d9e2e8',
  });
  await page.screenshot({
    path: testInfo.outputPath('portal-business-records.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
  await page.goto(
    `/portal/business/${businessDefinitionHandle}/019b40d9-8dd0-7ca2-a0db-9eae6a150511`,
  );
  await expect(page.getByRole('heading', { name: 'Record details' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
  await expectAccessible(page);
  await expect(page).toHaveScreenshot('portal-generated-business-detail.png', {
    fullPage: true,
    mask: [page.locator('time')],
    maskColor: '#d9e2e8',
  });
});

test('portal maker-checker approval requires a distinct step-up identity', async ({
  page,
  browser,
}, testInfo) => {
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
  const approverContext = await browser.newContext({
    baseURL: process.env.KUMWE_BROWSER_BASE_URL ?? 'http://127.0.0.1:8080',
  });
  const approverPage = await approverContext.newPage();
  try {
    await signIn(approverPage, approverEmail, approverPassword);
    const recoveryCode = await enrollPortalAuthenticator(approverPage);
    await approverPage.goto(`/portal/approvals/${approvalRequestId}`);
    await expect(approverPage.getByRole('heading', { name: 'Approval request' })).toBeVisible();
    await approverPage.getByLabel('Recovery code').check();
    await approverPage.getByLabel('Verification code').fill(recoveryCode);
    await approverPage.getByLabel('Reason').fill('Browser maker-checker acceptance');
    await approverPage.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(approverPage.getByText('approved', { exact: true })).toBeVisible();
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
});

test('portal generated forms complete a no-JavaScript lifecycle', async ({ browser }, testInfo) => {
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
    await page.getByRole('link', { name: 'Return to record' }).click();
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
    await page.getByRole('checkbox', { name: /Select record/ }).check();
    await page.getByLabel('Bulk operation').selectOption('archive');
    await page.getByRole('button', { name: 'Review bulk operation' }).click();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Archive selected records' }).click();
    await expect(page).toHaveURL(new RegExp(`/portal/business/${businessDefinitionHandle}\\?saved=1&bulk_count=1$`));
    await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
    await page.getByLabel('Search records').fill(updatedName);
    await page.getByLabel('Include archived').check();
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expectPortalRecordRow(page, updatedName);
    await page.getByRole('checkbox', { name: /Select record/ }).check();
    await page.getByLabel('Bulk operation').selectOption('restore');
    await page.getByRole('button', { name: 'Review bulk operation' }).click();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Restore selected records' }).click();
    await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
    await page.goto(`/portal/business/${businessDefinitionHandle}`);
    await page.getByLabel('Search records').fill(updatedName);
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.getByRole('link', { name: 'View', exact: true }).click();
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
    await expectAccessible(page);
  } finally {
    await context.close();
  }
});
