import { expect, type Locator } from '@playwright/test';
import { interfaceLocales, message, type InterfaceLocale } from './interface-catalogue';

function studioLocale(locale: string): InterfaceLocale {
  const supported = interfaceLocales.find((candidate) => candidate === locale);
  if (supported === undefined) throw new Error(`Unsupported Studio journey locale: ${locale}`);
  return supported;
}

/** Open a workspace pane through the responsive navigation when the shell needs it. */
export async function openStudioPanel(
  shell: Locator,
  panel: 'canvas' | 'blocks' | 'outline' | 'inspector',
  locale = 'en-GB',
): Promise<void> {
  const selectedLocale = studioLocale(locale);
  const navigation = shell.getByRole('navigation', {
    name: message(selectedLocale, 'core.studio.shell.workspace-panels'),
    includeHidden: true,
  });
  await expect(navigation).toBeAttached();
  if (!await navigation.isVisible()) return;
  const labels = {
    canvas: 'canvas-pane',
    blocks: 'palette-heading',
    outline: 'outline-heading',
    inspector: 'inspector-heading',
  } as const;
  const button = navigation.getByRole('button', {
    name: message(selectedLocale, `core.studio.shell.${labels[panel]}`),
    exact: true,
  });
  if (await button.getAttribute('aria-pressed') !== 'true') await button.click();
  await expect(button).toHaveAttribute('aria-pressed', 'true');
}

/** Advanced JSON editing is an explicit disclosure inside the Inspector. */
export async function openStudioAdvanced(shell: Locator, locale = 'en-GB'): Promise<void> {
  await openStudioPanel(shell, 'inspector', locale);
  const advanced = shell.locator('details.inspector-advanced');
  if (await advanced.getAttribute('open') === null) {
    await advanced.locator('summary').getByText(
      message(studioLocale(locale), 'core.studio.shell.inspector-advanced'), { exact: true },
    ).click();
  }
  await expect(advanced).toHaveAttribute('open', '');
}
