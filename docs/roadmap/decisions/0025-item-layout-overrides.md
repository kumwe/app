# ADR 0025 — An item keeps its own layout as a pinned Blueprint snapshot

**Status:** Proposed. The App change that implements it rests on working assumptions I1 to I3, which await the
maintainer; the maintainer's merge accepts them, and a rejection reverses them as described below.
**Decision maker:** the maintainer; drafted by the integrating agent, 2026-10-08.
**Scope:** contextual Content authoring on the pinned Studio `0.1.0-beta.9`, Producer `0.6.0` and extension SDK
`0.3.6` (`STUDIO-PROD-004`, `005`, `006` and `010`). No pin, Producer or SDK change is part of it.
**Bounded by:** [ADR 0020](0020-studio-contextual-content-authoring.md) (no App-local page builder) and
[ADR 0015](0015-studio-artifact-and-recovery-persistence.md) (immutable revisions, compare-and-set heads, audited
mutations, revisionless recovery). It rewrites no stored bytes.
**Amends:** [ADR 0024](0024-default-composition-from-the-content-model.md), its "Moving blocks" limit and the
per-item layout limit in its maintainer note.

## Evidence

The owner reported that a block "can't be moved around". On `63b4e044` (its `src/` is unchanged from `deaa7097`):

1. Every type declares `itemComposition: 'denied'`
   (`src/Studio/Application/Authoring/ContentStudioAuthoringDocuments.php` 337-343), so the pinned shell never
   sends an item Blueprint, and the host refuses one with `studio.authoring/item-composition-denied`
   (`src/Studio/Application/Authoring/ContentStudioAuthoringService.php` 1102-1104).
2. A moved block can be kept only by **Save new type version**, which changes the reusable type for every item.
3. The App already owns a per-entry composition record, `studio_entry_composition_overrides`, keyed by site and
   entry and removed with its entry by a cascading foreign key
   (`src/Infrastructure/Persistence/Migration/StudioContentProjectionMigration.php` 209-224). It is read through a
   read-only port whose contract anticipates a separate audited write port
   (`src/Studio/Application/Projection/ContentProjectionBindingRepository.php` 14-16). Nothing writes it.
4. The pinned shell sends `itemBlueprint` exactly when the type's policy is `overrides` and the Blueprint is dirty,
   and accepts a save-item plan whose affected artifacts are the Entry and the item Blueprint. Its save
   confirmation lists the plan's consequences and does not list `affectedArtifacts`.
5. Artifact history refuses a revision it already holds: the history insert maps a unique violation to
   `StudioPersistenceRace` (`src/Studio/Infrastructure/Persistence/DoctrineStudioHostStorage.php` 150-159).
6. A new type version takes its Blueprint identity from the session's `coordinates.blueprint`
   (`ContentStudioAuthoringService.php` 628-629). Under `denied` that is always the type's own Blueprint.

## Decision

Line numbers refer to the tree that carries this decision. The authoring service, the composition service, the
readers and the host ports are cited by method.

1. **Policy.** The App-wide policy `StudioContentCompositionService::ITEM_COMPOSITION` is `overrides`. The
   composition root shares one `StudioItemCompositionPolicy` carrying it
   (`src/Studio/Application/Composition/StudioItemCompositionPolicy.php`), and the authoring service, the preview
   binding source and the public renderer each ask that one instance, so they always decide alike;
   `ContentStudioAuthoringDocuments::authoringPolicy()` declares the same policy for every type. An item layout
   reaches storage only through **Save item**, in a Hybrid session holding `studio.permission/edit-blueprint`
   (refused otherwise with `forbidden` and `studio.authoring/item-layout-refused`), by an actor `ContentService`
   authorizes to update or create the item. REST, console and MCP authoring dispatch the same operations, so they follow the same rule.
