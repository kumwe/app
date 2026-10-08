# ADR 0024 — A Content type without a layout opens with a default composition from its model

**Status:** Proposed. The App change that implements it rests on working assumptions A1 to A3, which await the
maintainer; the maintainer's merge accepts them, and a rejection reverses them as described below.
**Decision maker:** the maintainer; drafted by the integrating agent, 2026-10-07.
**Scope:** contextual Content authoring on the pinned Studio `0.1.0-beta.9`, Producer `0.6.0` and extension SDK
`0.3.6` (`STUDIO-PROD-003`, `005`, `006`, `007` and `014`). No pin, Producer or SDK change is part of it.
**Bounded by:** [ADR 0020](0020-studio-contextual-content-authoring.md). The App changes data defaults, block
metadata, presentation defaults and its own preview resolution. It adds no shell behaviour and no App-local page
builder.
**Amends:** [ADR 0015](0015-studio-artifact-and-recovery-persistence.md) (decisions 2-3), for migration
`20261007120000_studio_field_block_revision` only.

## Evidence

The owner reported that an existing item's content does not open on the page, cannot be moved and cannot be
edited. On `383e4cd0` the causes are:

1. **Provisioning composes nothing.** `StudioContentCompositionService::provision()` admits the document
   `initialBlueprint()` builds, and that document always carries `'roots' => []`
   (`src/Studio/Application/Composition/StudioContentCompositionService.php` 138-163 and 560).
2. **An empty draft stays empty.** `ContentStudioAuthoringService::stateOf()` takes `find(...) ?? provision(...)`
   and hands the session the stored document unchanged
   (`src/Studio/Application/Authoring/ContentStudioAuthoringService.php` 906-907 and 936). A type save of an
   empty layout stores it as the type's draft (1506-1509), so the next start finds the same empty draft.
3. **Field blocks have no inspector editor.** The nine `core/field-*` block definitions declare a `value` port
   with no `authoring.control` (`src/Extension/Contribution/CoreStudioCompositionContributions.php` 151-157).
   The pinned shell edits a port in its inspector only when the port names one of five scalar controls:
   single-line text, multi-line text, switch, number or integer (`node_modules/@kumwe/studio/dist/kumwe-studio.js`
   890).
4. **An item cannot keep its own layout.** Every type declares `itemComposition: 'denied'`
   (`src/Studio/Application/Authoring/ContentStudioAuthoringDocuments.php` 337-343), so a moved block can be kept
   only by a type save.
5. **The page canvas is Studio's local render.** `session.preview.enabled` is `false`
   (`HostedContentStudioAuthoringConfigurationProvider.php` 413-417), so the canvas is the shell's own
   non-authoritative render. The pinned web renderer renders a block type it does not know as an "Unsupported
   Studio block" status line (`node_modules/@kumwe/studio-renderer-web/dist/renderer.js`).

A type save is admitted only against `ContentStudioAuthoringCatalog::renderableBlockLocks()` (126-129), the
contribution catalogue's blocks with a live App renderer, and `adopt()` refuses any other lock
(`StudioContentCompositionService.php` 330-342). Apart from blocks that admitted extensions contribute, those are
the App's own thirteen block types: four `studio.core/*` layout blocks and nine `core/field-*` blocks
(`CoreStudioCompositionContributions.php` 26-49). A first-party Studio content block such as
`studio.core/heading` therefore cannot be part of a stored default.

## Decision

Line numbers in this section refer to the tree that carries this decision.

### 1. Shape of the default composition

`src/Studio/Application/Composition/StudioContentDefaultComposition.php` derives the default from the projected
Content model that the shell already receives. It never reads raw schema.

- **One root.** The root is a `studio.core/section` with id `default/section`, empty `properties` and `bindings`,
  structural authoring mode, and one child per composed field in its `content` slot (`roots()`, 111-145).
- **Which fields.** A field is composed when all of these hold (`composableFields()` and `isComposableSource()`,
  343-401):
  - it is the entry title, or a top-level Content data field;
  - its cardinality is `one`;
  - it is not hidden from authoring.
