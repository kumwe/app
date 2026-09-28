import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page, type Request } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import {
  awaitStudioLaunchSettled,
  focusStop,
  STUDIO_SURFACE_PREFERENCE_KEY,
  tabStops,
  tabUntil,
  type FocusStop,
} from './support/studio-authoring';
import { journeyLocale, localized, studio, text } from './support/studio-journey-text';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';

/** Sign in through the production server-rendered administrator form. */
async function signInToAdministrator(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Require WCAG 2.2 AA automated rules on the surface an editor sees. */
async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    // The scriptless preview document is a separately rendered public surface; excluding its frame keeps the
    // scan on the Content editor and prevents recursive same-origin analysis of the sandboxed document.
    .exclude('iframe[data-studio-contextual-preview]')
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

/**
 * The Content editor mounts the pinned Studio page builder as its default surface and swaps with the
 * structured form on request, remembering the editor's choice across navigations.
 *
 * The page carries the Producer-emitted deployment as an inert JSON block and a `modulepreload` for
 * the exact browser module; the start module imports that module, mounts the contextual shell into
 * the target, and only then hides the structured form. Without a network path to the configured
 * package origin the launch reports `failed` and the form stays, which this journey treats as a real
 * failure: the page builder is the deliverable.
 */
test('the Studio page builder mounts on Content New and swaps with the structured form', async ({
  page,
}) => {
  test.slow();
  await signInToAdministrator(page);

  await page.goto('/administrator/content/new');
  const region = page.locator('[data-studio-authoring-region]');
  await expect(region).toBeVisible();
  const outcome = await awaitStudioLaunchSettled(page);
  expect(outcome, 'The pinned Studio browser module must mount from the configured origin.').toBe('ready');

  const mount = page.locator('#kumwe-studio-content');
  const toggle = page.getByRole('button', { name: 'Use the structured form' });
  // The structured form's own title field; the mounted page builder carries a second "Title" input.
  const title = page.locator('[data-studio-authoring-fallback-form]').getByLabel('Title');
  await expect(page.locator('[data-studio-launch-status]')).toHaveText('The Studio page builder is ready.');
  await expect(region).toHaveAttribute('data-studio-surface', 'studio');
  await expect(mount).toBeVisible();
  await expect(mount.locator('*').first()).toBeAttached();
  await expect(title).toBeHidden();
  await expect(toggle).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  // Studio asks how to start a new item; a blank start opens the contextual editor in the same mount.
  await expect(page.getByRole('heading', { name: 'Choose how to start' })).toBeVisible();
  await page.getByRole('radio', { name: /Blank start/u }).check();
  await page.getByRole('button', { name: 'Start Studio' }).click();
  await expect(mount.locator('kumwe-studio-contextual')).toBeAttached();
  await expect(page.getByRole('heading', { name: 'Choose how to start' })).toBeHidden();
  await expect(page.locator('[data-studio-launch-status]')).toHaveText('The Studio page builder is ready.');
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  await toggle.click();
  await expect(region).toHaveAttribute('data-studio-surface', 'form');
  await expect(mount).toBeHidden();
  await expect(title).toBeVisible();
  await expect(page.getByRole('button', { name: 'Use the page builder' })).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate((key) => window.localStorage.getItem(key), STUDIO_SURFACE_PREFERENCE_KEY))
    .toBe('form');

  // The remembered choice survives a navigation: the page opens on the form and Studio waits for the toggle.
  await page.reload();
  expect(await awaitStudioLaunchSettled(page)).toBe('deferred');
  await expect(page.locator('[data-studio-launch-status]')).toHaveText('The page builder loads when you switch to it.');
  await expect(region).toHaveAttribute('data-studio-surface', 'form');
  await expect(mount.locator('*')).toHaveCount(0);
  await expect(title).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  await page.getByRole('button', { name: 'Use the page builder' }).click();
  await expect(region).toHaveAttribute('data-studio-surface', 'studio');
  expect(await awaitStudioLaunchSettled(page)).toBe('ready');
  await expect(mount).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Choose how to start' })).toBeVisible();
  await expect(title).toBeHidden();
  expect(await page.evaluate((key) => window.localStorage.getItem(key), STUDIO_SURFACE_PREFERENCE_KEY))
    .toBe('studio');

  // An explicit surface in the URL wins over the remembered one and becomes the new memory.
  await page.goto('/administrator/content/new?surface=form');
  expect(await awaitStudioLaunchSettled(page)).toBe('deferred');
  await expect(region).toHaveAttribute('data-studio-surface', 'form');
  await expect(title).toBeVisible();
  expect(await page.evaluate((key) => window.localStorage.getItem(key), STUDIO_SURFACE_PREFERENCE_KEY))
    .toBe('form');
});

