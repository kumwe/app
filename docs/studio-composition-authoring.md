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

Editor.js is Studio's private rich-text implementation inside applicable blocks. Editor.js state, tool
configuration, or HTML is not an App contract and does not cross the host boundary.

## Current App truth

The pinned coordinated Studio family is `0.1.0-rc.1`; exact package and corpus bytes are recorded by
[`resources/studio-contract/PIN.json`](../resources/studio-contract/PIN.json). The release-candidate label describes
that coordinated Studio package family. It does **not** by itself prove Kumwe App's integrated contextual-authoring
journey.

App currently has valuable low-level integration primitives:

- a compiled browser shell and Studio-owned Blueprint canvas;
- authenticated PHP session and host-port dispatch;
- PHP-backed permission, artifact/recovery, media/resource, preview, localization, and telemetry operations;
- read-only Content model and entry projection;
- versioned Studio artifact persistence, optimistic concurrency, and audit;
- extension contribution admission and owner-aware activation; and
- trusted preview and published Content composition rendering.

The composed authoring route,
`/administrator/content-models/{id}/versions/{version}/composition`, is nevertheless Blueprint-only. It is reached
from an already-created immutable Content-type version, while Content models and entry values are still created or
edited through separate App forms.
The model host port is read-only and generic artifact save updates an existing draft only. Therefore App does not
yet provide blank-or-type creation, entry editing, field/model creation, the three explicit save outcomes, or the
complete context-preserving acceptance journey inside Studio. That is a major integration gap, not an alternative
product interpretation and not a reason to weaken the target.

The existing generated Content editor remains a transitional fallback until the integrated journey passes the
canonical acceptance proof.

## Product-contract mapping

| Requirement | App status | Required App outcome |
|---|---|---|
| `STUDIO-PROD-001` | Open | Launch from Content create/edit with the exact trusted resource context. |
| `STUDIO-PROD-002` | Open | Offer blank and reusable-type starts without copying entry values. |
| `STUDIO-PROD-003` | Open | Compose layout, fields, bindings, and values without a manual screen hand-off. |
| `STUDIO-PROD-004` | Partial primitives | Present one reusable type while preserving exact Model, Blueprint, policy, and revision identities. |
| `STUDIO-PROD-005` | Open | Hydrate the item's exact accepted type, Model, Blueprint, Entry revisions, and values. |
| `STUDIO-PROD-006` | Open | Implement separately confirmed item-save, new-type-version, and new-type outcomes. |
| `STUDIO-PROD-007` | Partial primitives | Preserve full session state across inline/minimized/maximized/fullscreen presentation and return. |
| `STUDIO-PROD-008` | Open | Resolve core and extension content areas through one generic Studio target declaration. |
| `STUDIO-PROD-009` | Partial primitives | Apply the canonical contribution lifecycle to blocks, field adapters, and patterns on admitted targets. |
| `STUDIO-PROD-010` | Partial primitives | Route every durable effect through declared host APIs and PHP App authority. |
| `STUDIO-PROD-011` | Implemented deployment rule; acceptance pending | Ship compiled assets; require no Node.js, npm, Vite, or JavaScript server in production. |
| `STUDIO-PROD-012` | Open | Remove pre-creation, copy/paste, catalogue-first, and manual revision reconciliation. |
| `STUDIO-PROD-013` | Partial primitives | Prove keyboard, explicit-control, touch, assistive-technology, zoom, directionality, and reflow parity. |
| `STUDIO-PROD-014` | Enforced documentation rule | Keep target, primitive, integration, package, conformance, gate, and fallback claims distinct. |
| `STUDIO-PROD-015` | Not passed | Prove the complete integrated acceptance journey exactly as specified by Studio. |

`Partial primitives` never means the end-to-end requirement is delivered.

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

## One App pull request, small working goals

Implementation proceeds in one App pull request. Each goal is one coherent, reviewable commit, leaves the branch
green, and adds the focused proof for the behavior it introduces. A Studio protocol or package change, if the
capability audit proves one necessary, lands first in one coordinated Studio pull request and is consumed by the
App pull request through one exact family re-pin.

1. **`S-G1` — Contract truth.** Land the one product authority, App ADR and mapping, corrected status/finding,
   acceptance fixture, production PHP/asset rule, and agent guardrails. This documentation increment completes that
   goal; it does not complete the product journey.
2. **`S-G2` — Context envelope and launch.** Ship the compiled Studio asset and open it from Content New/Edit with a
   PHP-issued trusted context for the exact resource, recovery scope, and deterministic return. Missing or incompatible
   assets fail the build/release clearly; the current form remains an explicit fallback.
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
