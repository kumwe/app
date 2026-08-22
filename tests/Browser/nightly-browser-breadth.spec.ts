import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL
  ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';

/** Sign in through the production form so every assertion runs inside the real administrator shell. */
async function signIn(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Attach full-page evidence without presenting another engine's pixels as a regression baseline. */
async function attachEvidenceScreenshot(page: Page, testInfo: TestInfo): Promise<void> {
  const path = testInfo.outputPath('nightly-browser-breadth-evidence-only.png');
  await page.screenshot({ path, fullPage: true, scale: 'css', animations: 'disabled', caret: 'hide' });
  await testInfo.attach('nightly-browser-breadth-evidence-only', {
    path,
    contentType: 'image/png',
  });
}

/** Read the border properties that make the product's forced-colours response observable. */
async function borderEvidence(locator: Locator): Promise<Readonly<Record<string, string>>> {
  return locator.evaluate((element) => {
    const style = getComputedStyle(element);

    return {
      color: style.borderTopColor,
      style: style.borderTopStyle,
      width: style.borderTopWidth,
    };
  });
}

test('Nightly browser breadth preserves keyboard, touch, high contrast, zoom and reflow', async ({
  browserName,
  page,
}, testInfo) => {
  const mobile = testInfo.project.name.startsWith('mobile-');

  await signIn(page);

  const criticalControls: Locator[] = [];
  if (mobile) {
    const toggle = page.getByRole('button', { name: 'Open administrator navigation' });
    await expect(toggle).toBeVisible();
    await toggle.focus();
    await expect(toggle).toBeFocused();
    expect((await toggle.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    await toggle.tap();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(
      page.getByRole('navigation', { name: 'Administrator navigation' }),
    ).toBeVisible();
    const settingsLink = page
      .getByRole('navigation', { name: 'Administrator navigation' })
      .getByRole('link', { name: 'Settings' });
    await settingsLink.focus();
    await expect(settingsLink).toBeFocused();
    expect((await settingsLink.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    await settingsLink.press('Enter');
    await expect(page).toHaveURL(/\/administrator\/settings$/u);
    criticalControls.push(toggle);
  } else {
    const settingsLink = page
      .getByRole('navigation', { name: 'Administrator navigation' })
      .getByRole('link', { name: 'Settings' });
    await settingsLink.focus();
    await expect(settingsLink).toBeFocused();
    await settingsLink.press('Enter');
    await expect(page).toHaveURL(/\/administrator\/settings$/u);
    criticalControls.push(settingsLink);
  }

  const save = page.getByRole('button', { name: 'Save settings and design' });
  await save.focus();
  await expect(save).toBeFocused();
  if (mobile) {
    expect((await save.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
  }
  criticalControls.push(save);

  // WebKit exposes long native option paint through ancestor scroll extents unless the select itself
  // owns that paint. Keep the platform picker, and prove the containment does not erase Kumwe's visible
  // keyboard-focus treatment before the forced-colour pass changes how focus is rendered.
  const nativeSelect = page.locator('select[name="homepage_content_id"]');
  await nativeSelect.focus();
  await expect(nativeSelect).toBeFocused();
  const nativeSelectFocusEvidence = await nativeSelect.evaluate((element) => {
    const style = getComputedStyle(element);

    return {
      boxShadow: style.boxShadow,
      containment: style.contain,
      focusVisible: element.matches(':focus-visible'),
      outlineStyle: style.outlineStyle,
      outlineWidth: style.outlineWidth,
    };
  });
  expect(nativeSelectFocusEvidence.containment).toContain('inline-size');
  expect(nativeSelectFocusEvidence.containment).toContain('paint');
  expect(nativeSelectFocusEvidence.focusVisible).toBe(true);
  expect(nativeSelectFocusEvidence.outlineStyle).toBe('solid');
  expect(nativeSelectFocusEvidence.outlineWidth).toBe('2px');
  criticalControls.push(nativeSelect);

  // Prove both halves of the product contract: the engine activates the media feature and Kumwe's
  // forced-colours declaration changes a real computed style. Checking matchMedia alone would only test
  // Playwright. This focused section-navigation link has no border in ordinary rendering and receives a
  // two-pixel CanvasText border from assets/administrator/phase-five.css while it is focus-visible.
  const forcedColorProbe = page.locator('.kis-phase-five-section-nav a').first();
  await forcedColorProbe.focus();
  await expect(forcedColorProbe).toBeFocused();
  expect(await forcedColorProbe.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
  const ordinaryColorEvidence = await borderEvidence(forcedColorProbe);
  expect(ordinaryColorEvidence.width).toBe('0px');

  // Forced colours is evaluated on a settled document, independently of the dark-scheme assertion.
  await page.emulateMedia({ forcedColors: 'active' });
  await page.reload();
  expect(await page.evaluate(() => matchMedia('(forced-colors: active)').matches)).toBe(true);
  await forcedColorProbe.focus();
  await expect(forcedColorProbe).toBeFocused();
  expect(await forcedColorProbe.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
  const activeColorEvidence = await borderEvidence(forcedColorProbe);
  expect(activeColorEvidence.width).toBe('2px');
  expect(activeColorEvidence.style).toBe('solid');

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '200%';
  });
  const reflow = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(reflow.findings, JSON.stringify(reflow, null, 2)).toEqual([]);

  const accessibility = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(accessibility.violations, JSON.stringify(accessibility.violations, null, 2)).toEqual([]);

  for (const control of criticalControls) {
    await expect(control).toBeVisible();
    await control.focus();
    await expect(control).toBeFocused();
  }

  const viewport = page.viewportSize();
  const evidence = {
    commit: process.env.GITHUB_SHA ?? 'local',
    database: process.env.DB_DRIVER ?? 'unknown',
    fixture: 'placeholder content plus manifest-owned identities from prepare-browser-contribution.php',
    project: testInfo.project.name,
    browser: browserName,
    viewport,
    locale: await page.evaluate(() => navigator.language),
    emulation: browserName === 'firefox' && mobile
      ? 'responsive touch viewport; Playwright Firefox does not support isMobile'
      : mobile ? 'mobile device' : 'desktop device',
    media: {
      forcedColors: {
        active: true,
        probe: '.kis-phase-five-section-nav a:first-child:focus-visible',
        ordinary: ordinaryColorEvidence,
        computedWhileActive: activeColorEvidence,
      },
      textZoomPercent: 200,
    },
    acceptance: {
      accessibilityViolations: accessibility.violations.length,
      horizontalOverflowFindings: reflow.findings.length,
      inaccessibleCriticalControls: 0,
      criticalControlsChecked: mobile
        ? ['administrator navigation toggle', 'settings navigation link', 'save settings', 'homepage select']
        : ['settings navigation link', 'save settings', 'homepage select'],
      nativeSelectFocus: nativeSelectFocusEvidence,
      keyboardInteraction: 'Settings navigation activated with Enter',
      touchInteraction: mobile
        ? 'Administrator navigation opened with tap and exposed its settings link'
        : 'not applicable',
    },
  };
  await testInfo.attach('nightly-browser-breadth-contract', {
    body: `${JSON.stringify(evidence, null, 2)}\n`,
    contentType: 'application/json',
  });
  await attachEvidenceScreenshot(page, testInfo);
});
