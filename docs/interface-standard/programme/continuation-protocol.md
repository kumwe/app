# Phase continuation protocol

Use this protocol at the start and end of every interface-migration session. Its purpose is to let later
Phase 3–6 chats resume from repository evidence instead of trusting a narrative summary.

## Start of a session

1. Read `AGENTS.md` and every document it requires.
2. Read the current KIS normative documents, the findings register, the latest verification report, and
   every other file in this programme directory.
3. Run `composer interface:programme` before changing source. If it fails, reconcile the inventory and
   ledger with the repository before selecting implementation work.
4. Inspect the current branch, recent commits, open changes, and the latest accepted evidence. Never assume
   a prior chat finished merely because a phase number appears in prose.
5. Select only ledger work items whose prerequisites are complete and whose status is `ready`,
   `in_progress`, or an explicitly resolved `blocked` state. Record the responsible implementation role.
6. Reconfirm affected surfaces, actors, tasks, journeys, capabilities, security invariants, fixtures,
   installed-template/extension compatibility, and old/new parity obligations.
7. Update the work item's status history to `in_progress` in the same first coherent commit that begins
   implementation. Do not bulk-mark work complete in advance.

## During implementation

- Migrate one bounded context or independently verifiable platform primitive at a time.
- Preserve route names, capability enforcement, application-service ownership, payloads, validation, CSRF,
  optimistic concurrency, step-up, approval, audit, recovery, and no-JavaScript behaviour unless an approved
  decision explicitly changes one.
- Add new routes, navigation, templates, generated exposures, fixtures, and extension/template slots to the
  inventory in the same commit as their source.
- Turn accepted visual or AI-assisted findings into deterministic assertions where practical. AI review
  may classify evidence; it cannot be the only gate and does not rewrite production code autonomously.
- Record newly found issues immediately with severity, surface, owner, reproduction, and target phase. Do
  not defer a finding by leaving it only in a chat or test log.
- Keep production templates declarative. Domain, authorization, query, and mutation rules remain in shared
  application services.
- Prove customization through supported token, component, contribution, and template mechanisms. Never use
  stored arbitrary CSS/JavaScript or raw template injection to satisfy a migration.

## End of a session

1. Run the narrowest affected tests, `composer interface:programme`, frontend checks/build, and all phase
   evidence commands that can run locally. Run the complete required suite before declaring a gate complete.
2. Copy the verification-report template, retain every canonical check row, and record exact commands,
   environments, results, artifacts, and finding blockers. Never convert unavailable execution into a pass.
3. Add evidence records with revision, environment, command/review method, result, and artifact paths.
4. Compare every affected surface against its pre-migration capability/field/action/payload manifest.
5. Update each work item separately: `in_review` when runtime evidence exists but acceptance is outstanding;
   `complete` only after all evidence slots and blocking findings are resolved.
6. Append a continuation entry naming the exact next ready work items, blockers, decisions, and evidence.
7. Commit documentation, programme state, runtime change, and its tests coherently. Never amend historical
   evidence merely to make the current state appear clean.
8. Push only after local iteration is complete; CI confirms the result and is not the primary test loop.

## Phase checklists

The machine-readable, item-level checklist lives in `phase-ledger.json`. These are its non-negotiable
outcomes.

### Phase 0 — defects and diagnostic baseline

- complete surface, template, navigation, actor, task, journey, and migration inventory;
- correct the portal guest shell and Business Security icon;
- enforce icon-registry integrity;
- add guest desktop/mobile visual regression and reusable element clipping/overflow/overlap diagnostics;
- capture administrator and portal landing baselines;
- prove the Business Definitions failure is detected at component level.

### Phase 1 — standard and reusable foundations

- approve KIS 1.0 principles, patterns, semantics, customization, and governance;
- implement tokens plus accessible server-first page header, tabs, master-detail, toolbar,
  technical-value, drawer, and validation-summary primitives;
- retain keyboard, mobile, theme, reduced-motion, and essential no-JavaScript behaviour;
- publish gallery fixtures and extension/template conformance contracts;
- prove an installed template can customize approved slots without breaking KIS or recovery.

### Phase 2 — reference vertical slice

- migrate Business Definitions to a catalog, contextual header, URL-addressable tasks, and focused field
  work without losing validation, publication, immutable ownership, or history;
- migrate Schema Plans to Summary, Operations, Approval, Execution, Recovery, and History tasks;
- cover long handles, many fields/operations, package-owned read-only, permission-reduced, destructive, and
  recovery states across the required matrix;
- complete old/new route, field, action, payload, security, and no-JavaScript parity evidence.

### Phase 3 — security and identity

- split Business Security into Overview, Organizations, Memberships, Policies, Approvals, and Credentials;
- split Users & Access into users, groups/roles, grants, assignments, tokens, and security events;
- separate browse, create, edit, review, and history flows; collect step-up at the action boundary;
- deliver and test the ordinary portal-user provisioning journey for full and reduced actors.

### Phase 4 — generated business and reports

- migrate administrator and portal business discovery, lists, details, forms, relations, workflow,
  history, bulk work, operation status, and custom views through shared KIS patterns;
- separate report catalog, runner, results, export status, and download history;
- provide filters, saved/default views, columns, pagination, dense/mobile alternatives, and clear selection;
- prove extension-generated surfaces need no extension-specific CSS and preserve cross-adapter policy parity.

### Phase 5 — remaining surfaces and template qualification

- migrate Content, Content Models, Navigation, Extensions, Automation, Media, Settings, dashboards, public
  presentation, portal home/account, and remaining extension views;
- remove superseded one-off markup/styles only after parity passes;
- finish dark/high-contrast, reduced-motion, keyboard, zoom, touch, print, localization/long-label, and
  all fixture-state review;
- qualify administrator, portal, and site template install/override/upgrade/fallback/reset behaviour.

### Phase 6 — whole-system qualification

- run complete production topology and MariaDB/MySQL/PostgreSQL-relevant interface evidence locally;
- execute every cross-surface journey, including provision-to-portal, definition-to-report, extension
  lifecycle, and customization upgrade/reset;
- require every new graphical contribution to declare purpose, actor, states, fixtures, capability, icon,
  KIS version, customization slots, and conformance tests;
- close every P0/P1 and every unwaived P2, publish approved evidence, and run all QA, browser,
  accessibility, visual, deployment, security, and recovery gates before final push.

## Continuation hand-off format

The final hand-off for a session must name:

- branch and source revision;
- work-item IDs completed, in review, blocked, and next ready;
- affected surface and journey IDs;
- evidence IDs and exact commands run;
- verification report ID, source revision, passed/blocked/not-run matrix, and excluded working-tree scope;
- open findings and severities;
- KIS decisions or deviations added;
- migration, customization, extension, security, and recovery implications;
- any local check that could not run and the exact external blocker.

The hand-off is a summary. The committed JSON records remain authoritative when the two disagree.
