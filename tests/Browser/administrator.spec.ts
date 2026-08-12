import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';
const limitedEmail = process.env.KUMWE_BROWSER_LIMITED_EMAIL ?? 'browser-limited@kumwe.test';
const limitedPassword = process.env.KUMWE_BROWSER_LIMITED_PASSWORD ?? 'browser limited password';
const businessDefinitionHandle = 'site.default.session5_order';
const assetInspectionDefinition = 'kumwe.asset-inspection-example.inspection';
const assetInspectionReport = 'kumwe.asset-inspection-example.inspection-summary';
const windhoekOrderId = '019b40d9-8dd0-7ca2-a0db-9eae6a150511';
const windhoekTargetId = '019b40d9-8dd0-7ca2-a0db-9eae6a150521';

async function expectAccessible(page: Page, include?: string): Promise<void> {
  const builder = new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']);
  if (include !== undefined) {
    builder.include(include);
  }
  const scan = await builder.analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

async function expectStylesLoaded(page: Page): Promise<void> {
  const failedStylesheets = await page.evaluate(() =>
    [...document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]')]
      .filter((stylesheet) => stylesheet.sheet === null)
      .map((stylesheet) => stylesheet.href),
  );
  expect(failedStylesheets).toEqual([]);
  expect(await page.locator('link[rel="stylesheet"]').count()).toBeGreaterThan(0);
}

/** Attach the unmodified live interface before any compatibility-only baseline normalization. */
async function attachLiveInterfaceScreenshot(
  page: Page,
  testInfo: TestInfo,
  name: string,
): Promise<void> {
  const path = testInfo.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(name, { path, contentType: 'image/png' });
}

/** Keep legacy comparisons scoped to their original shell; Session 6 has dedicated real screenshots. */
async function preservePreSession6VisualSnapshot(page: Page): Promise<void> {
  await page.locator('.administrator-navigation').evaluate((navigation) => {
    const session6Paths = [
      '/administrator/reports',
      '/administrator/extensions/kumwe/asset-inspection-example',
    ];

    for (const link of navigation.querySelectorAll<HTMLAnchorElement>('a[href]')) {
      const href = link.getAttribute('href') ?? '';
      if (!session6Paths.some((path) => href === path || href.startsWith(`${path}/`))) {
        continue;
      }

      const section = link.closest('section');
      if (section !== null && section.querySelectorAll('a[href]').length === 1) {
        section.remove();
      } else {
        link.closest('li')?.remove();
      }
    }
  });
  await page
    .locator('a[href^="/administrator/business/kumwe.asset-inspection-example."]')
    .evaluateAll((links) => {
      for (const link of links) {
        link.closest('.business-workspace-card')?.remove();
      }
    });
  await page.locator('select[name="definition_id"] option').evaluateAll((options) => {
    for (const option of options) {
      if (option.textContent?.includes('kumwe.asset-inspection-example.') === true) {
        option.remove();
      }
    }
  });
}

async function signIn(
  page: Page,
  email = administratorEmail,
  password = administratorPassword,
): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/);
}

async function fillSession5OrderForm(page: Page, name: string): Promise<void> {
  await page.locator('[name="values[name]"]').fill(name);
  await page.locator('[name="values[status]"]').selectOption('ready');
  await page.locator('[name="values[enabled]"][type="checkbox"]').check();
  await page.locator('[name="values[amount]"]').fill('10.000000000000000000000000000000');
  await page.locator('[name="values[price][amount]"]').fill('25.000000000000000000000000000000');
  await page.locator('[name="values[price][currency]"]').fill('nad');
  await page.locator('[name="values[quantity][amount]"]').fill('2.000000000000000000000000000000');
  await page.locator('[name="values[quantity][unit]"]').fill('unit');
  await page.locator('[name="values[service_date]"]').fill('2026-08-10');
  await page.locator('[name="values[local_time]"]').fill('13:14:15.123456');
  await page.locator('[name="values[recorded_at]"]').fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][instant]"]')
    .fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][timezone]"]').fill('Africa/Windhoek');
  await page.locator('[name="values[credential]"]').fill('browser-secret-value');
}

async function expectAdministratorRecordName(page: Page, value: string): Promise<void> {
  const field = page.locator('.business-detail-fields > div').filter({
    has: page.getByText('Name', { exact: true }),
  });
  await expect(field.locator('dd')).toHaveText(value);
}

async function expectAdministratorRecordRow(page: Page, value: string): Promise<void> {
  await expect(page.locator('.business-record-table tbody tr:visible, .kis-business-result-card:visible').filter({ hasText: value }).first())
    .toBeVisible();
}

async function ensureAnnouncementsActive(page: Page): Promise<void> {
  await page.goto('/administrator/extensions');
  const extension = page.locator('article').filter({ hasText: 'kumwe/announcements-example' }).first();
  const activate = extension.getByRole('button', { name: 'Activate' });
  if (await activate.count()) {
    await activate.click();
    await expect(page).toHaveURL(/\/administrator\/extensions$/);
  }
  await expect(extension).toContainText(/component · 2\.0\.0 · active/);
  await expect.poll(async () => {
    await page.goto('/administrator');
    return page.getByRole('link', { name: 'Announcements', exact: true }).count();
  }, {
    message: 'the active extension navigation to reach the local signed runtime map',
    timeout: 25_000,
  }).toBeGreaterThan(0);
  await page.goto('/administrator/extensions');
}

