# Customization

Customization is scoped, typed, portable, auditable, compatible, and resettable. It does not permit
arbitrary stored CSS, JavaScript, PHP, Twig, HTML, SQL, or expressions.

## Hierarchy

1. **KIS defaults** — immutable semantic and accessible baseline.
2. **Site or administrator theme** — installable package controlling approved identity and presentation.
3. **Administrator configuration** — allowed fields, columns, default views, ordering, help, dashboards,
   and role defaults.
4. **Role/workspace defaults** — contextual views and navigation shortcuts.
5. **User preferences** — mode, density, columns, saved filters, collapsed navigation, and dashboard cards.

A lower layer overrides only slots explicitly opened by the higher contract. Security, required warnings,
destructive classification, audit meaning, policy-hidden state, conflict/version state, and accessibility
semantics cannot be customized away.

Each surface declares a slot once and names the highest configuration layer allowed for it. That declaration
is a ceiling over the slot-specific legal order, not an exact-layer-only permission: for example, a dashboard
card slot declared at `user` also admits its legal administrator and role/workspace defaults. The surface area
still applies, so portal and public surfaces never gain an administrator layer and public surfaces never gain a
role/workspace layer merely because those layers are below a portable declaration's ceiling.

## Allowed capabilities

- light, dark, or system mode and comfortable or compact density;
- approved brand palette tokens after contrast validation;
- logo and organization identity;
- approved type roles and scale bounds;
- table column visibility, order, and width within mandatory-column constraints;
- bounded page size, sorting, filters, and saved views;
- dashboard card selection and order;
- authorized landing workspace and pinned shortcuts;
- localized labels/help through the translation and override registry;
- role-specific presentation defaults;
- reset current view, user preferences, role defaults, and site presentation to KIS defaults.

## Prohibited capabilities

- arbitrary stored CSS, JavaScript, Twig, PHP, or raw HTML;
- page-local brand colors, shadow systems, spacing scales, or unreviewed z-indexes;
- hiding required warnings, review, step-up, audit context, conflict state, or recovery consequences;
- exposing policy-hidden fields, actions, relationships, labels, counts, or examples;
- reordering a process so authorization, review, or confirmation can be bypassed;
- inaccessible colors, removed focus, undersized controls, or motion without a reduced alternative;
- replacing KIS component semantics with an unrelated theme-only interaction.

## Persistence and compatibility

Every customization record has schema version, owner, site/organization/workspace/user scope, optimistic
version, audit attribution, allowlisted values, and export/import behavior. KIS upgrades compile and
migrate preferences. Removed slots produce a diagnostic and safe default; they never silently corrupt a
layout. Reset is always available to an authorized actor.

The portable record shape is
[`schemas/presentation-preference.schema.json`](schemas/presentation-preference.schema.json). It admits only
the bounded value vocabulary for its selected slot and scope; it cannot carry CSS, JavaScript, Twig, HTML,
URLs, selectors, arbitrary component names, or policy instructions. A reset removes the scoped record and
reveals the next valid layer in the hierarchy. Import must revalidate the current surface permission, actor,
owner, optimistic version and KIS compatibility before persistence.

The stored owner is either `core` or the canonical extension identifier: two slash-separated segments of
1–63 lowercase alphanumeric, dot, underscore, or hyphen characters, each beginning with a letter or digit.
The associated owner-bound surface, landing workspace, and navigation shortcuts use the corresponding
bounded dotted grammar shared by contribution routes and workspaces. Import then applies the exact owner
namespace check; accepting the full extension identifier vocabulary never relaxes ownership.

KIS-aware schema tooling registers `x-kumwe-ownedSurface` to repeat that exact owner-prefix and safe-suffix
check during portable-document preflight. Standard JSON Schema consumers may treat the extension keyword as
an annotation; the PHP import boundary always enforces it.

The production type, precedence, persistence, authorization, audit, import/export, and reset boundaries are
specified in [Presentation preference runtime](presentation-preference-runtime.md).

## Installable templates

Site templates may replace the complete public `home.twig` and `page.twig` views because public content
contracts are presentation-ready and the surface is site-scoped. Administrator templates may replace the
shell contract and style KIS tokens; security-sensitive page semantics and core KIS components remain
protected. Extension administrator and portal views consume the same KIS components through stable Twig
namespaces.

The detailed package, override, validation, and recovery rules are in
[Template authoring](template-authoring.md). A template package declares its required KIS version and
supported token/component contract. Activation compiles it and validates the required structural shell,
asset outlets, navigation, current-item, skip-link, and focusable-main landmarks. Rendered qualification
separately proves contrast, focus visibility, supported modes, responsive behavior, and visual integrity;
the core recovery renderer remains available throughout.

## Change propagation

- A token change updates every consuming surface through shared properties.
- A component change updates every core and extension consumer through the shared Twig/Lit implementation.
- A pattern/compiler change updates typed declarations through explicit compatibility rules and preference
  migrations.

Page-specific CSS is not a propagation mechanism. Exceptions are named, scoped, documented, and tested.
