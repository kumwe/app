import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { gotoAfterRuntimeConvergence } from './support/runtime-convergence';

/**
 * V2-UX-003: records the service will refuse to change render read-only affordances, with the refusal's
 * own wording, on both generated browser surfaces; a refusal that races a stale page is answered on the
 * page instead of the global error boundary; and the definition editor shows the declared immutable states.
 *
 * Fixtures come from tests/Support/prepare-browser-contribution.php: `site.default.browser_record_lock`
 * declares an immutable `approved` state and a `posted_on` posting date, with one open, one approved and
 * one closed-period record. The spec never saves a successful change, so it is repeatable.
 */

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';
const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';
const definition = 'site.default.browser_record_lock';
const openRecord = '019b40d9-8dd0-7ca2-a0db-9eae6a150711';
const approvedRecord = '019b40d9-8dd0-7ca2-a0db-9eae6a150712';
const closedRecord = '019b40d9-8dd0-7ca2-a0db-9eae6a150713';
const immutableWording =
  'The business record is immutable in its current workflow state and is corrected by a linked reversal.';
const closedWording = 'Posting period browser-record-lock-4200 is closed for the declared posting date.';

async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

async function signInAdministrator(page: Page): Promise<void> {
  await gotoAfterRuntimeConvergence(page, '/administrator/login', 'The administrator sign-in page');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/);
}

async function signInPortal(page: Page): Promise<void> {
  await gotoAfterRuntimeConvergence(page, '/portal/login', 'The portal sign-in page');
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/);
}

async function expectReadOnlyNotice(page: Page, code: string, wording: string): Promise<void> {
  const notice = page.locator(`.business-record-lock[data-record-lock="${code}"]`);
  await expect(notice).toBeVisible();
  await expect(notice.getByRole('heading', { name: 'This record is read-only' })).toBeVisible();
  await expect(notice.locator('[data-record-lock-reason]')).toHaveText(wording);
}

test.describe('generated record locks', () => {
  test('administrator records in an immutable state or a closed period render read-only affordances', async ({
    page,
  }) => {
    await signInAdministrator(page);
    const base = `/administrator/business/${definition}`;

    await page.goto(`${base}/${openRecord}`);
    await expect(page.locator('.business-record-lock')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Edit', exact: true })).toBeVisible();

    await page.goto(`${base}/${approvedRecord}`);
    await expectReadOnlyNotice(page, 'business_record.immutable', immutableWording);
    await expect(page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Archive record' })).toHaveCount(0);
    await expectAccessible(page);

    await page.goto(`${base}/${approvedRecord}?edit=1`);
    await expectReadOnlyNotice(page, 'business_record.immutable', immutableWording);
    await expect(page.locator('fieldset.business-record-lock-fields')).toHaveAttribute('disabled', '');
    await expect(page.locator('[name="values[title]"]')).toBeDisabled();
    await expect(page.locator('[name="values[posted_on]"]')).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);
    await expectAccessible(page);

    await page.goto(`${base}/${closedRecord}`);
    await expectReadOnlyNotice(page, 'business_record.posting_period_closed', closedWording);
    await expect(page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);

    await page.goto(base);
    const approvedRow = page.locator('.business-record-table tbody tr:visible, .kis-business-result-card:visible')
      .filter({ hasText: 'Approved statement' }).first();
    await expect(approvedRow.locator('.business-record-lock-badge')).toHaveText('Read-only');
    const openRow = page.locator('.business-record-table tbody tr:visible, .kis-business-result-card:visible')
      .filter({ hasText: 'Open statement' }).first();
    await expect(openRow.locator('.business-record-lock-badge')).toHaveCount(0);
  });

  test('a refused save answers on the page with the refusal wording instead of an error page', async ({
    page,
  }) => {
    await signInAdministrator(page);
    const base = `/administrator/business/${definition}`;

    // Dating an open record into the closed period keeps the form, the typed values and marks the date.
    await page.goto(`${base}/${openRecord}?edit=1`);
    await page.locator('[name="values[title]"]').fill('Open statement');
    await page.locator('[name="values[posted_on]"]').fill('4200-01-10');
    await page.getByRole('button', { name: 'Save changes' }).click();
    const summary = page.locator('.business-error-summary');
    await expect(summary).toBeVisible();
    await expect(summary).toContainText(closedWording);
    await expect(page.locator('[name="values[posted_on]"]')).toHaveValue('4200-01-10');
    await expect(page.locator('.business-field', { has: page.locator('#business-field-posted_on') })
      .locator('.business-field-errors')).toContainText(closedWording);
    await expectAccessible(page);

    // A form composed before another actor approved the record lands on the read-only record, not a 500.
    await page.goto(`${base}/${openRecord}?edit=1`);
    await page.locator('form.business-record-form').evaluate((form, target) => {
      (form as HTMLFormElement).action = target;
      const version = form.querySelector<HTMLInputElement>('input[name="expected_version"]');
      if (version !== null) version.value = '2';
    }, `${base}/${approvedRecord}`);
    const [response] = await Promise.all([
      page.waitForResponse((candidate) => candidate.request().method() === 'POST'),
      page.getByRole('button', { name: 'Save changes' }).click(),
    ]);
    expect(response.status()).toBe(409);
    await expectReadOnlyNotice(page, 'business_record.immutable', immutableWording);
    await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(/error/i);
  });

  test('portal records keep the same read-only affordances and wording', async ({ page }) => {
    await signInPortal(page);
    const base = `/portal/business/${definition}`;

    await page.goto(`${base}/${approvedRecord}`);
    await expectReadOnlyNotice(page, 'business_record.immutable', immutableWording);
    await expect(page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
    await expectAccessible(page);

    await page.goto(`${base}/${closedRecord}`);
    await expectReadOnlyNotice(page, 'business_record.posting_period_closed', closedWording);

    await page.goto(`${base}/${openRecord}`);
    await expect(page.locator('.business-record-lock')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
  });

  test('the definition editor shows and explains the declared immutable states', async ({ page }) => {
    await signInAdministrator(page);
    await page.goto('/administrator/business-definitions?definition=019b40d9-8dd0-7ca2-a0db-9eae6a150702&tab=workflow');
    const field = page.getByLabel(/Immutable states/);
    await expect(field).toHaveValue('approved');
    await expect(field).toHaveAccessibleDescription(/corrected by a linked reversal/);
  });
});