2. **Shape.** An item layout is a full Blueprint snapshot stored as an ADR 0015 artifact:
   - id `content-item-blueprint:` followed by the lowercased entry UUID
     (`EntryCompositionOverrides::ITEM_BLUEPRINT_PREFIX` and `itemBlueprintId()`,
     `src/Studio/Domain/Projection/EntryCompositionOverrides.php` 193 and 128), one artifact per entry for its life,
     disjoint from the type prefix `content-blueprint:`;
   - version `1.0.0`, kind `blueprint`, status `published`, owner `kumwe.app/content` `2.0.0`;
   - the entry's exact type-version model lock, the live theme, and the catalogue locks of exactly the blocks it
     composes;
   - the extension `kumwe.app/item-composition` naming the type Blueprint reference (`id`, `version`, `revision`)
     the session was handed.

   Only its roots come from the browser; the host writes every other member. Its revision is `item-` followed by a
   SHA-256 over the site, id, version, model revision, base reference, theme revision, canonical block locks and
   canonical roots, so equal layouts share a revision. A published Blueprint needs at least one root, so a layout
   with no roots is refused with `studio.authoring/item-layout-empty` unless the type's layout is empty too.
3. **Pointer.** The new nullable column `studio_entry_composition_overrides.item_blueprint_revision` pins the exact
   revision an entry uses, validated as `item-` and 64 lowercase hexadecimal digits
   (`EntryCompositionOverrides.php` 62 and 201). `override_revision` is its compare-and-set counter. The pointer,
   not the artifact head, decides what is rendered: the head is only the base for appending history. A record
   created for a layout alone carries `override_values = {}`, and a pointer move never rewrites stored override
   values.
4. **Write port.** `EntryCompositionOverrideStore::pin()`
   (`src/Studio/Application/Composition/EntryCompositionOverrideStore.php` 38) is a write-only port beside the
   read-only projection port, implemented by `DoctrineContentProjectionBindingRepository::pin()`
   (`src/Studio/Infrastructure/Persistence/DoctrineContentProjectionBindingRepository.php` 148-197) and aliased in
   the composition root (`src/Kernel/ContainerFactory.php` 2151). It requires an active transaction. With no
   expected revision it inserts the record, and a concurrent insert becomes `StudioPersistenceRace`. Otherwise it
   moves only `item_blueprint_revision` and `override_revision`, and only while the stored override revision
   equals the expected one; any other outcome is `StudioPersistenceRace`. The next revision must exceed the
   expected one, so a successful move changes exactly one row on every engine. `ContentProjectionBindingRepository`
   stays read-only.
5. **Save.** Save item checks a sent layout in this order, when it plans and again when it commits, before any
   effect:
   - the policy must be `overrides`, or the save is refused as before;
   - the session must hold `studio.permission/edit-blueprint`;
   - the type fence (decision 7) must hold, for every save item, with or without a layout;
   - the sent `itemBlueprint` reference must equal the live `coordinates.blueprint` (otherwise `conflict`,
     `studio.authoring/item-layout-conflict`), and its model must equal `coordinates.model` (otherwise
     `validation-failed`, `studio.authoring/item-layout-model-mismatch`);
   - roots equal to the type's handed layout are *inherited*: nothing is stored, and an item that used its own
     layout has its pointer cleared, so a default composition is never frozen into an item and moving the blocks
     back restores inheritance;
   - any other layout is built, its locks compared with the renderable locks (`studio.authoring/unlocked-block`),
     admitted by `StudioArtifactAdmission`, and checked by `StudioPublishedCompositionGuard::assertCompatible()`
     (`validation-failed`, `studio.authoring/item-layout-incompatible`), so a layout the public page would refuse
     is refused at save. A layout whose revision is the one the item already uses is *unchanged*.

   The commit re-runs that validation against live state, writes the entry through `ContentService` as before,
   and refuses a kept layout with `conflict` and `studio.authoring/type-changed` when the written entry pins
   another type version than the layout locks. Only a create can do that: it pins the type version that is latest
   when it runs, so a version published while a create session was open would leave the new item's layout
   detached from its first moment. The refusal rolls the create back with the rest of the save. Then,
   through `StudioContentCompositionService::keepItemLayout()` in one transaction joined to the save's:
   stores the artifact only when its revision is not yet in history (a revision already stored is re-pinned
   without storing, so returning to an earlier layout never collides), moves the pointer, and records one audit
   event. A race on the head or the pointer becomes `conflict` with `studio.authoring/item-layout-conflict` and rolls
   the entry write back too. An *unchanged* layout writes no artifact, moves no pointer and records no layout
   event; the entry still advances. Replay under the save's idempotency key returns the recorded result.