- **Order.** Fields follow the model's authoring order, then their position in the model. The title projects
  with order 0. Data fields project in key order from 10 (`ContentStudioProjector.php` 120-145).
- **Which block.** Each field kind maps to one block (90-98):

  | Field kind | Block |
  |---|---|
  | `string` | `core/field-text` |
  | `integer` | `core/field-integer` |
  | `decimal` | `core/field-decimal` |
  | `boolean` | `core/field-boolean` |
  | `date` | `core/field-date` |
  | `date-time` | `core/field-date-time` |
  | `media` | `core/field-media` |

  Enumerations are left out on purpose: a text port would accept them, but the inspector would then offer free
  text for a closed set. The slug, object-valued fields and collections are also left for the author to add.
- **Lock.** `roots()` composes a block only when its type is in the lock it is given, and the node version
  comes from that lock. If the lock has no section, or no field qualifies, there are no roots. The section holds
  at most 100 children, which is its slot maximum. Which lock `presented()` gives it is described in decision 2.
- **Field nodes.** A field node's id is `default/field/` followed by the field id with `_` mapped to `:`
  (`fieldNodeId()`, 201-204). Node ids may not contain `_`, and `:` never occurs in a field id, so distinct
  fields keep distinct ids. Each node binds its `value` port to `{kind: 'entry-field', fieldPath: [fieldId]}`
  with no transforms, `onNull: 'empty'` and `onError: 'error'`. This is the policy the shell writes when an
  author binds a port by hand (`fieldNode()`, 416-434).

### 2. Where the default is applied (A1)

- **Provisioning.** `provision()` and `reference()` pass the derived roots to `initialBlueprint()`
  (`StudioContentCompositionService.php` 163-166 and 258-265), so a newly provisioned type stores the default as
  its `draft` composition.
  - `adopt()` passes no roots (325), because it uses the initial document only for its locks, model, owner and
    revision.
  - Non-empty initial roots join the `initial-` revision hash (545-557). An initial Blueprint with no roots
    keeps exactly the revision it had before this decision.
  - Every provisioning surface reaches this code: the administrator composition screen, REST, console and MCP
    (`StudioContentCompositionService.php` 30-39).
- **Load-time substitution.** `stateOf()` hands the session `StudioContentDefaultComposition::presented()` of the
  stored document, with the session catalogue's `renderableBlockLocks()` (`ContentStudioAuthoringService.php`
  948-952).
  - `presented()` replaces only a stored `draft` whose roots are empty. It returns a copy carrying the derived
    roots with the same id, version, revision and model (166-187). Every other document is returned as stored,
    so an authored layout, including an authored draft, is never replaced.
  - The stored lock wins for every block type it names. A provisioned draft locks every renderable block, so its
    copy keeps the stored lock unchanged.
  - A type save of an empty layout stores a draft that locks no block, because `adoptBlueprint()` locks only the
    types a layout composes. For that draft the default is derived against the renderable locks, and the copy's
    lock gains exactly the renderable lock of each composed type the stored lock does not name (`missingLocks()`,
    276-305). Without this, such a type would keep opening empty.
  - Nothing is written at load.
  - Every type-bound state the contextual authoring service builds, for a start, a plan or a save result, comes
    from `stateOf()` (846-890, 1039-1053 and 1447-1454). The machine authoring gateway dispatches the same
    operations, so it is handed the same document.
  - The transitional Blueprint-route composition screen and the machine composition gateway open the stored
    artifact and are never substituted. On them, an empty draft from before this decision still shows empty.
- **Type saves store an untouched default as an empty draft.** A type save stores a Blueprint with roots as
  `published`, and a published Blueprint replaces the structured template on the public site. Without a guard, a
  model-only type save would publish a layout the author never touched.
  - So `adoptBlueprint()` receives the state the save was planned against (1518-1574).
  - It stores `draft` when the saved roots are empty, as before.
  - When `StudioContentDefaultComposition::untouched()` holds (221-236), it stores the successor's composition as
    a `draft` with **no roots**, not as the default's roots (1538-1547). `untouched()` is true only when the
    handed Blueprint is a `draft`, its roots are non-empty, and those roots are canonically equal to both the
    saved roots and the default derived from the handed model and the handed copy's lock. The empty draft locks
    no block, which is the empty-layout path above, so the next load derives the default again from the saved
    model. Repeated model-only type saves therefore stay drafts and keep following the model, and a field a save
    removes is never persisted as a binding.
  - Every other layout is stored `published`, as before. In particular, an authored draft layout, such as one
    kept as a draft on the composition screen, still publishes on a type save.