/**
 * The STUDIO-PROD-015 contextual Content authoring journey, driven through the real administrator.
 *
 * Every step runs in the Content editor's own New and Edit context: the pinned, compiled Studio browser
 * module mounts from the configured asset origin with its manifest integrity, and every durable effect is
 * an authorized PHP host-port call to this origin. The steps share one item and one reusable type, so they
 * run in order: a blank canvas gains a typed field and becomes a reusable type, the same session saves its
 * item, the item reopens with its exact revisions, a concurrent save is refused without overwriting, a new
 * item starts from the type with empty values, a layout change with an admitted extension block becomes an
 * immutable successor type version, and the accepted item previews through the authenticated channel and
 * renders publicly once published.
 *
 * The interface locale is a parameter (`KUMWE_STUDIO_JOURNEY_LOCALE`, default `en-GB`): labels come from
 * that locale's catalogue by message identifier. The pinned Studio release renders its create-source chooser
 * and save confirmation without its catalogue overrides, so those two surfaces are addressed by their
 * stable data attributes rather than by wording.
 */

/** The manifest-six extension block the browser fixture admits for the contextual target. */
const EXTENSION_BLOCK = 'kumwe.contract-manifest-six/grid';

interface JourneyState {
  editPath?: string;
  modelId?: string;
  slug?: string;
  summary?: string;
}

const journey: JourneyState = {};

test.describe.configure({ mode: 'serial' });

/**
 * Record every request the page makes, so the journey can prove its runtime topology.
 *
 * Production Studio is compiled browser code plus PHP: no request may reach a Node.js development server,
 * a Vite client or an uncompiled source module, and every host operation must be a same-origin POST to the
 * PHP host-port endpoint the deployment configured.
 */
function recordRequests(page: Page): Request[] {
  const requests: Request[] = [];
  page.on('request', (request) => requests.push(request));
  return requests;
}

async function expectPhpOnlyTopology(page: Page, requests: readonly Request[]): Promise<void> {
  const origin = new URL(page.url()).origin;
  const moduleUrl = await page.locator('[data-studio-module-url]').getAttribute('data-studio-module-url');
  expect(moduleUrl, 'The page must name the compiled Studio browser module.').not.toBeNull();
  const assetOrigin = new URL(moduleUrl ?? '', origin).origin;
  const integrity = await page.locator(`link[rel="modulepreload"][href="${moduleUrl ?? ''}"]`).getAttribute('integrity');
  expect(integrity ?? '', 'The compiled module must be loaded with its manifest integrity.').toMatch(/^sha(256|384|512)-/u);
  const hostCalls = requests.filter((request) => request.url().includes('/administrator/studio/ports/'));
  expect(hostCalls.length, 'The journey must exercise the PHP host ports.').toBeGreaterThan(0);
  for (const request of requests) {
    const url = new URL(request.url());
    expect(url.pathname, `No request may reach a development server: ${url.href}`)
      .not.toMatch(/(^\/@vite\/|\/node_modules\/|\.tsx?$)/u);
    expect(url.protocol, `No request may use a live-reload socket: ${url.href}`).toMatch(/^(https?|data|blob):$/u);
    if (url.origin !== origin && url.protocol.startsWith('http')) {
      expect(url.origin, `A cross-origin request may only fetch the pinned Studio assets: ${url.href}`).toBe(assetOrigin);
      expect(request.method()).toBe('GET');
    }
  }
  for (const call of hostCalls) {
    expect(new URL(call.url()).origin).toBe(origin);
    expect(call.method()).toBe('POST');
    expect(call.headers()['x-csrf-token'] ?? '', 'Every host call carries the configured CSRF header.').not.toBe('');
  }
}

