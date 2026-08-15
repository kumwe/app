# ADR 0006 — Role-specific dashboards project the unified contribution runtime

**Status** Accepted
**Decided by** Product owner
**Verified against** Pending the final implementation revision
**Findings** `V2-ERP-006`
**Gate** None

---

## Context

`V2-ERP-006` recorded two possible ways to provide role-specific dashboards. Core could add a contributed
dashboard-panel registry and a per-role landing route, or extensions could continue to contribute ordinary
guarded routes, views and navigation while the existing dashboard selected from those contributions.

The unified extension runtime already compiles owner-bound surfaces, guarded routes, templates and navigation
into one immutable generation. Before navigation reaches either authenticated shell it has already been
filtered by extension trust and lifecycle, delivery area and the current actor's capabilities. Adding a second
dashboard contribution path would repeat those decisions and create another executable lifecycle to freeze,
document and keep compatible.

KIS already declares `dashboard-cards` and `navigation-shortcuts` customization slots, and the presentation
preference runtime already supplies bounded, versioned, audited scope layers. The missing primitive was a
composition of those existing contracts, not another extension registry.

## Decision

### 1. Dashboard workflows come from ordinary filtered navigation contributions

The administrator dashboard and portal home project the navigation catalogue already admitted to that exact
shell. An active trusted extension contributes dashboard-selectable work by contributing its ordinary owned KIS
surface, guarded route, view and navigation item. Core contributions pass through the same catalogue. A
dashboard projection does not reactivate a disabled contribution, bypass a missing capability, cross from one
delivery area into another, or invent a route from stored preference data.

The projected item is a semantic workflow link. Its stable contribution identifier, prepared display text,
group, icon and already-filtered root-relative destination are shaped into the shared dashboard view model.
Dashboard preferences store only bounded ordered identifier lists. At render time those identifiers are
intersected with the current live catalogue; an unknown or no-longer-live identifier is never executable.

### 2. Role and user preferences select from that one catalogue

KIS `dashboard-cards` and `navigation-shortcuts` remain the only customization slots. Canonical identity roles
are projected read-only as stable `role:<uuid>` presentation access groups; no dashboard-owned group or
assignment table is introduced. Effective role selections are resolved deterministically by the existing
presentation-preference runtime, and an exact user selection may override that result. Authorization to write a
role layer remains distinct from permission to consume it.

Access-group administration does not hydrate the role table into repeated dashboard forms. A collection-level
`users.manage` decision admits at most one canonical role on each of 100 numeric pages and bounded literal
role-code/name search for a targeted group outside that window. Because `users.manage` is global-only and the
role resource policy is installation-global, collection and canonical-item reads require the same grant and
cannot express a partial role set. The bounded preference read validates its keys against typed canonical rows
and repeats one constant collection decision rather than one decision per role. Mutation still rechecks the
exact canonical role and locks live existence. The role code remains visible beside its display name, and fixed
same-area links preserve validated browser state without accepting a return destination.

Effective dashboard composition separately projects at most 250 roles plus one overflow row. Complete role
sets are resolved with one preference batch per slot. When lookahead proves the set incomplete, composition
emits a stable non-sensitive diagnostic and applies none of the projected-role prefix; lower layers, the current
workspace and any personal override retain their ordinary precedence.

Both authenticated surfaces use the same typed preference mutation boundary. The browser supplies semantic
identifiers, order and an expected version; server-owned policy rechecks the target scope and current live
catalogue before an audited compare-and-swap write or reset. The server-rendered forms remain usable without
JavaScript.

Workflow discovery is a bounded view of that complete current catalogue, not a fixed admitted prefix.
Self and cross-area entries are removed before 32-candidate pages are formed. The first 100 numeric pages are
directly addressable; normalized search of at most 191 characters scans the complete already-filtered request
catalogue, so targeted workflows remain reachable beyond the 3,200-item numeric window. Widget and shortcut
choices share one workflow query. Each personal or access-group form prepends its own surviving live selections,
then core widgets where applicable and the current candidate page without duplicates. Fixed same-area links
preserve the independent access-group browser state, and mutation rechecks submitted identifiers against the
complete current catalogue rather than the displayed page alone.

### 3. Core may compose bounded semantic information widgets without widening the extension SPI

Core dashboard summaries, activity and access-context cards use a closed, typed, non-executable view contract.
They may read only application-authorized results and are omitted when their capability or source is absent.
This does not create a public extension widget renderer. Extension-owned business work remains an ordinary
contributed destination until a separately versioned contribution decision explicitly defines otherwise.

