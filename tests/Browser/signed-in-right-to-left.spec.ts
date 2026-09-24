import { expect, test, type Locator, type Page } from '@playwright/test';
import { message } from './support/interface-catalogue';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import {
  expectAccessible,
  expectOperableControl,
  signInAdministrator,
  signInPortal,
} from './support/locale-qualification';

/**
 * Hebrew and Arabic laid out from the right on the signed-in administrator shell and the portal.
 *
 * `right-to-left.spec.ts` proves the surfaces a visitor reaches without signing in; this file carries the
 * same acceptance past the sign-in into the administrator shell — its dashboard, a list and a form — and
 * into the portal home and account security page (V2-LNG-010, PL-G). Each surface must resolve the
 * project's own `lang` and `dir`, lay out with zero horizontal overflow and no overlapping controls,
 * pass the WCAG 2.2 AA scan, match its own committed right-to-left baseline, and keep every critical
 * control visible, keyboard-focusable and uncovered after the mirroring.
 *
 * The file name ends in `right-to-left.spec.ts` on purpose: `playwright.config.ts` runs that pattern
 * only under the four locale-scoped projects, so the baselines are filed under `desktop-chromium-he`,
 * `desktop-chromium-ar`, `mobile-chromium-he` and `mobile-chromium-ar` and never compared against a
 * left-to-right render. Controls are found by the catalogue's wording in the project's language, so a
 * surface that fell back to English would fail to find its own controls.
 */

const rightToLeftLocales = ['he', 'ar'] as const;

type RightToLeftLocale = (typeof rightToLeftLocales)[number];

/** The project's interface language, from the trailing subtag of its name. */
function projectLocale(projectName: string): RightToLeftLocale {
  const suffix = projectName.split('-').pop() ?? '';
  const locale = rightToLeftLocales.find((candidate) => candidate === suffix);
  if (locale === undefined) {
    throw new Error(`Project ${projectName} runs the right-to-left journeys but names no right-to-left locale.`);
  }

  return locale;
}

/**
 * One signed-in surface under test.
 *
 * Each is compared at the viewport rather than as a full page, with the regions whose content depends on
 * what earlier journeys created masked, so the baseline holds the mirrored shell and the surface's own
 * chrome rather than whatever records happened to exist.
 */
interface SignedInSurface {
  id: string;
  area: 'administrator' | 'portal';
  path: string;
  mask: readonly string[];
  controls: (page: Page, isMobile: boolean, locale: RightToLeftLocale) => ReadonlyArray<readonly [Locator, string]>;
}

/** The administrator shell controls that must survive the mirroring on every signed-in surface. */
function administratorShellControls(
  page: Page,
  isMobile: boolean,
  locale: RightToLeftLocale,
): Array<readonly [Locator, string]> {
  return [
    isMobile
      ? [page.getByRole('button', { name: message(locale, 'core.administrator.layout.open_navigation') }), 'navigation toggle']
      : [
          page
            .getByRole('navigation', { name: message(locale, 'core.administrator.layout.navigation_label') })
            .getByRole('link', { name: message(locale, 'core.navigation.administrator.content.label'), exact: true }),
          'content navigation entry',
        ],
    [page.getByRole('button', { name: message(locale, 'core.administrator.layout.sign_out') }), 'sign out'],
  ];
}

