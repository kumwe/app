import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const limitedEmail = process.env.KUMWE_BROWSER_LIMITED_EMAIL ?? 'browser-limited@kumwe.test';
const limitedPassword = process.env.KUMWE_BROWSER_LIMITED_PASSWORD ?? 'browser limited password';
const minimalEmail = 'browser-minimal@kumwe.test';
const minimalPassword = 'browser minimal password';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/);
}

async function openNavigation(page: Page, isMobile: boolean): Promise<void> {
  if (isMobile) {
    await page.getByRole('button', { name: 'Open administrator navigation' }).click();
  }
}

async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

test.describe('capability-degraded administrator access', () => {
  test('an administrator holding only administrator.access lands on a themed dashboard', async ({
    page,
    isMobile,
  }) => {
    await signIn(page, minimalEmail, minimalPassword);

    await expect(page.locator('.administrator-shell')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Good work starts with a clear view.' })).toBeVisible();
    await expect(page.locator('[data-permission-reduced]')).toBeVisible();
    await expect(page.getByText('Recently updated')).toHaveCount(0);
    await expect(page.getByText('% published')).toHaveCount(0);

    await openNavigation(page, isMobile);
    const navigation = page.locator('.administrator-navigation');
    await expect(navigation.getByRole('link', { name: 'Dashboard', exact: true })).toBeVisible();
    await expect(navigation.getByRole('link')).toHaveCount(1);
    await expectAccessible(page);
  });

  test('a denied administrator URL renders the themed 403 page instead of raw JSON', async ({
    page,
    isMobile,
  }) => {
    await signIn(page, limitedEmail, limitedPassword);

    const denied = await page.goto('/administrator/settings');
    expect(denied?.status()).toBe(403);
    expect(denied?.headers()['content-type'] ?? '').toContain('text/html');

    await expect(page.locator('.administrator-shell')).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'You do not have access to this screen' }),
    ).toBeVisible();
    await expect(page.getByText('settings.manage').first()).toBeVisible();

    await openNavigation(page, isMobile);
    const navigation = page.locator('.administrator-navigation');
    await expect(navigation.getByRole('link', { name: 'Dashboard', exact: true })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Content', exact: true })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Settings' })).toHaveCount(0);
    await expectAccessible(page);

    await page.goto('/administrator/settings');
    await page.getByRole('link', { name: 'Back to the dashboard' }).click();
    await expect(page).toHaveURL(/\/administrator$/);
    await expect(page.getByRole('heading', { name: 'Good work starts with a clear view.' })).toBeVisible();
    await expect(page.getByText('Recently updated')).toBeVisible();
  });

  test('non-browser callers still receive the machine-readable problem document', async ({ page }) => {
    await signIn(page, limitedEmail, limitedPassword);

    const response = await page.context().request.get('/administrator/settings', {
      headers: { Accept: 'application/json' },
    });

    expect(response.status()).toBe(403);
    expect(response.headers()['content-type']).toBe('application/problem+json');
    const problem = await response.json();
    expect(problem.type).toBe('urn:kumwe:problem:insufficient-capability');
    expect(problem.detail).toContain('settings.manage');
  });
});
