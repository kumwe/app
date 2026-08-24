# Studio Content composition authoring

The administrator can compose the Blueprint owned by one immutable Content-type version at
`/administrator/content-models/{id}/versions/{version}/composition`. This is a composition-model
surface, not a Content-entry editor. The existing server-rendered model and entry editors remain the
essential authoring paths and are not replaced.

## Provisioning and authority

The first visit is read-only and presents a normal HTML form. Its CSRF-protected `POST` provisions the
composition and returns `303 See Other`; `GET` never creates a binding or artifact. Both methods require
`content.read` and `studio.mode.blueprint`.

Provisioning obtains the exact authorized AP-2 Content-model projection, derives the stable Blueprint
identity `content-blueprint:{content-type-id}:v{content-type-version}`, and atomically writes:

- binding revision 1, whose null `blueprint_revision` means the current artifact head; and
- an admitted, empty, draft Blueprint through the AP-4 artifact admission and repository ports.

The Blueprint locks the projected model, the exact active public site theme, and every block that the active host
can actually render. That deployment/renderability snapshot is independent of the provisioning actor;
actor capability narrows only the presented authoring catalog, so another authorized actor sees the
same immutable artifact lock. The block lock and its trusted per-type renderer map come from the live
owner-bound contribution registry; they are not a copied palette or a browser prefix inference. An
existing Blueprint intersects that catalog with its exact immutable `type`, `version`, and `revision`
lock. An active same-type definition with a different version or revision refuses boot, rather than
silently substituting it. A later extension activation cannot widen an existing palette, and a disabled
or distrusted owner is withdrawn without deleting unresolved nodes already in a document.

The public-theme coordinate is not fabricated. A built-in theme revision digests the immutable App
release, complete built-in site-template tree, and only the runtime assets reachable from the Vite site
entry (or the manifest-less fallback). An extension theme uses its verified installed version and
deployed-tree digest. Both include the validated site-presentation document in the final revision. That
revision also participates in the host-session generation. If publication changes after provisioning,
the immutable Blueprint fails closed with a localized migration diagnostic; AP-7 never rewrites its
dependency lock. The current artifact framework has no safe theme-lock migration command, so migration
is an explicit external operation until that separately reviewed executor exists.

## Browser and host boundary

Only this surface dynamically loads the coordinated `0.1.0-alpha.11` Studio packages. It opens the AP-3
Blueprint session and uses the common CSRF-protected host route for artifact, permission, recovery,
model, localization, telemetry and—when composed by AP-6—preview operations. It does not advertise the
absent `resource.search` operation.

The exact AP-7 vector coverage is:

| Port | Vector IDs |
|---|---|
| Model | `vector.host-vector.model.get.stored`, `vector.host-vector.model.list.authorized` |
| Localization | `vector.host-vector.localization.messages.unknown-locale` |
| Telemetry | `vector.host-vector.telemetry.emit.accepted`, `vector.host-vector.telemetry.emit.non-primitive` |

The shell receives the complete 160-key Studio message corpus through the App's compiled catalogue and
site/organization override chain. Its active locale carries requested and resolved tags, direction,
time zone and the ordered source fallback. Studio's published named-interpolation grammar is retained
for its exact message namespace; neighbouring App messages remain ICU-validated.

Document changes execute through the Studio session, serialize optimistic saves, and acknowledge the
captured state version with `markSaved(revision, stateVersion)`. A late acknowledgement rebases that
snapshot without marking newer edits clean. A conflict leaves the local draft dirty, disables further
mutation, replaces the interactive shell with a localized refusal, and cannot stage a stale preview.
The draft surface exposes the canonical `artifact.publish` operation only when the live App policy also
grants the distinct `content.publish` capability; a published Blueprint exposes `artifact.unpublish` only
when policy grants `content.unpublish`. Blueprint authoring authority alone permits composition and save
but never widens into either lifecycle transition, and holding one transition capability never authorizes
the other. Studio's current protocol projects both operations through its shared
`studio.permission/publish` permission, so the App session response also carries private `canPublish` and
`canUnpublish` decisions. Each artifact operation enforces its own server-resolved decision, and either
decision changing invalidates the session generation even while the shared protocol permission remains.
Publication first waits for its latest draft save to settle. Both transitions send an exact reference,
expected revision and mutation idempotency key, then reload the accepted current head. Drafts open an
editable Studio session, while published and retired artifacts open read-only, so a published revision
cannot be changed by an ordinary composition command before it returns to draft.

Preview uses the AP-6 session metadata and two independent sequence lanes, both beginning at zero. The
parent serializes render and document-claim order while allowing an in-flight render's cancellation to
reach the server immediately. Cancellation is memoized per render attempt, so abort, Studio teardown
and page disposal cannot duplicate one cancellation. A later render of the same digest waits for that
attempt's acknowledged cancellation; an ambiguous preview transport failure terminally closes the
channel instead of spending a higher sequence. AP-6's bounded predecessor wait and cancellation
tombstone make a concurrent pre-grant cancel authoritative. A hidden `sandbox="allow-same-origin"`
staging iframe waits for the single-use HTML
document, checks its exact route and KIS sentinel, and is swapped into the preview slot only while its
generation is current. Measurement returns every finite `getClientRects()` fragment for the current
response's bounded marker set. Iframe and outer scroll, resize, visual-viewport changes, fonts and late
layout trigger coalesced geometry refresh.

## Layout contract

