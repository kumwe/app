import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL
  ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD
  ?? 'browser portal password';

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
  await page.goto('/portal/login');
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/);
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
