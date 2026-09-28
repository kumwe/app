import { expect, test, type Locator, type Page } from '@playwright/test';
import { message } from './support/interface-catalogue';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import {
  browserLocales,
  expectAccessible,
  expectOperableControl,
  signInAdministrator,
} from './support/locale-qualification';
import { preferStructuredContentForm } from './support/studio-authoring';

/**
 * Two `P7-E` archetype task journeys completed in a language other than the source (V2-LNG-010, PL-G).
 *
 * The locale matrix proves each surface renders in each language; these prove a person can finish real
 * work in one. German is the layout-stressing language of the proof set — long compounds are where a
 * label truncates, a button wraps into its neighbour or a label drifts away from its field — so the
 * content-authoring archetype (create, draft and find again) is run in German. Hebrew is the
 * right-to-left proof: the generated-business archetype (create a typed record with exact amounts,
 * dates and times, then read it back) is run in Hebrew, where numbers and Latin identifiers sit inside
 * right-to-left text, dates are formatted by the locale, and the completion is announced to assistive
 * technology in Hebrew.
 *
 * Each journey finds its controls by the catalogue's wording in its own language, so a surface that fell
 * back to English fails to be driven at all, and each step is held to zero overflow and a clean WCAG 2.2
 * AA scan.
 */

const businessDefinitionHandle = 'site.default.session5_order';

/**
 * Assert no visible label, legend, heading, button or link in `root` is truncated or overflows its box.
 *
 * Long German compounds break a layout quietly: an ellipsis hides the end of a word, or a word runs out
 * of a fixed-width button without any scrollbar. Both leave `scrollWidth` larger than `clientWidth` on
 * the element carrying the words.
 */
async function expectUntruncatedWording(page: Page, root: string): Promise<void> {
  const truncated = await page.locator(root).evaluate((container) => {
    const selectors = 'label, legend, h1, h2, h3, button, a.button, th, dt, .eyebrow, [role="tab"]';
    return [...container.querySelectorAll<HTMLElement>(selectors)]
      .filter((element) => element.checkVisibility() && element.clientWidth > 0)
      .filter((element) => element.scrollWidth > element.clientWidth + 1)
      .map((element) => `${element.tagName.toLowerCase()}: ${(element.textContent ?? '').trim().slice(0, 60)}`);
  });
  expect(truncated, 'wording is neither clipped nor overflowing its element').toEqual([]);
}

/**
 * Assert every visible form field in `root` sits inside the inline extent of the label that names it.
 *
 * A translated label that grows wider than its column pushes its field out of line or leaves the field
 * hanging outside the label it belongs to; either reads as a misaligned form.
 */
async function expectAlignedLabels(page: Page, root: string): Promise<void> {
  const misaligned = await page.locator(root).evaluate((container) => {
    const findings: string[] = [];
    for (const label of container.querySelectorAll<HTMLLabelElement>('label')) {
      const control = label.control;
      if (control === null || !label.checkVisibility() || !control.checkVisibility()) {
        continue;
      }
      if (!label.contains(control)) {
        continue;
      }
      const labelBox = label.getBoundingClientRect();
      const controlBox = control.getBoundingClientRect();
      if (controlBox.left < labelBox.left - 1 || controlBox.right > labelBox.right + 1) {
        findings.push(`${(label.textContent ?? '').trim().slice(0, 40)}: field escapes its label`);
      }
    }

    return findings;
  });
  expect(misaligned, 'every field stays aligned within its label').toEqual([]);
}

test.describe('German content authoring', () => {
  test.use({ locale: browserLocales.de, extraHTTPHeaders: { 'Accept-Language': 'de' } });

  test('an editor drafts and finds content entirely in German', async ({ page, context }, testInfo) => {
    test.setTimeout(120_000);
    await preferStructuredContentForm(context);
    await signInAdministrator(page, 'de');

    await page.goto('/administrator/content/new');
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    await expect(page.getByRole('heading', { level: 1, name: message('de', 'core.administrator.content_form.create_content') }))
      .toBeVisible();
    await expectNoDocumentOverflow(page);
    await expectUntruncatedWording(page, 'main');
    await expectAlignedLabels(page, 'main');
    await expectAccessible(page);

    const suffix = `${testInfo.project.name}-${Date.now()}`;
    const title = `Qualifizierungsbeitrag ${suffix}`;
    const slug = `qualifizierungsbeitrag-${suffix}`;
    await page.locator('main input[name="title"]').fill(title);
    await page.getByRole('textbox', { name: message('de', 'core.administrator.content_form.url_slug') }).fill(slug);
    await page.locator('[data-rich-text-editor]').first()
      .fill('Barrierefreiheitsanforderungen und Veröffentlichungszeitpläne in deutscher Sprache.');
    const createDraft = page.getByRole('button', { name: message('de', 'core.administrator.content_form.create_draft') });
    await expectOperableControl(createDraft, 'create draft');
    await createDraft.click();
    await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/u);
    await expect(page.locator('main input[name="title"]')).toHaveValue(title);
    await expect(page.getByRole('status').filter({
      hasText: message('de', 'core.administrator.content_form.changes_are_saved_when_you_submit'),
    })).toBeVisible();
    await expectNoDocumentOverflow(page);
    await expectUntruncatedWording(page, 'main');
    await expectAlignedLabels(page, 'main');
    await expectAccessible(page);

    // Find it again through the German list; its date is formatted by the German locale, not in English.
    await page.goto(`/administrator/content?q=${encodeURIComponent(suffix)}`);
    await expect(page.getByRole('heading', { level: 1, name: message('de', 'core.administrator.content_list.content') }))
      .toBeVisible();
    const row = page.locator('table tbody tr, .kis-content-result-card').filter({ hasText: title }).first();
    await expect(row).toBeVisible();
    await expect(row.locator('time')).toHaveText(/^\d{1,2}\. [A-Za-zÄÖÜäöü]+\.? \d{4}, \d{2}:\d{2}$/u);
    await expectNoDocumentOverflow(page);
    await expectUntruncatedWording(page, 'main');
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('de-content-journey.png'), fullPage: true });
    await testInfo.attach('de content journey', {
      path: testInfo.outputPath('de-content-journey.png'),
      contentType: 'image/png',
    });
  });
});

