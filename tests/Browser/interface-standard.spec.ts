import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';

async function signIn(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

test.describe('KIS production component gallery', () => {
  test.beforeEach(async ({ page }) => signIn(page));

  test('components preserve keyboard, focus, URL, responsive and mode contracts', async ({
    page,
    isMobile,
  }) => {
    // KIS-EVIDENCE-BEGIN p6-004-component-modes
    await page.goto('/administrator/interface-standard?tab=overview');
    await expect(page.getByRole('heading', { level: 1, name: 'Kumwe Interface Standard' })).toBeVisible();
    for (const component of [
      'page-header',
      'tabs',
      'master-detail',
      'resource-toolbar',
      'technical-value',
      'drawer',
      'validation-summary',
      'empty-state',
    ]) {
      await expect(page.locator(`[data-kis-component="${component}"]`).first()).toBeAttached();
    }

    const overview = page.getByRole('tab', { name: 'Overview' });
    await overview.focus();
    await page.keyboard.press('ArrowRight');
    const collections = page.getByRole('tab', { name: 'Collections' });
    await expect(collections).toBeFocused();
    await expect(collections).toHaveAttribute('aria-selected', 'true');
    await expect(page).toHaveURL(/tab=collections#kis-gallery-panel-collections$/u);
    await expect(page.locator('#kis-gallery-panel-collections')).toBeVisible();
    await expect(page.locator('#kis-gallery-panel-overview')).toBeHidden();

    if (isMobile) {
      const toggle = page.getByRole('button', { name: 'Browse example resources' });
      await expect(toggle).toBeVisible();
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator('#kis-gallery-resources-catalog')).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(toggle).toBeFocused();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    }

    await page.getByRole('tab', { name: 'Forms and drawers' }).click();
    const opener = page.getByRole('button', { name: 'Open focused editor' });
    await page.getByRole('link', { name: 'Enter a stable inspection reference.' }).click();
    await expect(page.getByRole('dialog', { name: 'Create inspection' })).toBeVisible();
    await expect(page.getByLabel('Reference')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(opener).toBeFocused();

    await opener.click();
    await expect(page.getByRole('dialog', { name: 'Create inspection' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Close' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(opener).toBeFocused();

    await page.getByRole('tab', { name: 'Safety and states' }).click();
    for (const state of [
      'empty',
      'sparse',
      'representative',
      'dense',
      'extreme',
      'error',
      'permission-reduced',
      'unchanged',
      'unsaved',
      'saving',
      'saved',
      'conflict',
      'failed',
    ]) {
      await expect(page.locator(`[data-kis-state="${state}"]`)).toBeVisible();
    }
    const conflict = page.locator('[data-kis-conflict-reference]');
    await expect(conflict.getByRole('button', { name: 'Compare changes' })).toBeVisible();
    await expect(conflict.getByRole('button', { name: 'Reload version 8' })).toBeVisible();
    await expect(conflict.getByRole('button', { name: 'Reapply my changes' })).toBeVisible();

    await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
    await expect.poll(async () => page.evaluate(() => getComputedStyle(document.documentElement).colorScheme))
      .toContain('dark');
    const report = await expectNoDocumentOverflow(page, { root: '#administrator-content' });
    expect(
      report.findings.filter((finding) => finding.kind !== 'control-overlap'),
      JSON.stringify(report, null, 2),
    ).toEqual([]);
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('kis-gallery-states-dark.png', {
      fullPage: true,
    });
    // KIS-EVIDENCE-END p6-004-component-modes
  });
});

test.describe('KIS server-rendered fallback', () => {
  test.use({ javaScriptEnabled: false });

  test('tabs, panels, drawer content and direct navigation remain usable without JavaScript', async ({
    page,
  }) => {
    // KIS-EVIDENCE-BEGIN p6-004-no-javascript
    await signIn(page);
    await page.goto('/administrator/interface-standard?tab=forms');

    await expect(page.getByRole('tab', { name: 'Forms and drawers' }))
      .toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('[role="tabpanel"]:visible')).toHaveCount(4);
    await expect(page.locator('#kis-gallery-drawer-drawer')).toBeVisible();
    await expect(page.getByLabel('Reference')).toHaveValue('INS-004');
    await page.getByRole('tab', { name: 'Safety and states' }).click();
    await expect(page).toHaveURL(/\/administrator\/interface-standard\?tab=safety/u);
    await expect(page.getByRole('heading', { name: 'Purge remains separate' })).toBeVisible();
    await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
    // KIS-EVIDENCE-END p6-004-no-javascript
  });
});

test.describe('KIS cross-tab form validation', () => {
  test.beforeEach(async ({ page }) => signIn(page));

  test('keeps the first invalid control focusable and restores cancelled submissions', async ({ page }) => {
    await page.goto('/administrator/interface-standard?tab=overview');
    await page.evaluate(() => {
      const fixture = document.createElement('form');
      fixture.dataset.testid = 'cross-tab-validation';
      fixture.innerHTML = `
        <kumwe-tabs data-kis-tab-parameter="validation_tab">
          <nav aria-label="Cross-tab validation fixture">
            <a role="tab" data-kis-tab="first" aria-selected="false" href="?validation_tab=first">First</a>
            <a role="tab" data-kis-tab="second" aria-selected="true" href="?validation_tab=second">Second</a>
          </nav>
          <section role="tabpanel" data-kis-tab-panel="first">
            <label>First required value<input data-testid="first-invalid" required></label>
          </section>
          <section role="tabpanel" data-kis-tab-panel="second">
            <label>Second required value<input data-testid="second-invalid" required></label>
            <button data-testid="cross-tab-submit" data-confirm="Continue?" type="submit">Continue</button>
          </section>
        </kumwe-tabs>`;
      document.querySelector('#administrator-content')?.append(fixture);
      fixture.querySelector<HTMLButtonElement>('[data-testid="cross-tab-submit"]')
        ?.addEventListener('click', (event) => event.preventDefault(), { once: true });
    });

    const firstPanel = page.locator('[data-kis-tab-panel="first"]');
    const secondPanel = page.locator('[data-kis-tab-panel="second"]');
    const submit = page.getByTestId('cross-tab-submit');
    await submit.click();
    await expect(firstPanel).toBeHidden();
    await expect(secondPanel).toBeVisible();

    await submit.click();
    await expect(page.getByTestId('first-invalid')).toBeFocused();
    await expect(page.getByRole('tab', { name: 'First' })).toHaveAttribute('aria-selected', 'true');
    await expect(firstPanel).toBeVisible();
    await expect(secondPanel).toBeHidden();

    await page.getByRole('tab', { name: 'Second' }).click();
    await page.evaluate(() => {
      const fixture = document.querySelector<HTMLFormElement>('[data-testid="cross-tab-validation"]');
      const button = fixture?.querySelector<HTMLButtonElement>('[data-testid="cross-tab-submit"]');
      if (!fixture || !button) throw new Error('Cross-tab submission fixture is unavailable.');
      button.formNoValidate = true;
      fixture.addEventListener('submit', (event) => event.preventDefault(), { once: true });
    });
    await submit.click();
    await expect(firstPanel).toBeHidden();
    await expect(secondPanel).toBeVisible();
  });
});
