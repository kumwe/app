# ADR 0007 — Studio visual composition: its contribution contract at Gate A, its integration at Gate B

**Status** Accepted; the product-surface clauses in decision 6 and the final non-goal are partially superseded
by [ADR 0020](0020-studio-contextual-content-authoring.md)
**Decided by** Product owner
**Verified against** `47597288f322c3db7a9e334cf915158e6e4cded9`
**Findings** `V2-STU-001`, `V2-STU-002`, `V2-STU-003`, `V2-STU-004`, `V2-STU-005`, `V2-STU-006`,
`V2-STU-007`
**Gate** A and B

---

> **Later product correction.** This record remains authoritative for the Gate split, contribution contract,
> Studio/App division of labour, exact package pin, artifact discipline, preview security, and qualification
> burden. [ADR 0020](0020-studio-contextual-content-authoring.md) supersedes only the idea that Studio is an
> additional, separately reached authoring surface and the non-goal against replacing the normal editor. Studio
> is now the target contextual Content create/edit experience under the exact `STUDIO-PROD-*` requirements in
> Studio's
> [`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md); no
> top-level Studio workspace is introduced, and the server-rendered editor becomes a transitional fallback.

## Context

### What existed at the decision point, verified

The administrator edits content through server-rendered forms: `content-form.twig` behind
`AdministratorContentEditorHandler` and its write handlers, with optimistic versions, retained input,
CSRF, and capability gating. Presentation is selected, not composed: stored preferences and settings
carry identifiers and order, never markup or URLs. There is no visual composition surface, no preview
endpoint — the editor links to the published address — and no `@kumwe/studio` dependency anywhere;
`lit` is the administrator build's single runtime dependency. The security headers send
`frame-ancestors 'none'` with a self-only script source.

Studio exists as a separate repository, [`kumwe/studio`](https://github.com/kumwe/studio): a standalone,
host-neutral page builder whose contract is a language-neutral corpus of JSON Schemas and canonical
fixtures, whose engine is deterministic and browser-independent, and whose authoring shell is a
keyboard-complete web component proven under a content-security policy of
`default-src 'none'` with a bare self script source and enforced Trusted Types. Everything
authoritative — identity, policy, persistence, media, resource/data resolution, preview delivery,
localization, telemetry — stays behind typed host ports the embedding platform implements. Its next
coordinated release family contains eight packages, including the portable semantic-web renderer; the corpus
they ship is replayable from PHPUnit because it is JSON, not code. The integration input is collected in
[`docs/roadmap/studio-integration.md`](../studio-integration.md).

### Why now, and why a decision record

Version 2's product objective includes authoring experience as a first-class capability, and the
composition surface is the largest interface capability the platform does not have. The
core-versus-adapter boundary must be settled before any host work starts, because the wrong boundary —
composition logic drifting into core, or authoritative services drifting into the composition layer —
would be a migration of live artifacts to undo.

## Decision

### 1. The contribution contract is Gate A; the integration is Gate B

Extensions will contribute to composition — blocks, patterns, inspectors and field controls, design
vocabulary, migrations for the documents their blocks appear in. Gate A exists to promise an extension
author that the contracts they build against do not move afterwards, and a composition contribution
declaration invented after Gate A would break that promise for every extension that had begun
contributing. So **the declarations are frozen at Gate A** as criterion 13, through the same additive
classification, generation and compatibility-fixture machinery every other contribution surface uses.
They declare and validate; they render and store nothing, and they are inert until the runtime exists.

**The integration is Gate B** as criterion 12, and Version 2 does not ship without it: the host
adapter, the authenticated preview endpoint, and the embedded surface. Phase S carries both halves, the
way phase L carries a Gate B tail, and its qualification rides phase 7.

### 2. The division of labour is fixed

Studio owns the portable page-building product and protocol: the command engine, production block catalog,
authoring shell, Editor.js-backed inline-content adapter, canonical document forms, semantic-web renderer,
trusted progressive enhancements, and their evolution under its own repository's gates and evidence
discipline. Kumwe owns the host adapter and everything authoritative behind it: identity and session
generations, policy, artifact persistence with optimistic revisions, media custody, dynamic resource/data
resolution, authenticated preview delivery, server-side Twig projection, localization, telemetry, and audit.
Composition documents are artifacts the platform stores and versions; core does not acquire a second page
model or duplicate Studio's block behavior. If an integration need cannot be met through the host ports, that
is a finding against the boundary, raised in both repositories — never an inline workaround.

### 3. Versions are pinned exactly, and the release manifest records them

The Studio contract is prerelease until its own programme ratifies it. The integration pins one exact
coordinated package family, upgrades all eight packages together, and records the exact release record and
tarball integrity in the Gate B signed manifest. A floating range, partial family, or App integration built
against an unmerged Studio branch is prohibited. The coordinated adoption sequence is normative in the
integration document. Studio's release policy disables beta/RC promotion until M1-04 and evidence acceptance;
a prerelease name alone is not evidence that either product gate passed.

### 4. Stored composition follows the platform's storage discipline

Studio's canonical documents are closed-schema JSON that its own negative fixtures keep free of raw
markup, styles, and code. This is the platform's existing rule — bounded typed structure rather than
stored executable content — applied to a new artifact kind, and the host adapter's tests assert it
with the corpus rather than trusting it.

### 5. The one security-posture change is a same-origin preview frame

The authoring page keeps the strict administrator policy. Authenticated preview renders in a
same-origin frame behind the origin-pinned, replay-resistant preview channel, which requires allowing
same-origin framing for exactly that route — the global `frame-ancestors 'none'` posture stays for
everything else, and the change ships with its own negative tests. No inline script, no relaxation of
script or style sources, and no third-party origin enters any policy.

### 6. The interface standard's boundaries are respected, in both directions

The dashboard remains a semantic overview and never becomes a composition surface. The existing
server-rendered editing paths remain, so essential operation without JavaScript is unchanged; the
composition surface is an additional capability-gated authoring surface for composition artifacts.
Its interior interaction contract — keyboard completeness, announcements, reflow, reduced motion — is
enforced by Studio's own machine-checked requirement registry, and the embedded result still passes
this platform's browser, accessibility, and locale matrix.

## Alternatives rejected

### Build a composition editor inside this repository

Rejected. It couples an interface programme to the platform release train, duplicates an engine and
contract Studio already proves with canonical vectors and enforced registries, and produces a second
in-house interface stack beside the interface standard rather than a bounded, contract-consuming
surface.

### Put the whole integration at Gate A

Rejected. Gate A is the extension-contract freeze, and building the adapter, the preview endpoint and
the embedded surface before it would delay the point where extension authors can start. Only the part
an extension author must be able to depend on — the declarations — belongs there.

### Leave the contribution declarations to Gate B with the rest

Rejected, and this is the decision's correction to its own first shape. It would mean an extension
published after Gate A that contributes a block must be reissued when the surface ships, which is
exactly the outcome Gate A exists to prevent. Declaring without a runtime is a small, additive cost;
retrofitting a declaration contract onto published extensions is not.

### Wait for the Studio contract to reach its stable release before starting

Rejected. The adapter builds against pinned exact prerelease versions and a corpus that already
carries the contract's semantics as executable fixtures; waiting serializes two programmes that can
proceed in parallel with an explicit pin.

## Consequences

**Positive.** Version 2 ships a visual composition capability whose authoring semantics are proven by
a fixture corpus rather than promised; the platform's authoritative boundaries are unchanged; the
adapter is testable from PHPUnit with no Studio code executing on the server.

**Cost.** A contribution generation is frozen at Gate A before the runtime that consumes it exists, so
a shape the runtime later finds insufficient costs an additive generation rather than a silent change.
A second repository's release discipline enters this programme's critical path at Gate B; phase S
carries the coordination. The pinned-version rule means Studio fixes reach the integration
only through deliberate upgrades.

**Risk that must be tested, not assumed.** The preview frame's origin pinning and the channel's
replay resistance under this platform's session model; the adapter's revision mapping under
concurrent editing; media policy parity between the corpus and the media module's real limits; and
the embedded surface's conformance to the locale matrix including right-to-left.

## Non-goals

No composition runtime in the Gate A half. No composition rule in core. No public
composition editing surface in Version 2 — the surface is an
administrator capability. No multi-tenant considerations beyond what decision D7 already fixes. No
change to the dashboard contract. No replacement of the existing content editor.