Stored documents carry bounded layout intent, never CSS. The AP-6 renderer projects admitted values to
the following closed attributes, and App styles consume only these values:

| Attribute | Values |
|---|---|
| `data-studio-layout-alignment` | `center`, `end`, `start`, `stretch` |
| `data-studio-layout-collapse` | `preserve`, `stack`, `wrap` |
| `data-studio-layout-columns` | decimal integers `1` through `12` |
| `data-studio-layout-direction` | `block`, `inline` |
| `data-studio-layout-spacing` | `comfortable`, `compact`, `none`, `spacious` |
| `data-studio-layout-visibility` | `hidden`, `visible` |

The coordinated theme supplies all five Studio layout controls and its responsive grid recipe. The
preview CSS uses logical properties, supports right-to-left documents, and applies the admitted
expanded-to-medium-to-compact `4 → 2 → 1` grid behavior without stored CSS. A one-width authoring
preview receives the unsuffixed attributes above. Marker-free published output instead retains the exact
same allowlisted values as `data-studio-layout-{compact|medium|expanded}-{property}` attributes. The
committed site stylesheet selects compact through `46rem`, medium above `46rem` through `62rem`, and
expanded above `62rem`; it applies alignment, collapse, columns, direction, spacing, and visibility only
from those tokens. The public renderer evaluates all three widths before returning any HTML, so a malformed
inactive-width override fails closed instead of lying dormant until a visitor reaches that viewport.

## Published page runtime

Public Content resolution remains authoritative. After the existing locator selects a published record,
the Studio publication boundary looks up only the binding for that record's exact site, Content-type ID,
and pinned Content-type version. No binding, or a bound Blueprint whose lifecycle is `draft` or `retired`,
keeps the existing layout and presenter byte-for-byte. A `published` Blueprint instead renders through the
internal canonical `page` template with an empty legacy data map and one host-produced safe composition
fragment; navigation, language alternates, canonical URL, colour scheme, cache policy, indexing policy,
site assets, and the validated public theme remain on the same `ContentPageRenderService` path.

Configuring a binding makes compatibility mandatory. The selected artifact must exist at its bound head or
revision, be the exact App-owned Blueprint, and pass the pinned Studio schema and stored-content policy. Its
model lock must equal the record's exact projected model ID, semantic version, and deterministic revision;
the matching historical Content definition must still exist. Its theme must equal the live published theme,
and every block lock and node must have the exact live core or signed-extension renderer. Missing artifacts,
wrong kinds, incompatible schemas, model drift, theme drift, ambiguous locks, withdrawn extensions, and
renderer failure throw typed fail-closed refusals instead of falling back and silently changing published
meaning.

That live decision also revalidates every node against the exact canonical block declaration: base and
viewport-effective responsive properties, unique node identity, declared slots, min/max cardinality,
accepted child types and every Content field named by an entry binding. Optional canonical-document
SHA-256 locks are verified before the declaration is interpreted. Public core layout fragments emit only
the closed compact, medium and expanded data tokens, and the site stylesheet maps those tokens to bounded
media-query behavior without accepting stored styles.

The public value projection does not invent an administrator or anonymous authorization context. The public
Content boundary has already selected a publishable record, so `ContentStudioProjector::publishedValues()`
projects its complete pinned schema with the same lossless recursive conversion as the authorized Studio entry
port. Stored strings remain strings; block output remains plain text escaped by the closed renderer. Published
markup shares preview traversal and registry semantics but omits every `data-studio-preview-marker` authoring
attribute. `StudioPublishedCompositionGuard::assertCompatible()` is the reusable application seam for the same
schema, owner, model, theme, and live-renderer check at artifact publication time.

## Qualification status

Automated coverage declares provisioning, exact sequence order, save conflict refusal, same-origin
preview validation, geometry-backed visual select/reorder/reparent, cancelled drag, keyboard parity,
responsive layout, right-to-left rendering, overflow, axe and deterministic style/geometry invariants. Browser execution and
snapshot ratification happen in the integrated AP-6 environment; source presence alone is not recorded
as execution evidence.

AP-6 projects the admitted published-theme variables through an authenticated, same-origin, no-store
`/administrator/studio/preview/theme.css` grant. The preview carries one `link[data-studio-theme]`, no
inline theme style or body style attribute, and keeps the strict CSP byte-identical. AP-7 browser
coverage verifies that response contract and the computed published `--site-accent`; AP-7 does not
copy the renderer or relax its preview policy.

The accountable-human acceptance journey is defined in
`tests/Fixtures/Studio/composition-acceptance-journey.json`. It requires a human author to compose,
preview and publish through the canonical lifecycle control, then repeat the journey in a non-source language. Its status remains `not_run`;
automation cannot self-attest that gate.

The signed manifest-6 compatibility fixture now supplies a real owner-specific Grid renderer. The browser
journey installs and activates the signed package, asserts its exact renderer/type/version/revision and
required properties, publishes it on the real Content route, proves the public response is marker-free,
then disables the owner and observes the fail-closed response before reactivation and unpublish. This
closes the composition-block slice of P7-F; the roadmap's broader six-fixture vertical-neutral proof
portfolio, lifecycle/backup/upgrade matrix and accountable acceptance still remain independent Gate B work.

The release chain already binds all seven packages to `0.1.0-alpha.11` in
`resources/studio-contract/studio-release.json` and records its SHA-256 in
`resources/studio-contract/PIN.json`; release verification rejects any package or record mismatch.
