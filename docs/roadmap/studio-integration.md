# Studio visual composition integration

**Companion to** decision D16, [ADR 0007](decisions/0007-studio-visual-composition-integration.md),
phase S in [`README.md`](README.md) section 9, Gate A criterion 13, and Gate B criterion 12.
**Ledger entries** `V2-STU-001` through `V2-STU-007`.

This document records phase S alignment: what Studio is, the exact release App consumes, the host
adapter now implemented, and where every normative contract lives. Released author-facing behavior is
documented in [`docs/studio-composition-authoring.md`](../studio-composition-authoring.md); the remaining
phase-7 human/security proof and authoritative contributed-renderer browser/DB qualification stay explicit below.

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

The coordinated release contains seven packages. All seven exact tarballs are vendored and pinned:
[`resources/studio-contract/PIN.json`](../../resources/studio-contract/PIN.json) is the authoritative
record of their exact versions and tarball checksums, and `composer studio:corpus` fails when the
vendored bytes and the pin disagree. App makes no runtime or qualification claim about a public npm
publication; its build consumes only these release-bound bytes.

| Package | Version | What it carries |
|---|---|---|
| `@kumwe/studio-protocol` | `0.1.0-alpha.10`, vendored and pinned | The wire types, guards, and the complete JSON Schema corpus with its digest manifest |
| `@kumwe/studio-core` | `0.1.0-alpha.10`, vendored and pinned | The deterministic command engine, session, contribution runtime, migrations, URL policy |
| `@kumwe/studio-preview` | `0.1.0-alpha.10`, vendored and pinned | Both ends of the origin-pinned preview channel: client, host responder, geometry |
| `@kumwe/studio-media` | `0.1.0-alpha.10`, vendored and pinned | Upload orchestration over the canonical media session state machine |
| `@kumwe/studio-rich-text` | `0.1.0-alpha.10`, vendored and pinned | The bounded rich-text grammar, parser and renderer projection |
| `@kumwe/studio` | `0.1.0-alpha.10`, vendored and pinned | The authoring shell as a web component, keyboard-complete and catalog-localized |
| `@kumwe/studio-testkit` | `0.1.0-alpha.10`, vendored and pinned | The canonical fixture corpus and a deterministic in-memory reference host |

The host adapter's server side needs none of these at runtime — the protocol is JSON over the wire.
The packages matter in two places: the administrator build consumes `@kumwe/studio` (and transitively
the runtime packages) through the existing Vite entry points, and the test suite consumes the corpus
shipped inside `@kumwe/studio-testkit` and `@kumwe/studio-protocol`.

## The contract corpus, usable from PHPUnit

Every contract artifact is language-neutral JSON, so the host side proves conformance without executing
any Studio code. The corpus at the versions above:

| Corpus | Where it ships | Count |
|---|---|---|
| Positive schema documents | coordinated protocol/testkit corpus | 218 |
| Command vectors (initial document, command, expected result or failure code, inverse) | `@kumwe/studio-testkit` `vectors/command/` | 60 |
| Canonical serializations | coordinated protocol/testkit corpus | 12 |
| Preview identities | coordinated protocol/testkit corpus | 2 |
| Negative fixtures the schemas must reject | coordinated protocol/testkit corpus | 43 |
| Rich-text renderer conformance projections | `@kumwe/studio-testkit` `conformance/rich-text/` | 7 |

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
in the Studio repository, with the port types in `@kumwe/studio-protocol`. Typed asynchronous ports
share one request envelope. App advertises only artifact, permission, recovery, model, media, preview,
localization and telemetry operations it actually implements; `resource.search` remains absent.
Capability negotiation is fail-closed: no common wire version or a missing required port means no
editable session; missing optional ports degrade with diagnostics.

The mapping to mechanisms Kumwe already has:

| Studio port concern | Existing Kumwe mechanism it maps onto |
|---|---|
| Identity and session generations | Administrator sessions and the security epoch; a permission change invalidates the Studio session generation |
| Policy | Capability checks through the existing authorization gateway; deny-by-default |
| Persistence and optimistic concurrency | Versioned artifacts with expected-revision writes; a stale write returns the safe current revision, matching the platform's version-conflict contract |
| Idempotent replay | The `Idempotency-Key` contract already on every API mutation |
| Media | The media module behind the canonical upload session state machine; the media policy vectors fix the rejection behaviour |
| External media and embed sources | Studio's lexical URL policy plus the host runtime obligations its threat registry records: fetch hardening, redirect re-validation, response verification |
| Preview | The AP-6 authenticated render, single-use document and grant-bound theme stylesheet endpoints behind the origin-pinned, replay-resistant preview channel |
| Localization | The Studio shell's host-overridable message catalog fed from the XLIFF-compiled catalogue chain, so wording and terminology overrides apply to the composition surface like any other |
| Telemetry | The observability contract; Studio's telemetry port carries primitive-only attributes within the established cardinality discipline |