6. **Consequences shown before saving.** A save that sends a layout plans `affectedArtifacts: ['entry',
   'blueprint']`. Its consequences add:
   - `kumwe.app/item-layout-kept` (information) when the item keeps a layout of its own;
   - `kumwe.app/item-layout-inherited` (information) when an item that uses its own layout returns to the type's;
   - `kumwe.app/item-layout-live` (warning) when the item is published and the save either keeps a new layout of
     its own or returns it to the type's layout, since either changes its public page at once;
   - `kumwe.app/item-layout-promoted` (information) on a type save from an item that uses its own layout
     (decision 8).

   Confirmation is required for a kept layout when the item does not already use a layout of its own, and for
   every layout change of a published item: a kept layout, or a return to the type's layout.
   The beta.9 confirmation shows only consequence text, so the `kept` text names the saved artifacts (the item's
   entry and its item Blueprint) for `STUDIO-PROD-006`. The texts are the catalogue entries
   `core.administrator.content_form.studio_item_layout_kept`, `_live`, `_inherited` and `_promoted`, translated in
   all nine interface catalogues.
7. **Type fence.** Each authoring context records the SHA-256 of the canonical type document it last handed its
   session, in the new nullable column `studio_content_authoring_contexts.handed_type_digest`
   (`ContentStudioAuthoringContextRepository::recordHandedType()` and `handedType()`,
   `src/Studio/Application/Authoring/ContentStudioAuthoringContextRepository.php` 88 and 99). It is written at every
   start and in every save result. A save item whose live type differs is refused before any effect with
   `conflict` and `studio.authoring/type-changed`. A context with no recorded digest, opened before the migration,
   is not fenced.
8. **Type saves from an item with its own layout.** **Save new type version** takes the predecessor Blueprint
   identity from the handed type (`$state->type->blueprint`), not from `coordinates.blueprint`, so the successor
   keeps the type's lineage `content-blueprint:<type>:v<n>`. The untouched-default check of ADR 0024 compares with
   the handed type layout, and the stored type copy drops the `kumwe.app/item-composition` extension and the item
   layout label. When the saved item uses its own layout, its pointer is cleared in the same transaction with
   audit reason `promoted`, so the item follows the new type version, whose layout is the one it built.
   **Save as new type** clears it the same way.
9. **Readers.**
   - *Editor.* The session is handed the pinned item layout **as stored**: ADR 0024's `presented()` substitution is
     never applied to it. `coordinates.blueprint` becomes the item layout's reference while `type.blueprint` stays
     the type's, which the pinned shell permits only under `overrides`.
   - *Projection.* The entry extension `kumwe.app/composition-override` gains `blueprint` (`id`, `version`,
     `revision`) when a pointer is set.
   - *Preview.* The accepted-revision preview accepts an item layout only for the session's own entry and only when
     its model lock is the entry's type-version model.
   - *Public page.* The renderer renders the pinned item layout before the type layout, through the same
     publication guard and renderer, even when the type's own layout is a draft. A missing pinned revision fails
     closed; a foreign identity or a non-published status is refused.
   - *Policy.* Under the `denied` policy each reader ignores stored item layouts: the editor hands the type's
     layout with no diagnostic, preview refuses an item layout, and the public page renders the type's layout, or
     the legacy page while the type's layout is a draft.
