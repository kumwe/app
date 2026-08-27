# ADR 0020 — Studio is Kumwe's contextual Content authoring surface

**Status** Accepted
**Decided by** Product owner
**Findings** `V2-STU-007`
**Gate** B
**Partially supersedes** [ADR 0007](0007-studio-visual-composition-integration.md),
[ADR 0013](0013-studio-content-model-projection.md), and
[ADR 0018](0018-studio-inline-content-editor-boundary.md), only where their product-surface or transitional
wording conflicts with this decision
**Normative product authority** Studio
[`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md), contract
`STUDIO-PROD-1.0-draft`

---

## Context

The integration implemented so far is a useful but incomplete **Blueprint-only slice**. It mounts Studio on
an immutable content-model-version composition route, can read projected Content definitions and entries, and
can persist separately versioned Studio artifacts. Model mutation remains absent, Content entry mutation is not
wired into the Studio journey, and ordinary Content create/edit still uses the legacy server-rendered form.
That slice proves protocol, projection, persistence, preview, and shell foundations; it does not deliver Kumwe's
contextual content-authoring product.

The product intent is different. Studio is the editor reached when an author creates or edits a Content item.
Within that continuous journey the author may start blank or from a reusable Kumwe content type, compose layout
and blocks, define or bind typed fields, enter the item's values, save the item, save a design as a new reusable
type, or explicitly create a successor type version. A larger workspace may continue the same session, but a
top-level Studio destination is not the entry point and no manual Blueprint/type preparation or copy-and-paste
handoff is acceptable.

Studio now records that intent in stable requirements. App must consume those requirements rather than maintain
a second, weaker definition of the product.

## Authority and interpretation

Studio's
[`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md) is the sole
normative product authority. This ADR records **App integration obligations and sequencing only**. It does not
restate, narrow, reorder, or redefine `STUDIO-PROD-*` behaviour. Studio schemas remain authoritative for
serialized shapes; Studio protocol documents remain authoritative for observable protocol semantics; App
remains authoritative for its PHP services, security, persistence, workflow, delivery, and release gates.

The App integration must satisfy these upstream requirement groups:

| App concern | Normative Studio requirements |
|---|---|
| Contextual launch, authoring, reusable types, and target declaration | `STUDIO-PROD-001` through `STUDIO-PROD-008`, `STUDIO-PROD-012` |
| Contribution lifecycle | `STUDIO-PROD-009` |
| PHP host authority and compiled production delivery | `STUDIO-PROD-010`, `STUDIO-PROD-011` |
| Accessible operation | `STUDIO-PROD-013` |
| Truthful status and transitional fallback | `STUDIO-PROD-014` |
| Minimum integrated qualification | `STUDIO-PROD-015` |

If App and Studio documentation disagree, the Studio requirement wins for product behaviour and this roadmap
must be corrected. If the pinned Studio package family cannot satisfy a required operation, the capability is
implemented and released in Studio first; App must not create an App-local substitute or depend on an unmerged
Studio branch.

## Decision

### 1. Content create/edit owns the entry point

The normal Studio launch is contextual to the exact Content item being created or edited, as required by
`STUDIO-PROD-001` and `STUDIO-PROD-012`. App will not add a top-level Studio workspace or require authors to
visit a catalogue-level composer first. Inline, minimized, maximized, or full-screen presentation is allowed
only as a context-preserving state or continuation satisfying `STUDIO-PROD-007`.

### 2. One journey coordinates separate authorities

The App host adapter will provide the model, Blueprint, entry, contribution, preview, media, and save operations
needed for `STUDIO-PROD-002` through `STUDIO-PROD-009`. The interface may coordinate them as one authoring
journey, but App keeps content-model revisions, Blueprint revisions, entry revisions, permissions, migrations,
and publication state separately identifiable. The reusable-type, exact-hydration, and save-outcome semantics
are those in `STUDIO-PROD-004` through `STUDIO-PROD-006`; App documentation must not invent different
semantics.

### 3. PHP is the complete server-side authority

Every durable effect crosses a typed host port and terminates in an authorized PHP application service and PHP
HTTP endpoint. PHP independently authenticates, authorizes, validates, transacts, versions, audits, and applies
the operation under App's existing Content, Workflow, media, preview, and publication boundaries. Browser
JavaScript coordinates the interface and calls those endpoints; it is never a server authority. This is the App
implementation of `STUDIO-PROD-010`.