const signedInSurfaces: readonly SignedInSurface[] = [
  {
    id: 'administrator-dashboard',
    area: 'administrator',
    path: '/administrator',
    mask: ['[data-visual-dynamic]', '.kis-dashboard-widget-grid'],
    controls: (page, isMobile, locale) => [
      ...administratorShellControls(page, isMobile, locale),
      [
        page.getByRole('link', { name: message(locale, 'core.interface_standard.dashboard.customize_action') }),
        'customize dashboard',
      ],
    ],
  },
  {
    id: 'administrator-content-list',
    area: 'administrator',
    path: '/administrator/content',
    // The rows and the item count depend on what earlier journeys created.
    mask: ['table tbody', '.kis-resource-toolbar-summary p', '[data-visual-dynamic]'],
    controls: (page, isMobile, locale) => [
      ...administratorShellControls(page, isMobile, locale),
      [
        page.locator('main').getByRole('link', { name: message(locale, 'core.administrator.content_form.create_content') }),
        'create content',
      ],
      [
        page.getByRole('button', { name: message(locale, 'core.administrator.content_list.apply_filters') }),
        'apply filters',
      ],
    ],
  },
  {
    id: 'administrator-account-form',
    area: 'administrator',
    path: '/administrator/account',
    mask: [],
    controls: (page, isMobile, locale) => [
      ...administratorShellControls(page, isMobile, locale),
      [page.getByLabel(message(locale, 'core.administrator.access_control.current_password')), 'current password'],
      [
        page.getByRole('button', { name: message(locale, 'core.administrator.access_control.change_my_password') }),
        'change password',
      ],
    ],
  },
  {
    id: 'portal-home',
    area: 'portal',
    path: '/portal',
    mask: ['[data-visual-dynamic]', '.kis-dashboard-workspace'],
    controls: (page, _isMobile, locale) => [
      [
        page
          .getByRole('navigation', { name: message(locale, 'core.portal.layout.navigation_label') })
          .getByRole('link', { name: message(locale, 'core.navigation.portal.portal-security.label') }),
        'account security navigation entry',
      ],
      [page.getByRole('button', { name: message(locale, 'core.portal.layout.sign_out') }), 'sign out'],
    ],
  },
  {
    id: 'portal-security',
    area: 'portal',
    path: '/portal/security',
    mask: [],
    controls: (page, _isMobile, locale) => [
      [page.getByRole('button', { name: message(locale, 'core.portal.security.start_setup') }), 'start setup'],
      [page.getByRole('button', { name: message(locale, 'core.portal.layout.sign_out') }), 'sign out'],
    ],
  },
];

/**
 * Remove navigation an extension contributed, so a baseline does not depend on extension activation.
 *
 * Earlier journeys in the same run activate and deactivate the example extensions; their entries come
 * and go with that state and resolve through the extensions' own catalogues. Core's entries, the
 * mirroring of the shell and every group heading core owns stay in the render.
 */
async function withoutContributedNavigation(page: Page): Promise<void> {
  await page.evaluate(() => {
    for (const link of document.querySelectorAll<HTMLAnchorElement>(
      'nav a[href^="/administrator/extensions/"], nav a[href^="/portal/extensions/"]',
    )) {
      const section = link.closest('section');
      link.closest('li')?.remove();
      if (section !== null && section.querySelectorAll('a[href]').length === 0) {
        section.remove();
      }
    }
  });
}

test.describe('Signed-in right-to-left presentation', () => {
  for (const surface of signedInSurfaces) {
    test(`${surface.id} renders right-to-left when signed in`, async ({ page, isMobile }, testInfo) => {
      const locale = projectLocale(testInfo.project.name);
      if (surface.area === 'administrator') {
        await signInAdministrator(page, locale);
      } else {
        await signInPortal(page, locale);
      }
      await page.goto(surface.path);

      const root = page.locator('html');
      await expect(root).toHaveAttribute('dir', 'rtl');
      await expect(root).toHaveAttribute('lang', locale);
      await expect(page.locator('body')).toHaveCSS('direction', 'rtl');

      const report = await expectNoDocumentOverflow(page);
      const overlaps = report.findings.filter((finding) => finding.kind === 'control-overlap');
      expect(overlaps, JSON.stringify(overlaps, null, 2)).toEqual([]);
      // The scan reads the page exactly as served, before any baseline normalization touches the DOM.
      await expectAccessible(page);

      // The baseline is taken before any control is focused, so a focus ring cannot enter it.
      await withoutContributedNavigation(page);
      await page.evaluate(() => window.scrollTo(0, 0));
      await expect(page).toHaveScreenshot(`${surface.id}.png`, {
        mask: surface.mask.map((selector) => page.locator(selector)),
        maskColor: '#d9e2e8',
      });

      for (const [control, description] of surface.controls(page, isMobile, locale)) {
        await expectOperableControl(control, `${surface.id}: ${description}`);
      }
    });
  }
});