/** Open the Content editor at a path and wait until the contextual Studio mount has settled. */
async function openEditor(page: Page, path: string): Promise<void> {
  const errors: string[] = [];
  const collect = (message: import('@playwright/test').ConsoleMessage): void => {
    if (message.type() === 'error') errors.push(message.text());
  };
  page.on('console', collect);
  await page.goto(localized(path));
  const outcome = await awaitStudioLaunchSettled(page);
  page.off('console', collect);
  expect(outcome, `The pinned Studio browser module must mount: ${errors.join(' | ')}`).toBe('ready');
}

/** The mounted contextual shell. */
function shellOf(page: Page): Locator {
  return page.locator('#kumwe-studio-content kumwe-studio-contextual');
}

/** Choose a start source on the create-source chooser, by its stable radio value or reference text. */
async function startFrom(page: Page, choice: 'blank' | RegExp): Promise<void> {
  const chooser = page.locator('kumwe-studio-hosted-start');
  await expect(chooser.locator('input[type="radio"]').first()).toBeEnabled();
  if (choice === 'blank') {
    await chooser.locator('input[type="radio"][value="blank"]').check();
  } else {
    await chooser.locator('label.choice', { hasText: choice }).locator('input[type="radio"]').check();
  }
  await chooser.locator('button.primary').click();
  await expect(shellOf(page)).toBeVisible();
}

/** Switch the shell's authoring mode through its tab list. */
async function mode(shell: Locator, name: 'model' | 'blueprint' | 'content'): Promise<void> {
  await shell.getByRole('tab', { name: studio(`mode-${name}`) }).click();
  await expect(shell.getByRole('tab', { name: studio(`mode-${name}`) })).toHaveAttribute('aria-selected', 'true');
}

/** Request one explicit save outcome, confirm the host's consequences when it asks, and await acceptance. */
async function save(
  page: Page,
  outcome: 'save-item' | 'save-as-new-type' | 'save-new-type-version',
  expectedConsequences: readonly string[] = [],
): Promise<void> {
  const status = page.locator('[data-studio-launch-status]');
  const accepted = page.waitForResponse((response) => response.url().endsWith(`/administrator/studio/ports/authoring/${outcome}`));
  await shellOf(page).getByRole('button', { name: studio(outcome), exact: true }).click();
  if (expectedConsequences.length > 0) {
    const confirmation = page.locator('[data-studio-save-confirmation]');
    await expect(confirmation).toBeVisible();
    await expect(confirmation).toHaveAttribute('role', 'alertdialog');
    for (const code of expectedConsequences) {
      await expect(confirmation.locator(`[data-studio-save-consequence="${code}"]`)).toBeVisible();
    }
    await expectAccessible(page);
    await confirmation.locator('[data-studio-save-confirmation-action="confirm"]').click();
  }
  expect((await accepted).status(), `The host must accept ${outcome}.`).toBe(200);
  await expect(status).toHaveAttribute('data-studio-launch-state', 'saved', { timeout: 20_000 });
  await expect(shellOf(page).locator('.dirty-summary')).toHaveAttribute('data-dirty', 'false');
}

/** Insert one admitted block from the Blueprint palette by its exact type. */
async function insertBlock(shell: Locator, type: string): Promise<void> {
  const index = await shell.evaluate((element, blockType) => {
    const canvas = (element as HTMLElement & { blueprintElement?: HTMLElement & {
      configuration?: { blockDefinitions: Array<{ type: string }> };
    } }).blueprintElement;
    return canvas?.configuration?.blockDefinitions.findIndex((definition) => definition.type === blockType) ?? -1;
  }, type);
  expect(index, `Block ${type} must be admitted in the contextual authoring catalogue.`).toBeGreaterThanOrEqual(0);
  await shell.getByRole('complementary', { name: text('core.studio.shell.palette-label') })
    .locator('ul.palette').first().getByRole('button').nth(index).click();
  await expect.poll(() => rootTypes(shell)).toContain(type);
}