### 4. KIS and extension compatibility stay with their existing authorities

This decision adds no extension manifest key, registry type, service-registration hook or contribution contract
version. It does not allow stored markup, URLs, selectors, scripts, callbacks or policy expressions. KIS decision
[0001](../../interface-standard/decisions/0001-dashboard-customization-compatibility.md) governs the compatible
scope-ceiling correction, the additive dashboard-card identifier grammar, stale-value behavior and the retained
`kis-1.0` classification. This roadmap decision does not replace or widen that compatibility record.

## Alternatives rejected

### Add a dashboard-panel contribution registry

Rejected because it would duplicate owner, trust, lifecycle, area, capability, collision and ordering controls
already enforced by the surface, route, view and navigation registries. It would also make an extension choose
between two ways to expose the same work and create a second compatibility surface for core to maintain.

### Let extensions provide arbitrary dashboard renderers or data callbacks

Rejected because a stored or extension-selected renderer would introduce executable behavior at the
presentation boundary and a second path around ordinary application authorization. The dashboard selects safe
semantic destinations; it does not execute extension-supplied markup, Twig, JavaScript, queries or callbacks.

### Give every role a separate landing route or template

Rejected because route and template proliferation would duplicate the shell and encourage authorization to be
expressed through presentation variants. One area-specific dashboard composes the current actor's admitted
work; role and user differences are data in bounded preferences, not different delivery pipelines.

### Keep the fixed content-first dashboard

Rejected because an authenticated actor without content access received an irrelevant or empty starting page,
while business and extension work already visible in navigation was absent. A dashboard must orient the actor
to permitted work, regardless of which subsystem owns it.

## Consequences

- Extension lifecycle, authorization and route ownership remain single-sourced in the unified contribution
  runtime. Dashboard composition consumes their result and does not mutate their registries.
- A disabled, untrusted, area-incompatible or unauthorized contribution cannot be restored by a stored
  dashboard identifier. Reactivation makes the ordinary contribution eligible again; it does not rewrite
  preference records.
- Access-group customization reuses canonical roles and the presentation-preference transaction, audit and
  optimistic-concurrency contracts. It creates no parallel access model.
- Extensions can obtain a dashboard entry without a core edit by contributing ordinary permitted work. They
  cannot inject an arbitrary card body or dashboard-specific executable service through this decision.
- The administrator and portal dashboards share composition and component contracts while preserving their
  separate trust, route and capability boundaries.

## Conformance evidence

The completing implementation must keep these boundaries executable:

- dashboard composer tests cover area-safe catalogue projection, bounded ordering, live-catalogue pruning and
  deterministic fallback;
- preference resolver and manager tests cover complete role union, all-or-none effective-role overflow,
  constant collection authorization, user precedence, exact mutation authorization, optimistic versions, audit,
  reset and bounded values;
- application, repository and protected-template tests cover collection-level catalogue denial, deterministic
  paging beyond sixty-four roles, truthful browse-limit evidence, bounded literal search reaching a uniquely
  identified role beyond the raw authorization window, canonical role-code disclosure and fixed same-area
  continuation;
- `DashboardComposerTest::testWorkflowBrowseLimitRequiresSearchBeyondTheNumericWindow`, the dashboard query
  decoder and protected preference-template suites prove the exact 32-by-100 workflow browser, complete-catalogue
  targeted search, independent state preservation and form-specific selected-first choices;
- administrator and portal preference-handler tests prove a live workflow beyond the former renderer prefix can
  be submitted only after validation against the complete current catalogue;
- extension lifecycle tests prove active contributions become selectable, disabled contributions disappear
  from the live catalogue, and reactivation restores eligibility through the ordinary runtime;
- browser tests cover administrator and portal personal save/reset without JavaScript, an authorized
  access-group default reaching a real member, capability-reduced dashboards and graphical extension lifecycle;
  and
- the KIS compatibility decision and its schema, unit, architecture, supported-database and browser gates remain
  green at the exact implementation revision.

This ADR records the architectural choice. It is not completion evidence: `V2-ERP-006` may leave the open
findings ledger only in the change whose runtime, documentation and required checks agree.

## Non-goals

- No arbitrary extension-supplied dashboard card renderer or data callback.
- No dashboard-only manifest declaration, registry, route family or template loader.
- No new role, membership, workspace, capability or authorization source.
- No promise that every business metric belongs in core; core cards remain bounded and capability-aware.
- No weakening of administrator, portal or public trust boundaries.
- No KIS version change beyond the compatibility decision owned by KIS decision 0001.
