# Interface migration governance

This document defines how interface decisions are owned, how findings are classified, which evidence may
close work, and how the programme advances. It applies to core, generated, portal, public, extension, and
template-owned graphical surfaces.

## Ownership

Every work item has one accountable role and may name supporting roles. Roles are stable programme
responsibilities rather than individual names:

| Role | Accountable for |
| --- | --- |
| Programme owner | Scope, sequencing, phase acceptance, and preventing silent omission. |
| KIS owner | Normative principles, pattern selection, compatibility, and deviations. |
| Product task owner | Actor vocabulary, task clarity, journey completeness, and parity of intended outcomes. |
| Implementation owner | Production code, migration safety, focused tests, and removal of superseded markup. |
| Security owner | Authorization, policy, CSRF, step-up, destructive work, disclosure, trust, audit, and recovery. |
| Accessibility owner | WCAG 2.2 AA, semantics, keyboard, focus, motion, zoom, touch, and assistive technology. |
| Extension contract owner | Installable template and extension compatibility, contribution schemas, and conformance tooling. |
| Quality owner | Fixtures, deterministic diagnostics, visual evidence, database matrix, and regression gates. |
| Release owner | Production topology, backup/restore, artifact reproducibility, and final release evidence. |

An accountable role cannot be replaced with “team”, “future worker”, or “AI”. A worker may execute the work,
but the ledger retains the role that must accept the evidence.

## Severity

- **P0** — an inaccessible critical task, authorization or disclosure risk, ambiguous destructive action,
  unusable supported viewport, missing primary workflow, or corruption risk. It blocks all progression.
- **P1** — clipping, overlap, unreachable action, serious navigation ambiguity, lost context, broken
  keyboard operation, or a materially overwhelming composition. It blocks the affected surface and phase.
- **P2** — a consistency, density, terminology, secondary-workflow, or recoverable responsive weakness. It
  must be scheduled before whole-system qualification. A waiver requires a named owner, rationale,
  compensating evidence, and a non-silent target.
- **P3** — optional polish without meaningful task, accessibility, security, or comprehension impact. It may
  follow KIS 1.0 only when retained in the ledger.

Severity is based on impact and reach, not implementation effort. Lowering severity requires new evidence
and a recorded disposition; it must not overwrite the original classification.

## Evidence

Evidence records are immutable identifiers. A correction creates a new record that names the superseded
record. Accepted evidence types are:

- `source` — committed implementation or a source inventory tied to a revision;
- `test` — command, environment, result, and durable report or log;
- `browser` — route, actor, fixture, viewport, theme, input mode, screenshot, accessibility, and diagnostic
  bundle;
- `parity` — old/new route, field, action, request, authorization, and outcome comparison;
- `security` — positive and negative actor matrix, threat or abuse case, and fail-closed result;
- `decision` — approved KIS rule, prototype outcome, compatibility decision, or deviation;
- `review` — human usability, accessibility, security, or architectural acceptance;
- `qualification` — production-topology, database-matrix, backup/restore, artifact, or release evidence.

An evidence record states its producer, source revision, environment, command or review method, result,
artifact path, and the task or gate it supports. “Tests pass”, a screenshot without route/fixture metadata,
or a document promising future behaviour is not evidence of runtime completion.

Accepted evidence is revision-bound. Its 40-character `source_revision` must resolve to a commit object in
the repository that runs the verifier. A shallow checkout can accept only locally present commits; an
export without Git metadata, an omitted shallow-history object, a blob/tree/tag hash, or an unsupported
packed representation is unverifiable and cannot satisfy completion. The record remains visible and must
be re-collected or verified in a repository with the required commit history.

Runtime KIS admission is explicit, not inferred from an inventory relationship. Every surface records
`kis_runtime_disposition` as `declared` or `legacy`; only `declared` surfaces may have a literal core
`SurfaceDefinition`, and that declaration must exactly match the inventory's canonical `kis_contract`.
Every navigation record likewise declares `runtime_surface_binding`. A `declared` core item must bind its
runtime `surface` to the inventoried surface ID, while a `legacy` item must retain a null runtime binding.
Thus every KIS-migrated navigation item has a typed binding, while absence on unmigrated items remains
visible and blocks completion of that item's migration rather than pretending it is already admitted.

## Gates

The programme uses six gates:

1. **Gate A — inventory and task model:** every graphical route, generated exposure, navigation entry, and
   template is owned, classified, scheduled, and verifier-clean.
2. **Gate B — KIS 1.0 approval:** normative rules and hard-case prototypes are accepted across the required
   viewport, theme, density, keyboard, and actor states.
3. **Gate C — reference implementation:** shared tokens/components/contracts and one difficult vertical
   slice work at runtime without duplicated domain policy.
4. **Gate D — migration safety:** old/new route, capability, field, action, payload, validation, CSRF,
   concurrency, step-up, approval, audit, no-JavaScript, customization, and database behaviour match.
5. **Gate E — bounded-context migration:** every surface in a context is migrated, one-off replacements are
   removed, extension/template compatibility is proved, and conformance is green.
6. **Gate F — whole-system qualification:** cross-surface journeys and complete local production checks pass
   before GitHub is used as final confirmation.

A gate is `complete` only when every required evidence slot in `phase-ledger.json` points to accepted
evidence and no blocking finding remains. Gate acceptance is a new status-history entry; failed or expired
evidence remains visible.

## KIS deviations and changes

A proposed deviation must name the unmet user need, affected actor and surfaces, why existing patterns are
insufficient, accessibility and responsive behaviour, security implications, customization compatibility,
tests, owner, and expiry or incorporation plan. If the need is reusable, update KIS through a versioned
decision instead of creating page-local markup.

A compatible token or component improvement may retain the current KIS version. A semantic, state,
customization, or extension-contract change requires a versioned compatibility decision, migration and
reset behaviour, conformance fixtures, and a documented deprecation window. Installed templates and
extensions declare the minimum KIS version they require; unsupported contributions fail closed with an
operator diagnostic.

## Completion and waivers

Runtime work is not complete when only documents, interfaces, component names, TODOs, fixtures that bypass
production, or source-string assertions exist. Completion requires production behaviour plus the evidence
named by the work item.

P0 and P1 items cannot be waived. A P2/P3 waiver records the finding, accountable role, rationale,
compensating control, expiry or future phase, and acceptance evidence. The verifier rejects a bare `waived`
status. Verification evaluates an `expires_at` bound against one UTC as-of instant for the whole run; the
bound is exclusive, so equality is expired. A `target_phase` bound expires when that phase first appears in
`current_focus` or its status advances beyond `planned`. When both bounds exist, the first one reached wins.
An expired waiver cannot satisfy a prerequisite or phase even before its status is corrected; the next
ledger update returns the item to `ready` or `blocked`, preserving the waiver and transition history.

## History-preserving updates

Status changes append an entry with UTC date, previous status, new status, reason, and evidence IDs. Existing
entries are corrected only by a later `supersedes` record. Phase hand-off adds a continuation note and does
not collapse completed tasks into prose. Git history provides byte-level audit, while the ledger provides a
stable domain history that later chats can understand without reconstructing commits.
