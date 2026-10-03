import { expect, test } from '@playwright/test';
import { focusStop, hasViewportIntersection, tabStops } from './support/studio-authoring';

test('Studio keyboard viewport checks retain clipping and native focus scrolling', async ({ page }) => {
  // Mobile emulation needs the same responsive viewport as Studio for exact CSS-pixel boundaries.
  await page.setContent(`
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      body { margin: 0; }
      #scroller { width: 200px; height: 80px; overflow: auto; border: 4px solid; }
      #spacer { height: 160px; }
      #shadow-host { display: block; width: 200px; height: 40px; overflow: hidden; }
    </style>
    <div id="scroller" tabindex="-1"><div id="spacer"></div><button>Scrolled control</button></div>
    <div id="shadow-host"></div>
    <button id="edge" style="position: fixed; top: 0; left: 100vw">Viewport edge</button>
  `);
  await page.locator('#shadow-host').evaluate((host) => {
    host.attachShadow({ mode: 'open' }).innerHTML =
      '<button style="margin-top: 80px">Clipped shadow control</button>';
  });
  for (const locator of [
    page.getByRole('button', { name: 'Scrolled control' }),
    page.getByRole('button', { name: 'Clipped shadow control' }),
  ]) {
    await expect(locator).not.toBeInViewport();
    expect(await locator.evaluate(hasViewportIntersection)).toBe(false);
  }

  // Tab must cause the browser itself to reveal a focused control; the helper must not scroll it.
  await page.keyboard.press('Tab');
  const stop = await focusStop(page);
  expect(stop.name).toBe('Scrolled control');
  await expect(stop.locator).toBeInViewport();
  expect(await page.locator('#scroller').evaluate((element) => element.scrollTop)).toBeGreaterThan(0);

  // A single pixel of genuine intersection passes, matching the native assertion's default ratio.
  const edge = page.getByRole('button', { name: 'Viewport edge' });
  await expect(edge).not.toBeInViewport();
  expect(await edge.evaluate(hasViewportIntersection)).toBeNull();
  await edge.evaluate((element) => { (element as HTMLElement).style.left = 'calc(100vw - 1px)'; });
  await expect(edge).toBeInViewport();
  await edge.evaluate((element) => {
    const style = (element as HTMLElement).style;
    style.position = 'static';
    style.marginLeft = 'calc(100vw - 1px)';
  });
  await expect(edge).toBeInViewport();
  expect(await edge.evaluate(hasViewportIntersection)).toBe(true);

  await edge.evaluate((element) => { (element as HTMLElement).style.clipPath = 'inset(1px)'; });
  expect(await edge.evaluate(hasViewportIntersection)).toBeNull();

  await page.setContent(`
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <div id="slot-host"><button style="display: block; margin-top: 80px">Clipped slotted control</button></div>
    <div style="overflow: hidden; height: 40px; width: 200px">
      <button style="position: absolute; top: 160px; left: 0">Escaped absolute control</button>
      <button style="position: fixed; top: 200px; left: 0">Escaped fixed control</button>
    </div>
  `);
  await page.locator('#slot-host').evaluate((host) => {
    host.attachShadow({ mode: 'open' }).innerHTML =
      '<div style="height: 20px; overflow: hidden"><slot></slot></div>';
  });
  const slotted = page.getByRole('button', { name: 'Clipped slotted control' });
  expect(await slotted.evaluate(hasViewportIntersection)).toBeNull();
  await slotted.evaluate((element) => { (element as HTMLElement).focus({ preventScroll: true }); });
  await expect(slotted).not.toBeInViewport();
  await expect(focusStop(page, 1_000)).rejects.toThrow('A focused control must be scrolled into the viewport.');
  for (const name of ['Escaped absolute control', 'Escaped fixed control']) {
    const control = page.getByRole('button', { name });
    expect(await control.evaluate(hasViewportIntersection)).toBeNull();
    await control.evaluate((element) => { (element as HTMLElement).focus({ preventScroll: true }); });
    expect((await focusStop(page)).name).toBe(name);
    await expect(control).toBeInViewport();
  }

  // Cover the repeated walk that exhausted the real Studio journey's budget in WebKit.
  await page.setContent(`<meta name="viewport" content="width=device-width, initial-scale=1">
  <main style="display: grid; grid-template-columns: repeat(6, 1fr)">${
    Array.from({ length: 150 }, (_, index) => `<button>Control ${index + 1}</button>`).join('')
  }</main>`);
  const stops = await tabStops(page, 150);
  expect(stops.map(({ name }) => name)).toEqual(
    Array.from({ length: 150 }, (_, index) => `Control ${index + 1}`),
  );
});
