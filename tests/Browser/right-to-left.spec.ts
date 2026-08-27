import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

/**
 * Hebrew and Arabic laid out from the right, on the surfaces a visitor reaches without signing in.
 *
 * The stylesheets carry no direction of their own: every inline-axis rule is a logical property, so
 * the whole mirroring follows from the `dir` attribute the layouts emit. These journeys assert both
 * halves of that — that the attribute is derived from the resolved locale rather than hardcoded, and
 * that the mirrored page still lays out without horizontal overflow, which is where a stray physical
 * declaration shows up first.
 *
 * Each surface also holds a committed screenshot baseline and its critical controls to the same
 * acceptance every other locale carries: zero horizontal overflow, and zero inaccessible critical
 * control — every control a visitor needs on the surface stays visible, keyboard-focusable and free
 * of overlap after the mirroring. The baseline is the mirrored render itself, so a regression that
 * flips a surface back to left-to-right fails the comparison rather than passing a directionless one.
 *
 * This file runs once per right-to-left project rather than looping over the languages itself, so the
 * language is an axis of the matrix and not a loop inside one cell. That is what gives `he` and `ar`
 * their own screenshot directory: a baseline is filed under the project name, and a right-to-left
 * page has nothing to prove when it is compared against a left-to-right one.
 *
 * The project supplies the browser's accepted language as well as the name, so these journeys also
 * exercise negotiation as a real client drives it, rather than only the explicit `locale` parameter.
 */

const surfaces = [
  {
    id: 'public-home',
    path: '/',
    // The mobile navigation lives behind its toggle, so the toggle is the critical control there;
    // on desktop the navigation itself is on the surface and its first link stands for it.
    criticalControls: (page: Page, isMobile: boolean): Locator[] =>
      isMobile
        ? [page.getByRole('button', { name: 'Open site navigation' })]
        : [
            page
              .getByRole('navigation', { name: 'Main navigation' })
              .getByRole('link', { name: 'Home' }),
          ],
  },
  {
    id: 'administrator-login',
    path: '/administrator/login',
    criticalControls: (page: Page): Locator[] => [
      page.getByLabel('Email address'),
      page.getByLabel('Password'),
      page.getByRole('button', { name: 'Sign in to Kumwe' }),
    ],
  },
  {
    id: 'portal-login',
    path: '/portal/login',
    criticalControls: (page: Page): Locator[] => [
      page.getByLabel('Email address'),
      page.getByLabel('Password'),
      page.getByRole('button', { name: 'Sign in' }),
    ],
  },
] as const;

/** Locales this suite knows to be written from the right, keyed by the subtag a project name carries. */
const rightToLeftLocales = ['he', 'ar'] as const;
const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL
  ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';

type RightToLeftLocale = (typeof rightToLeftLocales)[number];

/**
 * The interface language this project exercises, taken from the project name's trailing subtag.
 *
 * A project whose name carries no right-to-left subtag is a source-language project, and these
 * journeys are not part of its matrix cell at all — `playwright.config.ts` runs this file only under
 * the four locale-scoped projects, so an unrecognised name here means the matrix and this file have
 * drifted apart and the run should say so rather than quietly test Hebrew twice.
 */
function projectLocale(projectName: string): RightToLeftLocale {
  const suffix = projectName.split('-').pop() ?? '';
  const locale = rightToLeftLocales.find((candidate) => candidate === suffix);
  if (locale === undefined) {
    throw new Error(
      `Project ${projectName} runs the right-to-left journeys but names no right-to-left locale.`,
    );
  }

  return locale;
}

async function expectStudioGeometryParity(page: Page, shell: Locator): Promise<void> {
  await expect.poll(async () => {
    const markers = await page.locator('iframe[data-studio-preview]').evaluate((element) => {
      const frame = element as HTMLIFrameElement;
      const frameRect = frame.getBoundingClientRect();
      return Array.from(frame.contentDocument?.querySelectorAll<HTMLElement>('[data-studio-preview-marker]') ?? [])
        .flatMap((marker) => Array.from(marker.getClientRects()).map((rect) => ({
          height: rect.height,
          width: rect.width,
          x: frameRect.x + frame.clientLeft + rect.x,
          y: frameRect.y + frame.clientTop + rect.y,
        })));
    });
    const overlays = await shell.locator('.preview-canvas-region').evaluateAll((regions) =>
      regions.map((region) => {
        const rect = region.getBoundingClientRect();
        return { height: rect.height, width: rect.width, x: rect.x, y: rect.y };
      }));
    const order = (values: typeof markers): typeof markers => values.sort((left, right) =>
      left.y - right.y || left.x - right.x || left.height - right.height || left.width - right.width);
    order(markers);
    order(overlays);
    return markers.length > 0 && markers.length === overlays.length && markers.every((rect, index) => {
      const overlay = overlays[index];
      return overlay !== undefined
        && Math.abs(rect.x - overlay.x) <= 2
        && Math.abs(rect.y - overlay.y) <= 2
        && Math.abs(rect.width - overlay.width) <= 2
        && Math.abs(rect.height - overlay.height) <= 2;
    });
  }).toBe(true);
}

