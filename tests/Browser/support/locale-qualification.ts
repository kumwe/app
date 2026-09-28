import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './interface-diagnostics';
import {
  extensionDeclaredWording,
  isRightToLeft,
  message,
  translatedWording,
  untranslatedSourceWording,
  type InterfaceLocale,
} from './interface-catalogue';

/** The WCAG rule sets every qualified surface is scanned against. */
const accessibilityTags = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

/**
 * Browser locale a qualification context emulates for each interface locale.
 *
 * The HTTP `Accept-Language` header carries the exact interface tag, which is what negotiation reads; the
 * browser locale is the nearest regional tag Chromium understands, so client-side `Intl` formatting in the
 * page agrees with the server rather than silently formatting in the suite's default locale.
 */
export const browserLocales: Readonly<Record<InterfaceLocale, string>> = {
  'en-GB': 'en-GB',
  'en-US': 'en-US',
  af: 'af-ZA',
  de: 'de-DE',
  he: 'he-IL',
  ar: 'ar-EG',
  es: 'es-ES',
  'pt-BR': 'pt-BR',
  'zh-Hans': 'zh-CN',
};

/**
 * Regions whose wording an extension contributes rather than core.
 *
 * An extension's navigation labels resolve through the extension's own catalogue under the contribution
 * contract, so a sample extension that ships English only is not an untranslated core surface.
 */
const contributedWording = ['a[href^="/administrator/extensions/"]', 'a[href^="/portal/extensions/"]'];

/** One surface's qualification evidence, attached to the test as JSON. */
export interface SurfaceEvidence {
  surface: string;
  url: string;
  lang: string | null;
  dir: string | null;
  viewport: { width: number; height: number };
  horizontalOverflow: number;
  layoutOverflow: number;
  controlOverlaps: number;
  criticalControls: number;
  accessibilityViolations: number;
  translatedStrings: number;
  untranslated: string[];
}

/**
 * Assert one critical control is operable: visible, keyboard-focusable and the topmost thing at its centre.
 *
 * Visibility and focus alone pass a control another element sits on top of, which is exactly how a
 * longer translation or a mirrored layout breaks a screen: the button is still in the DOM and still
 * focusable, but a pointer lands on its neighbour. The control is scrolled to the middle of the viewport
 * first so a sticky header cannot be mistaken for an overlap, and a label that forwards to its input
 * counts as the input.
 */
export async function expectOperableControl(control: Locator, description: string): Promise<void> {
  await expect(control, `${description} is visible`).toBeVisible();
  await control.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'center' }));
  await control.focus();
  await expect(control, `${description} takes keyboard focus`).toBeFocused();
  const obstruction = await control.evaluate((element) => {
    const bounds = element.getBoundingClientRect();
    const topmost = document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
    if (topmost === null || topmost === element || element.contains(topmost)) {
      return null;
    }
    const labels = 'labels' in element ? Array.from((element as HTMLInputElement).labels ?? []) : [];
    if (labels.some((label) => label === topmost || label.contains(topmost))) {
      return null;
    }

    return `${topmost.tagName.toLowerCase()}${topmost.id ? `#${topmost.id}` : ''}.${[...topmost.classList].join('.')}`;
  });
  expect(obstruction, `${description} is not covered by another element`).toBeNull();
}

/** Run the WCAG 2.2 AA scan over the whole document and return the violations for the evidence. */
export async function expectAccessible(page: Page): Promise<number> {
  const scan = await new AxeBuilder({ page }).withTags(accessibilityTags).analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);

  return scan.violations.length;
}

/**
 * Read every string a person perceives on the page: visible text nodes and accessible-name attributes.
 *
 * Text inside an element matching one of `ignore` is left out. Those regions hold operator-authored
 * content or definition data — a page body, a record's field values, a content model's label — which is
 * content in its own language, not interface wording a catalogue translates.
 */
export async function perceivedWording(page: Page, ignore: readonly string[] = []): Promise<string[]> {
  return page.evaluate((ignored) => {
    const found = new Set<string>();
    const skip = (element: Element): boolean =>
      element.closest('script, style, template, noscript, [hidden], [aria-hidden="true"]') !== null
      || ignored.some((selector) => element.closest(selector) !== null);
    const visible = (element: Element): boolean =>
      typeof element.checkVisibility === 'function'
        ? element.checkVisibility({ opacityProperty: true, visibilityProperty: true })
        : (element as HTMLElement).offsetParent !== null;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      const parent = walker.currentNode.parentElement;
      const text = (walker.currentNode.textContent ?? '').replace(/\s+/gu, ' ').trim();
      if (parent === null || text === '' || skip(parent) || !visible(parent)) {
        continue;
      }
      found.add(text);
    }
    for (const element of document.body.querySelectorAll('[aria-label], [placeholder], [title], img[alt]')) {
      if (skip(element) || !visible(element)) {
        continue;
      }
      for (const attribute of ['aria-label', 'placeholder', 'title', 'alt']) {
        const value = element.getAttribute(attribute)?.replace(/\s+/gu, ' ').trim();
        if (value) {
          found.add(value);
        }
      }
    }

    return [...found];
  }, ignore);
}

