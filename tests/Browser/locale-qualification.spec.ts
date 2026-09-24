import { expect, test, type Locator, type Page } from '@playwright/test';
import { interfaceLocales, message, type InterfaceLocale } from './support/interface-catalogue';
import {
  attachEvidence,
  browserLocales,
  qualifySurface,
  signInAdministrator,
  signInPortal,
  type SurfaceEvidence,
} from './support/locale-qualification';
import { awaitStudioLaunchSettled } from './support/studio-authoring';

/**
 * Every Version 2 language qualified in its own right on the critical surfaces (V2-LNG-010, PL-G).
 *
 * Gate B criterion 11 asks for more than complete catalogues: each of the nine languages has to be shown
 * working where people work. For every locale this suite signs in through the localized forms and visits
 * the administrator dashboard, content list, content editor, settings, business definitions, business
 * records index and access control, then the portal home and account security pages and the public home.
 * On each surface it asserts the resolved `lang` and `dir`, zero horizontal overflow at the project's
 * viewport, no overlapping controls, every critical control visible, keyboard-focusable and topmost, a
 * clean WCAG 2.2 AA scan, and — in the seven translated languages — that no wording the catalogue
 * translates is still visible in English.
 *
 * The matrix runs inside the existing device projects rather than multiplying them: each locale is its own
 * browser context whose `Accept-Language` names the interface tag, so negotiation is exercised as a real
 * client drives it and every redirect and form post stays in that language. Screenshots are attached as
 * evidence per locale and surface; the right-to-left pixel baselines live in `right-to-left.spec.ts`, under
 * the locale-scoped projects that own them.
 */

type Controls = (page: Page, locale: InterfaceLocale, isMobile: boolean) => ReadonlyArray<readonly [Locator, string]>;

interface QualifiedSurface {
  id: string;
  path: string;
  controls: Controls;
  /** Regions holding operator-authored content or definition data rather than interface wording. */
  content?: readonly string[];
  /** Waits for the surface to settle and answers the controls that only its settled state shows. */
  settle?: (page: Page, locale: InterfaceLocale) => Promise<ReadonlyArray<readonly [Locator, string]>>;
}

/** Match an accessible name that begins with `wording`, as a label followed by its required marker does. */
function leadingWording(wording: string): RegExp {
  return new RegExp(`^${wording.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&')}`, 'u');
}

/** The shell controls every signed-in administrator surface must keep operable. */
function administratorShell(page: Page, locale: InterfaceLocale, isMobile: boolean): Array<readonly [Locator, string]> {
  const navigation = isMobile
    ? [page.getByRole('button', { name: message(locale, 'core.administrator.layout.open_navigation') }), 'navigation toggle'] as const
    : [
        page
          .getByRole('navigation', { name: message(locale, 'core.administrator.layout.navigation_label') })
          .getByRole('link', { name: message(locale, 'core.navigation.administrator.content.label'), exact: true }),
        'content navigation entry',
      ] as const;

  return [
    navigation,
    [page.getByRole('button', { name: message(locale, 'core.administrator.layout.sign_out') }), 'sign out'],
  ];
}

const administratorSurfaces: readonly QualifiedSurface[] = [
  {
    id: 'administrator-dashboard',
    path: '/administrator',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page.getByRole('link', { name: message(locale, 'core.interface_standard.dashboard.customize_action') }),
        'customize dashboard',
      ],
    ],
    content: ['[data-visual-dynamic]'],
  },
  {
    id: 'administrator-content-list',
    path: '/administrator/content',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page.locator('main').getByRole('link', { name: message(locale, 'core.administrator.content_form.create_content') }),
        'create content',
      ],
      [
        page.getByRole('button', { name: message(locale, 'core.administrator.content_list.apply_filters') }),
        'apply filters',
      ],
    ],
    // Content titles, and the content-model and workflow-state names a model defines, are content; the
    // rest of each row — its trash action and trashed marker — is interface wording and is checked.
    content: ['.content-title strong', 'table tbody .status', 'select[name="type"]', 'select[name="status"]'],
  },
  {
    id: 'administrator-content-editor',
    path: '/administrator/content/new',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page.getByRole('link', { name: message(locale, 'core.administrator.content_form.back_to_content') }),
        'back to content',
      ],
    ],
    // The content model's name (the header eyebrow and the type choices) is definition data.
    content: ['.kis-page-header-copy .eyebrow', 'select[name="type"]'],
    // Studio contextual authoring is the editor's default surface (ADR 0020): once it has mounted, the
    // structured form sits behind the surface toggle, which is then the control that must stay operable.
    // When Studio defers or is unavailable, the form's title field is in front instead.
    settle: async (page, locale) => {
      const outcome = await awaitStudioLaunchSettled(page);
      expect(['ready', 'deferred', 'fallback'], `the Studio launch settled as ${outcome}`).toContain(outcome);
      if (outcome === 'ready') {
        return [[
          page.getByRole('button', { name: message(locale, 'core.administrator.content_form.use_the_structured_form') }),
          'structured form toggle',
        ]];
      }

      // The title's accessible name may carry a required marker after the label, so the field is matched
      // by a name that leads with the catalogue's wording.
      return [[
        page
          .getByRole('textbox', { name: leadingWording(message(locale, 'core.administrator.content_form.title')) })
          .and(page.locator('main input[name="title"]')),
        'title field',
      ]];
    },
  },
  {
    // The structured form, named explicitly so its fields and the rich-text toolbar are on screen in every
    // locale rather than behind the Studio surface toggle.
    id: 'administrator-content-form',
    path: '/administrator/content/new?surface=form',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page
          .getByRole('textbox', { name: leadingWording(message(locale, 'core.administrator.content_form.title')) })
          .and(page.locator('main input[name="title"]')),
        'title field',
      ],
      [
        page
          .getByRole('toolbar', { name: message(locale, 'core.administrator.rich_text.toolbar_label') })
          .first()
          .getByRole('button', { name: message(locale, 'core.administrator.rich_text.bold_label') }),
        'rich-text bold button',
      ],
      [
        page.getByRole('textbox', { name: message(locale, 'core.administrator.rich_text.editor_label') }).first(),
        'rich-text editor',
      ],
    ],
    // The content model's name (header eyebrow, sidebar heading, type choices) and its field labels and
    // help are definition data; the toolbar, the editor's name and its help line are interface wording.
    content: [
      '.kis-page-header-copy .eyebrow',
      '.editor-sidebar > section:first-child h2',
      'select[name="type"]',
      '.rich-text-source > span',
      '.rich-text-source > small',
      '#content-fields label',
    ],
  },
  {
    id: 'administrator-settings',
    path: '/administrator/settings',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page.getByRole('textbox', { name: message(locale, 'core.administrator.settings.footer_text') }),
        'footer text field',
      ],
      [
        page.getByRole('button', { name: message(locale, 'core.administrator.settings.save_settings_and_design') }),
        'save settings',
      ],
    ],
  },
  {
    id: 'administrator-business-definitions',
    path: '/administrator/business-definitions',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page.getByRole('link', { name: message(locale, 'core.administrator.business_definitions.new_definition') }),
        'new definition',
      ],
    ],
    // Handles, owners and field names are machine identifiers; labels belong to each definition.
    content: ['.definition-catalog-item', '.definition-detail', 'code'],
  },
  {
    id: 'administrator-business-records',
    path: '/administrator/business',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [page.locator('main a[href^="/administrator/business/"]').first(), 'first business workspace'],
    ],
    content: ['.business-workspace-card'],
  },
  {
    id: 'administrator-access-control',
    path: '/administrator/access',
    controls: (page, locale, isMobile) => [
      ...administratorShell(page, locale, isMobile),
      [
        page.getByRole('link', { name: message(locale, 'core.administrator.access_control.create_user') }),
        'create user',
      ],
    ],
    content: ['table tbody'],
  },
];