### 3. Preview of a substituted default

The host-owned accepted-revision preview digests the Blueprint the shell holds. When the stored bytes at the
same revision differ from that digest, `StudioPreviewHostPort` asks the binding source to `present()` the draft
before its unchanged identity check runs (`StudioPreviewHostPort.php` 171-181).

`ContentStudioPreviewBindingSource::present()` applies the same `presented()` rule, with the same catalogue's
renderable locks, which the binding source now receives in its constructor (48-53 and 118-143). It does so only
when both hold:

- the session is a contextual Content authoring session;
- the stored draft's model lock is the projected model of the session's target.

It returns the draft unchanged for any other session, for a refused or unprojectable target and for a model
mismatch. The identity check then refuses with `studio.preview/draft-identity-mismatch` exactly as it did before.

### 4. Field blocks move to `core-block-r2` (A2)

All nine field block definitions move from `core-block-r1` to `core-block-r2`
(`CoreStudioCompositionContributions.php` 160). Three of their value ports gain an inspector control (145-153):

| Block | Value port control | Reason |
|---|---|---|
| `core/field-text` | `studio.control/multi-line-text` | One text block serves the title and every string field, including multi-line bodies. A single-line text input drops line breaks from its value. |
| `core/field-integer` | `studio.control/integer` | The shell types an `integer` port as an integer. |
| `core/field-boolean` | `studio.control/switch` | The shell types a `boolean` port as a boolean. |
| `core/field-decimal` | none | The shell types every port that is not boolean, integer or number as a string, while App decimal values are JSON numbers. A control would write a string into a number field, and the save would be refused. |
| `core/field-rich-text` | none | No App field projects as rich text, so nothing can bind to it. A plain-text control would also write a string into a structured value. |
| `core/field-date`, `core/field-date-time`, `core/field-media`, `core/field-resource` | none | The pinned shell has no scalar control for these. They are still edited in Content mode. |

The change is editor metadata only. Renderer requirements, accessibility metadata and property schemas are
unchanged.

### 5. Stored `core-block-r1` locks are migrated (A2)

After the bump, a Blueprint stored at r1 would fail the contribution catalogue's exact lock check
(`StudioCompositionContributionCatalog.php` 138-148), and the renderer runtime registers core renderers only at
the live coordinate (`StudioBlockRendererRuntime.php` 195-211). Migration `20261007120000_studio_field_block_revision`
(`src/Infrastructure/Persistence/Migration/StudioFieldBlockRevisionMigration.php`) is registered last in the
migration plan, after `CoreListingSortIndexMigration`.

What it does:

- It is forward-only and implements `RepeatableMigration`. A second run finds no r1 field lock and changes
  nothing.
- It reads Blueprint rows of `studio_artifact_revisions` and then `studio_artifact_heads` whose canonical document
  contains `"core-block-r1"` (144-158).
- In the document's `dependencyLock.blocks`, and in the row's canonical dependency list, it moves an entry from
  `core-block-r1` to `core-block-r2` only when the entry names one of the nine field types at version `1.0.0`
  (219-275). The nine types are written into the migration itself, so its meaning cannot drift with the live
  class.
- It re-encodes both columns canonically and re-orders the dependency list by canonical bytes, as artifact
  admission does (277-295).
- It proves each rewritten row by constructing `StoredStudioArtifact`, which re-checks the canonical bytes
  against the row's id, kind, revision and status. It then updates that row by site, artifact id, version and
  revision (193-207).

This amends [ADR 0015](0015-studio-artifact-and-recovery-persistence.md) decisions 2 and 3 for this one recorded
migration: stored Blueprint revision and head bytes change while revision identities do not. ADR 0015 carries a
matching amendment note.