test('login is accessible and visually stable', async ({ page }, testInfo) => {
  await page.goto('/administrator/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
  await page.screenshot({ path: testInfo.outputPath('login.png'), fullPage: true });
});

test('database-backed public presentation is responsive and ready', async ({ page, request }, testInfo) => {
  const readiness = await request.get('/health/ready');
  expect(readiness.status()).toBe(200);

  await page.goto('/');
  await expect(
    page.getByRole('heading', { level: 1, name: /Content systems ready for what comes next/ }),
  ).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Structure once. Publish with confidence.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'One content core. Every delivery surface.' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open administrator/ }).first()).toBeVisible();
  await expect(page.locator('nav[aria-label="Main navigation"]')).toContainText('Capabilities');
  await expect(page.locator('img[src*="kumwe-wordmark.svg"]')).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);

  const visualContract = await page.evaluate(() => {
    const element = (selector: string): HTMLElement => {
      const match = document.querySelector(selector);
      if (!(match instanceof HTMLElement)) {
        throw new Error(`Missing visual-contract element: ${selector}`);
      }

      return match;
    };
    const columns = (selector: string): number =>
      getComputedStyle(element(selector)).gridTemplateColumns.trim().split(/\s+/).length;

    return {
      viewportWidth: window.innerWidth,
      horizontalOverflow: document.documentElement.scrollWidth - window.innerWidth,
      headerHeight: Math.round(element('.site-header').getBoundingClientRect().height),
      heroColumns: columns('.managed-hero-grid'),
      sectionColumns: columns('.managed-section-grid'),
      headingSize: Math.round(Number.parseFloat(getComputedStyle(element('h1')).fontSize) * 10) / 10,
      bodyBackground: getComputedStyle(document.body).backgroundColor,
      primaryBackground: getComputedStyle(element('.site-button')).backgroundColor,
    };
  });
  const mobile = testInfo.project.name.startsWith('mobile-');
  expect(visualContract.viewportWidth).toBe(mobile ? 412 : 1440);
  expect(visualContract.horizontalOverflow).toBe(0);
  expect(visualContract.headerHeight).toBeGreaterThanOrEqual(68);
  expect(visualContract.heroColumns).toBe(mobile ? 1 : 2);
  expect(visualContract.sectionColumns).toBe(mobile ? 1 : 2);
  expect(visualContract.headingSize).toBeGreaterThan(mobile ? 34 : 52);
  expect(visualContract.bodyBackground).not.toBe('rgba(0, 0, 0, 0)');
  expect(visualContract.primaryBackground).not.toBe('rgba(0, 0, 0, 0)');

  await page.screenshot({
    path: testInfo.outputPath('public-home.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
});

test.describe('public presentation without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('keeps essential navigation and keyboard recovery available at every supported viewport', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('nav[aria-label="Main navigation"]')).toBeVisible();
    await expect(page.locator('nav[aria-label="Main navigation"]')).toContainText('Capabilities');
    await expect(page.getByRole('button', { name: 'Open site navigation' })).toBeHidden();
    await page.getByRole('link', { name: 'Skip to content' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#site-content')).toBeFocused();
  });
});

test('policy authoring retains a complete native no-JavaScript path', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await signIn(page);
    await page.goto(
      '/administrator/business-security?section=policies&mode=create&kind=resource&step=review#policy-step-review',
    );
    await expect(page).toHaveURL(/kind=resource&step=review#policy-step-review$/u);
    await expect(page.getByRole('link', { name: '4. Review', exact: true }))
      .toHaveAttribute('aria-current', 'step');
    await expect(page.locator('[data-kis-policy-step-panel]')).toHaveCount(4);
    for (const stage of await page.locator('[data-kis-policy-step-panel]').all()) {
      await expect(stage).toBeVisible();
    }
    await expect(page.locator('[data-kis-policy-step-flow] form')).toHaveAttribute('method', 'post');
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continue to predicate' })).toHaveAttribute(
      'href',
      /section=policies&mode=create&kind=resource&step=predicate#policy-step-predicate$/u,
    );
  } finally {
    await context.close();
  }
});

