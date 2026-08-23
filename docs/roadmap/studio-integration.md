# Studio visual composition integration

**Companion to** decision D16, [ADR 0007](decisions/0007-studio-visual-composition-integration.md),
phase S in [`README.md`](README.md) section 9, Gate A criterion 13, and Gate B criterion 12.
**Ledger entries** `V2-STU-001` through `V2-STU-007`.

This document is the integration input for phase S: what Studio is, what it publishes, what the host
adapter must implement, and where every normative contract lives. It is forward-work material and
deliberately lives in `docs/roadmap/`; when the integration ships, its operator- and author-facing
documentation is written as released behaviour in the ordinary documentation tree, and this file leaves
the roadmap with the phase.

---

## What Studio is

Studio is a schema-aware visual composition platform developed in its own repository,
[`kumwe/studio`](https://github.com/kumwe/studio). Authors compose pages and reusable structures from
typed, theme-bounded building blocks; the result is a canonical, portable JSON document — never stored
markup, never stored styles, never stored code. Its architecture is contract-first: a language-neutral
protocol of JSON Schemas and canonical fixtures, a deterministic browser-independent command engine with
byte-invertible history, an accessibility-complete authoring shell, and a host-port boundary through
which every authoritative concern — identity, policy, persistence, media, preview rendering,
localization, telemetry — remains owned by the host platform. Kumwe is such a host. The division of
labour is fixed in ADR 0007: Studio owns the authoring experience and the protocol; Kumwe owns the host
adapter and every authoritative service behind it.

Studio's programme runs its own two-gate discipline in its repository —
[`docs/roadmap/`](https://github.com/kumwe/studio/tree/main/docs/roadmap) there — with machine-checked
registries for its requirements and threats, a canonical fixture corpus, and an evidence model. Its
contract is `0.1-draft` until its own first gate ratifies it; consumers pin exact prerelease versions
until then, which is why every version below is exact.

## Published packages

Seven packages publish to the npm registry under the `kumwe` organization on the `alpha`
distribution tag. The two contract packages this repository consumes are vendored and pinned:
[`tests/Fixtures/Studio/PIN.json`](../../tests/Fixtures/Studio/PIN.json) is the authoritative
record of their exact versions and tarball checksums, and `composer studio:corpus` fails when the
vendored bytes and the pin disagree. For the remaining packages, confirm the current set with
`npm view @kumwe/studio-protocol versions`.

| Package | Version | What it carries |
|---|---|---|
| `@kumwe/studio-protocol` | pinned in `PIN.json` (`0.1.0-alpha.6` at vendoring) | The wire types, guards, and the complete JSON Schema corpus with its digest manifest |
| `@kumwe/studio-core` | `0.1.0-alpha.5`, unvendored | The deterministic command engine, session, contribution runtime, migrations, URL policy |
| `@kumwe/studio-preview` | `0.1.0-alpha.3`, unvendored | Both ends of the origin-pinned preview channel: client, host responder, geometry |
| `@kumwe/studio-media` | `0.1.0-alpha.4`, unvendored | Upload orchestration over the canonical media session state machine |
| `@kumwe/studio-rich-text` | `0.1.0-alpha.3`, unvendored | The bounded rich-text grammar, parser and renderer projection |
| `@kumwe/studio` | `0.1.0-alpha.5`, unvendored | The authoring shell as a web component, keyboard-complete and catalog-localized |
| `@kumwe/studio-testkit` | pinned in `PIN.json` (`0.1.0-alpha.8` at vendoring) | The canonical fixture corpus and a deterministic in-memory reference host |

The host adapter's server side needs none of these at runtime — the protocol is JSON over the wire.
The packages matter in two places: the administrator build consumes `@kumwe/studio` (and transitively
the runtime packages) through the existing Vite entry points, and the test suite consumes the corpus
shipped inside `@kumwe/studio-testkit` and `@kumwe/studio-protocol`.

## The contract corpus, usable from PHPUnit

Every contract artifact is language-neutral JSON, so the host side proves conformance without executing
any Studio code. The corpus at the versions above:

| Corpus | Where it ships | Count |
|---|---|---|
| JSON Schemas with digest manifest | `@kumwe/studio-protocol` `schemas/` | 23 |
| Command vectors (initial document, command, expected result or failure code, inverse) | `@kumwe/studio-testkit` `vectors/command/` | 44 |
| Media policy vectors (policy, request, accepted plan or stable rejection) | `@kumwe/studio-testkit` `vectors/media/` | 11 |
| Negative fixtures the schemas must reject | `@kumwe/studio-testkit` `invalid/` | 26 |
| Rich-text renderer conformance projections | `@kumwe/studio-testkit` `conformance/rich-text/` | 7 |
| Canonical example documents | `@kumwe/studio-testkit` `fixtures/` | 20 |

What the host test suite asserts with them:

- every response the adapter emits validates against the relevant schema, and every error it emits
  satisfies the closed twelve-category shape in `host-error.schema.json` — non-disclosing messages,
  stable categories, no raw internals;
- documents the adapter persists and returns survive the canonical serialization rules (sorted members,
  canonical scalars, the digest manifest's checksums recompute);
- where the adapter touches command processing at all, the command vectors replay to identical results —
  the engine itself runs in the browser, so the server-side obligation is storage fidelity and revision
  discipline, not reduction.

## The host contract

The normative host contract is
[`docs/contracts/host-adapter.md`](https://github.com/kumwe/studio/blob/main/docs/contracts/host-adapter.md)
in the Studio repository, with the port types in `@kumwe/studio-protocol`. Nine typed asynchronous
ports share one request envelope: identity, policy, artifact persistence, media, preview, localization,
telemetry, search, and configuration. Capability negotiation is fail-closed: no common wire version or a
missing required port means no editable session; missing optional ports degrade with diagnostics.

The mapping to mechanisms Kumwe already has:

| Studio port concern | Existing Kumwe mechanism it maps onto |
|---|---|
| Identity and session generations | Administrator sessions and the security epoch; a permission change invalidates the Studio session generation |
| Policy | Capability checks through the existing authorization gateway; deny-by-default |
| Persistence and optimistic concurrency | Versioned artifacts with expected-revision writes; a stale write returns the safe current revision, matching the platform's version-conflict contract |
| Idempotent replay | The `Idempotency-Key` contract already on every API mutation |
| Media | The media module behind the canonical upload session state machine; the media policy vectors fix the rejection behaviour |
| External media and embed sources | Studio's lexical URL policy plus the host runtime obligations its threat registry records: fetch hardening, redirect re-validation, response verification |
| Preview | A new authenticated render endpoint — none exists today — behind the origin-pinned, replay-resistant preview channel |
| Localization | The Studio shell's host-overridable message catalog fed from the XLIFF-compiled catalogue chain, so wording and terminology overrides apply to the composition surface like any other |
| Telemetry | The observability contract; Studio's telemetry port carries primitive-only attributes within the established cardinality discipline |

## What an extension contributes, and why it is frozen first

Studio models an extension's contributions as a manifest of typed declarations: composition blocks
with a bounded property schema, declared slots and a renderer binding; patterns as reusable composition
structures; inspectors and field controls that edit a contributed field type; design vocabulary
including tokens, recipes and the size roles a theme remaps; and migrations for documents a contributed
block appears in. Its own runtime activates them into an immutable generation with owner rules,
lifecycle states and unresolved-node handling, and its authoring surface validates a declaration
against exactly the rules that runtime enforces, so an author's mistake surfaces at declaration time
rather than at activation.

Kumwe's extension contract carries these as its own classified contribution surfaces — `S-A`, the Gate
A half of phase S. The declaration shapes are the published composition schemas rather than a
paraphrase of them, so the contract an author reads and the contract the runtime enforces cannot drift.
A property schema is bounded by the published schema profile, which is what keeps a contributed block
from smuggling unbounded structure into a stored document. Nothing renders or stores at Gate A; the
declarations are inert until the Gate B half exists, and freezing them first is what lets an extension
published the day after Gate A install unchanged when the surface arrives.

The behaviour those declarations get at Gate B is the same lifecycle the platform already guarantees:
a disabled or untrusted owner's blocks stop executing while documents that used them stay readable and
diagnosable, and an unresolved block is represented rather than dropped.

## Contract documents to read before implementing

All in the Studio repository under
[`docs/contracts/`](https://github.com/kumwe/studio/tree/main/docs/contracts):
`commands.md` (the sixteen-command subset, canonical vectors, failure taxonomy),
`blueprint.md` and `content-and-entries.md` (the composition and content artifacts),
`host-adapter.md` (ports, envelope, error categories), `preview.md` (handshake, markers, geometry,
reload and teardown), `media.md` (upload sessions, external sources, policy vectors), `rich-text.md`
(bounded grammar, renderer conformance), `theme.md` (tokens, recipes, size roles), `security.md` and
`security-threats.md` (the enforced threat registry and the pinned content-security baseline),
`versioning-and-migrations.md`, `extension-lifecycle.md`, `localization.md`, and `accessibility.md`.
Decision records live in
[`docs/decisions/`](https://github.com/kumwe/studio/tree/main/docs/decisions); programme state in
[`docs/roadmap/STATUS.md`](https://github.com/kumwe/studio/blob/main/docs/roadmap/STATUS.md).

## Alignment with standing Kumwe rules

The integration is consistent with the covenant and the interface standard, and phase S proves rather
than assumes each point:

- **Stored compositions hold structure, never code.** Studio documents are closed-schema JSON;
  raw markup and styles are rejected by its negative fixtures. This is the same rule the platform
  already applies to stored preferences and expressions.
- **The dashboard remains what the interface standard says it is.** The composition surface is a
  separate, capability-gated administrator authoring surface; it does not turn the dashboard into a
  page builder and it does not touch the dashboard contract.
- **Essential operations keep their server-rendered paths.** The existing content editor remains; the
  composition surface is an authoring capability for composition artifacts, and the platform's
  no-JavaScript guarantees for essential navigation, forms and recovery are unchanged.
- **Content security tightens rather than relaxes.** The Studio shell is proven in its own repository
  under `default-src 'none'` with a bare self script source and enforced Trusted Types; embedding it
  keeps the administrator policy strict, and the one deliberate change — a same-origin frame for
  authenticated preview — is recorded in ADR 0007.
- **Accessibility is contractual on both sides.** Studio's requirement registry binds keyboard
  completeness, reflow, reduced motion and zero-violation scans to executable checks; the embedded
  surface still passes the platform's own browser, accessibility and locale matrix.
