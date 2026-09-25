import { execFileSync } from 'node:child_process';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import { expectAccessible, signInAdministrator as signInLocalizedAdministrator } from './support/locale-qualification';
import { gotoAfterRuntimeConvergence } from './support/runtime-convergence';
import { preferStructuredContentForm } from './support/studio-authoring';

/**
 * P7-E automated interface acceptance: the five archetype task journeys ADR 0021 substitutes for the named
 * human reviewers. Each journey starts where a person would start, reaches its task through the product's
 * own navigation, completes it, and records task-based evidence as named steps: discoverability, task
 * completion, terminology, error recovery, confirmation, policy denial, concurrency and stale state,
 * long-form navigation, mobile ergonomics and assistive technology (axe wcag2a/2aa/21aa/22aa, keyboard and
 * focus). The journeys run on every `all` project; locally that is Chromium desktop and mobile, and the CI
 * nightly adds Firefox and WebKit. A WebKit result is WebKit evidence, not a claim about native Safari.
 *
 * The same content-authoring and generated-business archetypes are also completed in German and in Hebrew by
 * tests/Browser/locale-journeys.spec.ts, which this file does not repeat; both share the accessibility scan
 * and sign-in helpers in ./support/locale-qualification.
 *
 * Fixtures come from tests/Support/prepare-browser-contribution.php. Every journey creates the records it
 * changes, with a unique name, so a retry or a second project never meets another run's state. The lane
 * runs one worker over the files in name order, and this file is named to sort after every spec that pins
 * a screenshot, so the content and records a journey creates never enter a pinned screenshot of the same
 * project run.
 */

const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';
const ledgerDefinition = 'site.default.doc_header_browser';
const jobCardDefinition = 'site.default.browser_job_card';
const shopOrderDefinition = 'site.default.browser_shop_order';
const relationshipDefinition = 'site.default.session5_order';
const windhoekOrderId = '019b40d9-8dd0-7ca2-a0db-9eae6a150511';

/** Raw platform identifiers that must never be presented to a business user as a label. */
const rawPlatformTerms = [
  /\bsite\.default\./u,
  /\bcore\.[a-z_]+\b/u,
  /\bbusiness\.record\./u,
  /\b(?:action|document|relate|unrelate|reorder)\.[a-z_]+\b/u,
  /\{\{|\}\}/u,
];

async function expectBusinessTerminology(scope: Locator): Promise<void> {
  const text = await scope.innerText();
  for (const term of rawPlatformTerms) {
    expect(text, `visible copy must not present ${String(term)}`).not.toMatch(term);
  }
}

async function expectNoOverflow(page: Page, root: string): Promise<void> {
  const diagnostics = await expectNoDocumentOverflow(page, { root, detectControlOverlaps: false });
  expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
}

/** WCAG 2.2 target size: every visible primary control in the scope is at least 24 by 24 CSS pixels. */
async function expectTouchTargets(scope: Locator): Promise<void> {
  const undersized = await scope.locator('button:visible, a.button:visible, input[type="submit"]:visible')
    .evaluateAll((controls) => controls
      .map((control) => {
        const box = control.getBoundingClientRect();
        return { text: (control.textContent ?? '').trim(), width: box.width, height: box.height };
      })
      .filter((box) => box.width > 0 && (box.width < 24 || box.height < 24)));
  expect(undersized).toEqual([]);
}

async function signInAdministrator(page: Page): Promise<void> {
  await gotoAfterRuntimeConvergence(page, '/administrator/login', 'The administrator sign-in page');
  await signInLocalizedAdministrator(page, 'en-GB');
}

/** Run a tests/Support script the way an upstream system would act, and return what it printed. */
function runSupportScript(script: string, ...parameters: string[]): string {
  return execFileSync('php', [`tests/Support/${script}`, ...parameters], { encoding: 'utf8' })
    .split('\n').filter((line) => !line.startsWith('{')).join('').trim();
}

async function signInPortal(page: Page): Promise<void> {
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/u);
}

