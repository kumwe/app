# KIS conformance

Conformance combines typed declarations, deterministic source and browser checks, human-reviewable visual
evidence, and a machine-readable migration ledger. No single screenshot, accessibility scan, or AI score
is sufficient.

## Surface declaration

Every graphical surface declares:

- stable surface identifier and core/extension owner;
- KIS version, actor, resource, primary task, interaction intent, and pattern;
- route, handler, template, workspace/navigation entry, and capability;
- fields, actions, statuses, relationships, and destructive/high-impact classification;
- empty, sparse, representative, dense, extreme, error, and permission-reduced states;
- responsive priorities and values allowed to collapse into secondary detail;
- allowed customization scopes and mandatory presentation;
- fixture identifiers, automated tests, owning bounded context, migration phase, and evidence status.

Declarations contain no executable PHP, SQL, Twig, JavaScript, HTML, or unbounded expressions. Typed PHP
value objects or validated manifest metadata compile into the runtime registry. Unknown versions, actors,
intents, patterns, states, icons, components, customization slots, duplicate identifiers, unowned routes,
missing purpose, unsafe markup slots, and policy-bypassing actions fail closed.

The canonical machine contract is
[`schemas/surface-declaration.schema.json`](schemas/surface-declaration.schema.json). Schema validation is an
authoring aid and package preflight, not the runtime trust boundary: `SurfaceDefinition::fromArray()` repeats
strict parsing, owner-namespace validation, intent/pattern admission, state requirements, customization-scope
rules, and responsive-priority checks. Extension manifests carry these declarations in their versioned
contribution set. `npm run check:schemas` executes the Draft 2020-12 schemas against canonical and adversarial
documents. Providers feature-detect the additive `InterfaceSurfaceRegistrar` and reconcile the identical
typed definition through its owner-bound implementation; the frozen SPI-two registrar remains source
compatible for existing providers.

## Source and architecture gates

The repository verifier cross-checks the programme inventory against current graphical routes, core and
extension templates, navigation entries, actors, fixtures, phases, and test dispositions. Architecture
tests currently enforce a transport-free semantic contract, reuse of the owner-bound contribution lifecycle,
template-namespace isolation, production preference composition, and Phase 2 parity source bindings. Focused
unit checks enforce core icon resolution and typed-owner reconciliation. Page-local replica removal and
delivery-layer policy review remain explicit per-phase source-review obligations until a deterministic rule
can prove them without false positives; they are not represented as already automated.

KIS changes update normative documentation, schema/value objects, production components, gallery fixtures,
compatibility policy, migration ledger, tests, and changelog in one change.

## Deterministic Playwright evidence

For each applicable viewport/data/state/input case in
[Responsive accessibility](responsive-accessibility.md), collect:

- viewport and full-page screenshots;
- Axe results and accessibility/heading/landmark snapshots;
- visible element and scroll-container bounding boxes;
- clipping, overflow, overlap, sticky collision, and focus-obstruction findings;
- tab order, focus-visible, tab/drawer/dialog keyboard evidence;
- primary actions, forms, tables, details, and technical-value counts;
- console errors, request failures, failed assets, and unexpected layout shifts.

Hard failures include unintended document overflow, component clipping, material interactive overlap,
unlabelled table overflow, unresolved icons, invalid tab semantics/URL state, absent or competing primary
actions, sticky obstruction, focus leakage, and lost focus return.

## AI-assisted review

AI review receives the screenshot, route purpose, intended actor/task, heading and landmark outline,
visible-control inventory, diagnostics, and tokens. It returns structured findings with component selector
or coordinates, severity, evidence, correction, and confidence for orientation, task clarity, hierarchy,
density, navigation, terminology, state clarity, responsive integrity, consistency, and accessibility risk.

AI findings never rewrite production code directly. A reviewed finding is deduplicated, assigned, and
converted into a deterministic assertion where possible. Systemic findings change a token, component, or
pattern; they do not produce repeated page patches.

## Severity

- **P0** — inaccessible critical task, authorization/data disclosure risk, destructive ambiguity,
  unusable supported viewport, or missing primary workflow. Blocks all progression.
- **P1** — clipping/overlap, unreachable action, serious navigation ambiguity, lost context, or materially
  overwhelming composition. Blocks the affected migration.
- **P2** — consistency, density, terminology, secondary workflow, or recoverable responsive weakness.
  Must be scheduled before whole-system qualification.
- **P3** — optional polish with no meaningful task, accessibility, security, or comprehension impact.

A phase is not complete with a known P0/P1, a skipped declared state, placeholder component, or
documentation-only declaration. A waived P2 records owner, rationale, and scheduled phase.

## Per-migration gate

Before replacing a surface, record old/new parity for routes, capabilities, fields, actions, payloads,
validation, CSRF, optimistic concurrency, step-up, approval, audit, errors, no-JavaScript, keyboard,
customization reset, and database-neutral rendering. Remove legacy markup/styles only after parity and the
full viewport/data matrix pass locally.

## Merge and release gate

Required local gates are programme verification, architecture policy, docblocks, OpenAPI consistency,
coding standards, static analysis, unit/integration/functional tests, frontend type/build checks,
Playwright behavior/accessibility/visual diagnostics, and relevant deployment/database/security checks.
GitHub is the final confirmation, not the development iteration loop.

The per-PR report records branch/commit, inventory rows, KIS version, behavior changes, parity result,
screenshots, checks, database/deployment scope, security/customization/template impact, residual risks, and
recovery. Whole-system qualification follows the cross-surface journeys in the programme ledger.
