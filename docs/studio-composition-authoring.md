# Studio authoring in Kumwe App

## Authority and status

This is the single App-side host record for Studio. It does not define a second product direction. The sole
normative statement of Studio product intent is Studio's
[`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md), currently
`STUDIO-PROD-1.0-draft`. Studio schemas remain authoritative for serialized shapes, Studio contract documents
remain authoritative for protocol semantics, and the two repositories' roadmap status files remain authoritative
for implementation and qualification state.

This record maps that one product contract onto Kumwe App. Other App documents must link here for App-specific
status and must not restate a competing Studio workflow. A target described here is not evidence that it is
implemented.

## The one product outcome

Studio is Kumwe's contextual page builder and content editor. People should think about the content they are
creating, not about opening or operating a separate product.

- Creating or editing managed content opens Studio for that exact item.
- An author starts from a blank canvas or an authorized reusable Kumwe content type.
- Layout, blocks, typed fields, field bindings, and entry values are created in one continuous workspace.
- A Kumwe content type presents a reusable choice while retaining separately versioned content-model, Blueprint,
  and authoring-policy artifacts underneath.
- Saving an item, saving the design as a new type, and creating a new type version are explicit different actions.
- Studio may be inline, minimized, maximized, or full-screen, but the resource, unsaved state, authority, and return
  path remain intact.
- Kumwe App does not expose Studio as a primary/top-level navigation workspace. A dedicated full-screen route may
  be an expanded state of the current resource-bound session, never a catalogue-first prerequisite.
- There is no prerequisite Blueprint screen, manual identifier transfer, or copy-and-paste hand-off.
- Authorized extension contributions appear in this same workspace only where their declared target and the host's
  policy allow them.
- Studio is mounted through one canonical configuration per target element. A page may host several independent
  instances; none may infer a Kumwe route, credential, permission, resource, or contribution from a global default.

Editor.js is Studio's private rich-text implementation inside applicable blocks. Editor.js state, tool
configuration, or HTML is not an App contract and does not cross the host boundary.

## Current App truth

The pinned coordinated Studio family is `0.1.0-beta.3`; exact package and corpus bytes are recorded by
[`resources/studio-contract/PIN.json`](../resources/studio-contract/PIN.json). The beta label describes that
coordinated Studio package family; it does **not** by itself prove Kumwe App's integrated journey.

Content New and Content Edit mount the pinned, compiled Studio browser module in place for the exact PHP-resolved
target. PHP opens an opaque authoring context and a hybrid host session per mount, emits one Producer-proven
`studio-deployment` document with the exact operation routes, same-origin credentials, CSRF header, locale,
closed capability projection and return context, and answers every operation through
`ContentStudioAuthoringService`, `StudioAuthoringHostPort` and the other App host ports:

- blank and reusable-type starts on Content New, and an exact existing-item start on Content Edit that hydrates
  the item's accepted type, Model, Blueprint and Entry revisions and values;
- typed fields through Studio's Model control, Entry values through its Content mode, and Blueprint layout with
  the host allocating node identities for palette and keyboard insertion;
- `save-item`, `save-as-new-type` and `save-new-type-version` as separately planned PHP transactions with
  visible consequences, value exclusion, an immutable successor type/Model/Blueprint, expected-revision
  conflicts, idempotent replay and audit through the Producer mutation boundary; the session records the start
  it opened with and declares a constant set of save outcomes, so Studio reconciles every accepted save;
- a successor type version keeps its reusable type's Blueprint identity with a new revision, and a stored
  reusable Blueprint locks exactly the blocks it composes, each of which must have a live renderer;
- inline, minimized, maximized and fullscreen presentation of the same session, and a deterministic return to
  the accepted item's edit context;
- the interface-locale Studio message catalogue served by the localization port;
- a host-owned authenticated preview beside the shell over the origin-pinned, replay-resistant preview port,
  resolving the saved item's own values behind the opaque context, and trusted public rendering through PHP
  and Twig once the workflow publishes the item;
- the structured form as the explicitly labelled `STUDIO-PROD-014` fallback, remembered per editor and used
  when the runtime or configuration is unavailable.

The journey is automated in `tests/Browser/studio-authoring.spec.ts` on Chromium desktop and mobile, with its
interface locale as a parameter, and in `tests/Integration/Studio/ContentStudioAuthoringJourneyIntegrationTest.php`
on every engine. `tests/Architecture/StudioProductionRuntimeTest.php` refuses any production Node.js, npm or
Vite requirement.
[`tests/Fixtures/Studio/composition-acceptance-journey.json`](../tests/Fixtures/Studio/composition-acceptance-journey.json)
records, step by step, what is automated and what is not.

What remains open, and why:

- **Extension-owned targets.** App declares one core target, `kumwe.app/content-authoring`. An extension-owned
  content area resolving through the same declaration is not implemented.
- **Standalone dual mounting (`STUDIO-PROD-015` steps 1 to 3).** Standalone mode is Studio-owned; App's hosted
  surface uses the structured fallback instead and does not exercise it.
- **Pinned-release limitations.** In `0.1.0-beta.3` a reusable-type save result must echo the live Entry that
  the save request does not carry, so values entered before a blank canvas becomes a type cannot survive that
  save; save requests do not carry the presentation, so a save must happen in the presentation the session
  started in; the hosted in-shell preview cannot stage a live draft, so the App preview shows accepted
  revisions only; and the create-source chooser and save confirmation render without catalogue overrides. Each
  needs a Studio release before App can close it.
- **Extension lifecycle in the contextual shell.** An admitted extension block is used, saved, previewed and
  rendered; field-adapter and pattern use, and disable, unresolved, upgrade and migration behaviour, are proven
  only on the Blueprint route.
- **Human acceptance.** Assistive-technology and accountable human runs are not automated evidence.

The model-version composition route,
`/administrator/content-models/{id}/versions/{version}/composition`, remains a transitional Blueprint-only
surface and is not the authoring entry point.

## Product-contract mapping

| Requirement | App status | Required App outcome |
|---|---|---|
| `STUDIO-PROD-001` | Implemented, automated | Launch from Content create/edit with the exact trusted resource context. |
| `STUDIO-PROD-002` | Implemented, automated | Offer blank and reusable-type starts without copying entry values. |
| `STUDIO-PROD-003` | Implemented with pinned-release limits | Compose layout, fields, bindings, and values without a manual screen hand-off. |
| `STUDIO-PROD-004` | Implemented, automated | Present one reusable type while preserving exact Model, Blueprint, policy, and revision identities. |
| `STUDIO-PROD-005` | Implemented, automated | Hydrate the item's exact accepted type, Model, Blueprint, Entry revisions, and values. |
| `STUDIO-PROD-006` | Implemented, automated | Implement separately confirmed item-save, new-type-version, and new-type outcomes. |
| `STUDIO-PROD-007` | Implemented with pinned-release limits | Preserve full session state across inline/minimized/maximized/fullscreen presentation and return. |
| `STUDIO-PROD-008` | Partial | Resolve core and extension content areas through one generic Studio target declaration. |
| `STUDIO-PROD-009` | Partial | Apply the canonical contribution lifecycle to blocks, field adapters, and patterns on admitted targets. |
| `STUDIO-PROD-010` | Implemented, automated | Route every durable effect through declared host APIs and PHP App authority. |
| `STUDIO-PROD-011` | Implemented, automated refusal | Ship compiled assets; require no Node.js, npm, Vite, or JavaScript server in production. |
| `STUDIO-PROD-012` | Implemented, automated | Remove pre-creation, copy/paste, catalogue-first, and manual revision reconciliation. |
| `STUDIO-PROD-013` | Partial | Prove keyboard, explicit-control, touch, assistive-technology, zoom, directionality, and reflow parity. |
| `STUDIO-PROD-014` | Enforced | Keep target, primitive, integration, package, conformance, gate, and fallback claims distinct. |
| `STUDIO-PROD-015` | Automated in part; not accepted | Prove the complete integrated acceptance journey exactly as specified by Studio. |

`Partial` and `Implemented with pinned-release limits` never mean the end-to-end requirement is accepted.

## Configuration-first deployment boundary

Studio owns a portable mount contract; App supplies one instance of that contract for each eligible Content target.
The exact serialized member names and validators belong to Studio's published schemas and packages. App must consume
that contract after coordinated publication and must not create an App-private replacement while the upstream shape
is still changing.

For a hosted Kumwe instance, PHP emits HTML-safe JSON adjacent to the target element. The canonical configuration
must name the exact resolved operation endpoints, credential mode and CSRF transport, opaque context/session seed,
locale, authorized Studio capabilities, content and field inputs, reusable-type choices, admitted blocks and
patterns, media and preview services, explicit save outcomes, and return context. It may map several logical
operations to one physical dispatcher, but browser code receives the resolved URLs and never constructs them from a
base-route convention. Raw App capability maps remain server-side; only the closed Studio projection crosses the
boundary.

The administrator session cookie remains `HttpOnly`. Configuration tells Studio to use same-origin browser
credentials and supplies the scoped CSRF header name/value required by App; it never exposes a session cookie or
turns a bootstrap value into durable authority. PHP authenticates and authorizes every request again, re-resolves the
target and active contribution generation, validates the canonical request, and applies revision, transaction,
idempotency, audit, and outbox rules before accepting an effect.

Absence has two deliberately different meanings:

- An intentionally backendless Studio mount with no configured host route is Studio's standalone mode. Studio owns
  its built-in static catalogue, blank local canvas, canonical JSON import, and JSON download behavior.
- Kumwe Content is a hosted integration. If its complete canonical configuration or qualified runtime is absent or
  incompatible, App renders the named structured-editor fallback. Once a route is configured, an authentication,
  authorization, validation, conflict, rate-limit, or server response remains authoritative and must never be
  converted into standalone/offline success.

Each mount owns its DOM root, configuration, lifecycle, unsaved state, and opaque context. No singleton element ID,
global mutable adapter, shared sequence, or page-wide unload listener may couple otherwise independent instances.

## Host boundary: PHP is always authoritative

The production request path is browser Studio -> same-origin App endpoint -> PHP application service -> existing
authorization/domain/persistence/audit services. JavaScript can present state and request an operation; it cannot
be the server authority.

Node.js and npm are contributor, build, test, and release tools only. Official browser assets are compiled and
committed or packaged before deployment. Starting, operating, saving, previewing, publishing, and publicly rendering
Kumwe must never require `node`, `npm`, a development server, or a server-side JavaScript process.

The integrated journey needs these PHP-owned application operations. Existing Studio port names and schemas are
used where they already cover the outcome; any missing public protocol operation must first be defined in the
Studio repository and then consumed by App, never invented as a private parallel contract.

| Operation | PHP responsibility |
|---|---|
| Resolve authoring context | Authenticate the actor; resolve site, item or create intent, type/version, locale, workflow, capabilities, contribution generation, and return location. |
| Load blank or reusable start | List only authorized types; load the exact model/Blueprint/policy revision; initialize empty values; preserve all artifact identities. |
| Create a draft item | Call the existing Content application service under transaction, policy, validation, revision, audit, and idempotency rules. |
| Save an item | Validate the Studio result against the pinned type and workflow; compare the expected revision; persist through Content services; return the accepted revision. |
| Save as a new content type | Validate and atomically create the model, reusable Blueprint, policy/bindings, and initial type version without current entry values. |
| Create a content-type version | Show migration and dependent-entry effects; require explicit confirmation; create immutable successor revisions; never rewrite a published version. |
| Save and lifecycle Studio artifacts | Reuse the authenticated generation fence, expected revisions, audit, and replay-safe artifact operations. |
| Media, resources, preview, and publication | Reuse the existing typed host ports and trusted PHP/Twig delivery; reapply policy at every resolution. |
| Dispatch integrations or webhooks | Emit only after an accepted PHP transaction through App's durable outbox/integration services; sign, retry, and audit under host policy. |
| Resolve extension contributions | Admit only the active immutable generation for the exact target/surface/mode and require host-renderable, authorized definitions. |

## Extension reuse

Extensions do not embed or fork Studio. A schema-6 extension declares canonical Studio
`block-definition`, `pattern`, `field-adapter`, `inspector`, `design-vocabulary`, and `migration` documents plus
bounded App host bindings. The App resolves them into the same Studio generation as first-party tools. An
extension surface that declares an eligible Studio target can request contextual authoring for its authorized
resource; it must not create a new editor, expose Editor.js, or bypass the PHP host operations above.

Contribution admission and activation primitives already exist. Seamless contextual use from extension-owned
content areas remains part of the open integrated journey and must be proven by `STUDIO-PROD-015`.

## Successor App pull request, small working goals

PR 119 merged the `S-G1` documentation increment before runtime implementation began. Goals `S-G2` through
`S-G9` therefore proceed in one successor App pull request. Each goal is one coherent, reviewable commit, leaves
the branch green, and adds the focused proof for the behavior it introduces. A Studio protocol or package change,
if the capability audit proves one necessary, lands first in one coordinated Studio pull request and is consumed
by the App pull request through one exact family re-pin.

1. **`S-G1` — Contract truth.** Land the one product authority, App ADR and mapping, corrected status/finding,
   acceptance fixture, production PHP/asset rule, and agent guardrails. This documentation increment completes that
   goal; it does not complete the product journey.
2. **`S-G2` — Context envelope and launch.** Ship the compiled Studio asset and open it from Content New/Edit with a
   canonical per-mount configuration issued by PHP for the exact resource, resolved operation URLs, CSRF transport,
   recovery scope, and deterministic return. Missing or incompatible configuration/assets fail closed; a configured
   refusal remains authoritative, and the current form remains an explicit fallback.
3. **`S-G3` — Context-preserving shell.** Preserve resource identity, selection, authority, locale, unsaved state,
   history, validation, and return across inline, minimized, maximized, and full-screen presentations.
4. **`S-G4` — Existing-item round trip.** Load the authorized exact Model, Blueprint, type, and Entry revisions and
   values; change layout and values; save through PHP with expected revision, conflict, idempotency, audit, and workflow
   behavior; then reopen the accepted result.
5. **`S-G5` — Existing-type creation.** Start a new item from an authorized reusable type with its exact structure,
   fields, and bindings but empty Entry values, then save it through the same PHP path.
6. **`S-G6` — Blank creation and field authoring.** Start blank, compose blocks and typed fields, enter values in the
   same Studio journey, and save without a prerequisite catalogue workflow.
7. **`S-G7` — Explicit reusable-type outcomes.** Implement the separately confirmed save-item,
   save-as-new-type, and new-type-version PHP transactions with value exclusion, immutable successors, permission,
   migration/dependency impact, conflict, replay, and audit proof.
8. **`S-G8` — Contributions, preview, and delivery.** Resolve authorized extension contributions on their declared
   targets, prove an extension-owned authoring surface, and exercise authenticated preview and trusted public rendering.
9. **`S-G9` — Production qualification.** Run the exact `STUDIO-PROD-015` journey on the packaged artifact, PHP-only
   server topology, real databases, browser/accessibility/security/localization lanes, public rendering, fallback, and
   automated refusal of any production Node.js/npm dependency.

The pull request is not complete because its Blueprint canvas opens. It is complete only when the canonical
acceptance journey passes and the live status ledgers truthfully record that evidence.

## Focused references

- Product intent and acceptance: Studio
  [`docs/product-contract.md`](https://github.com/kumwe/studio/blob/main/docs/product-contract.md)
- Protocol semantics: Studio
  [`docs/contracts/`](https://github.com/kumwe/studio/tree/main/docs/contracts)
- Current Studio implementation and gate state: Studio
  [`docs/roadmap/STATUS.md`](https://github.com/kumwe/studio/blob/main/docs/roadmap/STATUS.md)
- Exact App package/corpus pin: [`resources/studio-contract/PIN.json`](../resources/studio-contract/PIN.json)
- App implementation ledger: [`docs/roadmap/STATUS.md`](roadmap/STATUS.md)
- Detailed phase-S evidence and component map: [`docs/roadmap/studio-integration.md`](roadmap/studio-integration.md)