What it deliberately leaves alone:

- The revision identity. Bindings, the published renderer and preview grants refer to it.
- Recovery envelopes, mutation replay results, preview grants and open sessions. Any r1 bytes they hold stay as
  recorded. By the code path, a shell opened before the upgrade still holds r1 bytes, so its accepted-revision
  preview no longer matches the stored digest and is refused until the editor is reopened. No test covers this.

This rewrites the bytes of stored revisions that the storage layer otherwise treats as immutable
(`src/Studio/Infrastructure/Persistence/DoctrineStudioHostStorage.php` 24-30), without changing their identity.
[ADR 0020](0020-studio-contextual-content-authoring.md) (161-163) allows existing artifacts to be adopted only
through explicit migration, and permits no silent rewrite of published Blueprint revisions. This migration is
explicit and recorded in the migration ledger, but it does change published revision bytes. It is justified only
because r1 to r2 changes inspector metadata and nothing a renderer reads. Accepting A2 is what authorises it. The
readiness probe used as a deployment gate refuses a database that is behind the migration set the build ships
(`src/Infrastructure/Persistence/ReadinessProbe.php` 22-30).

The migration checksums its own file, so `composer docs:api` and `composer docs:format` skip it
(`tools/verify-docblocks.php` 43-47 and 305, `tools/format-docblocks.php` 33-37 and 120). Its documentation
blocks are reviewed by hand.

### 6. The Content editor opens Studio maximized (A3)

The deployment's `launch.initialPresentation` is `maximized` for every Content editor intent
(`HostedContentStudioAuthoringConfigurationProvider.php` 311-312). The authoring service already admits that
value (`ContentStudioAuthoringService.php` 120 and 354-356). It records the start's presentation (375-383) and
reports it as the accepted base of every save result (1269-1275); Studio keeps any newer local presentation.

In the pinned shell, the maximized state is an in-flow box `block-size: min(90vh, 70rem)` with the workspace at
100% (`node_modules/@kumwe/studio/dist/contextual-authoring.js` 34-37 and 67-70). It sizes the box in the page
flow; it is not an overlay.

### 7. Mount box

`.studio-authoring-mount` sets `--studio-workspace-height: max(32rem, calc(100vh - 14rem))`. Where the browser
supports the dynamic viewport unit, an `@supports (block-size: 100dvh)` rule uses `100dvh` instead
(`assets/administrator/styles.css` 868-888). The `@supports` rule exists because a custom property keeps its
last declaration even where the browser cannot use the value.

- The property inherits into the shell's workspace, which otherwise defaults to `clamp(32rem, 74vh, 62rem)`
  (`node_modules/@kumwe/studio/dist/workspace-styles.js` 13). So it sizes the **inline** presentation.
- Maximized and fullscreen set the workspace to 100% on the shell element, which takes precedence. So the box an
  author sees by default under A3 is the shell's own maximized box, which this decision does not change.
- `overflow: auto` and the 24rem minimum are kept.
- `14rem` approximates the shell's header, mode tabs and footer, and is to be tuned from CI screenshots.

By arithmetic on the two rules, the inline workspace is up to about 33 CSS pixels shorter than the shell's own
default on viewports shorter than about 860 CSS pixels. It is taller above that, and has no 62rem cap.

### 8. The accepted-revision preview is a closed disclosure (A3)

In `templates/administrator/content-form.twig` (37-48), the preview region is a `<details>` that starts closed.

- Its `<summary>` carries only the existing eyebrow string ("Authenticated preview" in English), so the
  disclosure's accessible name stays short. The existing explanation sentence about the last saved composition
  is the first line inside the disclosure. No message key changed.
- The button, status and frame keep their data attributes inside the disclosure. `studio-launch.ts` finds them
  by those attributes within the Studio region (308-310), so the browser code is unchanged.
- The summary keeps the native disclosure marker and the shared keyboard-focus outline. It is at least the
  interface standard's touch target tall (`assets/administrator/styles.css` 909-920).
- The rule that hides the preview on the form surface still applies (905-907).

## Ownership