async function open(page: Page, path: string, locale: string): Promise<void> {
  const separator = path.includes('?') ? '&' : '?';
  await page.goto(`${path}${separator}locale=${locale}`);
}

test.describe('Right-to-left presentation', () => {
  for (const surface of surfaces) {
    test(`${surface.id} renders right-to-left`, async ({ page, isMobile }, testInfo) => {
      const locale = projectLocale(testInfo.project.name);
      await open(page, surface.path, locale);

      const root = page.locator('html');
      await expect(root).toHaveAttribute('dir', 'rtl');
      await expect(root).toHaveAttribute('lang', locale);

      const report = await expectNoDocumentOverflow(page);
      const overlaps = report.findings.filter((finding) => finding.kind === 'control-overlap');
      expect(overlaps, JSON.stringify(overlaps, null, 2)).toEqual([]);

      // The pixel baseline is compared before any control is focused, so a focus ring cannot enter
      // the evidence; the comparison is against this project's own committed right-to-left baseline.
      await expect(page).toHaveScreenshot(`${surface.id}.png`, { fullPage: true });

      // Zero inaccessible critical control: every control a visitor needs on this surface remains
      // visible and reachable by keyboard after the mirroring.
      for (const control of surface.criticalControls(page, isMobile)) {
        await expect(control).toBeVisible();
        await control.focus();
        await expect(control).toBeFocused();
      }
    });
  }

  test('an explicit source-language choice overrules the language the client asked for', async ({
    page,
  }) => {
    for (const surface of surfaces) {
      await open(page, surface.path, 'en-GB');

      const root = page.locator('html');
      await expect(root).toHaveAttribute('dir', 'ltr');
      await expect(root).toHaveAttribute('lang', 'en-GB');
    }
  });

  test('an unrecognised locale falls back rather than rendering a blank interface', async ({
    page,
  }, testInfo) => {
    // An unrecognised explicit choice is discarded, and negotiation carries on to the next input
    // rather than giving up: the client's accepted language, which this project sets to its own
    // right-to-left locale. Asserting an absolute direction here would assert that a stale bookmark
    // throws away what the browser asked for, which is the opposite of the intended behaviour.
    const locale = projectLocale(testInfo.project.name);
    await open(page, '/administrator/login', 'not-a-locale');

    const root = page.locator('html');
    await expect(root).toHaveAttribute('lang', locale);
    await expect(root).toHaveAttribute('dir', 'rtl');
    // The interface is what must survive the unusable value. Until the translated catalogues land
    // the wording is still the source language, and that is the point: a message no layer carries
    // renders as the source text rather than as nothing.
    await expect(page.getByRole('button', { name: 'Sign in to Kumwe' })).toBeVisible();
  });

  test('the Studio composition shell and exact preview remain right-to-left without overflow', async ({
    page,
  }, testInfo) => {
    const locale = projectLocale(testInfo.project.name);
    await open(page, '/administrator/login', locale);
    await page.getByLabel('Email address').fill(administratorEmail);
    await page.getByLabel('Password').fill(administratorPassword);
    await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
    await page.goto('/administrator/content-models');
    const model = page.locator('[data-content-type-id][data-content-type-version]').first();
    if (await model.getAttribute('open') === null) {
      await model.locator('summary').first().click();
    }
    const modelId = await model.getAttribute('data-content-type-id');
    const modelVersion = await model.getAttribute('data-content-type-version');
    expect(modelId).not.toBeNull();
    expect(modelVersion).not.toBeNull();
    await page.goto(`/administrator/content-models/${modelId}/versions/${modelVersion}/composition`);
    const provision = page.getByRole('button', { name: 'Create composition' });
    if (await provision.isVisible()) await provision.click();

    const shell = page.locator('kumwe-studio');
    const section = shell.getByRole('complementary', { name: 'Block palette' })
      .getByRole('button', { name: 'Section', exact: true });
    await expect(section).toBeVisible();
    await section.click();
    const frame = page.locator('iframe[data-studio-preview]').contentFrame();
    await expect(frame.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(frame.locator('[data-studio-preview-marker]').first()).toBeVisible();
    await expectStudioGeometryParity(page, shell);
    await page.locator('iframe[data-studio-preview]').evaluate((element) => {
      const previewWindow = (element as HTMLIFrameElement).contentWindow;
      const previewDocument = (element as HTMLIFrameElement).contentDocument;
      if (previewWindow === null || previewDocument === null || previewDocument.body === null) return;
      previewDocument.body.style.minInlineSize = 'calc(100vw + 200px)';
      previewWindow.scrollTo({ left: -90 });
    });
    await expectStudioGeometryParity(page, shell);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
    await expectNoDocumentOverflow(page);
    await expect(shell.getByRole('complementary', { name: 'Block palette' }))
      .toHaveCSS('direction', 'rtl');
  });
});