10. **Type changes.** An entry re-pinned to another type version by any path other than a Studio type save keeps
    its record and artifact, but the layout is not used while its model lock differs from the entry's type-version
    model. The editor then shows the type layout with the warning diagnostic `kumwe.app/item-layout-detached`
    (catalogue entry `core.administrator.content_form.studio_item_layout_detached`), and the public page renders the
    type layout. A layout locked to a theme that is no longer published is treated the same way
    (`StudioContentCompositionService::itemLayout()` and `StudioPublishedCompositionGuard::locksLiveTheme()`):
    kept, not used, and diagnosed with the same code, whose text names both causes. The author's next layout save
    locks the live theme again. Without this, a theme change would leave every item with its own layout
    uneditable, because no surface may open a Blueprint session on an item layout (decision 11). The App never
    merges or rebases layouts.
11. **One write path.** Blueprint sessions (`StudioHostSessionAuthority::open()`) and the generic artifact port
    (`StudioArtifactHostPort::requireResource()`) refuse `content-item-blueprint:` ids, on the administrator, REST,
    console and MCP surfaces alike, so an item layout is loaded, stored, published or unpublished only by its item.
12. **Audit.** Each layout change records one `AuditEvent` `studio.composition.item-layout` with subject
    `content_entry` and the entry UUID. Its metadata are `blueprint_identity_digest`, `blueprint_revision` (null when
    cleared), `base_blueprint_revision`, `content_type_version`, `entry_version`, `override_revision`, `reason`
    (`kept`, `inherited` or `promoted`) and `site_identifier`, with no document bytes (ADR 0015 decision 6). The
    entry version recorded beside the layout revision, with the immutable artifact history, answers which layout an
    entry version had, without a history table.
13. **Migration.** `20261008120000_studio_item_composition`
    (`src/Infrastructure/Persistence/Migration/StudioItemCompositionMigration.php`, registered after
    `StudioFieldBlockRevisionMigration` at `ContainerFactory.php` 2840) is forward-only and implements
    `RepeatableMigration`. It refuses to run without either parent table and adds each column only when absent:

    | Table | Column | Type |
    |---|---|---|
    | `studio_entry_composition_overrides` | `item_blueprint_revision` | string, length 200, nullable |
    | `studio_content_authoring_contexts` | `handed_type_digest` | string, length 64, nullable |

    No index, constraint or table is added, and no stored row is rewritten. It checksums its own file, so
    `composer docs:api` and `composer docs:format` skip it; its documentation blocks are reviewed by hand.

## Ownership

Studio owns the wire shape (`itemBlueprint`, the plan and result rules) and the shell. App owns authority,
persistence, audit and rendering (`STUDIO-PROD-010`). The pinned Studio packages, Producer, the extension SDK and
the content-model package offer no per-entry layout persistence. The existing per-entry record, artifact store,
admission, publication guard, mutation boundary and composition service are reused, and
`StudioContentCompositionService` stays the one reviewed audit writer for compositions. B2 adds one write-only
port beside the read-only one, one immutable policy value (`StudioItemCompositionPolicy`) so every reader asks one
injected decision, and one migration. It adds no table, index, registry or audit writer.

## Working assumptions awaiting the maintainer

The owner has not yet answered these. They are recorded verbatim, each with what the App change implements and
how to reverse it. The pull request's maintainer note calls them D1 to D3.

**I1 (D1).** "Every content type lets an item keep its own layout through Save item (itemComposition 'overrides'),
App-wide, without a per-type switch."

- *Implemented:* decisions 1 to 6.
- *Reverse:* share `StudioItemCompositionPolicy` with `denied` in the composition root (or set `ITEM_COMPOSITION`,
  its default, to `denied`). Every type then declares `denied`, saves refuse item layouts again with
  `studio.authoring/item-composition-denied`, and the editor, preview and public renderer ignore stored layouts,
  which are kept byte for byte. The integration journey proves this rollback with the policy injected. Ignoring
  them is required: under `denied` the pinned shell requires the type's Blueprint and the session's Blueprint
  coordinate to be the same.