The derivation composes App-owned block types registered by `CoreStudioCompositionContributions`. It depends
on App projector conventions: the `data_` field-id prefix, authored field ids and the `kumwe.app/source-field`
extension. It is an App data default, so the class lives in App.

Searches made while drafting found no existing default-composition capability:

- the pinned Studio packages offer session configuration builders, not a composition derived from a model;
- Producer offers a component scaffolder, which is unrelated;
- the extension SDK offers nothing.

`vendor/kumwe/content-model` was inspected once the dependencies were installed: its `Application`, `Domain` and
`Workflow` sources contain no Studio, Blueprint or composition code. It is a Studio-agnostic package and cannot
know App block types.

If Studio later offers "compose a default from a model", the App adopts it and deletes this class.

## Working assumptions awaiting the maintainer

The owner has not yet answered these. They are recorded verbatim, each with what the App change implements and
how to reverse it.

**A1.** "The App may hand the shell a derived default composition for a type whose stored layout is an empty
draft (substituted at load, not persisted until a type save), and new types are provisioned with that default
composition."

- *Implemented:* decisions 1 to 3. Provisioning from every surface uses the default. A type save that leaves it
  untouched stores an empty draft, so the public page keeps its structured template and the next load derives the
  default again from the saved model.
- *Reverse:* remove the `presented()` calls in `stateOf()` and `ContentStudioPreviewBindingSource::present()`,
  and the catalogue that binding source takes for them.
  Pass no roots from `provision()` and `reference()`. Types already provisioned with the default keep it as a
  stored draft layout.

**A2.** "The nine field-block documents move to revision core-block-r2 with authoring.control on their value
ports, and stored compositions locked at core-block-r1 are migrated to r2 by a migration."

- *Implemented:* decisions 4 and 5. All nine move to r2, but only the text, integer and yes-or-no ports gain a
  control. The other six would mis-type or cannot bind on the pinned shell.
- *Reverse:* revise the control map behind a further revision `core-block-r3` and add a forward migration. The
  migration this decision ships cannot be undone, by the migration contract.

**A3.** "The content editor opens Studio maximized by default, in a viewport-tall mount box; the
accepted-revision "Preview this item" block is collapsed into a disclosure below the shell."

- *Implemented:* decisions 6 to 8. The viewport-tall box in the default maximized state is the shell's own
  `min(90vh, 70rem)` box. The mount-box change sizes the inline presentation an author can switch to.
- *Reverse:* set `initialPresentation` back to `inline`, and unwrap the disclosure.

## Decisions for the maintainer

This note is written for the pull request description.

> This pull request (B1) implements three working assumptions you have not yet confirmed.
>
> **A1:** a type whose stored layout is an empty draft is handed a default composition derived from its model.
> That default is a section holding the title and each top-level scalar field. It is substituted at load and
> written only when an author changes it and saves the type. For a draft a type save stored with an empty layout,
> the handed copy also carries the renderable lock of each composed block type; the stored revision is unchanged
> until a type save. New types are provisioned with it, from every provisioning surface. A type save that leaves
> it untouched stores an empty draft, so the public page does not change and the default keeps following the
> model on every later load.
>
> **A2:** the nine Content-field blocks move to `core-block-r2`. Text, integer and yes-or-no ports gain inspector
> controls; decimal, rich-text, date, date-time, media and resource ports do not. Migration
> `20261007120000_studio_field_block_revision` rewrites stored r1 locks in place and keeps revision identities.
> ADR 0020 permits no silent rewrite of published Blueprint revisions. This rewrite is an explicit, recorded
> migration, but it does change the stored bytes of published revisions, so it needs your acceptance. It also
> amends ADR 0015 decisions 2 and 3 (immutable revision history, canonical bytes round-trip unchanged) for this
> one recorded migration: stored Blueprint revision and head bytes change while revision identities do not. ADR
> 0015 now carries an amendment note pointing here.
>
> **A3:** the Content editor opens Studio maximized. The accepted-revision preview is a closed disclosure below
> it.
>
> Each is reversible by one follow-up:
>
> - A1: drop the substitution and the provisioning call.
> - A2: revise the control map behind an r3 with a forward migration.
> - A3: set `initialPresentation` back to `inline` and unwrap the disclosure.
>
> Known limits on beta.9:
>
> - The page canvas still shows host field blocks as "Unsupported Studio block" lines. That ends with the
>   re-pin carrying Studio's host-block projection.
> - A moved block is kept only by a type save until per-item layout lands in a later pull request (B2).
> - The guard that keeps an untouched default a draft relies on the pinned shell sending the handed roots back
>   unchanged. The browser journey asserts this on beta.9; it passed locally on PostgreSQL 16 on 2026-10-08, and
>   CI runs it on every engine.
> - On beta.9 the shell validates the default against its compiled `studio.core/section`, whose `content` slot
>   accepts only `studio.core/*` blocks, so the Diagnostics region lists one "Slot content does not accept
>   core/field-text." error per composed field. The host's own section declaration accepts the field blocks, so
>   saves and the preview are unaffected. Observed locally on 2026-10-08.
> - The translator note of `core.administrator.content_form.studio_preview` still describes the preview as
>   beside the Studio mount. B1 leaves the catalogues unchanged; the note is stale until a follow-up.