/** Reach an administrator destination the way a person does: through the navigation, opened on mobile. */
async function openAdministratorDestination(page: Page, testInfo: TestInfo, name: string | RegExp): Promise<void> {
  const toggle = page.getByRole('button', { name: 'Open administrator navigation' });
  if (testInfo.project.name.startsWith('mobile-')) {
    // The drawer is a progressive enhancement: retry until the module has bound the toggle and opened it.
    await expect(async () => {
      if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
        await toggle.click();
      }
      await expect(toggle).toHaveAttribute('aria-expanded', 'true', { timeout: 1_000 });
    }).toPass({ timeout: 15_000 });
  }
  await page.getByRole('navigation', { name: 'Administrator navigation' })
    .getByRole('link', { name, exact: typeof name === 'string' }).first().click();
}

/** Upload one small PNG through the media library, as a person attaching a photograph would. */
async function uploadMedia(page: Page, name: string): Promise<void> {
  const file = page.locator('input[type="file"][name="media"]');
  await file.setInputFiles({
    name,
    mimeType: 'image/png',
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
      'base64',
    ),
  });
  await page.getByRole('button', { name: 'Upload media' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'uploaded' })).toBeVisible();
  await expect(page.getByText(name, { exact: true })).toBeVisible();
}

/** Confirm a generated action the way the page asks: acknowledge the reviewed version, then submit. */
async function confirmAction(page: Page, action: string): Promise<void> {
  const acknowledge = page.locator('[data-business-confirm-check]');
  await expect(acknowledge).toBeVisible();
  await expect(page.getByRole('button', { name: action })).toBeDisabled();
  await acknowledge.check();
  await page.getByRole('button', { name: action }).click();
}

function unique(testInfo: TestInfo, label: string): string {
  return `${label} ${testInfo.project.name.replace(/[^a-z]/gu, '')} ${Date.now()}`;
}