### Implemented S-C authority and S-D persistence boundaries

The App now opens Studio authority through `POST /administrator/studio/session` and dispatches the
normative protocol binding through
`POST /administrator/studio/ports/{port}/{operation}`. Both routes use the authenticated administrator
context and CSRF middleware. The client cannot assert actor, grant, site, organization, workspace,
surface, or browser-session identity. An opaque context key is bound to those trusted coordinates and
to one of all five canonical modes over the Content or Blueprint resource family.

`permission.explain` and `permission.refresh` resolve effective permissions through the existing
deny-by-default authorization gateway on every call. The same advertised host now implements artifact
`dependencies`, `load`, `save`, `publish`, and `unpublish`, plus recovery `load`, `store`, and `discard`.
A publication target requires `content.publish`, while return to draft requires `content.unpublish`.
The Studio protocol's shared lifecycle permission is only a compatibility projection: private live
`canPublish` and `canUnpublish` decisions authorize the corresponding mutation independently and both bind
the generation, so retaining one capability cannot conceal revocation of the other behind an unchanged
permission list.
A generation binds grants, the security epoch, membership policy, mode, effective permissions, host
capabilities, resource context, and authenticated browser session. Every operation still crosses the same
dispatcher and therefore returns `invalid-request` with `studio.host/stale-session-generation` before its
port can act under stale authority.

Artifact reads accept the published `{ "reference": … }` wrapper and save accepts
`{ "document": … }`; recovery store accepts `{ "envelope": … }` while load and discard require an empty
object. Save, publish, and unpublish require `expectedRevision`. The store appends an immutable revision and
moves its head only by compare-and-set, so a conflict cannot overwrite and reveals only the safe current
revision. Completed mutations replay by an atomic digest over actor, authenticated session, resource,
operation and caller key; request and trace IDs do not alter intent, while semantic arguments, protocol,
locale and expected revision do. A changed intent is refused.

Schema validation and a separate lexical stored-content policy run before persistence. Accepted canonical
bytes are stored as text and returned unchanged; markup, executable syntax, style declarations, unsafe
members and unsafe or out-of-schema URLs are rejected rather than sanitized. Recovery bytes are scoped by
actor, authenticated session and resource context, with canonical-number fidelity and bounded size and
fixed-window writes. Successful artifact and recovery mutations record one disclosure-safe audit event in
the same transaction. The audit carries operation and trusted coordinates/digests, never document or
envelope bytes, session material or raw idempotency keys; completed replay does not audit twice.

AP-1 replay accounting for this package names these exact vendored vector IDs:

- `vector.host-vector.permission.explain.withheld`
- `vector.host-vector.permission.refresh.snapshot`
- `vector.host-vector.envelope.malformed-context`
- `vector.host-vector.envelope.protocol-version`
- `vector.host-vector.envelope.stale-generation`

The migration and architecture decision are recorded in
[ADR 0014](decisions/0014-studio-host-session-authority.md). Versioned artifact and recovery persistence,
its replay/audit boundary and migration are recorded in
[ADR 0015](decisions/0015-studio-artifact-and-recovery-persistence.md). AP-5 media, AP-6 preview, and
AP-7 model reads, localization, telemetry and embedded Blueprint shell now cross this same
authority/dispatcher boundary. Model mutation and `resource.search` are deliberately not advertised;
an absent operation remains canonically unavailable without weakening the stale fence.
Every composition lookup reprojects the authorized exact Content model and compares its identifier,
version and revision with the immutable Blueprint model lock before returning the artifact. Drift in any
coordinate is a typed migration-required refusal rather than an opportunity to open the Blueprint against
a different schema.

AP-1 replay accounting for S-D additionally covers these exact vendored IDs:

- `vector.host-vector.artifact.dependencies`
- `vector.host-vector.artifact.load.stored`
- `vector.host-vector.artifact.load.unknown`
- `vector.host-vector.artifact.publish.accepted`
- `vector.host-vector.artifact.publish.forbidden`
- `vector.host-vector.artifact.publish.stale`
- `vector.host-vector.artifact.save.accepted`
- `vector.host-vector.artifact.save.forbidden`
- `vector.host-vector.artifact.save.stale`
- `vector.host-vector.artifact.unpublish.stale`
- `vector.host-vector.recovery.load.absent`
- `vector.host-sequence.artifact.publish.changed-intent`
- `vector.host-sequence.artifact.publish.idempotent-replay`
- `vector.host-sequence.recovery.store.canonical-number`
- `vector.host-sequence.recovery.store.changed-context`
- `vector.host-sequence.recovery.store.rate-limited`
- `vector.host-sequence.recovery.store.resource-scope`
- `vector.host-sequence.recovery.store.wrong-operation-id`

