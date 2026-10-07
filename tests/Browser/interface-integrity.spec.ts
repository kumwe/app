import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import {
  collectInterfaceDiagnostics,
  expectNoDocumentOverflow,
  type InterfaceDiagnosticReport,
} from './support/interface-diagnostics';
import {
  interfaceLandingSurfaces,
  type InterfaceLandingSurface,
  type InterfaceShell,
} from './support/interface-surface-manifest';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';
const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';

async function signInAdministrator(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await expect(page.locator('.login-page')).toBeVisible();
  await expect(page.locator('.administrator-shell')).toHaveCount(0);
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
  await expect(page.locator('.administrator-shell')).toBeVisible();
  await expect(page.locator('.login-page')).toHaveCount(0);
}

async function signInPortal(page: Page): Promise<void> {
  await page.goto('/portal/login');
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/u);
}

function routesFor(shell: InterfaceShell): readonly InterfaceLandingSurface[] {
  return interfaceLandingSurfaces.filter((surface) => surface.shell === shell);
}

/** Require the automated WCAG 2.2 AA contract on every inventoried core landing route. */
async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

async function attachEvidence(
  page: Page,
  testInfo: TestInfo,
  surface: InterfaceLandingSurface,
  report: InterfaceDiagnosticReport,
): Promise<void> {
  await testInfo.attach(`${surface.id}-diagnostics`, {
    body: Buffer.from(JSON.stringify({ surface, report }, null, 2)),
    contentType: 'application/json',
  });
  const screenshot = testInfo.outputPath(`${surface.id}.png`);
  await page.screenshot({
    path: screenshot,
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
  await testInfo.attach(`${surface.id}-baseline`, {
    path: screenshot,
    contentType: 'image/png',
  });
}

test('component diagnostics expose the Business Definition failure without a document scrollbar', async ({
  page,
}) => {
  // KIS-EVIDENCE-BEGIN p6-004-component-diagnostics
  await page.setContent(`
    <style>
      html, body { width: 100%; margin: 0; overflow-x: hidden; }
      .diagnostic-component { position: relative; width: 15rem; height: 8rem; overflow: hidden; }
      .diagnostic-child { width: 30rem; height: 2rem; }
      .diagnostic-action { position: absolute; left: 1rem; top: 4rem; width: 8rem; height: 2rem; }
      .below-fold-diagnostic { margin-top: 120vh; }
      @media print { details > * { display: block !important; } }
    </style>
    <form id="business-definition-form" class="diagnostic-component">
      <input type="hidden" name="id" value="named form controls must not replace the form id">
      <section class="diagnostic-child" data-interface-id="diagnostic-child">Clipped content</section>
      <button class="diagnostic-action" data-interface-id="first-action">First</button>
      <button class="diagnostic-action" data-interface-id="second-action">Second</button>
    </form>
    <details id="closed-disclosure">
      <summary>Optional editor</summary>
      <form class="diagnostic-component" data-interface-id="closed-editor">
        <section class="diagnostic-child">Intentionally undisclosed content</section>
      </form>
    </details>
    <section id="below-fold-component" class="diagnostic-component below-fold-diagnostic">
      <section class="diagnostic-child">Below-fold clipped content</section>
    </section>
  `);

  const report = await collectInterfaceDiagnostics(page);

  expect(report.viewport.horizontalOverflow).toBe(0);
  expect(report.findings).toEqual(expect.arrayContaining([
    expect.objectContaining({
      kind: 'component-overflow',
      selector: '#business-definition-form',
    }),
    expect.objectContaining({
      kind: 'clipped-by-ancestor',
      selector: '[data-interface-id="diagnostic-child"]',
      relatedSelector: '#business-definition-form',
    }),
    expect.objectContaining({
      kind: 'control-overlap',
      selector: '[data-interface-id="first-action"]',
      relatedSelector: '[data-interface-id="second-action"]',
    }),
    expect.objectContaining({
      kind: 'component-overflow',
      selector: '#below-fold-component',
    }),
  ]));
  expect(report.findings).not.toEqual(expect.arrayContaining([
    expect.objectContaining({ selector: '[data-interface-id="closed-editor"]' }),
  ]));

  await page.emulateMedia({ media: 'print' });
  const printReport = await collectInterfaceDiagnostics(page);
  expect(printReport.findings).toEqual(expect.arrayContaining([
    expect.objectContaining({
      kind: 'component-overflow',
      selector: '[data-interface-id="closed-editor"]',
    }),
  ]));
  // KIS-EVIDENCE-END p6-004-component-diagnostics
});

test('administrator landing routes emit complete interface baselines', async ({ page }, testInfo) => {
  // KIS-EVIDENCE-BEGIN p6-004-administrator-diagnostics
  test.setTimeout(120_000);
  await signInAdministrator(page);
  const registeredPaths = await page.locator('.administrator-navigation a[href]').evaluateAll((links) =>
    links.map((link) => link.getAttribute('href')),
  );
  expect(
    routesFor('administrator')
      .map((surface) => surface.path)
      .filter((path) => !registeredPaths.includes(path)),
  ).toEqual([]);

  for (const surface of routesFor('administrator')) {
    await page.goto(surface.path);
    await expect(page.getByRole('heading', { level: 1, name: surface.heading })).toBeVisible();
    const report = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(report.findings, JSON.stringify({ surface, report }, null, 2)).toEqual([]);
    await expectAccessible(page);
    await attachEvidence(page, testInfo, surface, report);
  }
  // KIS-EVIDENCE-END p6-004-administrator-diagnostics
});

test('diagnostics keeps the authenticated workspace readable across every section', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  await signInAdministrator(page);

  const navigationToggle = page.locator('[data-navigation-toggle]');
  if (await navigationToggle.isVisible()) {
    await navigationToggle.click();
  }
  const sidebar = page.locator('.administrator-navigation');
  await sidebar.getByRole('link', { name: 'Diagnostics', exact: true }).click();
  await expect(page).toHaveURL(/\/administrator\/diagnostics$/u);

  const surface = page.locator('[data-kis-surface="core.administrator.diagnostics"]');
  const sections = surface.getByRole('navigation', { name: 'Diagnostics', exact: true });
  const baseline = routesFor('administrator').find((item) => item.id === 'administrator.diagnostics');
  if (baseline === undefined) {
    throw new Error('The Diagnostics landing route is missing from the interface manifest.');
  }

  const diagnosticSections = [
    { id: 'contention', heading: 'Contention' },
    { id: 'queues', heading: 'Queues' },
    { id: 'slow', heading: 'Slow queries' },
    { id: 'backlog', heading: 'Backlog' },
    { id: 'retention', heading: 'Retention' },
  ];
  await expect(sections.getByRole('link')).toHaveCount(diagnosticSections.length);

  for (const section of diagnosticSections) {
    const tab = sections.getByRole('link', { name: section.heading, exact: true });
    await tab.scrollIntoViewIfNeeded();
    await expect(tab).toBeInViewport({ ratio: 1 });
    await tab.click();
    await expect(page).toHaveURL(new RegExp(`/administrator/diagnostics\\?section=${section.id}$`, 'u'));
    await expect(page.locator('.administrator-shell')).toBeVisible();
    await expect(page.locator('.administrator-topbar')).toBeVisible();
    await expect(page.locator('.login-page')).toHaveCount(0);
    await expect(surface.getByRole('heading', { level: 1, name: 'Diagnostics', exact: true })).toBeVisible();
    await expect(surface.getByRole('heading', { level: 2, name: section.heading, exact: true })).toBeVisible();
    await expect(sidebar.locator('a[aria-current="page"]')).toHaveCount(1);
    await expect(sidebar.locator('a[aria-current="page"]')).toHaveAttribute('href', '/administrator/diagnostics');
    await expect(sections.locator('a[aria-current="page"]')).toHaveCount(1);
    await expect(tab).toHaveAttribute('aria-current', 'page');

    // A narrow main column can wrap every word without producing horizontal overflow.
    // Assert usable space separately so the original collapsed-shell failure cannot pass.
    const geometry = await surface.evaluate((element) => {
      const main = element.closest('main');
      const panel = element.querySelector('section.panel');
      const sectionLinks = [...element.querySelectorAll<HTMLElement>('nav a')];
      if (main === null || panel === null) {
        throw new Error('The Diagnostics workspace is missing its main region or result panel.');
      }
      return {
        viewportWidth: window.innerWidth,
        mainWidth: main.getBoundingClientRect().width,
        panelWidth: panel.getBoundingClientRect().width,
        tabs: sectionLinks.map((link) => ({
          width: link.getBoundingClientRect().width,
          height: link.getBoundingClientRect().height,
          fontSize: Number.parseFloat(getComputedStyle(link).fontSize),
        })),
      };
    });
    expect(geometry.mainWidth).toBeGreaterThanOrEqual(
      geometry.viewportWidth * (geometry.viewportWidth <= 768 ? 0.95 : 0.65),
    );
    expect(geometry.panelWidth).toBeGreaterThanOrEqual(Math.min(280, geometry.viewportWidth * 0.7));
    for (const dimensions of geometry.tabs) {
      expect(dimensions.width).toBeGreaterThanOrEqual(44);
      expect(dimensions.height).toBeGreaterThanOrEqual(44);
      expect(dimensions.fontSize).toBeGreaterThanOrEqual(12);
    }

    const status = surface.getByRole('status');
    if (await status.count() > 0) {
      await expect(status).toHaveCount(1);
      await expect(status).toBeVisible();
      await expect(status).toHaveText(
        /^(?:No observations were found in this sample\.|This source is unavailable\.|The diagnostic exceeded|The database is not collecting)/u,
      );
      await expect(surface.locator('dl')).toHaveCount(0);
    } else {
      await expect(surface.getByText('Results are bounded samples.', { exact: false })).toBeVisible();
      expect(await surface.locator('dl').count()).toBeGreaterThan(0);
      for (const label of await surface.locator('dt').allTextContents()) {
        expect(label.trim()).not.toBe('');
      }
      for (const value of await surface.locator('dd').allTextContents()) {
        expect(value.trim()).not.toBe('');
      }
    }
    const report = await expectNoDocumentOverflow(page, { root: '#administrator-content' });
    expect(report.findings, JSON.stringify({ section, report }, null, 2)).toEqual([]);
    await expectAccessible(page);
    await attachEvidence(page, testInfo, {
      ...baseline,
      id: `${baseline.id}.${section.id}`,
      path: `${baseline.path}?section=${section.id}`,
    }, report);

    if (section.id === 'retention' && await surface.locator('dd').count() > 0) {
      // Exercise a long diagnostic identifier after recording the unmodified live page.
      const value = surface.locator('dd').first();
      const original = await value.textContent();
      await value.evaluate((element) => {
        element.textContent = 'browser-diagnostic-ledger-'.repeat(6).slice(0, 128);
      });
      try {
        const longValueReport = await expectNoDocumentOverflow(page, { root: '#administrator-content' });
        expect(longValueReport.findings, JSON.stringify(longValueReport, null, 2)).toEqual([]);
      } finally {
        await value.evaluate((element, text) => { element.textContent = text; }, original);
      }
    }
  }
});

test('portal landing routes emit complete interface baselines', async ({ page }, testInfo) => {
  // KIS-EVIDENCE-BEGIN p6-004-portal-diagnostics
  test.setTimeout(120_000);
  await signInPortal(page);
  const registeredPaths = await page.locator('.portal-navigation a[href]').evaluateAll((links) =>
    links.map((link) => link.getAttribute('href')),
  );
  expect(
    routesFor('portal')
      .map((surface) => surface.path)
      .filter((path) => !registeredPaths.includes(path)),
  ).toEqual([]);

  for (const surface of routesFor('portal')) {
    await page.goto(surface.path);
    await expect(page.getByRole('heading', { level: 1, name: surface.heading })).toBeVisible();
    const report = await expectNoDocumentOverflow(page, {
      root: '#portal-main',
      detectControlOverlaps: false,
    });
    expect(report.findings, JSON.stringify({ surface, report }, null, 2)).toEqual([]);
    await expectAccessible(page);
    await attachEvidence(page, testInfo, surface, report);
  }
  // KIS-EVIDENCE-END p6-004-portal-diagnostics
});
