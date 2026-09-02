import AxeBuilder from '@axe-core/playwright';
import { expect, test, type FrameLocator, type Locator, type Page } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import { gotoAfterRuntimeConvergence } from './support/runtime-convergence';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL
  ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';

interface PreviewTraffic {
  documents: string[];
  portSequences: string[];
}

interface StudioTestNode {
  id: string;
  slots: Record<string, StudioTestNode[]>;
}

interface PreviewRect {
  height: number;
  width: number;
  x: number;
  y: number;
}

type PreviewPointAnchor = 'start' | 'center' | 'end';

async function revealPreviewCanvasRegions(page: Page, shell: Locator, regions: Locator[]): Promise<void> {
  const nodeIds = await Promise.all(regions.map((region) => region.getAttribute('data-node-id')));
  expect(nodeIds.every((nodeId) => nodeId !== null), 'Every preview region must identify its node.')
    .toBe(true);
  const stage = shell.locator('.preview-stage');
  await stage.evaluate((element) => {
    element.scrollIntoView({ block: 'center', inline: 'nearest' });
  });

  const coordinates = await Promise.all(regions.map((region) => region.evaluate((element) => {
    const left = Number(element.getAttribute('x'));
    const top = Number(element.getAttribute('y'));
    return {
      bottom: top + Number(element.getAttribute('height')),
      left,
      right: left + Number(element.getAttribute('width')),
      top,
    };
  })));
  const commonLeft = Math.max(...coordinates.map(({ left }) => left));
  const commonRight = Math.min(...coordinates.map(({ right }) => right));
  expect(commonRight - commonLeft, 'Every drag endpoint must share a visible horizontal canvas band.')
    .toBeGreaterThan(4);
  await stage.evaluate((element, inlineCenter) => {
    const stageElement = element as HTMLElement;
    const maximum = Math.max(0, stageElement.scrollWidth - stageElement.clientWidth);
    const left = Math.max(0, Math.min(maximum, inlineCenter - stageElement.clientWidth / 2));
    stageElement.scrollTo({ behavior: 'auto', left });
  }, commonLeft + (commonRight - commonLeft) / 2);

  const frame = page.locator('iframe[data-studio-preview]');
  const frameHeight = await frame.evaluate((element) =>
    (element as HTMLIFrameElement).contentWindow?.innerHeight ?? 0);
  expect(frameHeight, 'The active preview document must expose a viewport.').toBeGreaterThan(4);
  const unionTop = Math.min(...coordinates.map(({ top }) => top));
  const unionBottom = Math.max(...coordinates.map(({ bottom }) => bottom));
  const scrollDelta = unionTop < 0
    ? unionTop
    : unionBottom > frameHeight
      ? unionBottom - frameHeight
      : 0;
  if (scrollDelta !== 0) {
    await frame.evaluate((element, delta) => {
      (element as HTMLIFrameElement).contentWindow?.scrollBy({ behavior: 'auto', top: delta });
    }, scrollDelta);
    await shell.evaluate((element) => {
      (element as HTMLElement & { refreshPreviewGeometry(): void }).refreshPreviewGeometry();
    });
  }

  await expect.poll(async () => {
    const stageBox = await stage.boundingBox();
    if (stageBox === null) return 0;
    const viewport = page.viewportSize() ?? await page.evaluate(() => ({
      height: window.innerHeight,
      width: window.innerWidth,
    }));
    const visibleExtents = await Promise.all(regions.map(async (region) => {
      const box = await region.boundingBox();
      if (box === null) return 0;
      const left = Math.max(box.x, stageBox.x, 0);
      const right = Math.min(box.x + box.width, stageBox.x + stageBox.width, viewport.width);
      const top = Math.max(box.y, stageBox.y, 0);
      const bottom = Math.min(box.y + box.height, stageBox.y + stageBox.height, viewport.height);
      return Math.min(right - left, bottom - top);
    }));
    return Math.min(...visibleExtents);
  }, { message: 'Every drag endpoint must be materially visible in the preview canvas.' })
    .toBeGreaterThan(4);
}

async function visiblePreviewCanvasPoint(
  page: Page,
  shell: Locator,
  region: Locator,
  verticalAnchor: PreviewPointAnchor = 'center',
): Promise<{ x: number; y: number }> {
  const [regionBox, stageBox] = await Promise.all([
    region.boundingBox(),
    shell.locator('.preview-stage').boundingBox(),
  ]);
  expect(regionBox, 'The preview region must have measurable geometry.').not.toBeNull();
  expect(stageBox, 'The preview stage must have measurable geometry.').not.toBeNull();
  if (regionBox === null || stageBox === null) {
    throw new Error('The preview canvas geometry was unavailable.');
  }

  const viewport = page.viewportSize() ?? await page.evaluate(() => ({
    height: window.innerHeight,
    width: window.innerWidth,
  }));
  const left = Math.max(regionBox.x, stageBox.x, 0);
  const right = Math.min(
    regionBox.x + regionBox.width,
    stageBox.x + stageBox.width,
    viewport.width,
  );
  const top = Math.max(regionBox.y, stageBox.y, 0);
  const bottom = Math.min(
    regionBox.y + regionBox.height,
    stageBox.y + stageBox.height,
    viewport.height,
  );
  const visibleWidth = right - left;
  const visibleHeight = bottom - top;
  expect(visibleWidth, 'The preview region must intersect the visible canvas horizontally.')
    .toBeGreaterThan(4);
  expect(visibleHeight, 'The preview region must intersect the visible canvas vertically.')
    .toBeGreaterThan(4);

  const edgeInset = Math.min(2, (visibleHeight - 1) / 2);
  const y = verticalAnchor === 'start'
    ? top + edgeInset
    : verticalAnchor === 'end'
      ? bottom - edgeInset
      : top + visibleHeight / 2;

  return { x: left + visibleWidth / 2, y };
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

async function openComposition(page: Page, modelHandle?: string): Promise<Locator> {
  await signIn(page);
  await page.goto('/administrator/content-models');
  const model = modelHandle === undefined
    ? page.locator('[data-content-type-id][data-content-type-version]').first()
    : page.locator(`#content-type-${modelHandle}`);
  if (await model.getAttribute('open') === null) {
    await model.locator('summary').first().click();
  }
  const modelId = await model.getAttribute('data-content-type-id');
  const modelVersion = await model.getAttribute('data-content-type-version');
  expect(modelId).not.toBeNull();
  expect(modelVersion).not.toBeNull();
  await page.goto(`/administrator/content-models/${modelId}/versions/${modelVersion}/composition`);
  const provision = page.getByRole('button', { name: 'Create composition' });
  if (await provision.isVisible()) {
    await provision.click();
    await expect(page).toHaveURL(/\/administrator\/content-models\/[^/]+\/versions\/[1-9][0-9]*\/composition$/u);
  }
  const shell = page.locator('kumwe-studio');
  await expect(shell.getByRole('complementary', { name: 'Block palette' })
    .getByRole('button', { name: 'Section', exact: true })).toBeVisible();
  await expect(page.locator('[data-studio-composition-status]')).toHaveText('Studio is ready.');
  return shell;
}

function waitForCompositionReloadReady(page: Page, lifecycleName: string): Promise<Locator> {
  return (async () => {
    const response = await page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return url.pathname === '/administrator/studio/session'
        && candidate.request().method() === 'POST';
    });
    expect(response.status()).toBe(201);
    const shell = page.locator('kumwe-studio');
    await expect(shell.getByRole('complementary', { name: 'Block palette' })
      .getByRole('button', { name: 'Section', exact: true })).toBeVisible();
    await expect(page.locator('[data-studio-composition-status]')).toHaveText('Studio is ready.');
    const lifecycle = page.getByRole('button', { name: lifecycleName });
    await expect(lifecycle).toBeVisible();
    await expect(lifecycle).toBeEnabled();
    return shell;
  })();
}

