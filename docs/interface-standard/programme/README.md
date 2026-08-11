# KIS interface migration programme

This directory is the control plane for migrating Kumwe's graphical interfaces to the Kumwe Interface
Standard (KIS). It is normative for programme scope, evidence, status, and hand-off. Product behaviour
continues to be governed by the application and architecture documents; this programme records how every
graphical delivery surface is brought into conformance without losing behaviour.

## Authoritative records

| Record | Purpose |
| --- | --- |
| [`surface-inventory.json`](surface-inventory.json) | Every current graphical route, template, navigation entry, generated exposure, owner, task, finding, fixture, coverage disposition, KIS pattern, and target phase. |
| [`actor-task-journeys.json`](actor-task-journeys.json) | Stable actor definitions, user tasks, and end-to-end journeys used to judge whether a migration preserves usable outcomes. |
| [`phase-ledger.json`](phase-ledger.json) | Gates, owners, evidence, severity policy, phase checklists, status history, waivers, and continuation state through Phase 6. |
| [`findings-register.json`](findings-register.json) | Deduplicated product, coverage, usability, and environment findings with current disposition, owner, severity, reproduction, evidence, blockers, and append-only history. |
| [`verification-report-template.json`](verification-report-template.json) | Reusable per-PR report contract covering the canonical source, PHP, frontend, browser, parity, security, extensibility, topology, recovery, and review checks. |
| [`reports/phase-0-2-current.json`](reports/phase-0-2-current.json) | Current Phase 0–2 checkpoint matrix, including checks that passed and checks honestly blocked or not run. |
| [`governance.md`](governance.md) | Human-readable rules for ownership, findings, evidence, decisions, deviations, and completion. |
| [`continuation-protocol.md`](continuation-protocol.md) | Exact start, execution, verification, and hand-off protocol for this chat and later Phase 3–6 chats. |

The JSON records are not generated documentation. They are reviewed programme inputs, committed with the
code whose current state they describe, and verified against repository sources. A change that adds,
removes, renames, or materially changes a graphical surface must update the records in the same commit.

## Scope

The inventory covers:

- core administrator authentication, management, generated-business, report, and mutation routes;
- core portal authentication, security, approval, generated-business, report, and mutation routes;
- public homepage and managed-page presentation routes;
- administrator and portal routes, navigation, views, and templates contributed by extensions;
- administrator and portal experiences generated from published business definitions;
- installable administrator, portal, and site-template overrides that replace a core presentation template;
- shared shells, partials, error states, and support fragments that do not own a route themselves.

Health checks, REST, OpenAPI, MCP, CLI, asset delivery, downloads, and media byte delivery are not graphical
surfaces. Their behaviour remains in the parity and security evidence for the graphical tasks that invoke
them. An endpoint must not be excluded merely because it returns a fragment: a fragment that a graphical
task depends on is a support surface and remains inventoried.

## Invariants

1. Every reachable graphical route belongs to exactly one stable surface ID.
2. Every Twig file has an explicit disposition as a surface, shell, partial, support fragment, error state,
   extension view, or installable override.
3. Every navigation contribution resolves to an inventoried surface and a declared capability.
4. Every administrator or portal exposure in a shipped business definition is recorded as a generated
   instance, including all declared view kinds.
5. Every surface has an owner, bounded context, actors, primary task, journey, fixtures, current coverage,
   findings, target patterns, migration phase, and verification disposition.
6. A surface may move phase only through a recorded ledger transition. It may not be deleted to make a
   phase appear complete.
7. Completed work retains its history. Corrections append a new status or evidence record; they do not
   rewrite prior evidence or erase a superseded decision.
8. No phase completes with an open P0, an open P1 affecting that phase, an unowned item, a missing required
   fixture, placeholder behaviour, or documentation-only claim of runtime completion.
9. KIS conformance never replaces authorization. Capability, policy, CSRF, step-up, concurrency, audit,
   extension trust, and recovery behaviour must be proved separately and retained.
10. Template and extension customization remains explicit, versioned, capability-filtered, accessible,
    recoverable, and resettable. A custom template may replace approved presentation slots, but it still
    owes the same task, semantic, safety, and conformance contracts.
11. A finding fingerprint is unique. Duplicate observations reference the canonical finding; they never
    inflate or erase scope. Environment limitations are findings, not passing evidence.
12. Every migration PR copies the verification-report template, retains every canonical check row, and
    records `blocked`, `not_run`, or `not_applicable` rather than inferring success from source presence.

## Verification

Run the dependency-free programme check whenever routes, navigation, templates, business-definition
fixtures, KIS documentation, or ledger status changes:

```bash
composer interface:programme
```

The command is part of `composer qa`. It checks internal references and completion rules, then compares the
inventory with `ContainerFactory`, core navigation contributions, every shipped extension manifest, all
shipped demo business definitions, every repository Twig template, typed core surface IDs/capabilities,
the findings register, and every verification report. A newly added source surface or malformed report
fails the check until it is deliberately classified and scheduled.

The verifier proves inventory coverage and ledger consistency. It does not prove visual quality. Runtime
browser, accessibility, responsive, security, parity, database, deployment, and recovery evidence remains
required by the phase and gate records.

## Per-PR verification reports

Copy `verification-report-template.json` to `reports/<stable-report-id>.json` for every bounded migration
PR. Replace every placeholder, retain all canonical check IDs, associate checks with affected phases and
work items, and reference the canonical findings register for failures or blockers. A passed check names
the exact command/review method, environment, result, and durable repository artifacts where applicable.

The report's `source_revision` covers committed implementation only. Shared or unrelated working-tree
changes are either excluded explicitly or reported as included; they are never silently swept into a pass.
An unavailable PHP runtime, Composer dependency set, application topology, database, or browser executable
is recorded as `blocked` or `not_run`. Alternative structural execution such as PHP WASM may prove its
narrow check, but cannot be relabelled as native Composer, PHPUnit, browser, or production qualification.

## Status vocabulary

`planned` means scoped but not started. `ready` means prerequisites and an owner are present. `in_progress`
means implementation or evidence collection is active. `blocked` names an external blocker and owner.
`in_review` means implementation evidence exists but the accountable gate is not yet accepted. `complete`
means all required evidence and acceptance rules passed. `waived` is permitted only for a P2 or P3 item and
requires an owner, rationale, expiry or target phase, and compensating evidence. `superseded` preserves an
old record whose replacement is named explicitly.

## Relationship to template packages

KIS is a semantic and interaction contract, not a prohibition on themes. Core administrator, portal, and
site templates remain overridable only through the platform's trusted, installable template mechanisms.
An override may change approved tokens, composition slots, and component variants, but it must preserve:

- route, task, capability, field, action, validation, and state parity;
- landmarks, names, keyboard operation, focus behaviour, contrast, motion preferences, and responsive
  priorities;
- required warnings, destructive classification, approval and recovery consequence, audit meaning, and
  optimistic-concurrency state;
- server-rendered and essential no-JavaScript operation where the core contract supplies it;
- recovery fallback when the installed template is invalid or incompatible.

Extension and template authors can therefore aim an implementation tool at the versioned KIS documents,
the machine-readable surface contract, and the conformance suite and produce an installable theme without
copying ungoverned page markup or bypassing policy.