async function rootTypes(shell: Locator): Promise<string[]> {
  return shell.evaluate((element) => {
    const canvas = (element as HTMLElement & { blueprintElement?: { document?: { roots: Array<{ type: string }> } } })
      .blueprintElement;
    return canvas?.document?.roots.map(({ type }) => type) ?? [];
  });
}

/**
 * Blank creation: typed field, reusable type and the item's values in one contextual session.
 *
 * STUDIO-PROD-001, 002, 003, 006, 007, 012 and 013: Content New opens Studio in place; the author starts
 * blank, defines a typed field through the explicit Model control, moves through every declared
 * presentation without losing unsaved work, saves the design as a new reusable type after confirming the
 * host's consequences, enters the item's values and saves the item, all without leaving the session.
 */
test('blank creation defines a typed field, saves a reusable type and saves its item in one session', async ({
  page,
}) => {
  test.slow();
  test.info().annotations.push({ type: 'locale', description: journeyLocale });
  const requests = recordRequests(page);
  await signInToAdministrator(page);
  await openEditor(page, '/administrator/content/new');
  await startFrom(page, 'blank');
  const shell = shellOf(page);
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'blank');
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  // Keyboard parity: the mode tabs move with the arrow keys, not only with a pointer.
  await mode(shell, 'model');
  await shell.getByRole('tab', { name: studio('mode-model') }).press('ArrowRight');
  await expect(shell.getByRole('tab', { name: studio('mode-blueprint') })).toBeFocused();
  await expect(shell.getByRole('tab', { name: studio('mode-blueprint') })).toHaveAttribute('aria-selected', 'true');
  await mode(shell, 'model');

  await shell.getByLabel(studio('field-identifier')).fill('summary');
  await shell.getByLabel(studio('field-label'), { exact: true }).fill('Summary');
  await shell.getByLabel(studio('field-type')).selectOption('string');
  await shell.getByRole('button', { name: studio('add-field') }).press('Enter');
  await expect(shell.locator('li[data-field-path="summary"]')).toBeVisible();
  await expect(shell.locator('.dirty-summary')).toHaveAttribute('data-dirty', 'true');

  // Presentation continuity: every declared state keeps the resource, the mode and the unsaved field.
  for (const presentation of ['maximized', 'fullscreen', 'minimized', 'inline'] as const) {
    await shell.getByRole('button', { name: studio(`presentation-${presentation}`), exact: true }).click();
    await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-presentation', presentation);
    await expect(shell.locator('.dirty-summary')).toHaveAttribute('data-dirty', 'true');
    await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'blank');
  }
  await expect(shell.locator('li[data-field-path="summary"]')).toBeVisible();

  await save(page, 'save-as-new-type', ['kumwe.app/new-content-type']);
  const identity = await shell.locator('.contextual-identity p').innerText();
  const modelId = /content-model:([0-9a-f-]{36})@/u.exec(identity)?.[1];
  expect(modelId, 'The session must now name the accepted reusable type.').toBeDefined();
  journey.modelId = modelId;
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'blank');

  await mode(shell, 'content');
  journey.slug = `studio-journey-${Date.now().toString(36)}`;
  journey.summary = 'Composed in one contextual session.';
  await shell.getByRole('textbox', { name: 'Title', exact: true }).fill('Studio journey item');
  await shell.getByRole('textbox', { name: 'Slug', exact: true }).fill(journey.slug);
  await shell.getByRole('textbox', { name: 'Summary', exact: true }).fill(journey.summary);
  await shell.getByRole('textbox', { name: 'Summary', exact: true }).press('Tab');
  await save(page, 'save-item');

  // The accepted item's edit context is the return destination; returning needs no confirmation.
  await shell.locator('.contextual-return-button').click();
  await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]{36}\/edit/u);
  journey.editPath = new URL(page.url()).pathname;
  await expectPhpOnlyTopology(page, requests);
});

/**
 * Existing-item round trip, reopen and stale-revision conflict.
 *
 * STUDIO-PROD-005 and 006: the edit context hydrates the exact accepted type, Model and Entry revisions and
 * the item's own values; an edit saves through PHP and reopens as the accepted revision; a second editor
 * holding the older revision is refused with a conflict and nothing it typed overwrites the accepted value.
 */