async function persistCompositionChange<T>(page: Page, change: () => Promise<T>): Promise<T> {
  const accepted = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === '/administrator/studio/ports/artifact/save'
      && response.request().method() === 'POST';
  });
  const [response, result] = await Promise.all([accepted, change()]);
  expect(response.status()).toBe(200);
  await expect(page.locator('[data-studio-composition-status]')).toHaveText('Composition saved.');
  return result;
}

async function changeCompositionLifecycle(
  page: Page,
  target: 'draft' | 'published',
): Promise<void> {
  const operation = target === 'published' ? 'publish' : 'unpublish';
  const button = page.getByRole('button', {
    name: target === 'published' ? 'Publish composition' : 'Return composition to draft',
  });
  const accepted = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === `/administrator/studio/ports/artifact/${operation}`
      && response.request().method() === 'POST';
  });
  await button.click();
  const response = await accepted;
  expect(response.status()).toBe(200);
  const nextButton = page.getByRole('button', {
    name: target === 'published' ? 'Return composition to draft' : 'Publish composition',
  });
  await expect(page.locator('[data-studio-composition-status]')).toHaveText('Studio is ready.');
  await expect(nextButton).toBeVisible();
  await expect(nextButton).toBeEnabled();
}

async function openDraftComposition(page: Page, modelHandle?: string): Promise<Locator> {
  await openComposition(page, modelHandle);
  if (await page.getByRole('button', { name: 'Return composition to draft' }).isVisible()) {
    await changeCompositionLifecycle(page, 'draft');
  }
  const shell = page.locator('kumwe-studio');
  await expect(page.getByRole('button', { name: 'Publish composition' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Publish composition' })).toBeEnabled();
  return shell;
}

async function setManifestSixActive(page: Page, active: boolean): Promise<void> {
  await gotoAfterRuntimeConvergence(
    page,
    '/administrator/extensions',
    'The manifest-six extension listing',
  );
  let extension = page.locator('article').filter({
    hasText: 'kumwe/contract-manifest-six',
  }).first();
  await expect(extension).toBeVisible();
  const action = extension.getByRole('button', { name: active ? 'Activate' : 'Disable' });
  if (await action.count()) {
    await action.click();
    await expect(page).toHaveURL(/\/administrator\/extensions$/u);
  }

  await gotoAfterRuntimeConvergence(
    page,
    `/administrator/extensions?studio-runtime=${Date.now()}`,
    'The manifest-six extension listing',
  );
  extension = page.locator('article').filter({
    hasText: 'kumwe/contract-manifest-six',
  }).first();
  await expect(extension).toContainText(
    new RegExp(`component · 1\\.0\\.0 · ${active ? 'active' : 'disabled'}`, 'iu'),
  );
}

function observePreviewTraffic(page: Page): PreviewTraffic {
  const traffic: PreviewTraffic = { documents: [], portSequences: [] };
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.pathname === '/administrator/studio/preview') {
      traffic.documents.push(url.toString());
    }
    if (url.pathname.startsWith('/administrator/studio/ports/preview/')) {
      traffic.portSequences.push(request.headers()['x-kumwe-studio-preview-sequence'] ?? '');
    }
  });
  return traffic;
}

async function previewFrame(page: Page): Promise<FrameLocator> {
  const iframe = page.locator('iframe[data-studio-preview]');
  await expect(iframe).toBeVisible();
  const frame = iframe.contentFrame();
  await expect(frame.locator('[data-kis-surface="core.administrator.content-editor"]')).toHaveCount(1);
  return frame;
}

async function blockPaletteButton(shell: Locator, blockType: string): Promise<Locator> {
  const index = await shell.evaluate((element, type) => {
    const studio = element as HTMLElement & {
      configuration?: { blockDefinitions: Array<{ type: string }> };
    };
    return studio.configuration?.blockDefinitions.findIndex((definition) => definition.type === type) ?? -1;
  }, blockType);
  expect(index, `Block ${blockType} must be present in the admitted authoring catalogue.`)
    .toBeGreaterThanOrEqual(0);
  return shell.getByRole('complementary', { name: 'Block palette' })
    .locator('ul.palette').first().getByRole('button').nth(index);
}

async function documentNodeIds(shell: Locator): Promise<string[]> {
  return shell.evaluate((element) => {
    const roots = (element as HTMLElement & { document?: { roots: StudioTestNode[] } }).document?.roots ?? [];
    const ids: string[] = [];
    const visit = (nodes: StudioTestNode[]): void => {
      for (const node of nodes) {
        ids.push(node.id);
        for (const children of Object.values(node.slots)) visit(children);
      }
    };
    visit(roots);
    return ids;
  });
}

async function authoredNodeState(shell: Locator, nodeId: string): Promise<{
  bindings: Record<string, unknown>;
  properties: Record<string, unknown>;
  type: string;
} | null> {
  return shell.evaluate((element, selectedId) => {
    interface AuthoredNode extends StudioTestNode {
      bindings: Record<string, unknown>;
      properties: Record<string, unknown>;
      type: string;
    }
    const roots = (element as HTMLElement & { document?: { roots: AuthoredNode[] } }).document?.roots ?? [];
    const find = (nodes: AuthoredNode[]): AuthoredNode | undefined => {
      for (const node of nodes) {
        if (node.id === selectedId) return node;
        for (const children of Object.values(node.slots)) {
          const found = find(children as AuthoredNode[]);
          if (found !== undefined) return found;
        }
      }
      return undefined;
    };
    const node = find(roots);
    return node === undefined
      ? null
      : { bindings: node.bindings, properties: node.properties, type: node.type };
  }, nodeId);
}

async function insertRoot(shell: Locator, label: string, exactType?: string): Promise<string> {
  const coreTypes: Record<string, string> = {
    Columns: 'studio.core/columns',
    Grid: 'studio.core/grid',
    Section: 'studio.core/section',
    Stack: 'studio.core/stack',
  };
  const type = exactType ?? coreTypes[label];
  const before = new Set(await documentNodeIds(shell));
  if (type === undefined) {
    await shell.getByRole('complementary', { name: 'Block palette' })
      .getByRole('button', { name: label, exact: true }).click();
  } else {
    const button = await blockPaletteButton(shell, type);
    await expect(button).toHaveText(label);
    await button.click();
  }
  await expect.poll(async () => (await documentNodeIds(shell)).find((id) => !before.has(id)) ?? null)
    .not.toBeNull();
  const inserted = (await documentNodeIds(shell)).find((id) => !before.has(id));
  if (inserted === undefined) throw new Error(`Block ${type ?? label} was not inserted.`);
  return inserted;
}

async function outlineEntries(shell: Locator, label: string): Promise<Locator> {
  const entries = shell.getByRole('complementary', { name: 'Outline' })
    .locator('button.outline-entry', { hasText: label });
  await expect(entries.first()).toBeVisible();
  return entries;
}

async function moveWithKeyboardPalette(page: Page, shell: Locator, node: Locator, destinationId: string): Promise<void> {
  await node.click();
  await page.keyboard.press('Control+k');
  const filter = shell.getByRole('textbox', { name: 'Filter commands' });
  await expect(filter).toBeFocused();
  await filter.fill(destinationId);
  // The shell renders palette matches as a labelled list of command buttons;
  // slot-destination labels carry the parent node id, which the fill above
  // uses as the filter.
  const result = shell.getByRole('list', { name: 'Matching commands' }).getByRole('button').first();
  await expect(result).toContainText(destinationId);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
}

async function nodeParentId(shell: Locator, nodeId: string): Promise<string | null> {
  return shell.evaluate((element, selectedId) => {
    const roots = (element as HTMLElement & {
      document?: { roots: Array<{ id: string; slots: Record<string, unknown[]> }> };
    }).document?.roots ?? [];
    const find = (
      nodes: Array<{ id: string; slots: Record<string, unknown[]> }>,
      parent: string | null,
    ): string | null | undefined => {
      for (const node of nodes) {
        if (node.id === selectedId) return parent;
        for (const children of Object.values(node.slots)) {
          const found = find(children as Array<{ id: string; slots: Record<string, unknown[]> }>, node.id);
          if (found !== undefined) return found;
        }
      }
      return undefined;
    };
    return find(roots, null) ?? null;
  }, nodeId);
}