test.describe('Hebrew generated business work', () => {
  test.use({ locale: browserLocales.he, extraHTTPHeaders: { 'Accept-Language': 'he' } });

  test('an operator records and reads back a typed business record in Hebrew', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    await signInAdministrator(page, 'he');

    await page.goto(`/administrator/business/${businessDefinitionHandle}?new=1`);
    const root = page.locator('html');
    await expect(root).toHaveAttribute('dir', 'rtl');
    await expect(root).toHaveAttribute('lang', 'he');
    const create = page.getByRole('button', { name: message('he', 'core.administrator.business_form.create_record') });
    await expectOperableControl(create, 'create record');
    await expectNoDocumentOverflow(page);
    await expectAccessible(page);

    const name = `רשומת הזמנה ${testInfo.project.name} ${Date.now()}`;
    await page.locator('[name="values[name]"]').fill(name);
    await page.locator('[name="values[status]"]').selectOption('ready');
    await page.locator('[name="values[enabled]"][type="checkbox"]').check();
    await page.locator('[name="values[amount]"]').fill('1234.500000000000000000000000000000');
    await page.locator('[name="values[price][amount]"]').fill('25.000000000000000000000000000000');
    await page.locator('[name="values[price][currency]"]').fill('nad');
    await page.locator('[name="values[quantity][amount]"]').fill('2.000000000000000000000000000000');
    await page.locator('[name="values[quantity][unit]"]').fill('unit');
    await page.locator('[name="values[service_date]"]').fill('2026-08-10');
    await page.locator('[name="values[local_time]"]').fill('13:14:15.123456');
    await page.locator('[name="values[recorded_at]"]').fill('2026-08-10T11:14:15.123456Z');
    await page.locator('[name="values[scheduled_for][instant]"]').fill('2026-08-10T11:14:15.123456Z');
    await page.locator('[name="values[scheduled_for][timezone]"]').fill('Africa/Windhoek');
    await page.locator('[name="values[credential]"]').fill('browser-secret-value');

    // Numbers, codes and instants are left-to-right runs inside right-to-left text. They must keep the
    // digits and order the operator typed, and their fields must not flip the page into overflow.
    await expect(page.locator('[name="values[amount]"]')).toHaveValue('1234.500000000000000000000000000000');
    await expect(page.locator('[name="values[scheduled_for][timezone]"]')).toHaveValue('Africa/Windhoek');
    await expectNoDocumentOverflow(page);

    await create.click();
    await expect(page).toHaveURL(new RegExp(
      `/administrator/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
      'u',
    ));

    // The completion is announced through a polite status region, in Hebrew.
    const announcement = page.getByRole('status').filter({
      hasText: message('he', 'core.administrator.business_detail.the_operation_completed'),
    });
    await expect(announcement).toBeVisible();
    await expect(announcement.getByRole('link', { name: message('he', 'core.administrator.business_detail.view_operation_status') }))
      .toBeVisible();

    await expect(page.getByRole('heading', { name: message('he', 'core.administrator.business_detail.record_details') }))
      .toBeVisible();
    await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
    // The record's own timestamps are formatted by the Hebrew locale: a Hebrew month in right-to-left text.
    const created = page
      .locator('dt')
      .filter({ hasText: new RegExp(`^${message('he', 'core.administrator.business_detail.created')}$`, 'u') })
      .first()
      .locator('xpath=following-sibling::dd[1]/time');
    await expect(created).toHaveText(/^\d{1,2} [֐-׿׳"]+ \d{4}, \d{2}:\d{2}$/u);
    await expectDirection(page.locator('main'), 'rtl');
    await expectNoDocumentOverflow(page);
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('he-business-journey.png'), fullPage: true });
    await testInfo.attach('he business journey', {
      path: testInfo.outputPath('he-business-journey.png'),
      contentType: 'image/png',
    });
  });
});

/** Assert the computed writing direction of a region. */
async function expectDirection(region: Locator, direction: 'rtl' | 'ltr'): Promise<void> {
  await expect(region).toHaveCSS('direction', direction);
}
