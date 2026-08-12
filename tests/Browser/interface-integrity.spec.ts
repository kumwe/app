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
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
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
  await page.setContent(`
    <style>
      html, body { width: 100%; margin: 0; overflow-x: hidden; }
      .diagnostic-component { position: relative; width: 15rem; height: 8rem; overflow: hidden; }
      .diagnostic-child { width: 30rem; height: 2rem; }
      .diagnostic-action { position: absolute; left: 1rem; top: 4rem; width: 8rem; height: 2rem; }
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
});

test('administrator landing routes emit complete interface baselines', async ({ page }, testInfo) => {
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
});

test('portal landing routes emit complete interface baselines', async ({ page }, testInfo) => {
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
});