/**
 * Qualify the surface currently open in `page` for `locale` and return its evidence.
 *
 * The resolved `lang` and `dir` must be the locale's own; the document must lay out with zero horizontal
 * overflow — against the visual and the layout viewport — and no overlapping controls; every critical control must be operable; the WCAG 2.2 AA scan
 * must be clean; and, in a translated locale, no source-language wording the catalogue translates may
 * remain visible. A full-page screenshot is attached as evidence, not compared.
 */
export async function qualifySurface(
  page: Page,
  testInfo: TestInfo,
  locale: InterfaceLocale,
  surface: string,
  controls: ReadonlyArray<readonly [Locator, string]>,
  ignoreWording: readonly string[] = [],
): Promise<SurfaceEvidence> {
  const root = page.locator('html');
  await expect(root, `${surface} resolves ${locale}`).toHaveAttribute('lang', locale);
  await expect(root, `${surface} lays out ${isRightToLeft(locale) ? 'right-to-left' : 'left-to-right'}`)
    .toHaveAttribute('dir', isRightToLeft(locale) ? 'rtl' : 'ltr');

  const report = await expectNoDocumentOverflow(page);
  // A phone that finds the document wider than its layout viewport zooms the whole page out, after which
  // `innerWidth` grows to match and the check above sees no overflow. The layout viewport does not grow,
  // so the document is also held to it.
  const layout = await page.evaluate(() => ({
    document: document.documentElement.scrollWidth,
    viewport: document.documentElement.clientWidth,
  }));
  const layoutOverflow = Math.max(0, layout.document - layout.viewport);
  expect(layoutOverflow, `${surface} fits its layout viewport without the page zooming out`).toBeLessThanOrEqual(1);
  const overlaps = report.findings.filter((finding) => finding.kind === 'control-overlap');
  expect(overlaps, `${surface} has overlapping controls: ${JSON.stringify(overlaps, null, 2)}`).toEqual([]);

  const screenshot = testInfo.outputPath(`${locale}-${surface}.png`);
  await page.screenshot({ path: screenshot, fullPage: true, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(`${locale} ${surface}`, { path: screenshot, contentType: 'image/png' });

  for (const [control, description] of controls) {
    await expectOperableControl(control, `${surface}: ${description}`);
  }

  const violations = await expectAccessible(page);

  const wording = await perceivedWording(page, [...contributedWording, ...ignoreWording]);
  const sourceWording = untranslatedSourceWording(locale);
  const translations = translatedWording(locale);
  const contributed = extensionDeclaredWording();
  const untranslated = wording.filter((text) => sourceWording.has(text) && !contributed.has(text)).sort();
  expect(untranslated, `${surface} still shows source-language wording in ${locale}`).toEqual([]);

  return {
    surface,
    url: report.url,
    lang: await root.getAttribute('lang'),
    dir: await root.getAttribute('dir'),
    viewport: { width: report.viewport.width, height: report.viewport.height },
    horizontalOverflow: report.viewport.horizontalOverflow,
    layoutOverflow,
    controlOverlaps: overlaps.length,
    criticalControls: controls.length,
    accessibilityViolations: violations,
    translatedStrings: wording.filter((text) => translations.has(text)).length,
    untranslated,
  };
}

/** Attach the evidence rows one test produced, so the report carries per-locale figures, not only a pass. */
export async function attachEvidence(
  testInfo: TestInfo,
  locale: InterfaceLocale,
  area: string,
  evidence: readonly SurfaceEvidence[],
): Promise<void> {
  await testInfo.attach(`${locale} ${area} qualification evidence`, {
    body: JSON.stringify({ locale, project: testInfo.project.name, area, surfaces: evidence }, null, 2),
    contentType: 'application/json',
  });
}

/** Sign in to the administrator through its own localized form, as a person in that language would. */
export async function signInAdministrator(page: Page, locale: InterfaceLocale): Promise<void> {
  const email = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
  const password = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';
  await page.goto('/administrator/login');
  await page.getByLabel(message(locale, 'core.administrator.login.email_label')).fill(email);
  await page.getByLabel(message(locale, 'core.administrator.login.password_label')).fill(password);
  await page.getByRole('button', { name: message(locale, 'core.administrator.login.submit') }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Sign in to the portal through its own localized form with the seeded, un-enrolled portal identity. */
export async function signInPortal(page: Page, locale: InterfaceLocale): Promise<void> {
  const email = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
  const password = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';
  await page.goto('/portal/login');
  await page.getByLabel(message(locale, 'core.portal.login.email_label')).fill(email);
  await page.getByLabel(message(locale, 'core.portal.login.password_label')).fill(password);
  await page.locator('#portal-workspace').fill('north');
  await page.getByRole('button', { name: message(locale, 'core.portal.login.submit') }).click();
  await expect(page).toHaveURL(/\/portal$/u);
}
