import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import type { KumweStudioStandaloneElement } from '@kumwe/studio';
import type { StudioHostedDeploymentConfiguration } from '@kumwe/studio-protocol';

const root = resolve(import.meta.dirname, '../..');
const manifest = JSON.parse(readFileSync(resolve(root, 'public/assets/build/.vite/manifest.json'), 'utf8')) as
  Record<string, { file: string }>;
const launch = manifest['assets/administrator/main.ts'];
if (launch === undefined) throw new Error('The production Studio launch chunk is missing.');
const browserRoot = resolve(root, 'node_modules/@kumwe/studio/dist/browser');
const browserManifest = JSON.parse(readFileSync(resolve(browserRoot, 'studio-assets.json'), 'utf8')) as {
  module: { entryPoint: string };
  release: { version: string; corpusManifestDigest: string };
};
const modulePath = '/studio-official.js';
const policy = [
  "default-src 'none'", "script-src 'self'", "style-src 'self'", "style-src-attr 'none'", "connect-src 'self'",
  "base-uri 'none'", "object-src 'none'",
].join('; ');

/** Serve the committed App launcher and official package bytes in an ordinary, backendless HTML document. */
async function openMountDocument(page: Page, configuration: unknown, status = 403): Promise<string[]> {
  const requests: string[] = [];
  const deployment = JSON.stringify(configuration).replaceAll('<', '\\u003c');
  await page.route('**/*', async (route) => {
    const path = new URL(route.request().url()).pathname;
    requests.push(path);
    if (path === '/studio-mount.html') {
      await route.fulfill({ contentType: 'text/html', headers: { 'Content-Security-Policy': policy }, body: `
        <!doctype html><html lang="en"><head><meta charset="utf-8"><title>Studio mount isolation</title></head><body>
        <div id="local" data-kumwe-studio></div>
        <div id="unrelated" data-kumwe-studio="missing-configuration"></div>
        <section data-studio-authoring-region aria-labelledby="studio-title">
          <h1 id="studio-title">Content authoring</h1>
          <p data-studio-launch-status data-studio-launch-state="pending">Loading</p>
          <button data-studio-surface-toggle hidden>Use the structured form</button>
          <div id="hosted" data-kumwe-studio="host-config" data-studio-module-url="${modulePath}"></div>
          <script id="host-config" type="application/json">${deployment}</script>
        </section>
        <form data-studio-authoring-fallback-form><label>Title<input name="title"></label></form>
        <div id="restored"></div>
        <script type="module" src="/studio-harness.js"></script></body></html>` });
    } else if (path === '/studio-harness.js') {
      await route.fulfill({ contentType: 'text/javascript', body:
        `import '/assets/build/${launch?.file}';` });
    } else if (path === modulePath) {
      await route.fulfill({ path: resolve(browserRoot, browserManifest.module.entryPoint), contentType: 'text/javascript' });
    } else if (path.startsWith('/assets/build/js/') && /^\/assets\/build\/js\/[\w.-]+\.js$/u.test(path)) {
      await route.fulfill({ path: resolve(root, `public${path}`), contentType: 'text/javascript' });
    } else if (path === '/host/resolve') {
      expect(route.request().method()).toBe('POST');
      expect(route.request().headers()['x-csrf-token']).toBe('mount-isolation-csrf');
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({
        contractVersion: '0.1-draft', kind: 'host-error',
        category: status === 401 ? 'unauthenticated' : 'forbidden',
        message: { key: 'kumwe.test/refused', defaultMessage: 'This hosted target was refused.' },
        retryable: false,
      }) });
    } else {
      throw new Error(`Unexpected mount request: ${path}`);
    }
  });
  await page.goto('/studio-mount.html');
  await expect(page.locator('[data-studio-launch-status]')).toHaveAttribute('data-studio-launch-state', 'failed');
  return requests;
}

/** Use the published deployment fixture with the installed exact release and explicit test-host transport. */
function hostedConfiguration(): StudioHostedDeploymentConfiguration {
  const configuration = JSON.parse(readFileSync(resolve(
    root, 'node_modules/@kumwe/studio-testkit/fixtures/studio-deployment.hosted.example.json',
  ), 'utf8')) as StudioHostedDeploymentConfiguration;
  configuration.mount = '#hosted';
  configuration.release = browserManifest.release;
  configuration.transport.routing = { kind: 'operation-map', endpoints: {
    'authoring/resolve-target': '/host/resolve', 'authoring/start': '/host/start',
  } };
  configuration.transport.authentication = {
    kind: 'same-origin-session', credentials: 'same-origin',
    csrf: { headerName: 'X-CSRF-Token', token: 'mount-isolation-csrf' },
  };
  return configuration;
}