**I2 (D2).** "A published item's layout goes live when the item is saved, like its values, and an item with its own
layout renders it publicly even while its type's layout is an untouched default draft."

- *Implemented:* decisions 6 and 9. The `kumwe.app/item-layout-live` warning and its confirmation say so before
  the save. Public pages are cached for up to 60 seconds (`src/Http/Handler/PublishedContentHandler.php` 116), as
  for value changes.
- *Reverse:* render item layouts only when the type layout is published, or stage layouts behind a workflow
  transition, which needs a Content draft-of-published mechanism that does not exist.

**I3 (D3).** "When an item is re-pinned to another type version its layout is kept and not used, with a diagnostic;
saving a new type version from an item with its own layout makes that layout the type's."

- *Implemented:* decisions 8 and 10.
- *Reverse:* clear the pointer on re-pin, or ask Studio for a rebase operation; App does not merge layouts.

Defaults recorded here that do not block the merge: confirmation when an item does not yet use its own layout and
for published items; each translation, which is its own entry, has its own layout and starts by inheriting the
type's; trash and restore leave the layout untouched; item artifacts are kept as immutable history, and purging them
after an entry is removed is a later explicit operation; reset is by moving the blocks back until Studio offers an
action.

## Decisions for the maintainer

This note is written for the pull request description.

> This pull request (B2) lets an author move blocks on one item, or insert catalogue blocks there, and keep that
> layout with **Save item**,
> without changing the reusable type. It implements three working assumptions you have not yet confirmed, which
> ADR 0025 (proposed) records as I1 to I3:
>
> - **D1:** every content type allows per-item layouts (`itemComposition: 'overrides'`), App-wide, with no
>   per-type switch.
> - **D2:** saving a published item's layout changes its public page at once, like its values. An item with its
>   own layout renders it even while its type's layout is still the untouched default draft, so that item stops
>   using the structured template.
> - **D3:** an item moved to another type version keeps its layout but does not use it; the editor shows the
>   diagnostic `kumwe.app/item-layout-detached`. Saving a new type version or a new type from an item's own layout
>   makes it the type's layout, and the item then follows the type.
>
> Each is reversible by one follow-up:
>
> - D1: share `StudioItemCompositionPolicy` with `denied` (its default is
>   `StudioContentCompositionService::ITEM_COMPOSITION`); stored layouts are kept and ignored.
> - D2: render item layouts only once the type's layout is published.
> - D3: clear the pointer on re-pin instead of keeping it.
>
> Known limits on beta.9:
>
> - There is no "use the type's layout" action; moving the blocks back to the type's arrangement restores
>   inheritance.
> - The save confirmation lists consequences, not affected artifacts, so the consequence text names them.
> - Field blocks still render as "Unsupported Studio block" lines on the canvas, so the moved order is seen in
>   the Outline, the accepted-revision preview and the public page.
> - In a Hybrid session the default section's field blocks can be reordered inside the section; root nodes
>   cannot be moved. Inserting a catalogue block is proven by the integration journey only; the browser journey
>   moves blocks and does not drive the beta.9 insertion flow.
> - Saving a published item, whether it keeps a new layout or goes back to the type's, warns that the public page
>   changes and asks for confirmation.
> - An item layout locked to a theme that is no longer published is kept but not used, like one made for another
>   type version; the editor shows `kumwe.app/item-layout-detached` and the public page renders the type's layout.

Open questions only the maintainer can answer:

1. **(D1)** Is one App-wide switch right, or should a type opt in? Per-type control needs a policy store, an
   administrator form, an audited policy service and REST, console and MCP parity.
2. **(D2)** May an item layout go live on save and render while the type's layout is a draft, or must it be
   staged?
3. **(D3)** Detach and diagnose, or clear, when an item moves to another type version?
4. Should confirmation also be required for every layout save of an unpublished item?
5. Should Studio be asked for a "use the type's layout" action and an affected-artifacts list in the save
   confirmation?

## Consequences

**Positive.** An author moves a block, saves the item and reopens it with the block moved. The type and its other
items are unchanged, and the accepted-revision preview and the public page show the item's layout.

