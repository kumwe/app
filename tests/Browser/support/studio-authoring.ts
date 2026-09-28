import { expect, type BrowserContext, type Locator, type Page } from '@playwright/test';

/** The storage key the Content editor's start module reads for the surface an editor last chose. */
export const STUDIO_SURFACE_PREFERENCE_KEY = 'kumwe.studio.surface';

/** The settled outcomes of the Studio launch on a Content editor page. */
export type StudioLaunchOutcome = 'deferred' | 'ready' | 'failed' | 'error' | 'saved' | 'fallback';

/**
 * Make every page in the context open Content items on the structured form.
 *
 * The page builder is the Content editor's default surface: once the pinned Studio module has mounted,
 * the structured form is hidden behind the surface toggle. Journeys that prove the structured form must
 * say so before they navigate, exactly as an editor who pressed "Use the structured form" once would be
 * remembered; the page then opens on the form and defers the Studio mount until the page builder is
 * asked for, so neither the swap that follows the asynchronous mount nor a second copy of the content
 * fields inside the mount can race their field interactions. The preference is set on the context so a
 * second tab opened during the journey honours it as well.
 */
export async function preferStructuredContentForm(context: BrowserContext): Promise<void> {
  await context.addInitScript((key: string) => {
    try {
      window.localStorage.setItem(key, 'form');
    } catch {
      // Storage is unavailable in this profile; the launch module then keeps the page builder.
    }
  }, STUDIO_SURFACE_PREFERENCE_KEY);
}

/**
 * Wait until the Content editor's Studio launch has settled and answer how.
 *
 * A page without the launch region carries the structured-editor fallback notice instead; a page with
 * it moves the status from `pending` either to `deferred` (the form is in front and Studio waits for
 * the toggle) or through `loading` to `ready`, `failed` or `error`. Asserting layout or accessibility
 * before that point measures whichever surface the race has reached.
 */
export async function awaitStudioLaunchSettled(page: Page): Promise<StudioLaunchOutcome> {
  if ((await page.locator('[data-studio-authoring-fallback]').count()) > 0) {
    return 'fallback';
  }
  const status = page.locator('[data-studio-launch-status]');
  await expect(status).toHaveAttribute('data-studio-launch-state', /^(deferred|ready|failed|error|saved)$/u, {
    timeout: 30_000,
  });
  const state = await status.getAttribute('data-studio-launch-state');
  if (state === 'deferred' || state === 'ready' || state === 'failed' || state === 'error' || state === 'saved') {
    return state;
  }
  throw new Error(`The Studio launch settled with the unexpected state "${state ?? 'null'}".`);
}

/** The attribute a keyboard walk uses to address the focused element through open shadow roots. */
const FOCUS_MARKER = 'data-kumwe-focus-stop';

/** One stop of a keyboard walk: the element holding focus, its accessible role and name, and its place. */
export interface FocusStop {
  /** The focused element, addressable for visibility, state and accessible-name assertions. */
  readonly locator: Locator;
  /** The ARIA role the accessibility tree reports for it. */
  readonly role: string;
  /** The accessible name the accessibility tree reports for it. */
  readonly name: string;
  /** Its top and left edges in page coordinates, for reading-order assertions. */
  readonly top: number;
  readonly left: number;
  readonly right: number;
}

/**
 * Describe the element that holds keyboard focus now, following focus into open shadow roots.
 *
 * Studio renders its shell in shadow DOM, so `document.activeElement` names only the host element. The
 * focused element is marked with the walk's own sequence number, so each stop stays addressable after focus
 * moves on, and its role and name are read from the accessibility tree rather than from markup, because that
 * is what an assistive technology announces. A stop must be visible, inside the viewport, and carry a
 * non-empty accessible name.
 */
export async function focusStop(page: Page): Promise<FocusStop> {
  const place = await page.evaluate((marker) => {
    const holder = window as Window & { kumweFocusStops?: number };
    let element: Element | null = document.activeElement;
    while (element?.shadowRoot?.activeElement) element = element.shadowRoot.activeElement;
    if (element === null || element === document.body || element === document.documentElement) return null;
    holder.kumweFocusStops = (holder.kumweFocusStops ?? 0) + 1;
    element.setAttribute(marker, String(holder.kumweFocusStops));
    const bounds = element.getBoundingClientRect();

    return {
      stop: holder.kumweFocusStops,
      top: bounds.top + window.scrollY,
      left: bounds.left + window.scrollX,
      right: bounds.right + window.scrollX,
    };
  }, FOCUS_MARKER);
  expect(place, 'Keyboard focus must rest on a control, never on the document itself.').not.toBeNull();
  const locator = page.locator(`[${FOCUS_MARKER}="${String(place?.stop ?? 0)}"]`);
  await expect(locator).toHaveCount(1);
  await expect(locator, 'A focused control must be visible.').toBeVisible();
  await expect(locator, 'A focused control must be scrolled into the viewport.').toBeInViewport();
  const snapshot = await locator.ariaSnapshot();
  const announced = /^- ([\w-]+)(?: "((?:[^"\\]|\\.)*)")?/u.exec(snapshot.trim());
  const role = announced?.[1] ?? '';
  const name = (announced?.[2] ?? '').replaceAll('\\"', '"');
  expect(name, `The focused ${role || 'element'} must carry an accessible name: ${snapshot}`).not.toBe('');

  return { locator, role, name, top: place?.top ?? 0, left: place?.left ?? 0, right: place?.right ?? 0 };
}

/**
 * Press one key until focus reaches a stop the predicate accepts, and answer every stop on the way.
 *
 * Each intermediate stop is held to the same visibility and naming obligations as the one sought, so a
 * walk proves the whole sequence an editor tabs through, not only its destination.
 */
export async function tabUntil(
  page: Page,
  accept: (stop: FocusStop) => boolean | Promise<boolean>,
  limit = 60,
  key: 'Tab' | 'Shift+Tab' = 'Tab',
): Promise<FocusStop[]> {
  const stops: FocusStop[] = [];
  for (let index = 0; index < limit; index += 1) {
    await page.keyboard.press(key);
    const stop = await focusStop(page);
    stops.push(stop);
    if (await accept(stop)) return stops;
  }
  throw new Error(`No focus stop matched within ${limit} presses of ${key}: ${
    stops.map(({ role, name }) => `${role} "${name}"`).join(' -> ')}`);
}

/** Press Tab a fixed number of times and answer each stop, in order. */
export async function tabStops(page: Page, count: number, key: 'Tab' | 'Shift+Tab' = 'Tab'): Promise<FocusStop[]> {
  const stops: FocusStop[] = [];
  for (let index = 0; index < count; index += 1) {
    await page.keyboard.press(key);
    stops.push(await focusStop(page));
  }

  return stops;
}