### Implemented S-F authenticated preview boundary

The App implements `preview.render` and `preview.cancel` through the common AP-3 dispatcher and exposes
the rendered document at authenticated `GET /administrator/studio/preview`. The HTTP binding is closed:
render accepts exactly `{payload}`, cancel accepts exactly `{draftDigest}`, and session opening returns
only `{channelId, documentPath, origin, sourceId}` as preview metadata. After render succeeds, the browser
navigates the same-origin frame to `documentPath` with the resource context, render request, session
generation, channel, source and the next independent document sequence. The GET atomically claims that
exact staged result once; it is neither a stable unpublished URL nor a bearer-token document store.

The renderer reads the immutable canonical draft from S-D's `studio_artifact_revisions`, resolves Content
bindings through the existing AP-2 authorization/projection service, and presents only that authorized
projection. Content stays authoritative. The host registry truthfully renders the four structural block
types `studio.core/{section,stack,grid,columns}` and these core-owned field blocks:
`core/field-text`, `core/field-rich-text`, `core/field-integer`, `core/field-decimal`,
`core/field-boolean`, `core/field-date`, `core/field-date-time`, `core/field-media`, and
`core/field-resource`. Media and resource fields render bounded labels or identifiers, never a client URL.
Unknown types remain visible as inert diagnostics rather than executing or disappearing.

Schema-6 extension blocks use that same registry seam without treating their manifest renderer string as
code. A verified provider must explicitly share a `StudioPreviewBlockRenderer` under the restricted
container identifier `extension.<owner-namespaced renderer binding>`. Core then binds that instance to the
reconciled canonical block's exact type, version and revision and to the exact package/runtime publication
that loaded it. Current-generation and live-trust checks run before every extension call; missing or
incorrect services, dependency-lock drift, foreign document owners, package replacement, trust withdrawal,
and renderer exceptions all produce the inert unresolved fragment. The runtime contribution keeps the
owner-local service binding separate from the canonical `preview` renderer capability: an authoring
catalog advertises `kumwe.contract-manifest-six/grid`, never
`kumwe.contract-manifest-six.renderer.grid`, and only while the exact executable entry reports support.
Installing and activating the committed signed manifest-6 fixture is the deterministic non-core renderer
used by P7-F and the AP-7 browser qualification catalog.

For the four structural types, the render request's semantic viewport resolves an exact responsive override
before the node's base property and Studio's runtime default. The renderer emits only the closed
`data-studio-layout-{alignment,collapse,columns,direction,spacing,visibility}` vocabulary: alignment,
collapse, direction, spacing and visibility use their published finite choices, while columns is an integer
from one through twelve. Attribute names are deterministically ordered and values are escaped. An arbitrary
data attribute, style attribute, malformed responsive container or out-of-vocabulary value is refused before
HTML exists; the draft never supplies CSS.

Published and unpublished output share `ContentPageRenderService`, the canonical site template and exact
validated theme values. Preview supplies composition markup as an already-presented entry, suppresses the
public theme-variable attribute, and projects the same variables into a closed generated stylesheet stored
with the grant. Claiming the document activates one authenticated same-origin no-store stylesheet link;
the subresource rechecks live authority and exact grant/channel/source coordinates without advancing either
protocol sequence. Immediately before markup exists, the canonical renderer re-resolves the trusted
published theme for the session's `SiteContext` and compares its exact identifier, version and revision
with the immutable draft lock. Theme activation or presentation drift abandons the pending grant and returns
the stable `conflict` / `studio.preview/theme-lock-mismatch` refusal. `preview.render` creates a
60-second grant bound to actor, site, optional organization/workspace, authenticated browser session,
resource context, session generation, origin, channel, source, draft identity and port sequence.
Cancellation and a newer render supersede matching pending work; completed HTML is no-store and can be
claimed once. Separate monotonic `port` and `document` ledgers reject replay or out-of-order traffic on
both endpoints. Migration `20260824040000_studio_preview_grants` creates the portable
`studio_preview_grants` and `studio_preview_sequences` stores repeatably.