test('the existing item reopens with its exact revisions, saves, and refuses a stale concurrent save', async ({
  page,
  browser,
}) => {
  test.slow();
  const editPath = journey.editPath;
  const modelId = journey.modelId;
  expect(editPath !== undefined && modelId !== undefined, 'The blank-creation step must have accepted its item.').toBe(true);
  await signInToAdministrator(page);
  await openEditor(page, editPath ?? '');
  const shell = shellOf(page);
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'existing');
  await expect(shell.locator('.contextual-identity p')).toContainText(`content-model:${modelId ?? ''}@`);
  await mode(shell, 'content');
  await expect(shell.getByRole('textbox', { name: 'Title', exact: true })).toHaveValue('Studio journey item');
  await expect(shell.getByRole('textbox', { name: 'Summary', exact: true })).toHaveValue(journey.summary ?? '');

  // A second editor opens the same revision in its own browser context.
  const other = await browser.newContext();
  const competitor = await other.newPage();
  await signInToAdministrator(competitor);
  await openEditor(competitor, editPath ?? '');
  const competing = shellOf(competitor);
  await mode(competing, 'content');

  await shell.getByRole('textbox', { name: 'Summary', exact: true }).fill('Accepted by the first editor.');
  await shell.getByRole('textbox', { name: 'Summary', exact: true }).press('Tab');
  await save(page, 'save-item');

  await competing.getByRole('textbox', { name: 'Summary', exact: true }).fill('Typed against a stale revision.');
  await competing.getByRole('textbox', { name: 'Summary', exact: true }).press('Tab');
  await competing.getByRole('button', { name: studio('save-item'), exact: true }).click();
  await expect(competitor.locator('[data-studio-launch-status]')).toHaveAttribute('data-studio-launch-state', 'error');
  await expect(competing.locator('.dirty-summary')).toHaveAttribute('data-dirty', 'true');
  await other.close();

  // Reopening shows the accepted revision, never the refused one.
  await openEditor(page, editPath ?? '');
  await mode(shellOf(page), 'content');
  await expect(shellOf(page).getByRole('textbox', { name: 'Summary', exact: true }))
    .toHaveValue('Accepted by the first editor.');
  await expectAccessible(page);
});

/**
 * Creation from the reusable type with empty values.
 *
 * STUDIO-PROD-002 and 004: the type saved from the blank canvas is offered on Content New, and starting from
 * it hydrates its exact Model with the typed field and no value from any earlier item.
 */
test('a new item from the reusable type receives its fields with empty values', async ({ page }) => {
  test.slow();
  const modelId = journey.modelId;
  expect(modelId !== undefined, 'The blank-creation step must have accepted its item.').toBe(true);
  await signInToAdministrator(page);
  await openEditor(page, '/administrator/content/new');
  await startFrom(page, new RegExp(`content-type:${modelId ?? ''}@`, 'u'));
  const shell = shellOf(page);
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'from-type');
  await mode(shell, 'content');
  await expect(shell.getByRole('textbox', { name: 'Summary', exact: true })).toHaveValue('');
  await expect(shell.getByRole('textbox', { name: 'Title', exact: true })).toHaveValue('');
  await expectAccessible(page);
});

/**
 * A layout change with an admitted extension block becomes an immutable successor type version.
 *
 * STUDIO-PROD-003, 006, 008 and 009: the Blueprint mode offers the admitted manifest-six extension block
 * beside the core blocks; inserting it and a core section changes the reusable design, which only a type
 * outcome may carry, and the host shows the dependent-entry and adoption consequences before it publishes
 * the successor type, Model and Blueprint revisions that the item adopts.
 */
