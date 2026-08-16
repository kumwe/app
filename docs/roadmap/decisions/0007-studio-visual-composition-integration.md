# ADR 0007 — Studio visual composition is integrated as a Gate B deliverable

**Status** Accepted
**Decided by** Product owner
**Verified against** `47597288f322c3db7a9e334cf915158e6e4cded9`
**Findings** `V2-STU-001`, `V2-STU-002`, `V2-STU-003`, `V2-STU-004`, `V2-STU-005`, `V2-STU-006`
**Gate** B

---

## Context

### What exists today, verified

The administrator edits content through server-rendered forms: `content-form.twig` behind
`AdministratorContentEditorHandler` and its write handlers, with optimistic versions, retained input,
CSRF, and capability gating. Presentation is selected, not composed: stored preferences and settings
carry identifiers and order, never markup or URLs. There is no visual composition surface, no preview
endpoint — the editor links to the published address — and no `@kumwe/studio` dependency anywhere;
`lit` is the administrator build's single runtime dependency. The security headers send
`frame-ancestors 'none'` with a self-only script source.

Studio exists as a separate repository, [`kumwe/studio`](https://github.com/kumwe/studio): a
schema-aware visual composition platform whose contract is a language-neutral corpus of JSON Schemas
and canonical fixtures, whose engine is deterministic and browser-independent, and whose authoring
shell is a keyboard-complete web component proven under a content-security policy of
`default-src 'none'` with a bare self script source and enforced Trusted Types. Everything
authoritative — identity, policy, persistence, media, preview rendering, localization, telemetry —
stays behind typed host ports the embedding platform implements. Seven packages publish to the npm
registry under the `kumwe` organization; the corpus they ship is replayable from PHPUnit because it is
JSON, not code. The integration input is collected in
[`docs/roadmap/studio-integration.md`](../studio-integration.md).

### Why now, and why a decision record

Version 2's product objective includes authoring experience as a first-class capability, and the
composition surface is the largest interface capability the platform does not have. The
core-versus-adapter boundary must be settled before any host work starts, because the wrong boundary —
composition logic drifting into core, or authoritative services drifting into the composition layer —
would be a migration of live artifacts to undo.

## Decision

### 1. Studio integration is Version 2 scope, gated at Gate B, not Gate A

Gate A freezes the extension contract so extension authors build on stable ground; it asserts nothing
about interface capabilities. The composition surface is an interface capability and a release
expectation: it becomes **Gate B criterion 12**, and Version 2 does not ship without it. Phase S owns
the work; it enters after Gate A and its qualification rides phase 7.

### 2. The division of labour is fixed

Studio owns the authoring experience and the protocol: the command engine, the shell, the canonical
document forms, and their evolution under its own repository's gates and evidence discipline. Kumwe
owns the host adapter and everything behind it: identity and session generations, policy, artifact
persistence with optimistic revisions, media, the authenticated preview renderer, localization, and
telemetry. Composition documents are artifacts the platform stores and versions; the platform never
interprets or mutates their interior on a write path, and core gains no composition rule. If an
integration need cannot be met through the host ports, that is a finding against the boundary, raised
in both repositories — never an inline workaround.

### 3. Versions are pinned exactly, and the release manifest records them

The Studio contract is prerelease until its own programme ratifies it. The integration pins exact
package versions, upgrades are deliberate changes with their own evidence, and the Gate B signed
manifest records the exact versions the release qualified. A floating range on a draft contract is
prohibited.

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

### Integrate at Gate A

Rejected. Gate A is the extension-contract freeze; adding an interface capability to it delays the
point where extension authors can build, and the composition surface depends on services — media,
preview, localization — whose Gate A state is already sufficient through their ordinary contracts.
The user-visible expectation is the release, and the release is Gate B.

### Wait for the Studio contract to reach its stable release before starting

Rejected. The adapter builds against pinned exact prerelease versions and a corpus that already
carries the contract's semantics as executable fixtures; waiting serializes two programmes that can
proceed in parallel with an explicit pin.

## Consequences

**Positive.** Version 2 ships a visual composition capability whose authoring semantics are proven by
a fixture corpus rather than promised; the platform's authoritative boundaries are unchanged; the
adapter is testable from PHPUnit with no Studio code executing on the server.

**Cost.** A second repository's release discipline enters this programme's critical path at Gate B;
phase S carries the coordination. The pinned-version rule means Studio fixes reach the integration
only through deliberate upgrades.

**Risk that must be tested, not assumed.** The preview frame's origin pinning and the channel's
replay resistance under this platform's session model; the adapter's revision mapping under
concurrent editing; media policy parity between the corpus and the media module's real limits; and
the embedded surface's conformance to the locale matrix including right-to-left.

## Non-goals

No composition rule in core. No public composition editing surface in Version 2 — the surface is an
administrator capability. No multi-tenant considerations beyond what decision D7 already fixes. No
change to the dashboard contract. No replacement of the existing content editor.
