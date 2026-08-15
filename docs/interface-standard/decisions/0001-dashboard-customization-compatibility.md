# KIS decision 0001 — Preserve KIS 1.0 while correcting dashboard customization admission

**Status** Accepted
**Owner** KIS owner
**Supporting owners** Extension contract owner, Security owner, Quality owner, Release owner
**Applies to** `kis-1.0` and presentation-preference schema 1
**Affected surfaces** `core.administrator.dashboard`, `core.portal.home`, and extension-owned workflow
contributions selected on either surface
**Related work** `V2-ERP-006`
**Verified against** Pending the final implementation revision

---

## Context and unmet need

An administrator or portal actor needs a dashboard assembled from current permitted work, with defaults that
an authorized administrator or access-group manager can specialize and that a user can override. A trusted
extension's ordinary navigation contribution must be selectable without a dashboard-only registry or a
page-local widget contract.

Two inconsistencies prevented that outcome.

1. `CustomizationScope` has described its value as the **highest** configuration layer since the KIS 1.0
   preference contract was introduced, and the normative customization hierarchy says lower layers override
   only slots opened by the higher contract. The live registry policy nevertheless compared the declared and
   requested scopes for equality. A surface declaring `dashboard-cards` at `user` therefore rejected the
   administrator and role/workspace defaults the declaration was intended to permit.
2. Schema-one `dashboard-cards` values used the legacy semantic-name grammar. The dashboard now projects the
   existing navigation catalogue, whose identifiers use the established dotted contribution grammar. The
   legacy grammar admits values such as `summary`, `work-queue`, and `core.content`, but excludes valid
   contribution identifiers with digit-led segments, underscores, or legacy repeated dots. Replacing that
   grammar outright would make previously valid stored values unreadable.

The existing KIS `status-workspace` pattern, customization slots, preference runtime, contribution registry,
and shared server-rendered components satisfy the user need. The inconsistency is in admission, not in the
available interaction model, so no new pattern, slot, state, component system, or extension SPI is justified.

## Decision

### 1. The KIS identifier remains `kis-1.0`

This is a compatible correction to KIS 1.0, not a new minor or major contract.

- Scope-ceiling behavior makes the runtime implement the meaning already published by
  `CustomizationScope`; it changes no surface-declaration field or serialized declaration.
- Dashboard-card admission is an additive union of the complete schema-one semantic-name grammar and the
  existing dotted contribution-identifier grammar. Every value accepted before this decision remains
  accepted and round-trips with identical bytes.
- No published KIS field, component, shell requirement, customization slot, stored-record field, or extension
  contribution shape is removed or incompatibly changed. The dashboard actions, responsive grid and protected
  consumer components are additive implementations of the existing `status-workspace` pattern and slot model.

The decision to retain `kis-1.0` is conditional on preserving those compatibility properties in executable
evidence. Narrowing dashboard cards to dotted identifiers alone would violate this decision and would require
a separately versioned migration decision.

### 2. A declared customization scope is a slot-specific ceiling

For one declared slot, the declared scope is the highest legal layer that may hold a value. Admission uses the
slot's order from the closed KIS scope matrix, not the ordinal position of `CustomizationScope` in isolation.
Both the declared ceiling and requested layer must be legal for that slot, and the requested layer must be at
or below the ceiling.

The rendered area then intersects that result with the layers it can consume:

- administrator surfaces may consume every layer legal for the slot;
- portal surfaces never consume the administrator layer;
- public surfaces never consume administrator or role/workspace layers; and
- template declarations do not form a preference resolution context.

This precedence is deterministic and preserves the existing low-to-high resolution order. A ceiling creates
no preference row and grants no write authority by itself.

### 3. Existing authorization and live-catalog filtering remain the security boundaries

The active contribution owner must still expose the exact surface and slot. Site, installation-administrator,
role/workspace, access-group, and user mutations retain their existing capability, identity, transaction,
optimistic-version, CSRF, and audit checks. A user ceiling therefore permits an authorized lower-layer default;
it does not authorize the current caller to write one.

A dashboard-card preference stores only a bounded ordered list of identifiers. It cannot store a URL, markup,
script, selector, component, or policy instruction. At render time the dashboard intersects those identifiers
with the navigation catalogue already filtered for area, capability, contribution ownership, extension trust,
and lifecycle. Unknown or stale entries are pruned with a stable diagnostic; a non-empty selection with no
survivors falls back to the current live default. No stored identifier can restore a hidden destination.

### 4. Dashboard-card grammar is additive

Schema one continues to admit up to 64 unique legacy semantic names. It additionally admits values accepted by
the existing `SurfaceId` dotted grammar so every valid navigation contribution can be selected. New dashboard
forms write only identifiers present in the live catalogue, but import and hydration retain legacy values so an
upgrade never converts valid preference bytes into repository corruption.

The wider grammar is deliberately limited to `dashboard-cards`. Column, label, saved-view, landing-workspace,
and navigation-shortcut contracts retain their existing slot-specific grammars and bounds.

The 64-card and 32-shortcut value bounds limit a saved selection; they do not truncate candidate identity.
The protected preference workspace exposes 32 permitted workflows on each of 100 numeric pages and bounded
191-character search across the complete already-filtered current catalogue. A selected live candidate outside
the current page is prepended to its own form, while stale or disabled candidates remain absent. Mutation checks
the submitted identifiers against that complete current catalogue, so paging changes discoverability without
weakening live-catalog admission or making page position part of stored preference bytes.