async function rootNodeIds(shell: Locator): Promise<string[]> {
  return shell.evaluate((element) => (element as HTMLElement & {
    document?: { roots: Array<{ id: string }> };
  }).document?.roots.map(({ id }) => id) ?? []);
}

async function clearCompositionRoots(page: Page, shell: Locator): Promise<void> {
  for (const nodeId of await rootNodeIds(shell)) {
    const entry = shell.locator(`button.outline-entry[data-node-id="${nodeId}"]`);
    await entry.click();
    const actions = entry.locator('xpath=..').getByRole('group', { name: 'Block actions' });
    await persistCompositionChange(page, () =>
      actions.getByRole('button', { name: 'Delete', exact: true }).click());
    await expect(entry).toHaveCount(0);
  }
  await expect.poll(() => rootNodeIds(shell)).toEqual([]);
}

async function childNodeIds(shell: Locator, parentId: string): Promise<string[]> {
  return shell.evaluate((element, selectedId) => {
    const roots = (element as HTMLElement & {
      document?: { roots: StudioTestNode[] };
    }).document?.roots ?? [];
    const find = (nodes: StudioTestNode[]): StudioTestNode | undefined => {
      for (const node of nodes) {
        if (node.id === selectedId) return node;
        for (const children of Object.values(node.slots)) {
          const found = find(children);
          if (found !== undefined) return found;
        }
      }
      return undefined;
    };
    const parent = find(roots);
    return parent === undefined
      ? []
      : Object.values(parent.slots).flatMap((children) =>
        children.map(({ id }) => id));
  }, parentId);
}

async function documentRoots(shell: Locator): Promise<string> {
  return shell.evaluate((element) => JSON.stringify((element as HTMLElement & {
    document?: { roots: unknown[] };
  }).document?.roots ?? []));
}

async function recordedCommands(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    (window as Window & { __appStudioCommands?: string[] }).__appStudioCommands ?? []);
}

function normalizedRects(rects: PreviewRect[]): PreviewRect[] {
  const rounded = rects.map((rect) => ({
    height: Math.round(rect.height * 10) / 10,
    width: Math.round(rect.width * 10) / 10,
    x: Math.round(rect.x * 10) / 10,
    y: Math.round(rect.y * 10) / 10,
  }));
  return rounded.sort((left, right) =>
    left.y - right.y || left.x - right.x || left.height - right.height || left.width - right.width);
}

async function previewMarkerRects(page: Page): Promise<PreviewRect[]> {
  return page.locator('iframe[data-studio-preview]').evaluate((element) => {
    const frame = element as HTMLIFrameElement;
    const document = frame.contentDocument;
    if (document === null) return [];
    const frameRect = frame.getBoundingClientRect();
    return Array.from(document.querySelectorAll<HTMLElement>('[data-studio-preview-marker]'))
      .map((marker) => {
        const rect = marker.getBoundingClientRect();
        return {
          height: rect.height,
          width: rect.width,
          x: frameRect.x + frame.clientLeft + rect.x,
          y: frameRect.y + frame.clientTop + rect.y,
        };
      });
  });
}

async function previewOverlayRects(shell: Locator): Promise<PreviewRect[]> {
  return shell.locator('.preview-canvas-region').evaluateAll((regions) => regions.map((region) => {
    const overlay = region as SVGGraphicsElement;
    const rect = overlay.getBBox();
    const matrix = overlay.getScreenCTM();
    if (matrix === null) throw new Error('The preview overlay region is not attached to a viewport.');
    // Firefox includes the selected region's painted stroke in getBoundingClientRect(), while the
    // preview marker and Studio measurement contract describe the element's layout rectangle. Map
    // the SVG fill box into viewport coordinates so every engine compares layout geometry to layout
    // geometry without weakening the two-pixel production alignment threshold.
    const corners = [
      new DOMPoint(rect.x, rect.y),
      new DOMPoint(rect.x + rect.width, rect.y),
      new DOMPoint(rect.x, rect.y + rect.height),
      new DOMPoint(rect.x + rect.width, rect.y + rect.height),
    ].map((point) => point.matrixTransform(matrix));
    const x = Math.min(...corners.map((point) => point.x));
    const y = Math.min(...corners.map((point) => point.y));
    const right = Math.max(...corners.map((point) => point.x));
    const bottom = Math.max(...corners.map((point) => point.y));
    return { height: bottom - y, width: right - x, x, y };
  }));
}

async function expectOverlayToMatchPreview(page: Page, shell: Locator): Promise<void> {
  await expect.poll(async () => {
    const [markers, overlay] = await Promise.all([
      previewMarkerRects(page),
      previewOverlayRects(shell),
    ]);
    const expected = normalizedRects(markers);
    const actual = normalizedRects(overlay);
    return expected.length === actual.length && expected.every((rect, index) => {
      const candidate = actual[index];
      return candidate !== undefined
        && Math.abs(candidate.x - rect.x) <= 2
        && Math.abs(candidate.y - rect.y) <= 2
        && Math.abs(candidate.width - rect.width) <= 2
        && Math.abs(candidate.height - rect.height) <= 2;
    });
  }).toBe(true);
}

