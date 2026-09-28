import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { message, rightToLeftInterfaceLocales, type InterfaceLocale } from './support/interface-catalogue';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import { signInAdministrator } from './support/locale-qualification';
import { awaitStudioLaunchSettled, focusStop, tabStops, tabUntil, type FocusStop } from './support/studio-authoring';

/**
 * The contextual Studio shell on Content New, in Hebrew and Arabic, laid out and operated from the right.
 *
 * The file name places it under the four right-to-left projects only (`playwright.config.ts` matches
 * `right-to-left.spec.ts`), so each run is one language on one device, and the language comes from the
 * project name exactly as it does in `right-to-left.spec.ts`. The editor signs in through the localized form,
 * the pinned Studio module mounts in the page's own direction, the chooser and the shell are operated with
 * the keyboard alone, every control on the way is announced with its name in that language, and the page
 * stays free of horizontal overflow and of WCAG 2.2 AA violations.
 */

/** The right-to-left interface language this project runs, taken from the project name's trailing subtag. */
function projectLocale(projectName: string): InterfaceLocale {
  const suffix = projectName.split('-').pop() ?? '';
  const locale = rightToLeftInterfaceLocales.find((candidate) => candidate === suffix);
  if (locale === undefined) {
    throw new Error(`Project ${projectName} runs the right-to-left journeys but names no right-to-left locale.`);
  }

  return locale;
}

/** Require WCAG 2.2 AA automated rules on the Content editor, leaving the separately rendered preview out. */
async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .exclude('iframe[data-studio-contextual-preview]')
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

test('the contextual Studio shell runs right-to-left with localized names and keyboard operation', async ({
  page,
}, testInfo) => {
  test.slow();
  const locale = projectLocale(testInfo.project.name);
  const studio = (name: string, parameters: Record<string, string> = {}): string =>
    message(locale, `core.studio.contextual.${name}`, parameters);
  await signInAdministrator(page, locale);
  await page.goto('/administrator/content/new');
  expect(await awaitStudioLaunchSettled(page), 'The pinned Studio browser module must mount.').toBe('ready');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('html')).toHaveAttribute('lang', locale);
  const chooser = page.locator('kumwe-studio-hosted-start');
  await expect(chooser.getByRole('searchbox')).toBeEnabled();
  await expect(chooser).toHaveCSS('direction', 'rtl');
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  // The chooser is reached and operated from the surface toggle with the keyboard alone.
  const toggle = message(locale, 'core.administrator.content_form.use_the_structured_form');
  const isToggle = (stop: FocusStop): boolean => stop.role === 'button' && stop.name === toggle;
  await page.getByRole('button', { name: toggle }).focus();
  const radio = (await tabUntil(page, (stop) => stop.role === 'radio', 4)).at(-1);
  await expect(radio?.locator ?? chooser).toHaveAttribute('value', 'blank');
  await expect(radio?.locator ?? chooser).toBeChecked();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  const shell = page.locator('#kumwe-studio-content kumwe-studio-contextual');
  await expect(shell).toBeVisible();
  await expect(shell.locator('.contextual-workspace')).toHaveCSS('direction', 'rtl');
  await expect.poll(() => page.evaluate(() => document.activeElement?.id ?? '')).toBe('studio-authoring-title');
  expect((await focusStop(page)).name)
    .toBe(message(locale, 'core.administrator.content_form.compose_this_item_visually'));

  // The shell header is announced in this language and laid out from the right: wherever two consecutive
  // stops share a row, the earlier one in reading order sits to the right of the later one.
  await tabUntil(page, isToggle, 3);
  const header = await tabStops(page, 9);
  expect(header.slice(0, 8).map(({ name }) => name)).toEqual([
    studio('return', { destination: message(locale, 'core.administrator.content_form.studio_return_destination') }),
    studio('presentation-inline'),
    studio('presentation-minimized'),
    studio('presentation-maximized'),
    studio('presentation-fullscreen'),
    studio('save-item'),
    studio('save-new-type-version'),
    studio('save-as-new-type'),
  ]);
  const controls = header.slice(0, 8);
  const rows = controls.slice(1).flatMap((stop, index) => {
    const previous = controls[index];
    return previous !== undefined && Math.abs(previous.top - stop.top) < 4 ? [[previous, stop] as const] : [];
  });
  expect(rows.length, 'At least two header controls share a row.').toBeGreaterThan(0);
  for (const [earlier, later] of rows) {
    expect(earlier.right, `${earlier.name} sits to the right of ${later.name}`).toBeGreaterThan(later.right);
  }
  expect(header[8]?.role).toBe('tab');
  await expect(header[8]?.locator ?? shell).toHaveAttribute('aria-selected', 'true');

  // The mode tabs answer the arrow keys in both directions, and each selected tab is announced by name.
  const selected = shell.locator('[role="tab"][aria-selected="true"]');
  const visited = new Set<string>();
  for (const key of ['ArrowLeft', 'ArrowLeft', 'ArrowRight', 'ArrowRight']) {
    await page.keyboard.press(key);
    const stop = await focusStop(page);
    expect(stop.role).toBe('tab');
    await expect(selected).toHaveAccessibleName(stop.name);
    visited.add(stop.name);
  }
  expect([...visited].sort()).toEqual([studio('mode-blueprint'), studio('mode-content'), studio('mode-model')].sort());

  // The App's own palette entries are named in this language as well, beside Studio's first-party blocks.
  await shell.getByRole('tab', { name: studio('mode-blueprint') }).click();
  const palette = shell.getByRole('complementary', { name: message(locale, 'core.studio.shell.palette-label') });
  for (const id of ['studio_block_yes_or_no', 'studio_block_text', 'studio_pattern_empty_section']) {
    const name = message(locale, `core.administrator.content_form.${id}`);
    await expect(palette.getByRole('button', { name, exact: true })).toBeVisible();
  }

  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);
});