### 5. Responsive, accessibility, no-JavaScript, and failure behavior do not fork

This decision governs admission and identifier compatibility while the same change adds protected consumer
components within KIS 1.0's compatible component-improvement allowance. The dashboard keeps programmatic widget
headings, labelled quick-link navigation, a responsive grid, a visible search label, permission-reduced and
empty states, and a server-rendered save/reset workflow. Reading, saving, validation, failure recovery, and
reset continue to work without JavaScript.

Malformed values fail at schema or typed hydration. Stale but well-formed values remain non-executable and use
the deterministic prune/fallback behavior above. There is no approximate rendering path.

## Compatibility, migration, reset, and deprecation

- **Surface declarations:** no migration. Existing `kis-1.0` declarations retain their bytes; their scope now
  has the highest-layer meaning it already documented.
- **Stored preferences:** no forward migration or rewrite. Every schema-one dashboard-card value remains
  parseable and exportable. A legacy value that names no current live widget is retained in storage and handled
  by ordinary prune/fallback behavior rather than guessed into a different identifier.
- **Extensions and templates:** no minimum-version change and no parallel renderer. Extensions continue to
  contribute ordinary owner-bound KIS surfaces and navigation; templates continue to consume the same protected
  semantic dashboard contract.
- **Reset:** the existing authorized scoped reset deletes that preference row and reveals the next valid layer
  or immutable default. Reset is optional for upgrade and remains the recovery path for an unwanted selection.
- **Rollback:** before reverting to code predating this decision, reset any dashboard-card row containing an
  identifier that the former semantic-name grammar did not accept. The forward upgrade itself needs no data
  operation.
- **Deprecation window:** none. No declaration, preference field, renderer, or legacy semantic identifier is
  deprecated. Legacy schema-one identifiers remain supported for the lifetime of schema one.

## Conformance evidence

The compatibility claim is executable at these boundaries:

- `PresentationPreferenceTest::testDashboardCardIdentifierWideningPreservesExistingPreferenceBytes` proves
  legacy semantic names and newly admitted contribution identifiers hydrate and round-trip together;
- `InterfaceStandardSchemaTest::testPreferenceSchemaAndExampleCoverTheCustomizationVocabulary` pins the
  portable schema's additive union;
- `tools/verify-interface-schemas.mjs` admits both grammar families and rejects executable-shaped identifiers;
- `SurfaceDefinitionTest::testCustomizationScopeCeilingUsesSlotSpecificLegalOrder` proves slot-specific
  precedence and rejects layers above or outside the slot order;
- `PresentationPreferenceResolverTest::testRegisteredPolicyTreatsLiveCustomizationDeclarationAsScopeCeiling`
  proves administrator and portal area intersections; and
- the dashboard composer and preference-manager unit suites prove live-catalog pruning, fallback, exact-role
  authorization, optimistic versioning, reset, and bounded list composition;
- the dashboard preference service, role-repository integration and protected-template suites prove
  collection-level catalogue denial, deterministic paging beyond sixty-four roles, truthful browse-limit
  evidence, bounded literal search reaching a uniquely identified role beyond the raw authorization window,
  visible canonical role codes and fixed same-area continuation;
- the dashboard workflow catalogue, query decoder, form presenter and both area-handler suites prove exact
  32-by-100 candidate paging, full-current-catalogue targeted search, selected-live off-page retention,
  independent role/workflow continuation state and mutation beyond the former fixed renderer prefix; and
- browser scenarios prove administrator and portal personal and access-group mutation flows plus trusted
  contribution disable, prune, fallback, reactivation and stored-selection recovery through the ordinary
  runtime.

Required release evidence is `npm run check:schemas`, the unit and architecture suites, the supported-database
suite, frontend and browser qualification for both areas, and the ordinary security workflow.

## Release note and ownership

The dashboard entry in [`CHANGELOG.md`](../../../CHANGELOG.md) is the release note for this correction and
links back to this decision. The KIS owner owns the retained-version classification and semantic precedence;
the Extension contract owner owns declaration and contribution compatibility; the Security owner owns
area-safe admission and authorization evidence; the Quality owner owns deterministic conformance; and the
Release owner must not qualify the change while any required check is red.

## Alternatives rejected

### Require one declaration entry for every exact scope

Rejected because the surface schema intentionally declares each slot once, and `CustomizationScope` already
defines that value as the highest layer. Repeated entries would contradict uniqueness, force declaration churn,
and still need area-safe precedence.

### Replace schema-one dashboard-card names with dotted identifiers

Rejected because valid stored values would fail hydration. Automatic rewriting is also rejected: a legacy name
has no universal mapping to a current contribution identifier, so guessing would silently change the user's
selection.

### Add a dashboard-only extension registry or renderer

Rejected because the filtered navigation and KIS surface registries already carry stable ownership, trust,
lifecycle, capability, route, icon, and semantic identifiers. A second registry would duplicate those controls
and create an unversioned extension path.

## Non-goals

- No arbitrary widget markup or extension-supplied dashboard data renderer.
- No change to navigation-shortcut grammar or list bounds.
- No new role, workspace, membership, capability, or authorization source.
- No automatic translation of stale legacy identifiers.
- No KIS 1.1 or KIS 2.0 renderer hidden behind the `kis-1.0` identifier.