### 4. Production contains compiled browser assets, not Node.js

Node.js and npm are contributor, CI, test, and release-build tools only. Official compiled assets are committed
and packaged before deployment. App's production install, images, startup, operation, preview, save, publish,
and rendering paths must contain no Node.js/npm installation, development server, or server-side JavaScript
process. Release qualification proves `STUDIO-PROD-011`.

### 5. The legacy form is exceptional, not the product definition

The server-rendered editor may remain during migration and as an explicit recovery, unsupported-capability,
no-JavaScript, or rollback path under `STUDIO-PROD-014`. It is not a coequal normal authoring product and its
continued presence cannot be used to call the Blueprint-only slice complete. Status and readiness claims must
follow `STUDIO-PROD-014`.

## One-App-PR small-goal commit ladder

Implementation proceeds in one App pull request as a sequence of independently reviewable, green commits. A
commit may combine adjacent goals only when their tests cannot be separated; it must not skip the acceptance
evidence for an earlier goal.

1. **Contract truth.** Land this ADR, the D16/Gate B/phase-S correction, the critical finding, status truth, and
   agent guardrails, all citing the exact upstream requirement IDs.
2. **Context envelope and launch.** Define the PHP-issued authoring context/session and open Studio from Content
   New/Edit for the exact resource; retain the legacy form only as the declared fallback.
3. **Context-preserving shell.** Mount the shell in the originating Content surface and preserve identity,
   selection, authority, locale, unsaved state, and return path across supported expanded states.
4. **Existing-item round trip.** Load authorized model, Blueprint, and entry projections; change layout and
   values; save the entry through PHP with expected revision and audit; reopen the accepted revision.
5. **Existing-type creation.** Start a new item from an authorized content type with its structure and fields
   but no previous entry values, then save it through the same PHP path.
6. **Blank creation and field authoring.** Start blank, compose blocks and typed fields, enter values in the same
   Studio journey, and save without a prerequisite catalogue workflow.
7. **Explicit reusable-type outcomes.** Implement PHP transactions for save-item, save-design-as-new-type, and
   explicit new-type-version outcomes, including value exclusion, scope confirmation, immutable successor,
   permissions, migration impact, conflict, replay, and audit proof.
8. **Contributions, preview, and delivery.** Exercise authorized contributed blocks and field adapters, then
   prove authenticated preview and trusted public rendering without leaking dependency-native state.
9. **Production qualification.** Prove the complete `STUDIO-PROD-015` journey from a clean packaged App runtime,
   including accessibility/locales/fallbacks and an automated refusal of any production Node.js/npm dependency.

The pull request is not complete when only the contextual shell appears. It completes when the entire canonical
acceptance journey passes against the exact pinned Studio release family and the App Gate B evidence accepts it.

## Consequences

**Positive.** Kumwe has one central, contextual content editor instead of a separate Blueprint utility and a
manual form workflow. Studio remains portable; App remains the authoritative PHP host; reusable types and entry
values keep distinct revisions; production operators and authors never install npm.

**Cost.** The existing Blueprint composer, projector, artifact store, preview path, and shell are foundations,
not disposable work, but they must be connected to Content entry and content-type write orchestration. The
pinned Studio family is on the critical path if any required operation is absent.

**Migration.** Existing server-rendered Content routes remain available as a named fallback while contextual
coverage is qualified. Existing Blueprint bindings and artifacts are adopted through explicit mapping and
migration; no silent rewrite of published model, Blueprint, or entry revisions is permitted.

## Alternatives rejected

- **Keep the model-version Blueprint composer as the Studio product.** Rejected: it fails
  `STUDIO-PROD-001` through `STUDIO-PROD-008`, `STUDIO-PROD-012`, and `STUDIO-PROD-014`.
- **Add a top-level Studio navigation workspace.** Rejected: it creates the disconnected prerequisite journey
  prohibited by `STUDIO-PROD-001`, `STUDIO-PROD-007`, and `STUDIO-PROD-012`.
- **Copy composition data between Studio and Content forms.** Rejected: it is not an integrated authoring or
  revision boundary.
- **Implement missing page-builder behaviour inside App.** Rejected: it violates the Studio/App ownership
  boundary and would create a second interpretation of the upstream product contract.
- **Run Node.js or npm on the server.** Rejected: App's backend is PHP and `STUDIO-PROD-011` prohibits a
  production Node.js requirement.