Open questions only the maintainer can answer:

1. **(A1)** May the App hand the shell a derived default for an empty draft, and provision new types with it,
   including through the composition route, REST, console and MCP?
2. **(A2)** Is rewriting stored r1 locks in place, keeping revision identities, acceptable? The alternative is an
   r1 alias kept permanently.
3. Should a type save that leaves the default untouched store an empty draft so the default keeps following the
   model (as implemented), or publish it so the public page renders the Studio layout?
4. **(A3)** Is `maximized` right for create as well as edit, and is a closed preview disclosure acceptable?
5. Which fields belong in the default? Should the slug, enumerations or a type's object-valued sections appear
   once Studio offers controls for them? Is key order acceptable, or should a Content type carry an explicit
   field order?
6. Should the inspector edit decimals, which needs Studio to type `decimal` ports, and rich text, which needs a
   rich-text field kind in the App projection?

## Consequences

**Positive.**

- An existing item of a type with no authored layout opens with a section listing its title and top-level scalar
  fields in the Outline.
- Its title and its text, integer and yes-or-no values can be edited in the inspector, and **Save item** saves
  them through the existing item save. The browser journey edits the title in the inspector, saves the item and
  asserts the reopened title; integer and yes-or-no inspector edits have no test.
- The accepted-revision preview renders the same default, through the host's own renderer.

These outcomes are derived from the pinned shell's code and the App change. They were not observed in a
running editor while drafting. The browser journey, which covers the title only, observed them locally on
2026-10-08, together with the slot diagnostics that the maintainer note lists.

**Limits and costs.**

- *Canvas placeholders.* On beta.9 the page canvas still renders App field blocks as "Unsupported Studio block"
  status lines. That lasts until the re-pin to a Studio release with the host-block projection. Values appear in
  the Outline, the inspector, Content mode and the accepted-revision preview.
- *Moving blocks.* A moved block persists only through **Save new type version**, because `itemComposition`
  stays `denied`. Per-item layout is a later App change.
- *Defaults follow the model until an author changes them.* A type save that leaves the default untouched stores
  an empty draft, so the default is derived again at every load from the current model. Once an author changes
  the layout and saves the type, it is an authored layout: a later model change neither adds new fields to it nor
  removes bindings to fields that disappeared, and a missing field shows `studio.binding/field-missing`, as it
  does for any authored layout.
- *Provisioned defaults are stored.* A newly provisioned type stores the default's roots as its draft. That draft
  belongs to one immutable type version, so it always matches that version's model; the first type save that
  leaves it untouched replaces it with an empty draft for the successor.
- *Authored drafts.* An authored draft layout still publishes on a type save, as before this decision.
- *Stored bytes.* Stored r1 Blueprint bytes are rewritten in place, with unchanged revision identities. Recovery
  envelopes and replay results keep r1.
- *Every provisioning surface.* The composition route and machine provisioning now provision the default too.
  Browser specs on those routes may see the default section.
- *Multi-line titles.* A multi-line text control lets an author type a line break into a title. Title
  validation is unchanged. This is accepted so that bodies keep their line breaks.
