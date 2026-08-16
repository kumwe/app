# Kumwe Interface Standard 1.0

Kumwe Interface Standard 1.0 (KIS 1.0) is the normative interface contract for core administrator
screens, authenticated portal screens, generated business surfaces, extension views, and installable
templates. It governs how a user task is declared, which interaction pattern implements it, how the
pattern renders and behaves, and which evidence proves conformance.

KIS is not a visual theme. Themes may change the approved presentation properties described in
[Customization](customization.md), while KIS retains the semantic, security, responsive, and
accessibility contract. Product policy remains in domain and application services; Twig and Lit render
presentation-ready state and never become authorization boundaries.

## Normative language

The words **must**, **must not**, **required**, **should**, **should not**, and **may** are normative.
An exception to a **must** requires an accepted KIS proposal, an owner, compatibility analysis, and
automated evidence. A page-specific stylesheet or undocumented template convention is not an exception.

The current identifier is `kis-1.0`. A surface declares the earliest compatible KIS version it needs.
Kumwe rejects a declaration requiring an unsupported version instead of rendering it approximately.

## The four layers

1. **Semantic interaction model** — declares the actor, resource, primary task, states, actions,
   relationships, policy requirements, and customization permissions independently of layout.
2. **Pattern selection** — maps measurable task and resource conditions to one approved workspace,
   collection, form, subform, step, review, history, or diagnostic pattern.
3. **Components and tokens** — server-rendered Twig components provide semantic markup; focused Lit
   controllers enhance it; shared tokens provide appearance, density, responsive limits, and modes.
4. **Conformance and governance** — typed declarations, schema validation, architecture checks,
   Playwright diagnostics, accessibility evidence, migration ledgers, and version policy prevent drift.

Every change must address all four layers. Adding a styled component without a semantic purpose and
conformance evidence is incomplete. Declaring a pattern without a production renderer is also incomplete.

## Platform invariants

- One workspace has one primary task in each state.
- Browse, create, edit, diagnostics, and history are separated when they compete for attention.
- Technical identifiers and advanced controls remain available through progressive disclosure.
- The current site, organization, workspace, resource, lifecycle state, and permission scope remain
  visible whenever they affect a decision.
- Hidden controls never implement authorization. Application services filter resources, fields, and
  actions before rendering.
- Dangerous, irreversible, externally visible, or high-impact work has a dedicated review boundary.
- All essential navigation, reading, form submission, validation, and recovery work without JavaScript.
- JavaScript enhances focus, disclosure, keyboard operation, state retention, and efficiency; it does
  not duplicate business rules or create a second application.
- Core and extension surfaces use the same public KIS vocabulary and conformance gates.
- Installable site and administrator templates remain supported within the safe override contract.
- No visible interactive element may clip, overlap another control, or escape an unlabelled container.
- Empty, sparse, representative, dense, long-label, error, and permission-reduced states are designed
  and tested, not inferred from the typical state.

## Standard documents

| Document | Normative scope |
| --- | --- |
| [Pattern selection](pattern-selection.md) | Interaction intents, decision order, and pattern thresholds |
| [Workspaces and navigation](workspaces-navigation.md) | Shells, headers, context, local navigation, tabs, and master-detail |
| [Collections and tables](collections-tables.md) | Discovery, filters, lists, tables, bulk work, and pagination |
| [Forms and subforms](forms-subforms.md) | Field grouping, validation, dirty state, conflicts, children, and choosers |
| [Actions and safety](actions-safety.md) | Action hierarchy, review, confirmation, step-up, and long-running work |
| [Responsive accessibility](responsive-accessibility.md) | Container behavior, keyboard, assistive technology, modes, and test matrix |
| [Customization](customization.md) | Tokens, preferences, themes, override boundaries, migration, and reset |
| [Preference runtime](presentation-preference-runtime.md) | Typed persistence, resolution precedence, authorization, audit, import/export, and reset |
| [Template authoring](template-authoring.md) | Installable site/administrator templates and extension-view consumption |
| [Conformance](conformance.md) | Declarations, deterministic checks, visual evidence, severities, and merge gates |
| [Compatibility decisions](decisions/) | Accepted compatibility records, migration posture, and evidence |
| [Programme ledger](programme/README.md) | Surface inventory, journeys, phases, evidence, and continuation procedure |

Machine consumers use the closed
[`surface-declaration.schema.json`](schemas/surface-declaration.schema.json) and
[`presentation-preference.schema.json`](schemas/presentation-preference.schema.json) contracts. The
[`examples`](examples/) directory contains canonical, non-executable inputs suitable for a package generator,
an AI implementation brief, or a conformance fixture. Runtime admission remains stricter than JSON Schema:
the typed PHP validator also proves ownership and cross-field semantics before a declaration reaches the
existing contribution registry.

## Version and change policy

KIS uses major and minor versions. A compatible minor release may add an optional token, state, or
component capability while preserving existing declarations and preferences. A major release is required
when a declaration, rendered semantic contract, keyboard behavior, customization slot, or stored preference
cannot be migrated without a visible behavioral change.

A KIS proposal must contain:

1. the unmet user need and affected actors;
2. evidence that existing patterns cannot satisfy it;
3. the semantic and security contract;
4. responsive, keyboard, accessibility, no-JavaScript, and failure behavior;
5. extension, theme, stored-preference, and declaration compatibility;
6. migration and reset behavior;
7. production component, gallery fixture, tests, and before/after evidence;
8. release note, deprecation window, and owner.

One current version is authoritative. A prior renderer may exist only for a named, bounded deprecation
window. Indefinite parallel design systems and unversioned page-local alternatives are prohibited.
Accepted compatibility decisions are indexed in [`decisions/`](decisions/). A correction may retain the
current identifier only when the record proves existing declarations and stored preferences remain valid,
defines migration and reset behavior, and pins that claim in conformance evidence.

## Ownership

KIS principles and pattern selection are owned by the normative standard. Security visibility is owned
by application authorization tests. Component semantics are owned by component contracts and gallery
fixtures. Responsive behavior is owned by container rules and the viewport/data matrix. Migration
completeness is owned by the machine-readable surface ledger. Release readiness is owned by local
qualification evidence followed by one final CI confirmation.