const portalSurfaces: readonly QualifiedSurface[] = [
  {
    id: 'portal-home',
    path: '/portal',
    controls: (page, locale) => [
      [
        page
          .getByRole('navigation', { name: message(locale, 'core.portal.layout.navigation_label') })
          .getByRole('link', { name: message(locale, 'core.navigation.portal.portal-security.label') }),
        'account security navigation entry',
      ],
      [page.getByRole('button', { name: message(locale, 'core.portal.layout.sign_out') }), 'sign out'],
    ],
    content: ['[data-visual-dynamic]'],
  },
  {
    id: 'portal-security',
    path: '/portal/security',
    controls: (page, locale) => [
      [page.getByRole('button', { name: message(locale, 'core.portal.security.start_setup') }), 'start setup'],
      [page.getByRole('button', { name: message(locale, 'core.portal.layout.sign_out') }), 'sign out'],
    ],
  },
];

const publicSurface: QualifiedSurface = {
  id: 'public-home',
  path: '/',
  controls: (page, locale, isMobile) => [
    isMobile
      ? [page.getByRole('button', { name: message(locale, 'core.site.layout.open_navigation') }), 'navigation toggle']
      : [
          page.getByRole('navigation', { name: message(locale, 'core.site.layout.main_navigation') })
            .getByRole('link')
            .first(),
          'first navigation link',
        ],
  ],
  // The page body, the menu labels, the site name and the footer text are what the operator wrote.
  content: ['main', '.site-navigation', 'footer.site-footer'],
};

for (const locale of interfaceLocales) {
  test.describe(`${locale} interface qualification`, () => {
    test.use({ locale: browserLocales[locale], extraHTTPHeaders: { 'Accept-Language': locale } });

    test(`${locale} administrator surfaces are qualified`, async ({ page, isMobile }, testInfo) => {
      test.setTimeout(180_000);
      await signInAdministrator(page, locale);
      const evidence: SurfaceEvidence[] = [];
      for (const surface of administratorSurfaces) {
        const response = await page.goto(surface.path);
        expect(response?.status(), `${surface.id} answers`).toBe(200);
        const settled = surface.settle === undefined ? [] : await surface.settle(page, locale);
        evidence.push(await qualifySurface(
          page,
          testInfo,
          locale,
          surface.id,
          [...surface.controls(page, locale, isMobile), ...settled],
          surface.content,
        ));
      }
      await attachEvidence(testInfo, locale, 'administrator', evidence);
    });

    test(`${locale} portal surfaces are qualified`, async ({ page, isMobile }, testInfo) => {
      test.setTimeout(120_000);
      await signInPortal(page, locale);
      const evidence: SurfaceEvidence[] = [];
      for (const surface of portalSurfaces) {
        const response = await page.goto(surface.path);
        expect(response?.status(), `${surface.id} answers`).toBe(200);
        evidence.push(await qualifySurface(
          page,
          testInfo,
          locale,
          surface.id,
          surface.controls(page, locale, isMobile),
          surface.content,
        ));
      }
      await attachEvidence(testInfo, locale, 'portal', evidence);
    });

    test(`${locale} public surface is qualified`, async ({ page, isMobile }, testInfo) => {
      const response = await page.goto(publicSurface.path);
      expect(response?.status(), 'the public home answers').toBe(200);
      const evidence = await qualifySurface(
        page,
        testInfo,
        locale,
        publicSurface.id,
        publicSurface.controls(page, locale, isMobile),
        publicSurface.content,
      );
      await attachEvidence(testInfo, locale, 'public', [evidence]);
    });
  });
}