test('a layout change with an extension block saves an immutable successor type version', async ({ page }) => {
  test.slow();
  const editPath = journey.editPath;
  expect(editPath !== undefined, 'The blank-creation step must have accepted its item.').toBe(true);
  await signInToAdministrator(page);
  await openEditor(page, editPath ?? '');
  const shell = shellOf(page);
  const before = await shell.locator('.contextual-identity p').innerText();
  await mode(shell, 'blueprint');
  const inspector = shell.getByRole('complementary', { name: text('core.studio.shell.inspector-heading') });

  // A core field block bound, through the explicit inspector control, to the type's typed field.
  await insertBlock(shell, 'core/field-text');
  const binding = inspector.locator('select', { has: shell.page().locator('option[value=\'["data_summary"]\']') });
  await binding.selectOption(JSON.stringify(['data_summary']));

  // The admitted manifest-six extension block, configured through the same non-drag inspector control.
  await insertBlock(shell, EXTENSION_BLOCK);
  for (const [name, value] of [['columns', '2'], ['collapse', '"stack"']] as const) {
    await inspector.getByLabel(text('core.studio.shell.inspector-add-property-name-label')).fill(name);
    await inspector.getByLabel(text('core.studio.shell.inspector-add-property-value-label')).fill(value);
    await inspector.getByRole('button', { name: text('core.studio.shell.inspector-add-property') }).click();
  }
  await expect(shell.locator('.dirty-summary')).toHaveAttribute('data-dirty', 'true');
  await save(page, 'save-new-type-version', ['kumwe.app/dependent-entries-remain', 'kumwe.app/item-adopts-successor']);
  const after = await shell.locator('.contextual-identity p').innerText();
  expect(after).not.toBe(before);
  expect(after).toMatch(/@0\.0\.\d+#content-type-v\d+$/u);
  expect(await rootTypes(shell)).toEqual(['core/field-text', EXTENSION_BLOCK]);

  await openEditor(page, editPath ?? '');
  await expect(shellOf(page).locator('.contextual-identity p')).toHaveText(after);
});

/**
 * Authenticated preview and trusted public rendering of the accepted item.
 *
 * STUDIO-PROD-010 and 011 with S-F: the host-owned preview renders the accepted composition through the
 * authenticated, origin-pinned channel into a same-origin frame; after the workflow publishes the item, the
 * public site renders it through PHP and Twig with no authoring runtime.
 */
test('the accepted item previews through the authenticated channel and renders publicly once published', async ({
  page,
}) => {
  test.slow();
  const editPath = journey.editPath;
  const slug = journey.slug;
  expect(editPath !== undefined && slug !== undefined, 'The blank-creation step must have accepted its item.').toBe(true);
  const requests = recordRequests(page);
  await signInToAdministrator(page);
  await openEditor(page, editPath ?? '');
  const preview = page.getByRole('button', { name: text('core.administrator.content_form.preview_this_item') });
  await expect(preview).toBeVisible();
  await preview.click();
  const status = page.locator('[data-studio-preview-status]');
  await expect(status).toHaveAttribute('data-studio-preview-state', 'ready', { timeout: 20_000 });
  const frame = page.locator('[data-studio-contextual-preview]');
  await expect(frame).toBeVisible();
  const document = page.frameLocator('[data-studio-contextual-preview]');
  await expect(document.locator('[data-kis-surface="core.administrator.content-editor"]')).toBeAttached();
  await expect(document.locator('[data-studio-preview-marker]')).toHaveCount(2);
  await expect(document.locator('.studio-preview-field-text', { hasText: 'Accepted by the first editor.' })).toBeVisible();
  await expect(document.locator('.studio-preview-extension-grid', { hasText: 'Contributed grid: 2 columns' }))
    .toBeVisible();
  const previewCalls = requests.filter((request) => request.url().includes('/administrator/studio/ports/preview/render'));
  expect(previewCalls.length).toBeGreaterThan(0);
  expect(previewCalls[0]?.headers()['x-kumwe-studio-preview-channel'] ?? '').toMatch(/^channels\/preview-/u);
  await expectAccessible(page);
  await expectPhpOnlyTopology(page, requests);

  // Publication is the Content workflow's own server-rendered action.
  await page.goto(localized(`${editPath ?? ''}?surface=form`));
  for (const state of ['Review', 'Published']) {
    await page.getByRole('button', { name: text('core.administrator.content_form.move_to', { to: state }) }).click();
    await expect(page.locator('.workflow-state h2')).toHaveText(state);
  }
  const response = await page.goto(`/${slug ?? ''}`);
  expect(response?.status()).toBe(200);
  await expect(page.locator('.studio-preview-field-text', { hasText: 'Accepted by the first editor.' })).toBeVisible();
  await expect(page.locator('.studio-preview-extension-grid', { hasText: 'Contributed grid: 2 columns' })).toBeVisible();
  expect(await page.locator('script[src*="studio-browser"], link[href*="studio-browser"]').count()).toBe(0);
  expect(await page.locator('[data-studio-preview-marker]').count()).toBe(0);
});

/**
 * Keyboard-only operation, accessible names and focus order of the contextual shell.
 *
 * STUDIO-PROD-013: an editor who never uses a pointer enters the Content editor through its skip link, tabs
 * to the surface toggle and on into Studio's create-source chooser, picks a start with the arrow keys and
 * opens the contextual shell with Enter. Every stop of every walk must be visible, inside the viewport and
 * announced with an accessible name; the chooser and the shell header are reached in reading order; the
 * declared presentations and the mode tabs answer the keyboard; a typed field is defined, and a block and the
 * App's canonical pattern are inserted through explicit controls with no drag, and on a touch device the same
 * controls answer a tap. At 320 CSS pixels, the reflow width WCAG 1.4.10 names, the editor still fits without
 * horizontal scrolling, and the WCAG 2.2 AA scan stays clean throughout.
 */
test('the contextual shell is operable by keyboard alone with named controls in reading order', async ({ page }) => {
  test.slow();
  await signInToAdministrator(page);
  await openEditor(page, '/administrator/content/new');
  const chooser = page.locator('kumwe-studio-hosted-start');
  await expect(chooser.getByRole('searchbox')).toBeEnabled();
  const toggle = text('core.administrator.content_form.use_the_structured_form');
  const isToggle = (stop: FocusStop): boolean => stop.role === 'button' && stop.name === toggle;
  const inReadingOrder = (stops: readonly FocusStop[]): void => {
    for (let index = 1; index < stops.length; index += 1) {
      const [previous, current] = [stops[index - 1], stops[index]];
      expect(current?.top ?? 0, `${current?.name ?? ''} must not precede ${previous?.name ?? ''} vertically`)
        .toBeGreaterThanOrEqual((previous?.top ?? 0) - 2);
    }
  };

  // The skip link is the first stop and leads into the editor; the surface toggle follows its tabs.
  await page.keyboard.press('Tab');
  expect((await focusStop(page)).role).toBe('link');
  await page.keyboard.press('Enter');
  await tabUntil(page, isToggle);

  // The chooser follows the toggle: the type search, its submit, the start radio group, then its actions.
  const choices = await tabStops(page, 5);
  expect(choices.map(({ role }) => role)).toEqual(['searchbox', 'button', 'radio', 'button', 'button']);
  inReadingOrder(choices);
  await expect(choices[2]?.locator ?? chooser).toHaveAttribute('value', 'blank');
  await expect(choices[2]?.locator ?? chooser).toBeChecked();
  await tabUntil(page, (stop) => stop.role === 'radio', 4, 'Shift+Tab');
  await page.keyboard.press('ArrowDown');
  const typed = await focusStop(page);
  expect(typed.role).toBe('radio');
  await expect(typed.locator).toBeChecked();
  await expect(typed.locator).not.toHaveAttribute('value', 'blank');
  await page.keyboard.press('ArrowUp');
  await expect((await focusStop(page)).locator).toHaveAttribute('value', 'blank');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  const shell = shellOf(page);
  await expect(shell).toBeVisible();
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'blank');

  // Studio replaces the chooser with the shell, and the control that held focus leaves with it: focus returns
  // to the region's heading rather than to the top of the page. The shell header then follows the toggle in
  // reading order: return, the four presentations, the three saves and the selected mode tab, each announced
  // by its catalogue name.
  await expect.poll(() => page.evaluate(() => document.activeElement?.id ?? '')).toBe('studio-authoring-title');
  const heading = await focusStop(page);
  expect(heading.role).toBe('heading');
  expect(heading.name).toBe(text('core.administrator.content_form.compose_this_item_visually'));
  await tabUntil(page, isToggle, 3);
  const header = await tabStops(page, 9);
  expect(header.slice(0, 8).map(({ name }) => name)).toEqual([
    studio('return', { destination: text('core.administrator.content_form.studio_return_destination') }),
    studio('presentation-inline'),
    studio('presentation-minimized'),
    studio('presentation-maximized'),
    studio('presentation-fullscreen'),
    studio('save-item'),
    studio('save-new-type-version'),
    studio('save-as-new-type'),
  ]);
  expect(header[8]?.role).toBe('tab');
  await expect(header[8]?.locator ?? shell).toHaveAttribute('aria-selected', 'true');
  inReadingOrder(header);

  // Presentation by keyboard: the maximized state and back, keeping the start and the session.
  await tabUntil(page, (stop) => stop.name === studio('presentation-maximized'), 8, 'Shift+Tab');
  await page.keyboard.press('Enter');
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-presentation', 'maximized');
  await tabUntil(page, (stop) => stop.name === studio('presentation-inline'), 12, 'Shift+Tab');
  await page.keyboard.press('Enter');
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-presentation', 'inline');
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'blank');

  // Modes by arrow keys, then a typed field defined without a pointer.
  await tabUntil(page, (stop) => stop.role === 'tab', 12);
  for (const key of ['ArrowLeft', 'ArrowLeft']) await page.keyboard.press(key);
  const model = await focusStop(page);
  expect(model.name).toBe(studio('mode-model'));
  await expect(model.locator).toHaveAttribute('aria-selected', 'true');
  await tabUntil(page, (stop) => stop.name === studio('field-identifier'), 20);
  await page.keyboard.type('caption');
  await tabUntil(page, (stop) => stop.name === studio('field-label'), 4);
  await page.keyboard.type('Caption');
  await tabUntil(page, (stop) => stop.name === studio('add-field'), 12);
  await page.keyboard.press('Enter');
  await expect(shell.locator('li[data-field-path="caption"]')).toBeVisible();
  await expect(shell.locator('.dirty-summary')).toHaveAttribute('data-dirty', 'true');

  // A block inserted from the palette with Enter: the explicit, non-drag insertion path.
  await tabUntil(page, (stop) => stop.role === 'tab', 40, 'Shift+Tab');
  await page.keyboard.press('ArrowRight');
  const blueprint = await focusStop(page);
  expect(blueprint.name).toBe(studio('mode-blueprint'));
  await expect(blueprint.locator).toHaveAttribute('aria-selected', 'true');
  const inPalette = (stop: FocusStop): Promise<boolean> =>
    stop.locator.evaluate((element) => element.closest('ul.palette') !== null);
  await tabUntil(page, inPalette, 40);
  await page.keyboard.press('Enter');
  await expect.poll(() => rootTypes(shell)).toHaveLength(1);
  // The App's canonical empty-section pattern is offered beside the blocks, named in the interface locale, and
  // applies from the keyboard too.
  const pattern = text('core.administrator.content_form.studio_pattern_empty_section');
  await tabUntil(page, (stop) => stop.name === pattern, 80);
  await page.keyboard.press('Enter');
  await expect.poll(() => rootTypes(shell)).toContain('studio.core/section');
  await expectAccessible(page);

  // Touch: on a touch-capable device the same explicit controls answer a tap.
  if (test.info().project.use.hasTouch === true) {
    await shell.getByRole('tab', { name: studio('mode-content') }).tap();
    await expect(shell.getByRole('tab', { name: studio('mode-content') })).toHaveAttribute('aria-selected', 'true');
    await shell.getByRole('button', { name: studio('presentation-maximized'), exact: true }).tap();
    await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-presentation', 'maximized');
    await shell.getByRole('button', { name: studio('presentation-inline'), exact: true }).tap();
    await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-presentation', 'inline');
  }

  // Reflow: at 320 CSS pixels the editor and the shell still fit the width without horizontal scrolling.
  await page.setViewportSize({ width: 320, height: 720 });
  await expect(shell).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expect(shell.locator('.contextual-workspace')).toHaveAttribute('data-start', 'blank');
  await expect(shell.locator('.dirty-summary')).toHaveAttribute('data-dirty', 'true');
});
