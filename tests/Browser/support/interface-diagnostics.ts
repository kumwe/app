import { expect, type Page } from '@playwright/test';

export type InterfaceFindingKind =
  | 'component-overflow'
  | 'viewport-overflow'
  | 'clipped-by-ancestor'
  | 'control-overlap';

export interface InterfaceFinding {
  kind: InterfaceFindingKind;
  selector: string;
  relatedSelector?: string;
  horizontalPixels?: number;
  verticalPixels?: number;
}

export interface InterfaceDiagnosticReport {
  url: string;
  root: string;
  viewport: {
    width: number;
    height: number;
    documentWidth: number;
    horizontalOverflow: number;
  };
  findings: InterfaceFinding[];
}

export interface InterfaceDiagnosticOptions {
  root?: string;
  tolerance?: number;
  detectControlOverlaps?: boolean;
  ignore?: readonly string[];
}

/**
 * Inspect component geometry instead of relying only on the document scroll width.
 *
 * The report distinguishes an element whose own contents escape its box, an element that escapes the
 * viewport without an intentional scroll container, clipping by a hidden ancestor, and controls that
 * occupy the same actionable area. Intentional horizontal scrollers are not reported as viewport
 * overflow, leaving table contracts free to test their labelled scroll affordance separately.
 */
export async function collectInterfaceDiagnostics(
  page: Page,
  options: InterfaceDiagnosticOptions = {},
): Promise<InterfaceDiagnosticReport> {
  return page.evaluate((configuration): InterfaceDiagnosticReport => {
    const rootSelector = configuration.root ?? 'body';
    const root = document.querySelector<HTMLElement>(rootSelector);
    if (root === null) {
      throw new Error(`Interface diagnostic root does not exist: ${rootSelector}`);
    }

    const tolerance = configuration.tolerance ?? 1;
    const ignoredSelectors = [
      '[hidden]',
      '[data-interface-diagnostic-ignore]',
      '.sr-only',
      '.portal-sr-only',
      '.skip-link',
      '.navigation-backdrop',
      'script',
      'style',
      'template',
      'symbol',
      ...(configuration.ignore ?? []),
    ];
    const componentSelector = [
      'main',
      'aside',
      'section',
      'article',
      'form',
      'fieldset',
      'details',
      'table',
      '[role="region"]',
      '[role="dialog"]',
      '.panel',
      '.portal-security-panel',
      '.portal-business-panel',
      '.kis-master-detail-grid',
    ].join(',');
    const findings: InterfaceFinding[] = [];

    const isIgnored = (element: Element): boolean =>
      ignoredSelectors.some((selector) => element.matches(selector) || element.closest(selector) !== null);
    const rectangle = (element: Element): DOMRect => element.getBoundingClientRect();
    const isVisible = (element: Element): boolean => {
      if (isIgnored(element)) {
        return false;
      }
      const closedDisclosure = element.closest('details:not([open])');
      if (closedDisclosure !== null && element !== closedDisclosure) {
        const summary = closedDisclosure.querySelector(':scope > summary');
        if (summary === null || (element !== summary && !summary.contains(element))) {
          return false;
        }
      }
      const style = getComputedStyle(element);
      const bounds = rectangle(element);
      return style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number.parseFloat(style.opacity) !== 0
        && bounds.width > tolerance
        && bounds.height > tolerance
        && bounds.right > 0
        && bounds.bottom > 0
        && bounds.left < window.innerWidth
        && bounds.top < window.innerHeight;
    };
    const selectorFor = (element: Element): string => {
      const elementId = element.getAttribute('id');
      if (elementId !== null && elementId !== '') {
        return `#${CSS.escape(elementId)}`;
      }
      const diagnosticId = element.getAttribute('data-interface-id');
      if (diagnosticId !== null) {
        return `[data-interface-id="${CSS.escape(diagnosticId)}"]`;
      }

      const segments: string[] = [];
      let current: Element | null = element;
      while (current !== null && current !== document.documentElement) {
        let segment = current.tagName.toLowerCase();
        const classes = [...current.classList]
          .filter((className) => /^[a-z][a-z0-9_-]*$/iu.test(className))
          .slice(0, 2);
        if (classes.length > 0) {
          segment += classes.map((className) => `.${CSS.escape(className)}`).join('');
        }
        const parentElement: HTMLElement | null = current.parentElement;
        if (parentElement !== null) {
          const currentTagName = current.tagName;
          const siblings = [...parentElement.children]
            .filter((candidate) => candidate.tagName === currentTagName);
          if (siblings.length > 1) {
            segment += `:nth-of-type(${siblings.indexOf(current) + 1})`;
          }
        }
        segments.unshift(segment);
        const candidate = segments.join(' > ');
        if (document.querySelectorAll(candidate).length === 1) {
          return candidate;
        }
        current = parentElement;
      }

      return segments.join(' > ');
    };
    const scrollsHorizontally = (element: Element): boolean => {
      const style = getComputedStyle(element);
      return (style.overflowX === 'auto' || style.overflowX === 'scroll')
        && element.scrollWidth > element.clientWidth + tolerance;
    };
    const hasHorizontalScroller = (element: Element): boolean => {
      let ancestor = element.parentElement;
      while (ancestor !== null && ancestor !== root.parentElement) {
        if (scrollsHorizontally(ancestor)) {
          return true;
        }
        if (ancestor === root) {
          break;
        }
        ancestor = ancestor.parentElement;
      }
      return false;
    };
    const clippingAncestor = (element: Element): Element | null => {
      let ancestor = element.parentElement;
      while (ancestor !== null && ancestor !== root.parentElement) {
        const style = getComputedStyle(ancestor);
        if (['hidden', 'clip'].includes(style.overflowX)
          || ['hidden', 'clip'].includes(style.overflowY)) {
          return ancestor;
        }
        if (ancestor === root) {
          break;
        }
        ancestor = ancestor.parentElement;
      }
      return null;
    };

    const candidates = [root, ...root.querySelectorAll<HTMLElement>(componentSelector)]
      .filter((element, index, elements) => elements.indexOf(element) === index)
      .filter(isVisible);
    for (const element of candidates) {
      const style = getComputedStyle(element);
      const bounds = rectangle(element);
      const horizontalPixels = Math.max(0, element.scrollWidth - element.clientWidth);
      if (horizontalPixels > tolerance
        && style.overflowX !== 'auto'
        && style.overflowX !== 'scroll') {
        findings.push({
          kind: 'component-overflow',
          selector: selectorFor(element),
          horizontalPixels: Math.ceil(horizontalPixels),
        });
      }
      if ((bounds.left < -tolerance || bounds.right > window.innerWidth + tolerance)
        && !hasHorizontalScroller(element)) {
        findings.push({
          kind: 'viewport-overflow',
          selector: selectorFor(element),
          horizontalPixels: Math.ceil(Math.max(-bounds.left, bounds.right - window.innerWidth, 0)),
        });
      }
      const clipper = clippingAncestor(element);
      if (clipper !== null) {
        const clippingBounds = rectangle(clipper);
        const clippedX = Math.max(
          clippingBounds.left - bounds.left,
          bounds.right - clippingBounds.right,
          0,
        );
        const clippedY = Math.max(
          clippingBounds.top - bounds.top,
          bounds.bottom - clippingBounds.bottom,
          0,
        );
        if (clippedX > tolerance || clippedY > tolerance) {
          findings.push({
            kind: 'clipped-by-ancestor',
            selector: selectorFor(element),
            relatedSelector: selectorFor(clipper),
            horizontalPixels: Math.ceil(clippedX),
            verticalPixels: Math.ceil(clippedY),
          });
        }
      }
    }

    if (configuration.detectControlOverlaps ?? true) {
      const controls = [...root.querySelectorAll<HTMLElement>(
        'a[href], button, input:not([type="hidden"]), select, textarea, summary, [role="tab"]',
      )].filter(isVisible);
      for (let leftIndex = 0; leftIndex < controls.length; leftIndex += 1) {
        const left = controls[leftIndex];
        if (left === undefined) {
          continue;
        }
        const leftBounds = rectangle(left);
        for (let rightIndex = leftIndex + 1; rightIndex < controls.length; rightIndex += 1) {
          const right = controls[rightIndex];
          if (right === undefined || left.contains(right) || right.contains(left)) {
            continue;
          }
          const rightBounds = rectangle(right);
          const width = Math.min(leftBounds.right, rightBounds.right)
            - Math.max(leftBounds.left, rightBounds.left);
          const height = Math.min(leftBounds.bottom, rightBounds.bottom)
            - Math.max(leftBounds.top, rightBounds.top);
          if (width > tolerance && height > tolerance) {
            findings.push({
              kind: 'control-overlap',
              selector: selectorFor(left),
              relatedSelector: selectorFor(right),
              horizontalPixels: Math.ceil(width),
              verticalPixels: Math.ceil(height),
            });
          }
        }
      }
    }

    return {
      url: window.location.href,
      root: rootSelector,
      viewport: {
        width: window.innerWidth,
        height: window.innerHeight,
        documentWidth: document.documentElement.scrollWidth,
        horizontalOverflow: Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
      },
      findings,
    };
  }, options);
}

/** Assert the page remains within the viewport while returning the full component-level evidence. */
export async function expectNoDocumentOverflow(
  page: Page,
  options: InterfaceDiagnosticOptions = {},
): Promise<InterfaceDiagnosticReport> {
  const report = await collectInterfaceDiagnostics(page, options);
  expect(report.viewport.horizontalOverflow, JSON.stringify(report, null, 2))
    .toBeLessThanOrEqual(options.tolerance ?? 1);
  return report;
}