**Limits and costs.**

- *Reset.* beta.9 has no "use the type's layout" action; moving the blocks back restores inheritance.
- *Theme change.* An item layout locked to a theme that is no longer published is kept but not used, so its item
  shows the type's layout until the author saves a layout again; saving the untouched type layout from the editor
  keeps the stale pointer, as for any detached layout (decision 10).
- *Insertion.* Inserting a catalogue block into an item's layout is proven by the integration journey; the browser
  journey moves blocks only.
- *Confirmation.* beta.9 lists consequences, not affected artifacts; the consequence text names them.
- *Canvas.* Field blocks still render as placeholders on the beta.9 canvas.
- *Frozen block set.* An item with its own layout does not gain fields a later type version adds, as for any
  authored layout. A layout that binds a removed field fails the publication guard and is refused at save.
- *Type fence.* A type layout saved on the transitional composition screen while an item session is open refuses
  that session's next save item with `studio.authoring/type-changed`; the author reopens the item. Only a type
  version whose binding follows the Blueprint head can move this way.
- *Storage.* One immutable artifact revision per distinct layout. Purging them after an entry is removed is a
  separate explicit operation, not part of this decision.
- *Observability.* Operators see `studio.composition.item-layout` audit events (reason `kept`, `inherited` or
  `promoted`) and the `kumwe.app/item-layout-detached` diagnostic; rollback is the one injected policy.
- *Not observed here.* The MariaDB and MySQL lanes run only in CI.

## Rejected alternatives

- **A delta in `override_values`.** Studio's `entry.compositionOverrides` is a per-node value map that the pinned
  shell never writes for a move, and a tree-diff format would be App-local page-builder behaviour, which
  [ADR 0020](0020-studio-contextual-content-authoring.md) rejects.
- **The whole Blueprint inside `override_values`.** It would be copied into the Studio entry document, bypass
  ADR 0015 history, and be invisible to preview, which reads artifacts.
- **A counter inside the revision.** It also avoids history collisions, but makes equal layouts distinct; the
  content-addressed revision with re-pinning is simpler.
- **A per-type policy table with an administrator switch.** It needs a new table, an administrator form, an
  audited policy service and machine parity, for a choice the owner has not asked for.
- **Separate per-entry layout binding and history tables.** They would add a second per-entry owner beside the one
  the read port anticipates.
- **Automatic rebase onto a new type version.** A tree merge inside App is page-builder behaviour, and nothing
  triggers it today.
- **Clearing the pointer silently on a type change.** It loses the author's work.

## Architectural change checklist ([growth guide](../../architecture/growth.md))

- *Constraint and capacity.* At most one override row per entry and one artifact revision per distinct layout;
  no measurement was made, and none is claimed.
- *Data owner.* App Studio composition: the existing per-entry override record and the ADR 0015 artifact store.
- *Consistency, retry and failure.* One transaction with the entry save; compare-and-set on the artifact head and
  on the pointer; keyed replay through the existing mutation boundary; every race is a `conflict` that rolls back
  the whole save.
- *Authorization and audit.* A Hybrid session holding `studio.permission/edit-blueprint`, `content.update` or
  `content.create`, and one audit event per layout change.
- *Migration and rollback.* Two nullable columns, forward-only and repeatable; rollback is the one injected
  policy, `StudioItemCompositionPolicy`.
- *Database matrix.* PostgreSQL, MariaDB and MySQL in CI.
- *Signals.* The audit event and the detached diagnostic.
- *Stable interfaces.* The read-only projection port and Studio's pinned wire contract are unchanged.

## Proof

Recorded by the integrator on 2026-10-08 at base `63b4e044`, with the working tree that carries this decision. The
sandbox had PHP 8.5.11 (a prebuilt CLI with the CI extension set), the pinned native Engine 1.0.3, PostgreSQL 16,
Redis and Node 22.

