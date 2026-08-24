import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

interface ResponsiveLayoutSnapshot {
  alignment: string;
  collapseColumns: number;
  columns: number;
  direction: string;
  gap: string;
  scheme: string;
  visibility: string;
  wrap: string;
}

const responsiveMarkup = `
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <div
    id="responsive-grid"
    class="studio-preview-grid"
    data-studio-layout-compact-alignment="center"
    data-studio-layout-compact-collapse="preserve"
    data-studio-layout-compact-columns="1"
    data-studio-layout-compact-spacing="compact"
    data-studio-layout-compact-visibility="visible"
    data-studio-layout-medium-alignment="end"
    data-studio-layout-medium-collapse="preserve"
    data-studio-layout-medium-columns="3"
    data-studio-layout-medium-spacing="none"
    data-studio-layout-medium-visibility="visible"
    data-studio-layout-expanded-alignment="stretch"
    data-studio-layout-expanded-collapse="preserve"
    data-studio-layout-expanded-columns="4"
    data-studio-layout-expanded-spacing="spacious"
    data-studio-layout-expanded-visibility="visible"
  >
    <div>One</div><div>Two</div><div>Three</div><div>Four</div>
  </div>
  <div
    id="responsive-collapse"
    class="studio-preview-grid"
    data-studio-layout-compact-alignment="stretch"
    data-studio-layout-compact-collapse="stack"
    data-studio-layout-compact-columns="4"
    data-studio-layout-compact-spacing="comfortable"
    data-studio-layout-compact-visibility="visible"
    data-studio-layout-medium-alignment="stretch"
    data-studio-layout-medium-collapse="wrap"
    data-studio-layout-medium-columns="4"
    data-studio-layout-medium-spacing="comfortable"
    data-studio-layout-medium-visibility="visible"
    data-studio-layout-expanded-alignment="stretch"
    data-studio-layout-expanded-collapse="preserve"
    data-studio-layout-expanded-columns="4"
    data-studio-layout-expanded-spacing="comfortable"
    data-studio-layout-expanded-visibility="visible"
  >
    <div>One</div><div>Two</div><div>Three</div><div>Four</div>
  </div>
  <div
    id="responsive-stack"
    class="studio-preview-stack"
    data-studio-layout-compact-alignment="stretch"
    data-studio-layout-compact-direction="inline"
    data-studio-layout-compact-spacing="comfortable"
    data-studio-layout-compact-visibility="visible"
    data-studio-layout-medium-alignment="stretch"
    data-studio-layout-medium-direction="block"
    data-studio-layout-medium-spacing="comfortable"
    data-studio-layout-medium-visibility="visible"
    data-studio-layout-expanded-alignment="stretch"
    data-studio-layout-expanded-direction="inline"
    data-studio-layout-expanded-spacing="comfortable"
    data-studio-layout-expanded-visibility="visible"
  ><div>Alpha</div><div>Beta</div></div>
  <section
    id="responsive-visibility"
    class="studio-preview-section"
    data-studio-layout-compact-alignment="stretch"
    data-studio-layout-compact-spacing="comfortable"
    data-studio-layout-compact-visibility="visible"
    data-studio-layout-medium-alignment="stretch"
    data-studio-layout-medium-spacing="comfortable"
    data-studio-layout-medium-visibility="hidden"
    data-studio-layout-expanded-alignment="stretch"
    data-studio-layout-expanded-spacing="comfortable"
    data-studio-layout-expanded-visibility="visible"
  >Visibility</section>
`;

async function layoutAt(page: Page, width: number): Promise<ResponsiveLayoutSnapshot> {
  await page.setViewportSize({ width, height: 900 });

  return page.evaluate(() => {
    const grid = getComputedStyle(document.querySelector('#responsive-grid') as HTMLElement);
    const collapse = getComputedStyle(document.querySelector('#responsive-collapse') as HTMLElement);
    const stack = getComputedStyle(document.querySelector('#responsive-stack') as HTMLElement);
    const visibility = getComputedStyle(document.querySelector('#responsive-visibility') as HTMLElement);
    const tracks = grid.gridTemplateColumns === 'none'
      ? []
      : grid.gridTemplateColumns.split(/\s+/u).filter((track) => track !== '' && track !== '0px');
    const collapseTracks = collapse.gridTemplateColumns === 'none'
      ? []
      : collapse.gridTemplateColumns.split(/\s+/u).filter((track) => track !== '' && track !== '0px');

    return {
      alignment: grid.alignItems,
      collapseColumns: collapseTracks.length,
      columns: tracks.length,
      direction: stack.flexDirection,
      gap: grid.gap,
      scheme: getComputedStyle(document.documentElement).colorScheme,
      visibility: visibility.display,
      wrap: stack.flexWrap,
    };
  });
}

test('published Studio layout preserves compact medium and expanded computed styles in both themes', async ({
  page,
}) => {
  const expected: Record<string, Omit<ResponsiveLayoutSnapshot, 'scheme'>> = {
    compact: {
      alignment: 'center',
      collapseColumns: 1,
      columns: 1,
      direction: 'row',
      gap: '10px',
      visibility: 'grid',
      wrap: 'wrap',
    },
    medium: {
      alignment: 'end',
      collapseColumns: 3,
      columns: 3,
      direction: 'column',
      gap: '0px',
      visibility: 'none',
      wrap: 'nowrap',
    },
    expanded: {
      alignment: 'stretch',
      collapseColumns: 4,
      columns: 4,
      direction: 'row',
      gap: '36px',
      visibility: 'grid',
      wrap: 'wrap',
    },
  };
  const widths = { compact: 360, medium: 768, expanded: 1440 } as const;
  const themed: Record<string, Record<string, Omit<ResponsiveLayoutSnapshot, 'scheme'>>> = {};

  for (const scheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.setContent(responsiveMarkup);
    await page.addStyleTag({ path: resolve('public/assets/site.css') });
    themed[scheme] = {};

    for (const [viewport, width] of Object.entries(widths)) {
      const snapshot = await layoutAt(page, width);
      expect(snapshot.scheme).toContain(scheme);
      const { scheme: _scheme, ...layout } = snapshot;
      expect(layout).toEqual(expected[viewport]);
      themed[scheme][viewport] = layout;
    }
  }

  expect(themed.dark).toEqual(themed.light);
});