test.describe('authenticated administrator', () => {
  test.beforeEach(async ({ page }) => signIn(page));

  test('dashboard supports desktop and responsive navigation', async ({ page, isMobile }, testInfo) => {
    await expect(page.getByRole('heading', { name: 'Good work starts with a clear view.' })).toBeVisible();
    await expectStylesLoaded(page);
    if (isMobile) {
      const toggle = page.getByRole('button', { name: 'Open administrator navigation' });
      await toggle.click();
      await expect(page.getByRole('navigation', { name: 'Administrator navigation' })).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(toggle).toBeFocused();
    }
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('dashboard.png'), fullPage: true });
  });

  test('content discovery and graphical editor work without raw JSON', async ({ page }, testInfo) => {
    await page.goto('/administrator/content');
    await expect(page.getByRole('heading', { name: 'Content', exact: true })).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search' }).fill('launch');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/q=launch/);
    await expectAccessible(page);

    await page.goto('/administrator/content/new');
    await expect(page.getByRole('heading', { name: 'Create content' })).toBeVisible();
    await expect(page.getByText('JSON', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'Rich text editor' }).first()).toBeVisible();
    await expect(page.getByRole('toolbar', { name: 'Text formatting' }).first()).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('content-editor.png'), fullPage: true });
  });

  test('business definitions publish through graphical compatibility gates', async ({ page }, testInfo) => {
    const suffix = `${Date.now()}_${Math.floor(Math.random() * 10000)}`;
    const handle = `site.default.browser_invoice_${suffix}`;
    await page.goto('/administrator/business-definitions?new=1');
    await expect(page.getByRole('heading', { name: 'Business definitions' })).toBeVisible();
    await expect(page.locator('textarea[name="definition_json"]')).toHaveCount(0);
    await page.locator('input[name="handle"]').fill(handle);
    await page.getByLabel('Singular label').fill('Browser invoice');
    await page.getByLabel('Plural label').fill('Browser invoices');
    await page.getByRole('tab', { name: 'Fields' }).click();
    const longFieldLabel = `Cross-border invoice reference ${'with controlled operational context '.repeat(2).trim()}`;
    for (let index = 0; index < 8; index += 1) {
      await page.getByRole('button', { name: 'Add field' }).click();
      await expect(page.locator('details[data-row="field"][open]')).toHaveCount(1);
      const field = page.locator('[data-row="field"]').last();
      await field.getByLabel('Handle').fill(index === 0 ? 'reference' : `supporting_field_${index}`);
      await field.getByLabel('Label').fill(index === 0 ? longFieldLabel : `Supporting field ${index}`);
      await field.locator('select').first().selectOption('core.text');
      await field.getByLabel('Length').fill('120');
      if (index === 0) await field.getByText('Required', { exact: true }).click();
    }
    await page.getByRole('button', { name: 'Save and validate draft' }).press('Enter');
    await expect(page).toHaveURL(/\/administrator\/business-definitions\?tab=fields&definition=/);
    await expect(page.locator('[data-row="field"]')).toHaveCount(9);
    await expect(page.locator('details[data-row="field"][open]')).toHaveCount(1);
    await expect(page.getByText(longFieldLabel, { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Publication' }).click();
    await expect(page.getByRole('heading', { name: 'Compatibility plan' })).toBeVisible();
    await expect(page.locator('dt').filter({ hasText: /^Draft checksum$/u })).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('business-definition-draft.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /Publish version 1/ }).press('Enter');
    await expect(page).toHaveURL(/tab=publication/);
    await page.getByRole('tab', { name: 'History' }).click();
    await expect(page.getByRole('heading', { name: 'Version history' })).toBeVisible();
    await expect(page.getByText('Version 1', { exact: true })).toBeVisible();
    await expectAccessible(page);
  });

  test('definition workspace contains dense package contracts and permission-reduced actors', async ({
    page,
  }) => {
    await page.goto(
      '/administrator/business-definitions?definition=kumwe.asset-inspection-example.inspection&tab=fields',
    );
    await expect(page.getByText('Package owned.', { exact: false })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Fields' })).toBeVisible();
    expect(await page.locator('[role="tabpanel"] table tbody tr').count()).toBeGreaterThanOrEqual(7);
    await expect(page.getByText('Restricted internal note', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);

    await page.context().clearCookies();
    await signIn(page, limitedEmail, limitedPassword);
    await page.goto(
      '/administrator/business-definitions?definition=019b40d9-8dd0-7ca2-a0db-9eae6a150501&tab=identity',
    );
    await expect(page.getByRole('tab', { name: 'Identity' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('link', { name: 'New definition' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save and validate draft' })).toHaveCount(0);
    await expect(page.getByText('Read-only access.', { exact: true })).toBeVisible();
    await expectAccessible(page);
  });

  test('schema plans are inspectable, capability-gated and visually stable', async ({
    page,
    isMobile,
  }, testInfo) => {
    await page.goto('/administrator/business-schema-plans');
    await expect(page.getByRole('heading', { name: 'Business schema plans' })).toBeVisible();
    if (isMobile) {
      await page.getByRole('button', { name: 'Browse schema plans' }).click();
    }
    const pendingApprovalPlan = page
      .locator('.schema-plan-catalog .definition-catalog-item', {
        has: page.locator('.status-pending-approval'),
      })
      .first();
    await expect(pendingApprovalPlan).toBeVisible();
    await pendingApprovalPlan.click();
    await expect(pendingApprovalPlan).toHaveAttribute('aria-current', 'true');
    await page.getByRole('tab', { name: 'Operations' }).click();
    await expect(page.getByRole('heading', { name: 'Generated operations' })).toBeVisible();
    await page.getByRole('tab', { name: 'Summary' }).click();
    await expect(page.getByText('Plan checksum', { exact: true })).toBeVisible();
    await expect(page.getByText('Physical checksum', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Approval' }).click();
    await expect(page.getByRole('heading', { name: 'Approve exact plan' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Schema plans', exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    );
    await expect(page.locator('textarea[name="sql"], textarea[name="plan"], input[name="sql"]')).toHaveCount(0);
    await expect(page.locator('.schema-operations-table tbody tr')).not.toHaveCount(0);
    const missingCsrf = await page.context().request.post('/administrator/business-schema-plans/plan', {
      form: { definition_id: '018f22e2-7c8b-7ab0-8f3a-88e8026bb401' },
    });
    expect(missingCsrf.status()).toBe(403);
    expect(await missingCsrf.text()).toContain('security token is invalid');
    await expectStylesLoaded(page);
    await expectAccessible(page);
    await attachLiveInterfaceScreenshot(page, testInfo, 'schema-plans-live');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('schema-plans.png', {
      fullPage: true,
      mask: [
        page.locator('.schema-plan-catalog'),
        page.locator('.kis-master-detail-catalog .count-badge'),
        page.locator('[data-kis-detail] [data-visual-mask]'),
      ],
      maskColor: '#d9e2e8',
    });
  });

  test('business security is structured, isolated and accessible', async ({ page }, testInfo) => {
    test.slow();
    await page.goto('/administrator/business-security');
    await expect(page.getByRole('heading', { level: 1, name: 'Business Security' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Authority overview' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Business Security concerns' }).getByRole('link'))
      .toHaveCount(6);
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);

    await page.getByRole('link', { name: 'Policies', exact: true }).click();
    await expect(page).toHaveURL(/section=policies/u);
    await expect(page.getByRole('heading', { name: 'Policy catalog' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Organizations and workspaces' })).toHaveCount(0);
    await page.getByRole('link', { name: 'Create policy', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Create row and field policy' })).toBeVisible();
    await expect(page).toHaveURL(/kind=resource&step=scope#policy-step-scope$/u);
    await expect(page.getByRole('link', { name: '1. Scope', exact: true }))
      .toHaveAttribute('aria-current', 'step');
    await expect(page.locator('#policy-step-scope')).toBeFocused();
    await expect(page.locator('#policy-step-scope')).toBeVisible();
    await expect(page.locator('#policy-step-predicate')).toBeHidden();
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeHidden();
    const policyCode = page.locator('input[name="policy_code"]');
    await policyCode.fill('browser.policy.retained');

    await page.getByRole('link', { name: 'Continue to predicate' }).click();
    await expect(page).toHaveURL(/kind=resource&step=predicate#policy-step-predicate$/u);
    await expect(page.locator('#policy-step-predicate')).toBeFocused();
    await expect(policyCode).toHaveValue('browser.policy.retained');
    await expect(page.locator('#policy-step-scope')).toBeHidden();
    await expect(page.getByRole('link', { name: '2. Predicate', exact: true }))
      .toHaveAttribute('aria-current', 'step');

    await page.getByRole('link', { name: 'Continue to disclosure' }).click();
    await expect(page).toHaveURL(/kind=resource&step=disclosure#policy-step-disclosure$/u);
    await expect(page.locator('#policy-step-disclosure')).toBeFocused();
    await expect(page.locator('textarea')).toHaveCount(0);
    await expect(page.locator('input[name="policy_json"], input[name="canonical_ast"]')).toHaveCount(0);
    for (const usage of [
      'create',
      'update',
      'detail',
      'list',
      'filter',
      'search',
      'sort',
      'aggregate',
      'report',
      'export',
      'audit',
      'mcp',
      'relation',
      'include',
      'public_reference',
    ]) {
      await expect(page.locator(`select[name="fields_${usage}[]"]`)).toBeVisible();
    }
    await expect(policyCode).toHaveValue('browser.policy.retained');
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeHidden();

    await page.getByRole('link', { name: 'Review policy' }).click();
    await expect(page).toHaveURL(/kind=resource&step=review#policy-step-review$/u);
    await expect(page.locator('#policy-step-review')).toBeFocused();
    await expect(page.getByRole('link', { name: '4. Review', exact: true }))
      .toHaveAttribute('aria-current', 'step');
    await expect(policyCode).toHaveValue('browser.policy.retained');
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create reviewed policy' })).toBeVisible();
    const missingCsrf = await page.context().request.post('/administrator/business-security', {
      form: { action: 'organization.create', identifier: 'forged', name: 'Forged' },
    });
    expect(missingCsrf.status()).toBe(403);
    expect(await missingCsrf.text()).toContain('security token is invalid');
    await expectStylesLoaded(page);
    await expectAccessible(page);
    const horizontalOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth - window.innerWidth
    );
    expect(horizontalOverflow).toBe(0);
    await page.screenshot({
      path: testInfo.outputPath('business-security.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });
  });

  test('users and access separates provisioning, assignments, tokens and step-up', async ({
    page,
  }, testInfo) => {
    test.setTimeout(90_000);
    const expectBoundedAccessWorkspace = async (attachment: string): Promise<void> => {
      await expectAccessible(page);
      const diagnostics = await expectNoDocumentOverflow(page, {
        root: '#administrator-content',
        detectControlOverlaps: false,
      });
      expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
      await attachLiveInterfaceScreenshot(page, testInfo, attachment);
    };

    await page.goto('/administrator/access');
    await expect(page.getByRole('heading', { level: 1, name: 'Users & access' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Users and Access concerns' }).getByRole('link'))
      .toHaveCount(6);
    await expect(page.getByRole('heading', { name: 'Users', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Provision an ordinary portal member' })).toBeVisible();
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);

    await page.getByRole('link', { name: 'Create user' }).first().click();
    await expect(page.getByRole('heading', { name: 'Create active identity' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Next: grant portal membership' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continue to membership' }))
      .toHaveAttribute('href', /business-security\?section=memberships&mode=create/u);
    await expectBoundedAccessWorkspace('access-portal-provisioning');

    await page.goto('/administrator/access?section=assignments');
    await expect(page.getByRole('heading', { name: 'Role assignments' })).toBeVisible();
    await page.getByRole('link', { name: 'Assign role' }).first().click();
    await expect(page.getByRole('heading', { name: 'Assign group or role' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Portal membership remains separate' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continue portal provisioning' }))
      .toHaveAttribute('href', /business-security\?section=memberships&mode=create/u);
    await expectBoundedAccessWorkspace('access-role-assignment');

    await page.goto('/administrator/access?section=tokens');
    await expect(page.getByRole('heading', { name: 'API, CLI and MCP tokens' })).toBeVisible();
    await page.getByRole('link', { name: 'Create token' }).first().click();
    await expect(page.getByRole('heading', { name: 'Create API, CLI or MCP token' })).toBeVisible();
    await expect(page.getByLabel('Capabilities')).toHaveAttribute('multiple', '');
    await expect(page.getByLabel('Audience')).toBeVisible();
    await expect(page.getByLabel('Purpose')).toBeVisible();
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expectBoundedAccessWorkspace('access-token-issuance');

    await page.goto('/administrator/access?section=events');
    await expect(page.getByRole('heading', { name: 'Security events' })).toBeVisible();
    const securityEvents = page.getByRole('region', { name: 'Identity security events' });
    await expect(securityEvents.locator('tbody')).not.toContainText(/password|recovery_code|metadata/u);
    await expect(page.getByText('Operational metadata and all credential values are omitted.'))
      .toBeVisible();
    await page.getByRole('link', { name: 'Set up my verification' }).click();
    await expect(page.getByRole('heading', { name: 'Authenticator and recovery setup' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Begin authenticator setup' })).toBeVisible();
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);
    await expectBoundedAccessWorkspace('access-step-up-enrollment');
  });

  test('published content links through a typed menu to its canonical path', async ({
    page,
  }, testInfo) => {
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
    const title = `Browser About ${suffix}`;
    const slug = `browser-about-${suffix}`;

    await page.goto('/administrator/content/new');
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('URL slug').fill(slug);
    await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(`Published ${title}`);
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/);

    await page.getByRole('button', { name: 'Move to Review' }).click();
    await page.getByRole('button', { name: 'Move to Published' }).click();
    await expect(page.getByRole('link', { name: 'View page' })).toHaveAttribute('href', `/${slug}`);

    await page.goto('/administrator/navigation');
    const addItem = page.locator('details').filter({ hasText: 'Add a menu item' }).first();
    await addItem.locator('summary').click();
    const form = addItem.locator('form');
    await form.getByLabel('Link type').selectOption('content');
    await form.locator('select[name="content_id"]').selectOption({ label: `${title} · Published` });
    await form.getByLabel('Link label').fill(title);
    await form.getByLabel('URL segment').fill(slug);
    await form.getByRole('button', { name: 'Add link' }).click();
    await expect(page.getByText(`Calculated menu path: /${slug}`)).toBeVisible();

    await page.goto(`/${slug}`);
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
    await expect(page.getByRole('link', { name: title, includeHidden: true })).toHaveAttribute(
      'href',
      `/${slug}`,
    );
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#site-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'published-content-canonical-page');
  });

  test('media library is usable and accessible', async ({ page }, testInfo) => {
    await page.goto('/administrator/media');
    await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
    await expect(page.getByLabel('File type')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('media-library.png'), fullPage: true });
  });

  test('site identity and corporate schemes are managed graphically', async ({ page }, testInfo) => {
    const handle = `browser_${testInfo.project.name.replaceAll('-', '_')}`;
    await page.goto('/administrator/settings');
    await expect(page.getByRole('heading', { name: 'Site settings' })).toBeVisible();
    await expect(page.getByLabel('Active color scheme')).toHaveValue(/corporate|browser_/);

    await page.getByRole('button', { name: 'Choose media' }).click();
    const mediaDialog = page.getByRole('dialog', { name: 'Choose the site logo' });
    await expect(mediaDialog).toBeVisible();
    await mediaDialog.getByLabel('Filter media').fill('kumwe-symbol');
    await mediaDialog.getByRole('button', { name: /kumwe-symbol\.svg/ }).click();
    await expect(page.locator('#presentation-logo')).toHaveValue(/kumwe-symbol\.svg$/);

    await page.getByRole('button', { name: 'Add color scheme' }).click();
    const scheme = page.locator('[data-presentation-scheme-row]').last();
    await scheme.getByLabel('Name').fill(`Browser ${testInfo.project.name}`);
    await scheme.getByLabel('Handle').fill(handle);
    await page.getByLabel('Active color scheme').selectOption(handle);
    await page.getByLabel('Button treatment').selectOption('soft');
    await page.getByLabel('Button shape').selectOption('pill');
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Save settings and design' }).click();
    await expect(page).toHaveURL(/\/administrator\/settings\?saved=1$/);

    await page.goto('/');
    await expect(page.locator('body')).toHaveAttribute('data-presentation-scheme', handle);
    await expect(page.locator('body')).toHaveAttribute('data-button-style', 'soft');
    await expect(page.locator('body')).toHaveAttribute('data-button-shape', 'pill');
    await expectAccessible(page);

    await page.goto('/administrator/settings');
    await page.getByLabel('Active color scheme').selectOption('corporate');
    await page.getByLabel('Button treatment').selectOption('solid');
    await page.getByLabel('Button shape').selectOption('rounded');
    await page.getByRole('button', { name: 'Save settings and design' }).click();
    await expect(page).toHaveURL(/\/administrator\/settings\?saved=1$/);
  });

  test('automation uses generated job controls rather than JSON', async ({ page }, testInfo) => {
    await page.goto('/administrator/automation');
    await expect(page.getByRole('heading', { name: 'Automation' })).toBeVisible();
    await expect(page.locator('textarea[name="payload"]')).toHaveCount(0);
    await expect(page.getByLabel('Job type')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('automation.png'),
      fullPage: true,
      mask: [page.locator('[data-visual-dynamic]')],
      maskColor: '#ffffff',
    });
  });

  test('generated business workspaces are responsive, accessible and visually bounded', async ({
    page,
  }, testInfo) => {
    await page.goto('/administrator/business');
    await expect(page.getByRole('heading', { level: 1, name: 'Business records' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Open session 5 orders/i })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open Example inspections' })).toBeVisible();
    const liveNavigation = page.locator('.administrator-navigation');
    await expect(liveNavigation.locator('a[href="/administrator/access"]')).toHaveCount(1);
    await expect(liveNavigation.locator('a[href="/administrator/business-security"]')).toHaveCount(1);
    await expect(liveNavigation.locator('a[href="/administrator/reports"]')).toHaveCount(1);
    await expect(liveNavigation.locator(
      'a[href^="/administrator/extensions/kumwe/asset-inspection-example"]',
    )).toHaveCount(1);
    await expectStylesLoaded(page);
    await expectAccessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-workspaces');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-workspace.png', {
      fullPage: true,
    });
  });

  test('asset-inspection extension workspace is policy-filtered and bounded', async ({
    page,
  }, testInfo) => {
    await page.goto('/administrator/extensions/kumwe/asset-inspection-example');
    const surface = page.locator(
      '[data-kis-surface="kumwe.asset-inspection-example.administrator.index"]',
    );
    await expect(surface).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Asset inspection example' }))
      .toBeVisible();
    await expect(page.getByRole('heading', { name: 'Row and field disclosure' })).toBeVisible();
    await expect(page.getByText('EXAMPLE-INSPECTION-001')).toBeVisible();
    await expect(page.getByText('ROW-POLICY-DENIED')).toHaveCount(0);
    await expect(page.getByText('FOREIGN-SITE-ROW')).toHaveCount(0);
    await expect(page.getByText('Restricted-field disclosure: withheld.')).toBeVisible();
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'asset-inspection-extension-workspace');
  });

  test('reports execute graphically and expose queued export status', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    await page.goto('/administrator/reports');
    await expect(page.getByRole('heading', { level: 1, name: 'Business reports' })).toBeVisible();
    await expect(page.locator('a[href="/administrator/reports"]')).toHaveAttribute(
      'aria-current',
      'page',
    );
    await expect(page.getByRole('link', { name: 'Asset inspection example', exact: true }))
      .toBeVisible();
    const report = page.locator(`form[action="/administrator/reports/${assetInspectionReport}"]`);
    await expect(report.getByRole('heading', { name: 'Asset inspection example summary' }))
      .toBeVisible();
    await expect(report.getByText(assetInspectionReport, { exact: true })).toBeVisible();
    await report.locator('[name="parameters[minimum_score]"]').fill('70');
    await report.getByRole('button', { name: 'Run report', exact: true }).click();

    const results = page.getByRole('region', { name: 'Report results', exact: true });
    await expect(results).toBeVisible();
    const resultsTable = results.getByRole('region', { name: 'Scrollable report results table' });
    await expect(resultsTable).toBeVisible();
    await expect(resultsTable.getByRole('columnheader', { name: 'Reference' })).toBeVisible();
    await expect(resultsTable.getByRole('columnheader', { name: 'Risk score' })).toBeVisible();
    const accepted = resultsTable.getByRole('row').filter({ hasText: 'BROWSER-INSPECT-001' });
    await expect(accepted).toBeVisible();
    await expect(accepted).toContainText('79');
    await expect(resultsTable.getByText('BROWSER-POLICY-DENIED', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Browser report restricted note', { exact: true })).toHaveCount(0);
    await expectStylesLoaded(page);
    await expectAccessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    if ((page.viewportSize()?.width ?? 0) >= 900) {
      const dimensions = await page.locator('.kis-business-report-grid').evaluate((layout) => ({
        catalogWidth: layout.children.item(0)?.getBoundingClientRect().width ?? 0,
        workspaceWidth: layout.children.item(1)?.getBoundingClientRect().width ?? 0,
      }));
      expect(dimensions.workspaceWidth).toBeGreaterThan(dimensions.catalogWidth);
    }
    await page.screenshot({
      path: testInfo.outputPath('administrator-report-results.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });

    await report.getByRole('button', { name: 'Queue CSV export', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
    await expect(page.getByText('Queued', { exact: true })).toBeVisible();
    await expect(page.locator('dl')).toContainText('Rows');
    await expect(page.locator('dl')).toContainText('Pending');
    const status = page.getByRole('link', { name: 'Refresh status' });
    await expect(status).toHaveAttribute(
      'href',
      /^\/administrator\/reports\/exports\/[0-9a-f-]{36}$/u,
    );
    await expect(page.getByRole('link', { name: 'Download verified CSV' })).toHaveCount(0);
    await status.click();
    await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
    await expectAccessible(page, 'section[aria-labelledby="export-history-title"]');
    await page.locator('section[aria-labelledby="export-history-title"]').screenshot({
      path: testInfo.outputPath('administrator-export-status.png'),
      animations: 'disabled',
      caret: 'hide',
    });
  });

  test('generated business list remains responsive and progressively enhanced', async ({
    page,
    isMobile,
  }, testInfo) => {
    await page.goto(`/administrator/business/${businessDefinitionHandle}`);
    await expect(page.locator(isMobile ? '.kis-business-result-card' : '.business-record-table tbody tr').first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Report', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Export', exact: true })).toBeVisible();
    await page.getByLabel('Search records').fill('Windhoek order');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(page.locator(isMobile ? '.kis-business-result-card' : '.business-record-table tbody tr').filter({ hasText: 'Windhoek order' }).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    if (isMobile) {
      expect(await page.locator('.business-table-wrap').evaluate((table) =>
        table.scrollHeight - table.clientHeight,
      )).toBe(0);
    }
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-list-live');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-list.png', {
      fullPage: true,
      mask: [page.locator('.business-record-table tbody')],
      maskColor: '#d9e2e8',
    });
    const pageSearch = page.getByLabel('Filter this page');
    await pageSearch.fill('does-not-match-any-seeded-record');
    await expect(page.getByText('No records on this page match your search.')).toBeVisible();
    await pageSearch.clear();
    await page.locator('input[name="bulk_records[]"]:visible').first().check();
    await page.getByLabel('Bulk operation').selectOption('archive');
    await page.getByRole('button', { name: 'Review bulk operation' }).click();
    await expect(page.getByRole('heading', { name: 'Archive selected records' })).toBeVisible();
    await expect(page.locator('input[name="operation_id"]')).not.toHaveValue('');
    await expect(page.locator('input[name="confirmed"]')).toHaveValue('1');
    await page.goBack();
  });

  test('generated operation status and custom views are accessible and bounded', async ({
    page,
  }, testInfo) => {
    test.setTimeout(90_000);
    const expectBoundedGeneratedSurface = async (attachment: string): Promise<void> => {
      await expectAccessible(page);
      const diagnostics = await expectNoDocumentOverflow(page, {
        root: '#administrator-content',
        detectControlOverlaps: false,
      });
      expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
      await attachLiveInterfaceScreenshot(page, testInfo, attachment);
    };

    await page.goto(`/administrator/business/${businessDefinitionHandle}?new=1`);
    const name = `Operation evidence ${testInfo.project.name} ${Date.now()}`;
    await fillSession5OrderForm(page, name);
    await page.getByRole('button', { name: 'Create record' }).click();
    await expect(page).toHaveURL(new RegExp(
      `/administrator/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
    ));
    await page.getByRole('link', { name: 'View operation status' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Operation status' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Operation completed' })).toBeVisible();
    await expect(page.locator('[data-kis-component="generated-operation-status"]'))
      .toHaveAttribute('data-kis-surface', 'core.administrator.generated-operation');
    await expect(page.getByRole('link', { name: 'Return to record', exact: true })).toBeVisible();
    await expectBoundedGeneratedSurface('administrator-operation-status');

    await page.goto(`/administrator/business/${assetInspectionDefinition}`);
    const customView = page.getByRole('link', { name: 'Inspection risk summary' });
    await expect(customView).toBeVisible();
    await customView.click();
    await expect(page.getByRole('heading', { level: 1, name: 'Inspection risk summary' })).toBeVisible();
    await expect(page.locator('[data-kis-component="generated-custom-view"]'))
      .toHaveAttribute('data-kis-surface', 'core.administrator.generated-custom-view');
    await expect(page.getByRole('heading', { name: 'View result' })).toBeVisible();
    await page.getByLabel('Rows available to the view').selectOption('25');
    await page.getByRole('button', { name: 'Run view' }).click();
    await expect(page).toHaveURL(/page_size=25/u);
    await expect(page.getByText('Policy-filtered inspection risk summary')).toBeVisible();
    await expect(page.getByText('BROWSER-INSPECT-001')).toBeVisible();
    await expect(page.getByText('BROWSER-POLICY-DENIED', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Browser report restricted note', { exact: true })).toHaveCount(0);
    const restricted = page.locator('.kis-business-custom-object > div').filter({
      has: page.getByText('Restricted fields disclosed', { exact: true }),
    });
    await expect(restricted.locator('dd')).toHaveText('No');
    await expectBoundedGeneratedSurface('administrator-custom-view');
  });

  test('generated business detail and confirmation remain accessible and visually stable', async ({
    page,
  }, testInfo) => {
    await page.goto(
      `/administrator/business/${businessDefinitionHandle}/${windhoekOrderId}`,
    );
    await expect(page.getByRole('heading', { name: 'Record details' })).toBeVisible();
    await expectAccessible(page);
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-detail');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-detail.png', {
      fullPage: true,
      mask: [page.locator('time')],
      maskColor: '#d9e2e8',
    });
    await page.getByRole('link', { name: 'Archive record' }).click();
    await expect(page.getByRole('heading', { name: 'Archive record' })).toBeVisible();
    const confirm = page.getByRole('button', { name: 'Archive record' });
    await expect(confirm).toBeDisabled();
    await page.getByRole('checkbox').check();
    await expect(confirm).toBeEnabled();
    await expectAccessible(page);
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-confirmation');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-confirmation.png', {
      fullPage: true,
    });
  });

  test('generated relationship selectors and owned lines persist and reorder', async ({
    page,
    isMobile,
  }, testInfo) => {
    test.setTimeout(90_000);
    await page.goto(`/administrator/business/${businessDefinitionHandle}`);
    await page.getByRole('link', { name: /Create session 5 order/i }).click();
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-form-live');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-form.png', {
      fullPage: true,
    });
    await expect(page.locator('[name="values[conditional_note]"]')).toHaveCount(0);
    await expect(page.locator('[name="values[display_name]"]')).toHaveCount(0);
    await expect(page.locator('[name="values[credential]"]')).toHaveAttribute('type', 'password');
    const name = `Relationship order ${testInfo.project.name} ${Date.now()}`;
    await fillSession5OrderForm(page, name);
    await page.getByRole('button', { name: 'Create record' }).click();
    await expectAdministratorRecordName(page, name);
    await expect(page.getByText('Stored conditional note', { exact: true })).toBeVisible();
    await expect(page.getByText('browser-secret-value', { exact: true })).toHaveCount(0);
    const recordUrl = new URL(page.url()).pathname;

    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    await expect(page.locator('[name="values[conditional_note]"]')).toBeVisible();
    await expect(page.locator('[name="values[display_name]"]')).toHaveCount(0);
    await expect(page.locator('[name="values[credential]"]')).toHaveAttribute('type', 'password');
    await page.goto(recordUrl);

    let tags = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Tags', exact: true }),
    });
    await tags.getByRole('link', { name: 'View and manage' }).click();
    tags = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Tags', exact: true }),
    });
    await tags.getByRole('link', { name: 'Search available records' }).click();
    await expect(page.getByRole('heading', { name: 'Choose tags' })).toBeVisible();
    await expect(page.locator('[data-kis-component="generated-choice-browser"]'))
      .toHaveAttribute('data-kis-surface', 'core.administrator.generated-choices');
    await expectAccessible(page);
    const choiceDiagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(choiceDiagnostics.findings, JSON.stringify(choiceDiagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'administrator-relationship-chooser');
    const choiceLayout = await page.locator('.business-choice-table').evaluate((table) => ({
      viewportWidth: window.innerWidth,
      rootOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableOverflow: table.scrollWidth - table.clientWidth,
    }));
    expect(choiceLayout.viewportWidth).toBe(isMobile ? 412 : 1440);
    expect(choiceLayout.rootOverflow).toBe(0);
    if (isMobile) {
      expect(choiceLayout.tableOverflow).toBeGreaterThan(0);
    }
    const windhoekChoice = page.getByRole('row', { name: /Windhoek relationship target/ });
    const walvisBayChoice = page.getByRole('row', { name: /Walvis Bay relationship target/ });
    await expect(windhoekChoice).toBeVisible();
    await expect(walvisBayChoice).toBeVisible();
    const chooseWindhoek = windhoekChoice.getByRole('link', { name: 'Choose' });
    await chooseWindhoek.click();
    await tags.locator('select[name="target_record_id"]').selectOption(windhoekTargetId);
    await tags.getByRole('button', { name: 'Add relationship' }).click();
    await expect(tags.locator('.business-related-records > li').filter({
      hasText: 'Windhoek relationship target',
    })).toBeVisible();

    await page.getByRole('link', { name: 'Back to record' }).click();
    let lines = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Lines', exact: true }),
    });
    await lines.getByRole('link', { name: 'View and manage' }).click();
    lines = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Lines', exact: true }),
    });
    let lineForm = lines.locator('form.business-owned-line-form');
    await lineForm.locator('[name="target_values[description]"]').fill('Browser line one');
    await lineForm.locator('[name="target_values[units]"]').fill('1.000');
    await lineForm.getByRole('button', { name: 'Add Neutral line' }).click();
    lineForm = lines.locator('form.business-owned-line-form');
    await lineForm.locator('[name="target_values[description]"]').fill('Browser line two');
    await lineForm.locator('[name="target_values[units]"]').fill('2.000');
    await lineForm.getByRole('button', { name: 'Add Neutral line' }).click();

    const orderedItems = lines.locator('[data-business-order-list] li');
    await expect(orderedItems).toHaveCount(2);
    const originalIds = await orderedItems.evaluateAll((items) =>
      items.map((item) => item.getAttribute('data-record-id') ?? ''),
    );
    const firstLineId = originalIds[0];
    const secondLineId = originalIds[1];
    if (!firstLineId || !secondLineId) {
      throw new Error('The generated owned-line order is incomplete.');
    }
    await orderedItems.nth(1).getByRole('button', { name: /^Move .* up$/ }).click();
    const orderControls = lines.locator('select[name="ordered_record_ids[]"]');
    await expect(orderControls).toHaveCount(2);
    await expect.poll(() => orderControls.evaluateAll((controls) =>
      controls.map((control) => (control as HTMLSelectElement).value),
    )).toEqual([secondLineId, firstLineId]);
    await lines.getByRole('button', { name: 'Save order' }).click();
    await expect(lines.locator('[data-business-order-list] li').first())
      .toHaveAttribute('data-record-id', secondLineId);
    await expectAccessible(page);
    const ownedLineDiagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(ownedLineDiagnostics.findings, JSON.stringify(ownedLineDiagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'administrator-owned-lines');
  });

  test('generated business forms complete an ordinary no-JavaScript lifecycle', async ({
    browser,
  }, testInfo) => {
    test.slow();
    test.setTimeout(300_000);
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
      await signIn(page);
      await page.goto(
        '/administrator/business-definitions?definition=kumwe.asset-inspection-example.inspection&tab=fields',
      );
      await expect(page.locator('html')).not.toHaveClass(/\bjs\b/);
      await expect(page.locator('[data-kis-tab-panel]')).toHaveCount(7);
      for (const panel of await page.locator('[data-kis-tab-panel]').all()) await expect(panel).toBeVisible();
      await expect(page.getByRole('tab', { name: 'Fields' })).toHaveAttribute('href', /tab=fields/);
      await page.goto('/administrator/business-schema-plans?tab=operations');
      await expect(page.locator('[data-kis-tab-panel]')).toHaveCount(6);
      for (const panel of await page.locator('[data-kis-tab-panel]').all()) await expect(panel).toBeVisible();

      await page.goto(`/administrator/business/${businessDefinitionHandle}`);
      await expect(page.locator('html')).not.toHaveClass(/\bjs\b/);
      await page.getByRole('link', { name: /Create session 5 order/i }).click();
      const form = page.locator('form.business-record-form');
      await expect(form).toHaveAttribute('method', 'post');
      await expect(form.locator('input[name="_csrf"]')).not.toHaveValue('');
      await expect(form.locator('input[name="operation_id"]')).not.toHaveValue('');
      await expect(form.getByRole('button', { name: 'Create record' })).toBeVisible();
      const name = `No JS administrator order ${testInfo.project.name} ${Date.now()}`;
      await fillSession5OrderForm(page, name);
      await form.getByRole('button', { name: 'Create record' }).click();
      await expect(page).toHaveURL(new RegExp(
        `/administrator/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
      ));
      await expectAdministratorRecordName(page, name);
      await expect(page.getByText('browser-secret-value', { exact: true })).toHaveCount(0);
      await page.getByRole('link', { name: 'View operation status' }).click();
      await expect(page.getByRole('heading', { level: 1, name: 'Operation status' })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Operation completed' })).toBeVisible();
      await page.getByRole('link', { name: 'Return to record', exact: true }).click();
      await page.getByRole('link', { name: 'Edit', exact: true }).click();
      const updatedName = `${name} updated`;
      await page.locator('[name="values[name]"]').fill(updatedName);
      await page.locator('[name="values[credential]"]').fill('browser-secret-updated');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expectAdministratorRecordName(page, updatedName);
      await page.getByRole('link', { name: 'History', exact: true }).click();
      await expect(page.getByRole('heading', { level: 1, name: 'Record history' })).toBeVisible();
      await expect(page.getByText('update', { exact: true }).first()).toBeVisible();
      await page.goto(`/administrator/business/${businessDefinitionHandle}`);
      await page.getByLabel('Search records').fill(updatedName);
      await page.getByRole('button', { name: 'Apply', exact: true }).click();
      await expectAdministratorRecordRow(page, updatedName);
      await page.locator('input[name="bulk_records[]"]:visible').first().check();
      await page.getByLabel('Bulk operation').selectOption('archive');
      await page.getByRole('button', { name: 'Review bulk operation' }).click();
      await page.getByRole('checkbox').check();
      await page.getByRole('button', { name: 'Archive selected records' }).click();
      await expect(page).toHaveURL(new RegExp(
        `/administrator/business/${businessDefinitionHandle}\\?saved=1&bulk_count=1$`,
      ));
      await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
      await page.getByLabel('Search records').fill(updatedName);
      await page.getByLabel('Include archived').check();
      await page.getByRole('button', { name: 'Apply', exact: true }).click();
      await expectAdministratorRecordRow(page, updatedName);
      await page.locator('input[name="bulk_records[]"]:visible').first().check();
      await page.getByLabel('Bulk operation').selectOption('restore');
      await page.getByRole('button', { name: 'Review bulk operation' }).click();
      await page.getByRole('checkbox').check();
      await page.getByRole('button', { name: 'Restore selected records' }).click();
      await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('typed component navigation opens an accessible graphical page', async ({
    page,
    isMobile,
  }, testInfo) => {
    await ensureAnnouncementsActive(page);
    await page.goto('/administrator');
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    const workspace = page.locator('.navigation-group').filter({
      has: page.getByRole('heading', { name: 'Announcements', exact: true }),
    });
    const announcements = workspace.getByRole('link', { name: 'Announcements' });
    await expect(announcements).toBeVisible();
    if (isMobile) {
      await announcements.focus();
      await announcements.press('Enter');
    } else {
      await announcements.click();
    }
    await expect(page).toHaveURL(/\/administrator\/extensions\/kumwe\/announcements-example$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Announcements' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Component announcements' })).toBeVisible();
    await expect(page.getByText('Contribution contract active')).toBeVisible();
    await expectStylesLoaded(page);
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'announcements-contribution');
  });

  test('component navigation and guarded route are unavailable without its capability', async ({
    page,
    isMobile,
  }) => {
    await ensureAnnouncementsActive(page);
    await page.context().clearCookies();
    await signIn(page, limitedEmail, limitedPassword);
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    await expect(page.getByRole('link', { name: 'Announcements' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Schema plans', exact: true })).toHaveCount(0);
    const schemaResponse = await page.goto('/administrator/business-schema-plans');
    expect(schemaResponse?.status()).toBe(403);
    const generatedBusinessResponse = await page.goto(
      `/administrator/business/${businessDefinitionHandle}`,
    );
    expect(generatedBusinessResponse?.status()).toBe(403);
    await expect(page.getByText('Windhoek order', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Walvis Bay order', { exact: true })).toHaveCount(0);
    const response = await page.goto('/administrator/extensions/kumwe/announcements-example');
    expect(response?.status()).toBe(403);
  });

  test('disabling removes component navigation and reactivation restores it', async ({
    page,
    isMobile,
  }) => {
    test.setTimeout(90_000);
    await ensureAnnouncementsActive(page);
    try {
      const extension = page.locator('article').filter({ hasText: 'kumwe/announcements-example' }).first();
      await extension.getByRole('button', { name: 'Disable' }).click();
      await expect(page).toHaveURL(/\/administrator\/extensions$/);
      await expect(extension).toContainText(/component · 2\.0\.0 · disabled/);

      await expect.poll(async () => {
        await page.goto('/administrator');
        return page.getByRole('link', { name: 'Announcements', exact: true }).count();
      }, {
        message: 'the disabled extension navigation to leave the local signed runtime map',
        timeout: 25_000,
      }).toBe(0);
      if (isMobile) {
        await page.getByRole('button', { name: 'Open administrator navigation' }).click();
      }
      await expect(page.getByRole('link', { name: 'Announcements' })).toHaveCount(0);
    } finally {
      await ensureAnnouncementsActive(page);
    }
    await page.goto('/administrator');
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    await expect(page.getByRole('link', { name: 'Announcements' })).toBeVisible();
  });
});