test('AP7 composition provisions by POST and opens an exact measured preview channel', async ({ page, context }) => {
  test.setTimeout(120_000);
  const traffic = observePreviewTraffic(page);
  const themeResponses: Array<{ cacheControl: string; contentType: string; status: number }> = [];
  page.on('response', (response) => {
    if (new URL(response.url()).pathname !== '/administrator/studio/preview/styles.css') return;
    const headers = response.headers();
    themeResponses.push({
      cacheControl: headers['cache-control'] ?? '',
      contentType: headers['content-type'] ?? '',
      status: response.status(),
    });
  });
  const published = await context.newPage();
  await published.goto('/');
  const publishedAccent = await published.locator('body').evaluate((element) =>
    getComputedStyle(element).getPropertyValue('--site-accent').trim());
  expect(publishedAccent).toMatch(/^#[0-9a-f]{6}$/u);
  await published.close();
  const shell = await openDraftComposition(page);

  expect(await shell.evaluate((element) => {
    const frame = element.querySelector(':scope > iframe[slot="preview"][data-studio-preview]');
    return frame instanceof HTMLIFrameElement && frame.getAttribute('sandbox') === 'allow-same-origin';
  })).toBe(true);
  const contributionTruth = await shell.evaluate((element) => {
    const studio = element as HTMLElement & {
      configuration?: {
        blockDefinitions: Array<{
          owner: { id: string; version: string };
          label: { defaultMessage: string };
          revision: string;
          type: string;
          version: string;
        }>;
        session: { features: { customInspectors: boolean } };
      };
    };
    const boot = JSON.parse(document.querySelector('#studio-composition-boot')?.textContent ?? '{}') as {
      blockRenderers?: Record<string, string>;
      contributions?: Array<{
        kind: string;
        owner: { id: string; version: string };
        revision: string;
        type?: string;
        version: string;
      }>;
    };
    const blocks = studio.configuration?.blockDefinitions ?? [];
    const admitted = new Map((boot.contributions ?? [])
      .filter((candidate) => candidate.kind === 'block-definition' && candidate.type !== undefined)
      .map((candidate) => [candidate.type as string, candidate]));
    return {
      customInspectors: studio.configuration?.session.features.customInspectors,
      extensions: blocks.filter(({ type }) => !type.startsWith('studio.core/') && !type.startsWith('core/'))
        .map((block) => {
          const declared = admitted.get(block.type);
          return {
            exact: declared !== undefined
              && declared.version === block.version
              && declared.revision === block.revision
              && declared.owner.id === block.owner.id
              && declared.owner.version === block.owner.version,
            renderer: boot.blockRenderers?.[block.type] ?? null,
            label: block.label.defaultMessage,
            type: block.type,
          };
        }),
    };
  });
  expect(contributionTruth.customInspectors).toBe(false);
  const manifestSix = contributionTruth.extensions.find(({ type }) =>
    type === 'kumwe.contract-manifest-six/grid');
  expect(manifestSix).toEqual({
    exact: true,
    label: 'Grid',
    renderer: 'kumwe.contract-manifest-six/grid',
    type: 'kumwe.contract-manifest-six/grid',
  });
  for (const extension of contributionTruth.extensions) {
    await expect(await blockPaletteButton(shell, extension.type)).toHaveText(extension.label);
  }
  const lifecycle = await shell.evaluate((element) => {
    const studio = element as HTMLElement & {
      configuration?: { session: { sessionState: 'editable' | 'read-only' } };
    };
    const boot = JSON.parse(document.querySelector('#studio-composition-boot')?.textContent ?? '{}') as {
      status?: 'draft' | 'published' | 'retired';
    };
    return { sessionState: studio.configuration?.session.sessionState, status: boot.status };
  });
  expect(lifecycle.sessionState).toBe(lifecycle.status === 'draft' ? 'editable' : 'read-only');
  if (lifecycle.status === 'draft') {
    await expect(page.getByRole('button', { name: 'Publish composition' })).toBeVisible();
  } else if (lifecycle.status === 'published') {
    await expect(page.getByRole('button', { name: 'Return composition to draft' })).toBeVisible();
  }

  const initialFrame = await previewFrame(page);
  await expect(initialFrame.locator('link[data-studio-composition]')).toHaveCount(1);
  await expect(initialFrame.locator('style[data-studio-composition], body[style]')).toHaveCount(0);
  await expect.poll(() => initialFrame.locator('body').evaluate((element) =>
    getComputedStyle(element).getPropertyValue('--site-accent').trim())).toBe(publishedAccent);
  expect(await shell.evaluate((element) => {
    const studio = element as HTMLElement & {
      document?: { dependencyLock: { theme: { id: string; revision: string; version: string } } };
      theme?: { id: string; owner: { id: string; version: string }; revision: string; version: string };
    };
    const locked = studio.document?.dependencyLock.theme;
    const theme = studio.theme;
    return locked !== undefined && theme !== undefined
      && theme.id === locked.id
      && theme.version === locked.version
      && theme.revision === locked.revision
      && theme.owner.id === locked.id
      && theme.owner.version === locked.version;
  })).toBe(true);
  await expect.poll(() => themeResponses.length).toBeGreaterThan(0);
  expect(themeResponses.at(-1)).toEqual({
    cacheControl: 'private, no-store',
    contentType: 'text/css; charset=utf-8',
    status: 200,
  });
  await expect(shell.getByRole('complementary', { name: 'Block palette' }))
    .toHaveCSS('overflow-x', 'visible');

  const existingSections = await shell.getByRole('complementary', { name: 'Outline' })
    .locator('button.outline-entry', { hasText: 'Section' }).count();
  await insertRoot(shell, 'Section');
  const insertedSection = (await outlineEntries(shell, 'Section')).nth(existingSections);
  const insertedSectionId = await insertedSection.getAttribute('data-node-id');
  expect(insertedSectionId).toBeTruthy();
  const frame = await previewFrame(page);
  await expect.poll(() => frame.locator('[data-studio-preview-marker]').count()).toBeGreaterThan(0);
  await expect(shell.locator('.preview-canvas-overlay')).toBeVisible();
  const region = shell.locator(`.preview-canvas-region[data-node-id="${insertedSectionId ?? ''}"]`).first();
  await expect(region).toHaveCount(1);
  expect(Number(await region.getAttribute('width'))).toBeGreaterThan(0);
  expect(Number(await region.getAttribute('height'))).toBeGreaterThan(0);

  await expect.poll(() => traffic.portSequences.length).toBeGreaterThan(1);
  await expect.poll(() => traffic.documents.length).toBeGreaterThan(1);
  expect(traffic.portSequences[0]).toBe('0');
  expect(new URL(traffic.documents[0] ?? '').searchParams.get('sequence')).toBe('0');
  expect(new URL(traffic.documents[0] ?? '').searchParams.get('render')).toBeTruthy();
  expect(traffic.portSequences).toEqual(traffic.portSequences.map((_, index) => String(index)));
  expect(traffic.documents.map((url) => new URL(url).searchParams.get('sequence')))
    .toEqual(traffic.documents.map((_, index) => String(index)));

  const previewStage = shell.locator('.preview-stage');
  await expect(previewStage).toHaveAttribute('tabindex', '0');
  await previewStage.focus();
  await expect(previewStage).toBeFocused();
  await expect(shell.locator('iframe[data-studio-preview]')).toHaveAttribute('title', /\S/u);
  const scan = await new AxeBuilder({ page })
    .include('[data-kis-surface="core.administrator.studio-composition"]')
    // The scriptless preview document is a separately rendered surface. Excluding its frame from
    // Axe's context keeps this scan on the Studio shell and prevents recursive same-origin analysis.
    .exclude('iframe[data-studio-preview]')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
  await expectNoDocumentOverflow(page);
});

test('a signed field composition publishes marker-free public output and unpublishes to legacy content', async ({
  page,
  context,
}) => {
  test.setTimeout(120_000);
  const expectedHeading = 'Content systems ready for what comes next.';
  const extensionOutput = 'Contributed grid: 2 columns (expanded)';
  const shell = await openDraftComposition(page, 'page');
  await clearCompositionRoots(page, shell);
  const compositionUrl = page.url();
  const contribution = await shell.evaluate((element) => {
    const studio = element as HTMLElement & {
      configuration?: {
        blockDefinitions: Array<{
          owner: { id: string; version: string };
          revision: string;
          type: string;
          version: string;
        }>;
      };
    };
    const boot = JSON.parse(document.querySelector('#studio-composition-boot')?.textContent ?? '{}') as {
      blockRenderers?: Record<string, string>;
      contributions?: Array<{
        kind: string;
        owner: { id: string; version: string };
        revision: string;
        type?: string;
        version: string;
      }>;
    };
    const type = 'kumwe.contract-manifest-six/grid';
    const block = studio.configuration?.blockDefinitions.find((candidate) => candidate.type === type);
    const declared = boot.contributions?.find((candidate) =>
      candidate.kind === 'block-definition' && candidate.type === type);
    return block === undefined || declared === undefined
      ? null
      : {
          exact: declared.version === block.version
            && declared.revision === block.revision
            && declared.owner.id === block.owner.id
            && declared.owner.version === block.owner.version,
          renderer: boot.blockRenderers?.[type] ?? null,
          type: block.type,
        };
  });
  expect(contribution).toEqual({
    exact: true,
    renderer: 'kumwe.contract-manifest-six/grid',
    type: 'kumwe.contract-manifest-six/grid',
  });

  const frame = await previewFrame(page);
  await expect.poll(() => frame.locator('.studio-preview-field-text').count()).toBe(0);
  await expect.poll(() => frame.locator('.studio-preview-extension-grid').count()).toBe(0);
  const textNodeId = await persistCompositionChange(page, () =>
    insertRoot(shell, 'Text', 'core/field-text'));
  await expect(shell.locator(`button.outline-entry[data-node-id="${textNodeId}"]`)).toBeVisible();
  const inspector = shell.getByRole('complementary', { name: 'Inspector' });
  const field = inspector.getByLabel('Value', { exact: true });
  await expect(field.locator('option[value=\'["data_heading"]\']'))
    .toHaveText('Hero heading (data_heading)');
  await persistCompositionChange(page, () => field.selectOption(JSON.stringify(['data_heading'])));
  expect(await authoredNodeState(shell, textNodeId)).toMatchObject({
    bindings: {
      value: {
        source: { fieldPath: ['data_heading'], kind: 'entry-field' },
      },
    },
    type: 'core/field-text',
  });

  const extensionNodeId = await persistCompositionChange(page, () =>
    insertRoot(shell, 'Grid', 'kumwe.contract-manifest-six/grid'));
  await expect(shell.locator(`button.outline-entry[data-node-id="${extensionNodeId}"]`)).toBeVisible();
  await inspector.getByLabel('New property name').fill('columns');
  await inspector.getByLabel('New property value as JSON').fill('2');
  await persistCompositionChange(page, () =>
    inspector.getByRole('button', { name: 'Add property' }).click());
  await inspector.getByLabel('New property name').fill('collapse');
  await inspector.getByLabel('New property value as JSON').fill('"stack"');
  await persistCompositionChange(page, () =>
    inspector.getByRole('button', { name: 'Add property' }).click());
  expect(await authoredNodeState(shell, extensionNodeId)).toEqual({
    bindings: {},
    properties: { collapse: 'stack', columns: 2 },
    type: 'kumwe.contract-manifest-six/grid',
  });

  await expect.poll(() => frame.locator('.studio-preview-field-text').count()).toBe(1);
  await expect.poll(() => frame.locator('.studio-preview-extension-grid').count()).toBe(1);
  await expect(frame.locator('.studio-preview-extension-grid', { hasText: extensionOutput }).last())
    .toBeVisible();
  await expect(frame.locator('[data-studio-preview-marker]')).not.toHaveCount(0);

  const publicPage = await context.newPage();
  const extensionPage = await context.newPage();
  try {
    await changeCompositionLifecycle(page, 'published');
    const published = await publicPage.goto(`/?studio-journey=published-${Date.now()}`);
    expect(published?.status()).toBe(200);
    expect(new URL(publicPage.url()).pathname).toBe('/');
    await expect(publicPage.locator('[data-kis-surface="core.public.home"]')).toBeVisible();
    await expect(publicPage.locator('.studio-preview-field-text', { hasText: expectedHeading }).last())
      .toBeVisible();
    await expect(publicPage.locator('.studio-preview-extension-grid', { hasText: extensionOutput }).last())
      .toBeVisible();
    await expect(publicPage.locator('[data-studio-preview-marker], iframe[data-studio-preview]'))
      .toHaveCount(0);

    await setManifestSixActive(extensionPage, false);
    // Firefox deliberately refuses to commit an HTTP 500 navigation and reports
    // NS_ERROR_NET_ERROR_RESPONSE instead of returning Playwright's Response wrapper. Probe the
    // same public representation through the browser context's request client so the assertion
    // remains on the server's exact status and bytes rather than on engine-specific error UI.
    const refused = await context.request.get(`/?studio-journey=withdrawn-${Date.now()}`, {
      failOnStatusCode: false,
    });
    try {
      expect(refused.status()).toBe(500);
      const refusedMarkup = await refused.text();
      expect(refusedMarkup).not.toContain(expectedHeading);
      expect(refusedMarkup).not.toContain(extensionOutput);
      expect(refusedMarkup).not.toMatch(
        /data-studio-preview-marker|studio-preview-field-text|studio-preview-extension-grid|managed-hero/u,
      );
    } finally {
      await refused.dispose();
    }

    await setManifestSixActive(extensionPage, true);
    await expect.poll(async () => {
      const recovered = await publicPage.goto(`/?studio-journey=recovered-${Date.now()}`);
      return recovered?.status();
    }, {
      message: 'the reactivated signed renderer to recover published output',
      timeout: 25_000,
    }).toBe(200);
    await expect(publicPage.locator('.studio-preview-extension-grid', { hasText: extensionOutput }).last())
      .toBeVisible();
  } finally {
    try {
      if (!extensionPage.isClosed()) {
        await setManifestSixActive(extensionPage, true);
      }
    } finally {
      try {
        if (!page.isClosed()) {
          await page.goto(compositionUrl);
          await expect(page.locator('[data-studio-composition-status]')).toHaveText('Studio is ready.');
          if (await page.getByRole('button', { name: 'Return composition to draft' }).isVisible()) {
            await changeCompositionLifecycle(page, 'draft');
          }
          await clearCompositionRoots(page, page.locator('kumwe-studio'));
        }
      } finally {
        if (!extensionPage.isClosed()) await extensionPage.close();
      }
    }
  }

  const legacy = await publicPage.goto(`/?studio-journey=legacy-${Date.now()}`);
  expect(legacy?.status()).toBe(200);
  await expect(publicPage.locator('.studio-preview-field-text, .studio-preview-extension-grid'))
    .toHaveCount(0);
  await expect(publicPage.locator('[data-studio-preview-marker], iframe[data-studio-preview]'))
    .toHaveCount(0);
  await expect(publicPage.locator('.managed-hero h1')).toHaveText(expectedHeading);
  await publicPage.close();
});

test('draft lifecycle control sends the canonical publication envelope', async ({ page }) => {
  let publication: { arguments?: unknown; context?: Record<string, unknown> } | undefined;
  await page.route('**/administrator/studio/ports/artifact/publish', async (route) => {
    publication = route.request().postDataJSON() as typeof publication;
    await route.fulfill({
      body: JSON.stringify({ value: null, revision: 'browser-published-r1' }),
      contentType: 'application/json',
      status: 200,
    });
  });
  await openDraftComposition(page);
  const boot = await page.locator('#studio-composition-boot').evaluate((element) =>
    JSON.parse(element.textContent ?? '{}') as {
      artifact: { id: string; revision: string; version: string };
      status: string;
    });
  expect(boot.status).toBe('draft');
  const button = page.getByRole('button', { name: 'Publish composition' });
  await expect(button).toBeVisible();

  await button.click();
  await expect.poll(() => publication).toBeDefined();

  expect(publication?.arguments).toEqual({ reference: boot.artifact });
  expect(publication?.context).toMatchObject({
    expectedRevision: boot.artifact.revision,
    operationId: 'studio.operation/artifact.publish',
    resourceContextKey: expect.stringMatching(/^contexts\//u),
    sessionGeneration: expect.stringMatching(/^session-/u),
  });
  expect(publication?.context?.idempotencyKey).toEqual(expect.stringMatching(/^operations\/browser-/u));
  expect(publication?.context?.requestId).toEqual(expect.stringMatching(/^requests\/browser-/u));
});

test('private target authority hides and refuses publication despite the shared protocol permission', async ({ page }) => {
  await openDraftComposition(page);
  await page.route('**/administrator/studio/session', async (route) => {
    const response = await route.fetch();
    const document = await response.json() as Record<string, unknown>;
    await route.fulfill({
      response,
      json: { ...document, lifecycle: { canPublish: false, canUnpublish: true } },
    });
  });
  let publicationAttempts = 0;
  await page.route('**/administrator/studio/ports/artifact/publish', async (route) => {
    publicationAttempts++;
    await route.fulfill({
      body: JSON.stringify({ value: null, revision: 'browser-published-without-authority' }),
      contentType: 'application/json',
      status: 200,
    });
  });

  await page.reload();
  const shell = page.locator('kumwe-studio');
  await expect(shell.getByRole('complementary', { name: 'Block palette' })
    .getByRole('button', { name: 'Section', exact: true })).toBeVisible();
  await expect(page.locator('[data-studio-composition-status]')).toHaveText('Studio is ready.');
  const button = page.locator('[data-studio-publish]');
  await expect(button).toBeHidden();
  await button.evaluate((element) => (element as HTMLButtonElement).click());
  await page.waitForTimeout(50);

  expect(publicationAttempts).toBe(0);
  await expect(page.locator('[data-studio-composition-status]')).toHaveText('Studio is ready.');
});

test('published lifecycle control sends the symmetric canonical unpublish envelope', async ({ page }) => {
  await openComposition(page);
  if (await page.getByRole('button', { name: 'Publish composition' }).isVisible()) {
    await changeCompositionLifecycle(page, 'published');
  }
  const boot = await page.locator('#studio-composition-boot').evaluate((element) =>
    JSON.parse(element.textContent ?? '{}') as {
      artifact: { id: string; revision: string; version: string };
      status: string;
    });
  expect(boot.status).toBe('published');

  let unpublication: { arguments?: unknown; context?: Record<string, unknown> } | undefined;
  const unpublishRoute = '**/administrator/studio/ports/artifact/unpublish';
  await page.route(unpublishRoute, async (route) => {
    unpublication = route.request().postDataJSON() as typeof unpublication;
    await route.fulfill({
      body: JSON.stringify({ value: null, revision: 'browser-draft-r1' }),
      contentType: 'application/json',
      status: 200,
    });
  });
  const reloaded = waitForCompositionReloadReady(page, 'Return composition to draft');
  try {
    await page.getByRole('button', { name: 'Return composition to draft' }).click();
    await expect.poll(() => unpublication).toBeDefined();

    expect(unpublication?.arguments).toEqual({ reference: boot.artifact });
    expect(unpublication?.context).toMatchObject({
      expectedRevision: boot.artifact.revision,
      operationId: 'studio.operation/artifact.unpublish',
      resourceContextKey: expect.stringMatching(/^contexts\//u),
      sessionGeneration: expect.stringMatching(/^session-/u),
    });
    expect(unpublication?.context?.idempotencyKey).toEqual(expect.stringMatching(/^operations\/browser-/u));
    expect(unpublication?.context?.requestId).toEqual(expect.stringMatching(/^requests\/browser-/u));
    await reloaded;
  } finally {
    await page.unroute(unpublishRoute);
    if (!page.isClosed()) {
      await reloaded;
      await changeCompositionLifecycle(page, 'draft');
    }
  }
});

test('measured canvas select, reorder and reparent have keyboard parity', async ({ page }) => {
  const shell = await openDraftComposition(page);
  await shell.evaluate((element) => {
    (window as Window & { __appStudioCommands?: string[] }).__appStudioCommands = [];
    element.addEventListener('studio-document-change', (event) => {
      const command = (event as CustomEvent<{ command: { type: string } | null }>).detail.command;
      if (command !== null) {
        (window as Window & { __appStudioCommands?: string[] }).__appStudioCommands?.push(command.type);
      }
    });
  });

  const existingSections = await shell.getByRole('complementary', { name: 'Outline' })
    .locator('button.outline-entry', { hasText: 'Section' }).count();
  await insertRoot(shell, 'Section');
  await shell.evaluate((element) => {
    (element as HTMLElement & { selectNode(nodeId: string | undefined): void }).selectNode(undefined);
  });
  await insertRoot(shell, 'Section');
  const sections = await outlineEntries(shell, 'Section');
  const firstSection = sections.nth(existingSections);
  const secondSection = sections.nth(existingSections + 1);
  const firstSectionId = await firstSection.getAttribute('data-node-id');
  const secondSectionId = await secondSection.getAttribute('data-node-id');
  expect(firstSectionId).toBeTruthy();
  expect(secondSectionId).toBeTruthy();

  await firstSection.click();
  const existingStacks = await shell.getByRole('complementary', { name: 'Outline' })
    .locator('button.outline-entry', { hasText: 'Stack' }).count();
  await insertRoot(shell, 'Stack');
  const stack = (await outlineEntries(shell, 'Stack')).nth(existingStacks);
  const stackId = await stack.getAttribute('data-node-id');
  expect(stackId).toBeTruthy();
  await secondSection.click();
  const existingGrids = await shell.getByRole('complementary', { name: 'Outline' })
    .locator('button.outline-entry', { hasText: 'Grid' }).count();
  await insertRoot(shell, 'Grid');
  const grid = (await outlineEntries(shell, 'Grid')).nth(existingGrids);
  const gridId = await grid.getAttribute('data-node-id');
  expect(gridId).toBeTruthy();
  // Host-owned insertion selects the admitted child. Select the container again so Studio's
  // selected-region hit priority can expose the overlapping parent marker for root reordering.
  await secondSection.click();
  await expect(secondSection).toHaveAttribute('aria-pressed', 'true');

  await previewFrame(page);
  await expect(shell.locator(`.preview-canvas-region[data-node-id="${stackId ?? ''}"]`)).toHaveCount(1);
  await expect(shell.locator(`.preview-canvas-region[data-node-id="${gridId ?? ''}"]`)).toHaveCount(1);
  const editToggle = shell.getByRole('button', { name: 'Select and move rendered blocks' });
  await editToggle.click();

  const firstSectionRegion = shell
    .locator(`.preview-canvas-region[data-node-id="${firstSectionId ?? ''}"]`).first();
  const secondSectionRegion = shell
    .locator(`.preview-canvas-region[data-node-id="${secondSectionId ?? ''}"]`).first();
  await expect(secondSectionRegion).toHaveAttribute('data-selected', 'true');
  const rootsBeforeReorder = await rootNodeIds(shell);
  expect(rootsBeforeReorder.indexOf(firstSectionId ?? '')).toBeLessThan(
    rootsBeforeReorder.indexOf(secondSectionId ?? ''),
  );
  // Raw page.mouse coordinates do not auto-scroll like locator actions. SVG region boxes describe
  // the full responsive canvas, which can be wider than the preview-stage clipping viewport, so
  // target the visible region/stage/viewport intersection rather than the full box centre.
  await revealPreviewCanvasRegions(page, shell, [firstSectionRegion, secondSectionRegion]);
  const secondSectionDragPoint = await visiblePreviewCanvasPoint(
    page,
    shell,
    secondSectionRegion,
    'start',
  );
  const firstSectionDropPoint = await visiblePreviewCanvasPoint(
    page,
    shell,
    firstSectionRegion,
    'start',
  );

  await page.mouse.move(secondSectionDragPoint.x, secondSectionDragPoint.y);
  await page.mouse.down();
  await page.mouse.move(firstSectionDropPoint.x, firstSectionDropPoint.y, { steps: 8 });
  await expect(shell.locator('.preview-canvas-drop-indicator')).toBeVisible();
  await page.mouse.up();
  await expect.poll(async () => (await rootNodeIds(shell)).indexOf(secondSectionId ?? ''))
    .toBeLessThan((await rootNodeIds(shell)).indexOf(firstSectionId ?? ''));
  // The published non-drag-move-equivalence conformance vector binds every
  // input modality to one canonical studio.command/move-node dispatch.
  await expect.poll(async () => (await recordedCommands(page)).at(-1))
    .toBe('studio.command/move-node');

  const source = shell.locator(`.preview-canvas-region[data-node-id="${stackId ?? ''}"]`).first();
  const gridRegion = shell.locator(`.preview-canvas-region[data-node-id="${gridId ?? ''}"]`).first();
  await expect(source).toHaveCount(1);
  await expect(gridRegion).toHaveCount(1);
  await revealPreviewCanvasRegions(page, shell, [source]);
  const selectionSourcePoint = await visiblePreviewCanvasPoint(page, shell, source);

  await page.mouse.click(selectionSourcePoint.x, selectionSourcePoint.y);
  await expect(stack).toHaveAttribute('aria-pressed', 'true');

  const rootsBeforeCancellation = await documentRoots(shell);
  const commandsBeforeCancellation = (await recordedCommands(page)).length;
  await revealPreviewCanvasRegions(page, shell, [source, gridRegion]);
  const cancellationSourcePoint = await visiblePreviewCanvasPoint(page, shell, source);
  const cancellationGridPoint = await visiblePreviewCanvasPoint(page, shell, gridRegion, 'end');
  await page.mouse.move(cancellationSourcePoint.x, cancellationSourcePoint.y);
  await page.mouse.down();
  await page.mouse.move(cancellationGridPoint.x, cancellationGridPoint.y, { steps: 8 });
  await expect(shell.locator('.preview-canvas-drop-indicator')).toBeVisible();
  await page.keyboard.press('Escape');
  await page.mouse.up();
  await expect(shell.locator('.preview-canvas-drop-indicator')).toHaveCount(0);
  await expect(shell.locator('.assistive')).toContainText('Reorder cancelled.');
  expect(await documentRoots(shell)).toBe(rootsBeforeCancellation);
  expect((await recordedCommands(page)).length).toBe(commandsBeforeCancellation);
  expect(await nodeParentId(shell, stackId ?? '')).toBe(firstSectionId);

  await revealPreviewCanvasRegions(page, shell, [source, gridRegion]);
  const sourceAfterCancellationPoint = await visiblePreviewCanvasPoint(page, shell, source);
  const gridAfterCancellationPoint = await visiblePreviewCanvasPoint(page, shell, gridRegion, 'end');
  await page.mouse.move(sourceAfterCancellationPoint.x, sourceAfterCancellationPoint.y);
  await page.mouse.down();
  await page.mouse.move(gridAfterCancellationPoint.x, gridAfterCancellationPoint.y, { steps: 8 });
  await expect(shell.locator('.preview-canvas-drop-indicator')).toBeVisible();
  await page.mouse.up();
  await expect.poll(() => nodeParentId(shell, stackId ?? '')).toBe(secondSectionId);
  expect(await recordedCommands(page)).toContain('studio.command/move-node');

  const stackAfterMove = (await outlineEntries(shell, 'Stack')).nth(existingStacks);
  await stackAfterMove.focus();
  const childrenBeforeKeyboardReorder = await childNodeIds(shell, secondSectionId ?? '');
  const stackPosition = childrenBeforeKeyboardReorder.indexOf(stackId ?? '');
  expect(stackPosition).toBeGreaterThanOrEqual(0);
  await page.keyboard.press(stackPosition === 0 ? 'Alt+ArrowDown' : 'Alt+ArrowUp');
  await expect.poll(() => childNodeIds(shell, secondSectionId ?? ''))
    .not.toEqual(childrenBeforeKeyboardReorder);
  // Within one parent the shell reorders children; the canonical move-node
  // dispatch of the non-drag-move-equivalence vector covers positional moves
  // of roots, which the drag and palette lanes above and below assert.
  await expect.poll(async () => {
    const values = await recordedCommands(page);
    return values.at(-1);
  }).toBe('studio.command/reorder-children');

  await shell.getByRole('button', { name: 'Undo' }).click();
  await shell.getByRole('button', { name: 'Undo' }).click();
  await expect.poll(() => nodeParentId(shell, stackId ?? '')).toBe(firstSectionId);
  await moveWithKeyboardPalette(
    page,
    shell,
    (await outlineEntries(shell, 'Stack')).nth(existingStacks),
    secondSectionId ?? '',
  );
  await expect.poll(() => nodeParentId(shell, stackId ?? '')).toBe(secondSectionId);
  await expect.poll(async () => {
    const values = await recordedCommands(page);
    return values.at(-1);
  }).toBe('studio.command/move-node');
});

test('same-origin preview redirects to a different path are refused', async ({ page }) => {
  const cancellations = new Map<string, number>();
  page.on('request', (request) => {
    if (!new URL(request.url()).pathname.endsWith('/ports/preview/cancel')) return;
    const body = request.postDataJSON() as { arguments?: { draftDigest?: string } };
    const digest = body.arguments?.draftDigest ?? 'unknown';
    cancellations.set(digest, (cancellations.get(digest) ?? 0) + 1);
  });
  await page.route('**/administrator/studio/wrong-preview', async (route) => {
    await route.fulfill({
      body: '<!doctype html><html><body><main data-kis-surface="core.administrator.content-editor"></main></body></html>',
      contentType: 'text/html',
      status: 200,
    });
  });
  await page.route('**/administrator/studio/preview?*', async (route) => {
    await route.continue({
      headers: {
        ...route.request().headers(),
        'x-kumwe-browser-preview-redirect': 'different-path',
      },
    });
  });
  const cancellationAccepted = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith('/ports/preview/cancel')
      && response.request().method() === 'POST';
  });

  const shell = await openComposition(page);
  const cancellationResponse = await cancellationAccepted;
  expect(cancellationResponse.status()).toBe(200);
  const cancellationBody = cancellationResponse.request().postDataJSON() as {
    arguments?: { draftDigest?: string };
  };
  const cancelledDigest = cancellationBody.arguments?.draftDigest ?? 'unknown';
  const iframe = page.locator('iframe[data-studio-preview]');
  await expect(iframe).toBeHidden();
  await expect.poll(() => iframe.evaluate((element) =>
    (element as HTMLIFrameElement).contentWindow?.location.pathname ?? ''))
    .not.toBe('/administrator/studio/wrong-preview');
  await expect(shell.getByText('Preview is stale. Editing remains available.')).toBeVisible();
  await expect(shell.locator('.preview-canvas-overlay')).toHaveCount(0);
  await expect.poll(() => cancellations.get(cancelledDigest) ?? 0).toBe(1);
  expect([...cancellations.values()]).toEqual([...cancellations.values()].map(() => 1));
});

test('a deterministically delayed conflicting save replaces the mutable shell and cannot stage', async ({ page, context }) => {
  const firstShell = await openDraftComposition(page);
  await previewFrame(page);
  const compositionUrl = page.url();
  const competingPage = await context.newPage();
  const competingTraffic = observePreviewTraffic(competingPage);
  let releaseSave = (): void => undefined;
  let markSaveStarted = (): void => undefined;
  const saveRelease = new Promise<void>((resolve) => { releaseSave = resolve; });
  const saveStarted = new Promise<void>((resolve) => { markSaveStarted = resolve; });
  await competingPage.route('**/administrator/studio/ports/artifact/save', async (route) => {
    markSaveStarted();
    await saveRelease;
    await route.continue();
  }, { times: 1 });
  await competingPage.goto(compositionUrl);
  const competingShell = competingPage.locator('kumwe-studio');
  await expect(competingShell.getByRole('complementary', { name: 'Block palette' })
    .getByRole('button', { name: 'Section', exact: true })).toBeVisible();
  await previewFrame(competingPage);
  await expect(competingShell.getByText('Preview is current.')).toBeVisible();
  const acceptedDocuments = competingTraffic.documents.length;

  await insertRoot(competingShell, 'Stack');
  await saveStarted;
  await insertRoot(firstShell, 'Section');
  await expect(page.locator('[data-studio-composition-status]')).toHaveText('Composition saved.');
  releaseSave();
  await expect(competingPage.locator('[data-studio-composition-status]'))
    .toHaveText('Another session saved this composition. Reload before making more changes.');
  await expect(competingPage.locator('kumwe-studio')).toHaveCount(0);
  await expect(competingPage.locator('[data-studio-composition-conflict]'))
    .toHaveText('Another session saved this composition. Reload before making more changes.');
  await expect(competingPage.getByRole('button', { name: 'Section', exact: true })).toHaveCount(0);
  await competingPage.waitForTimeout(750);
  expect(competingTraffic.documents).toHaveLength(acceptedDocuments);
});

test('rapid superseded preview navigations stay hidden, ordered and cancel each digest once', async ({ page }) => {
  const traffic = observePreviewTraffic(page);
  const cancellations = new Map<string, number>();
  let releaseFirstDocument = (): void => undefined;
  let markFirstDocumentClaimed = (): void => undefined;
  const firstDocumentRelease = new Promise<void>((resolve) => { releaseFirstDocument = resolve; });
  const firstDocumentClaimed = new Promise<void>((resolve) => { markFirstDocumentClaimed = resolve; });
  let documentCount = 0;
  page.on('request', (request) => {
    if (!new URL(request.url()).pathname.endsWith('/ports/preview/cancel')) return;
    const body = request.postDataJSON() as { arguments?: { draftDigest?: string } };
    const digest = body.arguments?.draftDigest ?? 'unknown';
    cancellations.set(digest, (cancellations.get(digest) ?? 0) + 1);
  });
  await page.route('**/administrator/studio/preview?*', async (route) => {
    const response = await route.fetch();
    documentCount += 1;
    if (documentCount === 1) {
      markFirstDocumentClaimed();
      await firstDocumentRelease;
    }
    await route.fulfill({ response });
  });

  const shell = await openDraftComposition(page);
  await firstDocumentClaimed;
  const active = page.locator('iframe[data-studio-preview]');
  await expect(active).toBeHidden();
  await insertRoot(shell, 'Section');
  await insertRoot(shell, 'Stack');
  await expect(active).toBeHidden();
  releaseFirstDocument();

  await previewFrame(page);
  await expect.poll(() => traffic.documents.length).toBeGreaterThan(1);
  expect(traffic.documents.map((url) => new URL(url).searchParams.get('sequence')))
    .toEqual(traffic.documents.map((_, index) => String(index)));
  const activeRender = await active.evaluate((element) =>
    new URLSearchParams((element as HTMLIFrameElement).contentWindow?.location.search ?? '').get('render'));
  expect(activeRender).toBe(new URL(traffic.documents.at(-1) ?? '').searchParams.get('render'));
  expect([...cancellations.values()]).toEqual([...cancellations.values()].map(() => 1));
  await expect(page.locator('iframe:not([data-studio-preview])')).toHaveCount(0);
});

test('an in-flight render is cancelled concurrently and repeated same-digest cycles stay independent', async ({ page }) => {
  test.setTimeout(60_000);
  interface Gate {
    release(): void;
    released: Promise<void>;
    start(): void;
    started: Promise<void>;
  }
  const makeGate = (): Gate => {
    let release = (): void => undefined;
    let start = (): void => undefined;
    return {
      release: () => release(),
      released: new Promise<void>((resolve) => { release = resolve; }),
      start: () => start(),
      started: new Promise<void>((resolve) => { start = resolve; }),
    };
  };
  const renderDigests: string[] = [];
  const cancelledDigests: string[] = [];
  const firstGate = makeGate();
  let gate: Gate | undefined = firstGate;
  let cancellationGate: Gate | undefined;
  await page.route('**/administrator/studio/ports/preview/render', async (route) => {
    const body = route.request().postDataJSON() as {
      arguments?: { payload?: { draftDigest?: string } };
    };
    renderDigests.push(body.arguments?.payload?.draftDigest ?? 'unknown');
    const response = await route.fetch();
    const held = gate;
    gate = undefined;
    if (held !== undefined) {
      held.start();
      await held.released;
    }
    await route.fulfill({ response });
  });
  page.on('request', (request) => {
    if (!new URL(request.url()).pathname.endsWith('/ports/preview/cancel')) return;
    const body = request.postDataJSON() as { arguments?: { draftDigest?: string } };
    cancelledDigests.push(body.arguments?.draftDigest ?? 'unknown');
  });
  await page.route('**/administrator/studio/ports/preview/cancel', async (route) => {
    const response = await route.fetch();
    const held = cancellationGate;
    cancellationGate = undefined;
    if (held !== undefined) {
      held.start();
      await held.released;
    }
    await route.fulfill({ response });
  });

  const shell = await openDraftComposition(page);
  await firstGate.started;
  await insertRoot(shell, 'Section');
  await expect.poll(() => cancelledDigests.length).toBeGreaterThan(0);
  firstGate.release();
  await previewFrame(page);

  const exerciseSameDigestCancellation = async (from: string, to: string): Promise<string> => {
    const cycle = makeGate();
    const cancellation = makeGate();
    gate = cycle;
    await shell.getByRole('button', { name: from, exact: true }).click();
    await cycle.started;
    cancellationGate = cancellation;
    const digest = renderDigests.at(-1) ?? 'unknown';
    const renderCount = renderDigests.length;
    const before = cancelledDigests.filter((candidate) => candidate === digest).length;
    await shell.getByRole('button', { name: to, exact: true }).click();
    await cancellation.started;
    await expect.poll(() => cancelledDigests.filter((candidate) => candidate === digest).length)
      .toBeGreaterThan(before);
    cycle.release();
    await page.waitForTimeout(100);
    expect(renderDigests).toHaveLength(renderCount);
    cancellation.release();
    await expect.poll(() => renderDigests.length).toBeGreaterThan(renderCount);
    await previewFrame(page);
    return digest;
  };

  const firstDigest = await exerciseSameDigestCancellation('Compact', 'Medium');
  const secondDigest = await exerciseSameDigestCancellation('Compact', 'Expanded');
  expect(secondDigest).toBe(firstDigest);
  expect(cancelledDigests.filter((candidate) => candidate === firstDigest).length).toBeGreaterThanOrEqual(2);
});

test('an ambiguous preview transport failure terminally closes the sequenced channel', async ({ page }) => {
  const requests: Array<{ operation: string; sequence: string }> = [];
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (!url.pathname.startsWith('/administrator/studio/ports/preview/')) return;
    requests.push({
      operation: url.pathname.split('/').at(-1) ?? '',
      sequence: request.headers()['x-kumwe-studio-preview-sequence'] ?? '',
    });
  });
  await page.route('**/administrator/studio/ports/preview/render', async (route) => {
    await route.abort('connectionfailed');
  }, { times: 1 });

  const shell = await openComposition(page);
  await expect(shell.getByText('Preview is stale. Editing remains available.')).toBeVisible();
  const requestCount = requests.length;
  await shell.getByRole('button', { name: 'Compact', exact: true }).click();
  await shell.getByRole('button', { name: 'Medium', exact: true }).click();
  await page.waitForTimeout(250);

  expect(requests).toHaveLength(requestCount);
  expect(requests).toEqual([{ operation: 'render', sequence: '0' }]);
});

