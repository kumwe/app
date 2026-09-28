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
documents. The host consumes each typed surface directly from the canonical SDK manifest graph. Provider code
cannot add, replace, or reinterpret a surface declaration.

The portable schema and runtime share the same actor/area matrix and the complete slot/scope allowlist.
Surface identifiers use a bounded lowercase dotted grammar that admits digit-led, underscored, and hyphenated
extension namespaces. Internal repeated dots remain representable because released extension identifiers may
contain them; the owner-bound registrar verifies the declaring owner's exact prefix and a non-ambiguous suffix.
Distinct active owners with equal or prefix-overlapping legacy dotted namespaces fail before registration.
Responsive entries also carry the repository semantic keyword `x-kumwe-uniqueBy: "element"`. JSON Schema's standard
`uniqueItems` only rejects identical objects, so KIS-aware authoring tools must register this keyword (as
`tools/verify-interface-schemas.mjs` does) or repeat the equivalent uniqueness check. The PHP trust boundary
always rejects two entries naming the same semantic element, even when their other fields differ.

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

## Automated interface acceptance (ADR 0021, P7-E)

[ADR 0021](../roadmap/decisions/0021-automated-acceptance-and-sampled-capacity.md) replaces the named
human reviewers of the five archetype task journeys with workflow tests; the maintainer's merge is the
acceptance record. `tests/Browser/user-acceptance-journeys.spec.ts` runs the five journeys, each registered
in `programme/actor-task-journeys.json` with an `acceptance` block naming its test and the dimensions it
evidences. The file is named to run after every spec that pins a screenshot, so the content and records the
journeys create never enter a pinned screenshot of the same project run:

| Journey | Evidence the test records |
| --- | --- |
| `journey.acceptance-content-publication` | Media and Content reached from navigation; an empty upload held by the form; a structured draft without raw JSON; review and publication; a menu link with its calculated path; the public page without an inline style, overflow or axe violations; skip-link focus. |
| `journey.acceptance-exact-document` | A fresh thousand-line draft, written out of process as an import would, found through the disclosed title filter; one thousand exact-value lines rendered with the exact `5005.00` total; a total that disagrees with the lines refused with the rule's own message and the typed values kept; submit, approve and post each behind an explicit confirmation; the posted document read-only with the refusal's own wording; history naming every step in business terms; a verified CSV export carrying the exact total. |
| `journey.acceptance-portal-relationship` | Anonymous access sent to sign-in; a wrong password that keeps the address; business work reached from the portal home; related records; a refused wrong current password; administrator entry denied. |
| `journey.acceptance-mobile-assignment` | A site photograph uploaded and attached to a new job card through the media chooser; the job started behind a confirmation and given grouped parts, labour and measurement lines with controls of at least 24 CSS pixels and no overflow; completion making it read-only. |
| `journey.acceptance-catalogue-order` | The public catalogue leading into the portal; an invalid quantity recovered with the typed values kept; the fulfilment action denied to the customer; a payment recorded out of process through the REST API, a stale `If-Match` refused; the administrator's confirmed fulfilment visible to the customer. |

Every step runs axe with `wcag2a`, `wcag2aa`, `wcag21aa` and `wcag22aa`, and every journey checks that
visible copy carries no raw platform identifier (`site.default.*`, `core.*`, `business.record.*`,
`action.*` and similar revision identifiers, or unrendered template syntax). The journeys run on every
`all` browser project: Chromium desktop and mobile on each pull request and locally, and Firefox and WebKit
in the nightly workflow. A WebKit result is WebKit evidence; it is not a claim that a person used native
Safari. The accessibility evidence is automated: it does not stand for a screen-reader user, and the
workflows describe what they checked rather than inventing human review.

Journey (b) exercises the generic record-lock behaviour of `V2-UX-003`; `tests/Browser/record-lock.spec.ts`
pins it separately on both surfaces. The content-authoring and generated-business archetypes are also
completed in German and in Hebrew by `tests/Browser/locale-journeys.spec.ts` (`V2-LNG-010`, `PL-G`); the two
files share the accessibility scan and sign-in helpers instead of repeating each other.

The journeys found, and the generated surfaces now repair, this generic debt on both administrator and
portal:

- Record history named revisions by platform identifier (`action.post`, `relate.lines`, `document.create`)
  and headed the page with the definition handle. It now names each revision in the catalogue's words or by
  the definition's own action, relationship and field labels, headed by the plural label; the revision items
  themselves stay the projection every adapter discloses.
- Record states were raw handles title-cased in the template, with English `Active`, `Archived` and
  `Deleted` defaults that no catalogue translated. They now use the definition's workflow state label and
  translated lifecycle words.
- Relationship kinds were shown as `Owned Line Collection` or `Many To Many`; they now read as record lines,
  one linked record or linked records, translated.
- Workflow action buttons appended the transition handle (`Submit for review → Submit`); the action's own
  label now stands alone.
- A refused record rule (such as a document total that disagrees with its lines) left the form saying
  "review the marked fields" with nothing marked; the rule's declared message is now listed in the summary.

Residual debt the journeys record rather than hide: an enum field shows its stored option (`paid`), because
the definition language owned by `kumwe/business-definition` declares options without labels; a reference
chosen through the chooser reads "Selected option" rather than the chosen item's name until it is saved;
and every declared workflow action is offered whatever the record's state, with the record service refusing
one that cannot fire, because action metadata does not yet disclose each transition's source state.