test.describe('P7-E archetype task journeys', () => {
  test('(a) content authoring, media, navigation, workflow and publication', async ({ page }, testInfo) => {
    test.setTimeout(150_000);
    const title = unique(testInfo, 'Journey notice');
    const slug = `journey-notice-${testInfo.project.name.replace(/[^a-z]/gu, '')}-${Date.now()}`;
    await preferStructuredContentForm(page.context());
    await signInAdministrator(page);

    await test.step('discoverability: Content and Media are reached from the administrator navigation', async () => {
      await openAdministratorDestination(page, testInfo, 'Media');
      await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
      await expectAccessible(page);
    });

    await test.step('error recovery: an upload without a file is held by the form, then a real file uploads', async () => {
      const upload = page.getByRole('button', { name: 'Upload media' });
      await upload.click();
      const file = page.locator('input[type="file"][name="media"]');
      expect(await file.evaluate((input) => (input as HTMLInputElement).validity.valueMissing)).toBe(true);
      await uploadMedia(page, `${slug}.png`);
    });

    await test.step('task completion: a draft is authored in the structured editor without raw JSON', async () => {
      await openAdministratorDestination(page, testInfo, 'Content');
      await expect(page.getByRole('heading', { name: 'Content', exact: true })).toBeVisible();
      const create = page.getByRole('main').getByRole('link', { name: 'Create content', exact: true }).first();
      await expect(create).toBeVisible();
      await create.click();
      await expect(page.getByText('JSON', { exact: true })).toHaveCount(0);
      await page.getByLabel('Title').fill(title);
      await page.getByLabel('URL slug').fill(slug);
      await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(`Opening hours for ${title}.`);
      await page.getByRole('button', { name: 'Create draft' }).click();
      await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/u);
      await expectBusinessTerminology(page.locator('main'));
    });

    await test.step('workflow and confirmation: review then publish, and the published page is linked', async () => {
      await page.getByRole('button', { name: 'Move to Review' }).click();
      await page.getByRole('button', { name: 'Move to Published' }).click();
      await expect(page.getByRole('link', { name: 'View page' })).toHaveAttribute('href', `/${slug}`);
      await expectAccessible(page);
    });

    await test.step('navigation: the page is added to a menu with its calculated path', async () => {
      await openAdministratorDestination(page, testInfo, 'Menus');
      const addItem = page.locator('details').filter({ hasText: 'Add a menu item' }).first();
      await addItem.locator('summary').click();
      const form = addItem.locator('form');
      await form.getByLabel('Link type').selectOption('content');
      await form.locator('select[name="content_id"]').selectOption({ label: `${title} · Published` });
      await form.getByLabel('Link label').fill(title);
      await form.getByLabel('URL segment').fill(slug);
      await form.getByRole('button', { name: 'Add link' }).click();
      await expect(page.getByText(`Calculated menu path: /${slug}`)).toBeVisible();
      const item = page.locator('[data-menu-item]').filter({ has: page.locator(`input[value="${title}"]`) });
      await expect(item).toHaveAttribute('data-menu-depth', /^\d+$/u);
      await expect(item).not.toHaveAttribute('style', /.*/u);
    });

    await test.step('publication: the public page renders with no inline style and no overflow', async () => {
      await page.goto(`/${slug}`);
      await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
      await expect(page.locator('body')).not.toHaveAttribute('style', /.*/u);
      await expect(page.locator('link[data-site-theme]')).toHaveAttribute('href', /^\/presentation\/theme\.css\?/u);
      await expectAccessible(page);
      await expectNoOverflow(page, '#site-content');
    });

    await test.step('keyboard: the skip link moves focus to the page content', async () => {
      await page.keyboard.press('Tab');
      await page.keyboard.press('Enter');
      await expect(page.locator('#site-content')).toBeFocused();
    });
  });

  test('(b) an exact-value thousand-line document is drafted, reviewed, approved, posted, inspected and exported', async ({
    page,
  }, testInfo) => {
    test.setTimeout(240_000);
    const title = unique(testInfo, 'Ledger');
    const prefix = `${testInfo.project.name.slice(0, 1).toUpperCase()}${Date.now().toString(36).toUpperCase()}`;
    // A thousand lines arrive from an upstream system, not a keyboard: the draft is written out of process.
    const recordId = runSupportScript('draft-browser-ledger-document.php', title, prefix);
    expect(recordId).toMatch(/^[0-9a-f-]{36}$/u);
    await signInAdministrator(page);
    const base = `/administrator/business/${ledgerDefinition}`;
    const record = `${base}/${recordId}`;

    await test.step('discoverability and progressive disclosure: the draft is found through the title filter', async () => {
      await page.goto('/administrator/business');
      await page.getByRole('link', { name: /Ledger documents/u }).first().click();
      await expect(page).toHaveURL(new RegExp(`${base}$`, 'u'));
      const filters = page.locator('details.kis-business-advanced-filters');
      await expect(filters.getByLabel('Title', { exact: true })).toBeHidden();
      await filters.locator('summary').click();
      await filters.getByLabel('Title', { exact: true }).fill(title);
      await page.locator('form.business-query-form').getByRole('button', { name: 'Apply', exact: true }).click();
      await expect(page.getByText(title).locator('visible=true').first()).toBeVisible();
      await expect(page.locator('main')).toContainText('Draft');
      await expectBusinessTerminology(page.locator('main'));
    });

    await test.step('long-form navigation: all one thousand lines render with the exact total', async () => {
      await page.goto(record);
      await expect(page.getByText('5005.00').first()).toBeVisible();
      const document = page.locator('main');
      await expect(document.getByText(`${prefix}-0001`, { exact: true })).toBeVisible();
      await expect(document.getByText(`${prefix}-1000`, { exact: true })).toHaveCount(1);
      await expect(document.getByText('10.00', { exact: true }).first()).toBeAttached();
      await expectNoOverflow(page, '#administrator-content');
    });

    await test.step('drafting and error recovery: a total that disagrees with the lines is refused and explained', async () => {
      await page.goto(`${record}?edit=1`);
      await page.locator('[name="values[title]"]').fill(`${title} reviewed`);
      await page.locator('[name="values[total]"]').fill('5005.01');
      await page.getByRole('button', { name: 'Save changes' }).click();
      const summary = page.getByRole('alert');
      await expect(summary).toContainText('The record could not be saved');
      await expect(summary.locator('[data-record-error]')).toHaveText('The document total must equal the sum of its lines.');
      await expect(page.locator('[name="values[title]"]')).toHaveValue(`${title} reviewed`);
      await expectAccessible(page);
      await page.locator('[name="values[total]"]').fill('5005.00');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expect(page.locator('main')).toContainText(`${title} reviewed`);
    });

    await test.step('confirmation: submit, approve and post each ask for explicit confirmation', async () => {
      for (const [action, state] of [
        ['Submit for review', 'In review'],
        ['Approve document', 'Approved'],
        ['Post to ledger', 'Posted'],
      ] as const) {
        await page.goto(`${record}?task=actions`);
        await page.getByRole('link', { name: action, exact: true }).click();
        await confirmAction(page, action);
        await expect(page).toHaveURL(new RegExp(`${record}`, 'u'));
        await expect(page.locator('main')).toContainText(state);
      }
    });

    await test.step('policy: the posted document is read-only with the refusal wording', async () => {
      await page.goto(`${record}?task=relations`);
      await expect(page.locator('.business-record-lock[data-record-lock="business_record.immutable"]')).toBeVisible();
      await expect(page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
      await expectAccessible(page);
    });

    await test.step('history: every step is inspectable in business terms', async () => {
      await page.goto(`${record}?task=history`);
      await expect(page.getByRole('heading', { name: /history/iu }).first()).toBeVisible();
      for (const step of ['Post to ledger', 'Approve document', 'Submit for review', 'Edited', 'Document created']) {
        await expect(page.locator('main')).toContainText(step);
      }
      await expect(page.locator('main .eyebrow').first()).toHaveText('Ledger documents');
      await expectBusinessTerminology(page.locator('main'));
      await expectAccessible(page);
    });

    await test.step('export: the queued CSV export carries the exact total and is downloadable', async () => {
      await page.goto(base);
      await page.getByRole('button', { name: 'Queue CSV export', exact: true }).click();
      await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
      const download = page.getByRole('link', { name: 'Download verified CSV' });
      await expect(async () => {
        if ((await download.count()) === 0) {
          await page.getByRole('link', { name: 'Refresh status' }).click();
        }
        await expect(download).toBeVisible({ timeout: 1_000 });
      }).toPass({ timeout: 90_000, intervals: [1_000, 2_000, 5_000] });
      const response = await page.request.get((await download.getAttribute('href')) ?? '');
      expect(response.status()).toBe(200);
      expect(response.headers()['content-type']).toMatch(/^text\/csv/u);
      const rows = (await response.text()).replace(/^\uFEFF/u, '').trim().split(/\r?\n/u);
      expect(rows[0]).toBe('"Title","Total"');
      expect(rows).toContain(`"${title} reviewed","5005.00"`);
    });
  });

  test('(c) a relationship and self-service portal flow', async ({ page }) => {
    test.setTimeout(150_000);
    await gotoAfterRuntimeConvergence(page, '/portal/login', 'The portal sign-in page');
    await page.goto('/portal/business');

    await test.step('policy denial: an anonymous visitor is sent to sign in, never shown records', async () => {
      await expect(page).toHaveURL(/\/portal\/login$/u);
      await expectAccessible(page);
    });

    await test.step('error recovery: a wrong password keeps the address and explains the refusal', async () => {
      await page.getByLabel('Email address').fill(portalEmail);
      await page.getByLabel('Password').fill('not the portal password');
      await page.getByLabel('Workspace').fill('north');
      await page.getByRole('button', { name: 'Sign in' }).click();
      await expect(page.getByLabel('Email address')).toHaveValue(portalEmail);
      await expect(page.getByRole('alert')).toBeVisible();
      await signInPortal(page);
    });

    await test.step('discoverability: business workspaces are reached from the portal home', async () => {
      await page.locator('a[href="/portal/business"]').first().click();
      await expect(page).toHaveURL(/\/portal\/business$/u);
      await expectBusinessTerminology(page.locator('main'));
    });

    await test.step('relationship: the member opens a record and reviews its related records', async () => {
      await page.goto(`/portal/business/${relationshipDefinition}/${windhoekOrderId}`);
      await expect(page.locator('main')).toContainText(/Windhoek/u);
      const relations = page.getByRole('link', { name: /Related|View and manage/u }).first();
      if ((await relations.count()) > 0) {
        await relations.click();
      }
      await expectAccessible(page);
    });

    await test.step('self-service: the account page refuses a wrong current password without changing it', async () => {
      await page.goto('/portal/account');
      await page.getByLabel('Current password').fill('not the portal password');
      await page.getByLabel('New password', { exact: true }).fill('a new portal passphrase');
      await page.getByLabel(/Confirm new password|Repeat new password/u).fill('a new portal passphrase');
      await page.getByRole('button', { name: /Change my password/iu }).click();
      await expect(page.locator('main')).toContainText(/current password/iu);
      await expectAccessible(page);
    });

    await test.step('policy denial: the member cannot open the administrator', async () => {
      const response = await page.goto('/administrator');
      expect(response?.status()).toBeLessThan(500);
      await expect(page).toHaveURL(/\/administrator\/login$/u);
    });
  });

  test('(d) a mobile assignment with parts, labour, measurements and media', async ({ page }, testInfo) => {
    test.setTimeout(180_000);
    const job = unique(testInfo, 'Pump station service');
    const photo = `site-${testInfo.project.name.replace(/[^a-z]/gu, '')}-${Date.now()}.png`;
    await signInAdministrator(page);
    const base = `/administrator/business/${jobCardDefinition}`;

    await test.step('media: the technician uploads the site photograph from the phone', async () => {
      await openAdministratorDestination(page, testInfo, 'Media');
      await uploadMedia(page, photo);
    });

    await test.step('task completion: the assignment is created with the site photograph attached', async () => {
      await page.goto(base);
      await page.getByRole('link', { name: /Create job card/iu }).first().click();
      await page.getByRole('link', { name: 'Search and browse available media' }).click();
      await page.getByLabel('Media name').fill(photo);
      await page.getByRole('button', { name: 'Search', exact: true }).click();
      await page.getByRole('row').filter({ hasText: photo }).getByRole('link', { name: 'Choose' }).click();
      const siteMedia = page.locator('[name="values[site_photo]"]');
      await expect(siteMedia).toHaveValue(/^[0-9a-f-]{36}$/u);
      const photoId = await siteMedia.inputValue();
      await page.locator('[name="values[title]"]').fill(job);
      await page.locator('[name="values[site_address]"]').fill('12 Harbour Road, Walvis Bay');
      await expectTouchTargets(page.locator('main'));
      await page.getByRole('button', { name: 'Create record' }).click();
      await expect(page.locator('main')).toContainText(job);
      await expect(page.locator('main')).toContainText(photoId);
    });
    const recordPath = new URL(page.url()).pathname;

    await test.step('workflow and confirmation: the technician starts work after confirming', async () => {
      await page.goto(`${recordPath}?confirm=action&action=start`);
      await confirmAction(page, 'Start work');
      await expect(page.locator('main')).toContainText(/In progress/iu);
    });

    for (const [relation, fields, button, recorded] of [
      [
        'Parts used',
        { part_number: 'PMP-SEAL-40', description: 'Mechanical seal', quantity: '2.00' },
        /Add Part used/u,
        'PMP-SEAL-40',
      ],
      ['Labour', { task: 'Replaced mechanical seal', hours: '1.50' }, /Add Labour entry/u, 'Replaced mechanical seal'],
      [
        'Measurements',
        { metric: 'Discharge pressure', reading: '3.20', unit: 'bar' },
        /Add Measurement/u,
        'Discharge pressure',
      ],
    ] as const) {
      await test.step(`long-form navigation: ${relation} are recorded as grouped lines`, async () => {
        await page.goto(`${recordPath}?task=relations`);
        const section = page.locator('article.business-relation').filter({
          has: page.getByRole('heading', { name: relation, exact: true }),
        });
        const manage = section.getByRole('link', { name: 'View and manage' });
        if ((await manage.count()) > 0) {
          await manage.click();
        }
        const form = page.locator('article.business-relation').filter({
          has: page.getByRole('heading', { name: relation, exact: true }),
        }).locator('form.business-owned-line-form');
        for (const [field, value] of Object.entries(fields)) {
          await form.locator(`[name="target_values[${field}]"]`).fill(value);
        }
        await expectTouchTargets(form);
        await form.getByRole('button', { name: button }).click();
        await expect(page.locator('main')).toContainText(recorded);
      });
    }

    await test.step('mobile ergonomics and assistive technology: the record stays within the viewport', async () => {
      await page.goto(`${recordPath}?task=relations`);
      await expectAccessible(page);
      await expectNoOverflow(page, '#administrator-content');
    });

    await test.step('completion: completing the job makes it read-only', async () => {
      await page.goto(`${recordPath}?confirm=action&action=complete`);
      await confirmAction(page, 'Complete job');
      await expect(page.locator('.business-record-lock[data-record-lock="business_record.immutable"]')).toBeVisible();
    });
  });

  test('(e) a public catalogue to an authenticated order, fulfilment and out-of-process payment', async ({
    page,
    browser,
    request,
  }, testInfo) => {
    test.setTimeout(180_000);
    const address = unique(testInfo, 'Dock 4, Lüderitz');

    await test.step('discoverability: the public catalogue offers the order and the portal asks to sign in', async () => {
      await gotoAfterRuntimeConvergence(page, '/browser-catalogue', 'The public catalogue page');
      await expect(page.getByRole('heading', { level: 1, name: 'Field service kits' })).toBeVisible();
      await expectAccessible(page);
      await page.getByRole('link', { name: 'Order the site survey kit' }).click();
      await expect(page).toHaveURL(/\/portal\/login$/u);
      await signInPortal(page);
    });

    await test.step('error recovery: an incomplete order keeps what was typed and marks what is missing', async () => {
      await page.goto(`/portal/business/${shopOrderDefinition}?new=1`);
      await expect(page.locator('[name="values[payment_status]"]')).toHaveCount(0);
      await page.locator('[name="values[product]"]').fill('Site survey kit');
      await page.locator('[name="values[delivery_address]"]').fill(address);
      await page.locator('[name="values[quantity]"]').fill('0.5');
      await page.getByRole('button', { name: 'Create record' }).click();
      await expect(page.locator('[name="values[delivery_address]"]')).toHaveValue(address);
      await page.locator('[name="values[quantity]"]').fill('2');
      await page.getByRole('button', { name: 'Create record' }).click();
      await expect(page.locator('main')).toContainText(address);
      await expect(page.locator('main')).toContainText(/Pending/iu);
    });
    const orderPath = new URL(page.url()).pathname;
    const orderId = orderPath.split('/').pop() ?? '';

    await test.step('policy denial: the customer cannot fulfil their own order', async () => {
      await page.goto(`${orderPath}?confirm=action&action=fulfil`);
      await expect(page.getByRole('button', { name: 'Mark as fulfilled' })).toHaveCount(0);
    });

    await test.step('out-of-process payment: the adapter records payment and a stale version is refused', async () => {
      // The adapter's token is issued out of process, now, exactly as a payment service would hold one.
      const token = runSupportScript('issue-browser-payment-token.php');
      const headers = { Authorization: `Bearer ${token}`, 'Kumwe-Site': 'default', Accept: 'application/json' };
      const api = `/api/v1/business/records/${shopOrderDefinition}/${orderId}`;
      const read = await request.get(api, { headers });
      expect(read.status(), await read.text()).toBe(200);
      const etag = read.headers().etag ?? '';
      const paid = await request.patch(api, {
        headers: {
          ...headers,
          'Content-Type': 'application/json',
          'If-Match': etag,
          'Idempotency-Key': `payment-${orderId}`,
        },
        data: { values: { payment_status: 'paid' } },
      });
      expect(paid.status(), await paid.text()).toBeLessThan(300);
      const stale = await request.patch(api, {
        headers: {
          ...headers,
          'Content-Type': 'application/json',
          'If-Match': etag,
          'Idempotency-Key': `payment-stale-${orderId}`,
        },
        data: { values: { payment_status: 'failed' } },
      });
      expect([409, 412]).toContain(stale.status());
      await page.goto(orderPath);
      await expect(page.locator('main')).toContainText(/Payment status\s*paid/iu);
    });

    await test.step('fulfilment and confirmation: the administrator fulfils the paid order', async () => {
      const administrator = await browser.newPage();
      await signInAdministrator(administrator);
      await administrator.goto(`/administrator/business/${shopOrderDefinition}/${orderId}?confirm=action&action=fulfil`);
      await confirmAction(administrator, 'Mark as fulfilled');
      await expect(administrator.locator('main')).toContainText(/Fulfilled/u);
      await administrator.close();
      await page.goto(orderPath);
      await expect(page.locator('main')).toContainText(/Fulfilled/u);
      await expectAccessible(page);
      await expectNoOverflow(page, 'main');
    });
  });
});