for (const status of [401, 403]) {
  /** STUDIO-PROD-010/011/012/015: a configured refusal neither claims nor downgrades a neighboring local mount. */
  test(`the Content launcher isolates a configured ${status} from standalone authoring`, async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const requests = await openMountDocument(page, hostedConfiguration(), status);
    await expect(page.locator('[data-studio-launch-status]')).toHaveAttribute('data-studio-launch-state', 'failed');
    await expect(page.locator('[data-studio-authoring-fallback-form]')).toBeVisible();
    await expect(page.locator('#hosted kumwe-studio-standalone')).toHaveCount(0);
    await expect(page.locator('#hosted [role="alert"]')).toHaveText('This hosted target was refused.');
    await expect(page.locator('#local > *')).toHaveCount(0);
    await expect(page.locator('#unrelated > *')).toHaveCount(0);
    expect(requests.filter((path) => path.startsWith('/host/'))).toEqual(['/host/resolve']);

    // Mount explicitly without configuration, using the same official compiled module App just loaded.
    await page.evaluate(async (url) => {
      const studio: typeof import('@kumwe/studio') = await import(url);
      await studio.mountStudio('#local');
    }, modulePath);
    const local = page.locator('#local > kumwe-studio-standalone');
    await expect(local).toBeVisible();
    const beforeLocalWork = [...requests];
    expect(await local.evaluate((element) => (element as KumweStudioStandaloneElement).project.state.blueprint.roots))
      .toEqual([]);
    await local.getByRole('complementary', { name: 'Block palette' })
      .getByRole('button', { name: 'Section', exact: true }).click();
    const project = await local.evaluate((element) => (element as KumweStudioStandaloneElement).exportProjectJson());
    expect(JSON.parse(project).state.blueprint.roots).toMatchObject([{ type: 'studio.core/section' }]);
    const downloadPromise = page.waitForEvent('download');
    await local.evaluate((element) => (element as KumweStudioStandaloneElement).downloadProject());
    const download = await downloadPromise;
    const downloadedPath = await download.path();
    expect(downloadedPath).not.toBeNull();
    expect(readFileSync(downloadedPath!, 'utf8')).toBe(project);
    const intentPromise = page.waitForEvent('download');
    const intent = await local.evaluate((element) => {
      const standalone = element as KumweStudioStandaloneElement;
      standalone.downloadSaveIntent();
      return standalone.exportSaveIntentJson();
    });
    const intentPath = await (await intentPromise).path();
    expect(intentPath).not.toBeNull();
    expect(readFileSync(intentPath!, 'utf8')).toBe(intent);

    await page.evaluate(async ({ url, project }) => {
      const studio: typeof import('@kumwe/studio') = await import(url);
      const restored = await studio.mountStudio('#restored');
      (restored.element as KumweStudioStandaloneElement).importProjectJson(project);
    }, { url: modulePath, project });
    const restored = page.locator('#restored > kumwe-studio-standalone');
    expect(await restored.evaluate((element) => (element as KumweStudioStandaloneElement).exportProjectJson()))
      .toBe(project);
    // Further local edits do not change the fresh import or retry the refused hosted transport.
    await local.getByRole('complementary', { name: 'Block palette' })
      .getByRole('button', { name: 'Section', exact: true }).click();
    expect(await restored.evaluate((element) => (element as KumweStudioStandaloneElement).exportProjectJson()))
      .toBe(project);
    expect(requests).toEqual(beforeLocalWork);
    expect(errors).toEqual([]);
  });
}

for (const kind of ['missing', 'standalone', 'other-target'] as const) {
  /** A Content mount always needs its own hosted configuration; never borrow a local or neighboring target. */
  test(`the Content launcher rejects ${kind} deployment configuration`, async ({ page }) => {
    const configuration = kind === 'missing' ? null : kind === 'standalone' ? {
      kind: 'studio-deployment', contractVersion: '0.1-draft',
      instanceId: 'local-content', mount: '#hosted', release: browserManifest.release,
    } : { ...hostedConfiguration(), mount: '#local' };
    const requests = await openMountDocument(page, configuration);
    await expect(page.locator('[data-studio-launch-status]')).toHaveAttribute('data-studio-launch-state', 'failed');
    await expect(page.locator('[data-studio-authoring-fallback-form]')).toBeVisible();
    await expect(page.locator('kumwe-studio-standalone, kumwe-studio-contextual')).toHaveCount(0);
    expect(requests.filter((path) => path.startsWith('/host/'))).toEqual([]);
  });
}
