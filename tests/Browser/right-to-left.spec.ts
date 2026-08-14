import { expect, test, type Page } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

/**
 * Hebrew and Arabic laid out from the right, on the surfaces a visitor reaches without signing in.
 *
 * The stylesheets carry no direction of their own: every inline-axis rule is a logical property, so
 * the whole mirroring follows from the `dir` attribute the layouts emit. These journeys assert both
 * halves of that — that the attribute is derived from the resolved locale rather than hardcoded, and
 * that the mirrored page still lays out without horizontal overflow, which is where a stray physical
 * declaration shows up first.
 */

const rightToLeftLocales = ['he', 'ar'] as const;
const surfaces = [
  { id: 'public-home', path: '/' },
  { id: 'administrator-login', path: '/administrator/login' },
  { id: 'portal-login', path: '/portal/login' },
] as const;

async function open(page: Page, path: string, locale: string): Promise<void> {
  const separator = path.includes('?') ? '&' : '?';
  await page.goto(`${path}${separator}locale=${locale}`);
}

test.describe('Right-to-left presentation', () => {
  for (const locale of rightToLeftLocales) {
    for (const surface of surfaces) {
      test(`${surface.id} renders right-to-left in ${locale}`, async ({ page }) => {
        await open(page, surface.path, locale);

        const root = page.locator('html');
        await expect(root).toHaveAttribute('dir', 'rtl');
        await expect(root).toHaveAttribute('lang', locale);

        await expectNoDocumentOverflow(page);
      });
    }
  }

  test('the same surfaces stay left-to-right in the source language', async ({ page }) => {
    for (const surface of surfaces) {
      await open(page, surface.path, 'en-GB');

      const root = page.locator('html');
      await expect(root).toHaveAttribute('dir', 'ltr');
      await expect(root).toHaveAttribute('lang', 'en-GB');
    }
  });

  test('an unrecognised locale falls back rather than rendering a blank interface', async ({ page }) => {
    await open(page, '/administrator/login', 'not-a-locale');

    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.getByRole('button', { name: 'Sign in to Kumwe' })).toBeVisible();
  });
});