The only CSP delta is selected by the exact preview path: `frame-src 'self'`,
`frame-ancestors 'self'`, `style-src-attr 'none'`, `X-Frame-Options: SAMEORIGIN`, and
`Referrer-Policy: no-referrer`. Style elements and attributes remain forbidden; the exact theme is fetched
under the unchanged `style-src(-elem) 'self'` policy. Script remains self-only and no inline script/style,
`eval`, remote frame or remote connection source is admitted. A policy comparison test holds every other
administrator directive byte-identical. Authentication, live session-generation resolution, same-origin
Origin/Referer evidence, no-store delivery, Cross-Origin-Resource-Policy and the single-use claim are all
rechecked on the document request rather than inferred from a successful render call.

The PHPUnit contract suite replays all four directly applicable published vectors by ID:

- `vector.preview-identity.canonical-preorder`
- `vector.preview-identity.empty-draft`
- `vector.host-sequence.preview.cancel.cross-context`
- `vector.host-sequence.preview.render.cancelled`

It also distinguishes foreign origin, wrong channel, wrong source, replayed sequence and out-of-order
sequence refusals on both transport lanes; holds the exact HTTP wrappers; validates the emitted rendered
payload against `preview-message`; and covers draft identity, expiry, cancellation, supersession,
cross-context isolation and single claim. The Security workflow runs the boundary's negative-path suite
as an explicit P7-C qualification step and retains `build/security/studio-preview-junit.xml` inside the
commit-bound `security-evidence-${GITHUB_SHA}` artifact.

Preview staging is ephemeral presentation activity, not an authoritative Content or Blueprint mutation,
so it does not manufacture a business audit event. The authorization gateway continues to write its
ordinary accountable authorization evidence. A separate fail-closed structured activity recorder emits
only actor, site, resource kind, a resource fingerprint, request/correlation IDs, closed action/outcome,
and stable reason. Its allowlist excludes canonical draft bytes and digest, HTML, grants/context keys,
channel/source identifiers, sequences, markers and marker maps; a test holds that observability boundary.

[ADR 0017](decisions/0017-authenticated-studio-preview.md) records the security and rendering decisions.
Local SQLite conformance is directly runnable. S-F is not evidence for S-G's built browser surface or for
the phase-7 human qualification: CI must still prove the migration and replay ledger on MariaDB, MySQL and
PostgreSQL, the built administrator bundle must exercise the real `PreviewBinding`/iframe sequence in the
browser matrix, and an independent security run must retain the P7-C artifact before Gate B assessment.

### Implemented published Content composition runtime

The public Content handlers now ask one optional `StudioPublishedContentRenderer` after the existing
publication-aware locator has selected a record. The lookup is exact by site, Content-type identity, and
the record's pinned definition version. No binding, or a bound Blueprint in `draft` or `retired`, preserves
the legacy layout and presenter. A `published` artifact is rendered only after its binding identity,
App-owned kind and schema, projected model ID/version/revision, historical Content definition, active public
theme, and every exact block renderer lock have been re-resolved. Once configured, absence or drift is a
typed fail-closed error; it never becomes an accidental legacy page.

`StudioPublishedCompositionGuard::assertCompatible()` owns the reusable schema/owner/model/theme/live-renderer
decision so `artifact.publish` can execute the same guard before accepting a lifecycle transition. The
public request path runs it again to cover package withdrawal or theme/model change after publication.
The decision reconstructs each exact canonical block definition from the current owner-aware registry,
verifies its optional SHA-256 integrity lock and live renderer binding, and interprets the published
property schema over both base properties and every viewport's effective responsive overlay. It also
enforces unique node identities, declared slots, slot cardinality, accepted child types and the exact
Content fields named by entry bindings. A stored artifact can therefore remain readable after a package
change without becoming executable under a different declaration.
`ContentStudioProjector::publishedValues()` reuses the schema-governed lossless value conversion without
fabricating an authenticated actor, because the public Content boundary already made the publication
decision. The structural renderer shares preview traversal, escaping, and the live core/signed-extension
registry while omitting authoring markers. Its closed responsive output carries fixed compact, medium and
expanded layout tokens whose bounded site styles reproduce visibility, direction, collapse, column,
alignment and spacing intent without accepting stored CSS. Both the ordinary page route and nominated
homepage then place that safe fragment in the internal canonical `page` template, retaining navigation,
languages, canonical URLs, theme, cache, indexing, and site assets from the existing
`ContentPageRenderService` path.

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
from smuggling unbounded structure into a stored document. Gate A admitted only inert declarations.
Gate B now activates all six canonical Studio document kinds through the owner-aware contribution
runtime, locks only exact host-renderable blocks, and withdraws disabled or distrusted owners. An
owner-specific executable renderer is required before an extension block enters the palette or preview.
The runtime and signed manifest-6 proof now implement that boundary, including distinct public capability
and owner-local service identifiers; P7-F remains open only until the authoritative browser and
database-backed lifecycle runs retain their CI evidence.

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