| Lane | Command | Outcome |
|---|---|---|
| Manifest | `composer validate` | Exit 0 (the existing advisory on the exact `ext-kumwe_engine` constraint only). |
| Static analysis | `composer analyse` | No errors (PHPStan level max). |
| Coding standard | `composer cs` | Exit 0. |
| Documentation blocks | `composer docs:api`, and `tools/verify-docblocks.php` on the self-checksumming migration | 0 violations each. |
| Whole unit suite | `vendor/bin/phpunit --testsuite unit` with `PHPRC` set to the sandbox PHP's `php.ini` and the PostgreSQL test environment exported (the command `composer test:unit` runs, `composer.json` 172) | 3066 tests, 97391 assertions, OK, with the same 26 PHPUnit notices as the base. |
| Architecture suite | `vendor/bin/phpunit --testsuite architecture` | 314 tests, 28939 assertions, OK. |
| Database integration | `vendor/bin/phpunit --testsuite integration,functional` on a fresh PostgreSQL 16 database after `database:migrate`, with a superuser test role | 715 tests, 26937 assertions, 31 skipped (as on the base), OK. The item-layout journeys keep, revisit, inherit, fence and promote an item layout; the persistence tests apply this migration on SQLite. MariaDB and MySQL are left to CI. |
| Browser journeys | `studio-authoring`, `studio-composition`, `studio-keyboard-focus`, `studio-mount-isolation`, `studio-published-responsive` and `administrator` specs on `desktop-chromium` and `mobile-chromium`, after a fresh database | 124 passed, including the new item-layout journey. Run against a preinstalled Chromium build and a loopback mirror of the pinned Studio packages, because the sandbox could not reach the CDN. |
| Catalogues and assets | `composer translation:check`, `translation:strings`, `translation:quality`, `assets:direction`; `npx tsc --noEmit` | Exit 0 each. No asset source moved, so no rebuild was needed. |
| Other `qa` members | `composer architecture:policy`, `extension:independence`, `machine:parity`, `studio:dependencies`, `observability:rules`, `openapi:check` | Exit 0 each. |
| Quality baseline | `composer baseline:record`, then `composer baseline:check` | Verified. After the review fixes below, the recorded change against the previous record is 16 unit and 8 integration test methods, 2 unit test files and the migration `20261008120000_studio_item_composition`. |
| Review fixes, re-run | With `PATH` on the sandbox PHP, `PHPRC` set to its `php.ini` and `COMPOSER_ALLOW_SUPERUSER=1`: `composer cs`, `composer analyse`, `composer docs:api`, `composer translation:check`, `translation:strings`, `translation:quality`; `vendor/bin/phpunit --testsuite unit`; `vendor/bin/phpunit --testsuite architecture`; `vendor/bin/phpunit tests/Integration/Studio` on a fresh PostgreSQL 16 database (immutable parent schema, then `database:migrate`); `composer baseline:record`, then `composer baseline:check`; the whole `studio-authoring` spec on `desktop-chromium` and `mobile-chromium` after a fresh browser database | Exit 0 each. Unit 3067 tests, 97410 assertions, OK, with the same 26 PHPUnit notices; architecture 314 tests, 28956 assertions, OK; Studio integration 71 tests, 2995 assertions, OK; baseline verified; browser 18 passed. The new fence and the published-inherit disclosure were each removed once and the integration journey failed, then restored. |
| Core growth | `composer kumwe:core-growth-check` | Fails on the base tree and on this tree alike, at the ledger record `KUMWE-MIG-2026-030` (a removed host test), before any inventory rule runs. A read-only inventory probe against `core-growth-baseline.json` shows this change adds `EntryCompositionOverrideStore` (application) and `StudioItemCompositionMigration` (infrastructure) and changes the public surface of nine existing Studio classes it edits. The optional inventory tool fails on the base tree at KUMWE-MIG-2026-030; under the 2026-09-29 direction no Core Growth Record is required (`docs/architecture/core-growth/README.md` 3-7), and the ownership choice is explained under Ownership. |