test('closed layout intent renders four, two and one columns without surface overflow', async ({ page }) => {
  const shell = await openDraftComposition(page);
  const existingGrids = await shell.getByRole('complementary', { name: 'Outline' })
    .locator('button.outline-entry', { hasText: 'Grid' }).count();
  const gridId = await insertRoot(shell, 'Grid');
  await expect(shell.locator(`button.outline-entry[data-node-id="${gridId}"]`))
    .toHaveAttribute('aria-pressed', 'true');
  const inspector = shell.getByRole('complementary', { name: 'Inspector' });
  const columns = inspector.getByLabel('Value of columns as JSON');
  await expect(columns).toBeVisible();
  await columns.fill('4');
  await columns.press('Enter');
  const frame = await previewFrame(page);

  const expectations = [
    ['Expanded', 4],
    ['Medium', 2],
    ['Compact', 1],
  ] as const;
  for (const [viewport, expectedColumns] of expectations) {
    await shell.getByRole('button', { name: viewport, exact: true }).click();
    await expect.poll(async () => frame.locator('.studio-preview-grid').nth(existingGrids).evaluate((element) => ({
      columns: getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/u).length,
      intent: element.getAttribute('data-studio-layout-columns'),
    }))).toEqual({ columns: expectedColumns, intent: '4' });
    await expectOverlayToMatchPreview(page, shell);
  }

  const iframe = page.locator('iframe[data-studio-preview]');
  await iframe.evaluate((element) => {
    const previewWindow = (element as HTMLIFrameElement).contentWindow;
    const previewDocument = (element as HTMLIFrameElement).contentDocument;
    if (previewWindow === null || previewDocument === null || previewDocument.body === null) return;
    previewDocument.body.style.paddingBlockStart = '37px';
    previewDocument.body.style.minBlockSize = 'calc(100vh + 300px)';
    previewDocument.body.style.minInlineSize = 'calc(100vw + 300px)';
    previewWindow.scrollTo({ left: 120, top: 120 });
  });
  await expect.poll(() => iframe.evaluate((element) =>
    (element as HTMLIFrameElement).contentWindow?.scrollY ?? 0)).toBeGreaterThan(0);
  await expect.poll(() => iframe.evaluate((element) =>
    (element as HTMLIFrameElement).contentWindow?.scrollX ?? 0)).toBeGreaterThan(0);
  await expectOverlayToMatchPreview(page, shell);
  await page.evaluate(() => {
    document.body.style.minBlockSize = 'calc(100vh + 500px)';
    window.scrollTo({ top: 240 });
  });
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  await expectOverlayToMatchPreview(page, shell);
  await expectNoDocumentOverflow(page);
});