- *Actor independence.* The default is the same for every actor while field disclosure is record-level. A
  per-field disclosure policy would make a provisioned default reflect the provisioning actor's view.
- *Round-trip equality.* The draft rule compares the roots the browser sends back with the handed roots by
  canonical JSON. If the shell ever rewrote an untouched node, an untouched default would publish on a type
  save. The browser journey (`tests/Browser/studio-authoring.spec.ts`) makes a type save of the untouched
  default through the pinned shell and asserts that the save result and the reopened item are handed a `draft`
  Blueprint holding the derived default, which only the empty-draft path yields: a stored copy of the default
  would be `published`. The pinned shell adds a Model field only while the handed Model is a `draft`
  (`node_modules/@kumwe/studio/dist/contextual-authoring.js` 441), and an existing item's type is handed
  `published`, so that type save changes neither the Model nor the layout. The database journey covers type
  saves that add fields.

## Rejected alternatives

- **First-party `studio.core/heading` or rich-text blocks in the default.** They cannot be stored in a type's
  Blueprint (Evidence).
- **An App-local page builder or canvas renderer.** Rejected by ADR 0020; the canvas projection is a Studio
  release item.
- **Keep r1 admissible as an alias of r2.** It needs a permanent compatibility path in the catalogue lock check,
  the renderer registry and `adopt()`.
- **Persist the default at load.** Opening an editor would then write a stored revision; a load stays a read.
- **A single-line text control on `core/field-text`.** It would silently drop line breaks from bodies.
- **A `number` control on decimals.** The shell would write strings into number fields.
- **`overflow: visible` on the mount.** The mount must keep containing the shell, and the journeys assert no
  document overflow at 320 CSS pixels.

## Proof

Recorded by the integrator on 2026-10-07 and 2026-10-08 at base `383e4cd0`, with the working tree that carries
this decision, after the review fixes (untouched default stored as an empty draft). The sandbox had PHP 8.5.11 (a
prebuilt CLI with the CI extension set), the pinned native Engine 1.0.3 built by `tools/install-native-engine.sh`,
PostgreSQL 16, Redis and Node 22. It had no browser binaries.

| Lane | Command | Outcome |
|---|---|---|
| Static analysis | `composer analyse` | No errors (PHPStan level max). |
| Coding standard | `composer cs` | Exit 0. |
| Documentation blocks | `composer docs:api` (does not cover the self-checksumming migration) | 0 violations. |
| Whole unit suite | `composer test:unit` | 3051 tests, 97160 assertions, OK, with 26 PHPUnit notices (the same count an earlier run of this branch reported). |
| Architecture suite | `vendor/bin/phpunit --testsuite architecture` | 314 tests, 28901 assertions, OK. |
| Database integration | `vendor/bin/phpunit --testsuite integration,functional` on PostgreSQL 16 after `database:migrate`, with a superuser test role | 709 tests, 26280 assertions, 31 skipped, OK. The default-composition journey asserts that two successive model-only type saves of the untouched default each store an empty draft and that the successor is handed a default composing the added field. MariaDB and MySQL are left to CI. |
| Browser journeys | CI; `tests/Browser/studio-authoring.spec.ts` also locally on 2026-10-08 | The first CI run timed out in the default-composition journey: its model-only type save tried to add a field to an existing item, whose published Model the pinned shell does not let an author change. The journey now saves the untouched default without a Model change. Locally the whole spec passed on `desktop-chromium` and `mobile-chromium` (PostgreSQL 16, a preinstalled Chromium build and a loopback mirror of the pinned Studio packages, because the sandbox could not reach the CDN). |
| Asset build and direction | `npm run build`, `composer assets:direction` | Built; direction check exit 0. |
| Quality baseline | `composer baseline:check` | Verified; the review fixes add no public test method, so the recorded baseline is unchanged. |
| Core growth | `composer kumwe:core-growth-check` | Fails on the base tree and on this tree alike, at the ledger record `KUMWE-MIG-2026-030` (a removed host test), so the growth delta of this change could not be evaluated by the tool. |
