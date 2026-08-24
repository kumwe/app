# Kumwe App completion plan for the Studio composition surface

**Objective.** Take the App from a frozen composition contract to a **working, integrated visual page
builder in the administrator**, mapped onto this platform's own content types, and qualified for the
Version 2 release.

**Companion documents.**

- Studio's own steps: [`kumwe/studio` → `docs/roadmap/version-two-completion.md`](https://github.com/kumwe/studio/blob/main/docs/roadmap/version-two-completion.md)
- The joint sequence binding both: [`kumwe/studio` → `docs/integration/version-two-joint-plan.md`](https://github.com/kumwe/studio/blob/main/docs/integration/version-two-joint-plan.md)

This file holds forward work only, per [How this document moves](README.md#how-this-document-moves).
Scope for each package is already fixed in [`README.md`](README.md) phase S and in
[`findings.json`](findings.json) as `V2-STU-002` through `V2-STU-007`; this document sequences that work
and states the detail an implementing agent needs, without restating the acceptance tests the ledger
already carries.

---

## What is already done

`S-A` is complete and accepted. Gate A criterion 13 was accepted on 2026-08-22, and on 2026-08-23 the
canonical generation shipped: manifest schema 6 with contribution SPI 4 carrying canonical Studio
`block-definition`, `pattern`, `field-adapter`, `inspector`, `design-vocabulary` and `migration`
documents, the pinned-corpus validation registry, the additive `CanonicalCompositionRegistrar` beside
the frozen `CompositionContributionRegistrar`, separate capability-validated host bindings, the
lossless-only manifest-5 adapter, and a signed manifest-6 lifecycle fixture.
`composer extension:contract` records six manifest generations, four SPI generations and 117 public
types.

`S-B` is partly done: `@kumwe/studio-testkit` and `@kumwe/studio-protocol` are vendored under
`tests/Fixtures/Studio/` with `PIN.json`, and `composer studio:corpus` digest-verifies them.

---

## The dependency that governs everything below

Five of the six remaining packages consume Studio behaviour that **does not exist in Studio yet**. The
joint plan is authoritative for the handshake; the short version:

| App package | Blocked on Studio step | Why |
|---|---|---|
| `S-C` identity and policy | `ST-2` host-session binding | Nothing in Studio calls a host, so implemented ports would connect to nothing |
| `S-D` persistence | `ST-2` | Same; revision and generation semantics arrive through that API |
| `S-E` media | `ST-2` | The media port is only reachable through a bound session |
| `S-F` preview | `ST-4` preview surface | Studio renders no preview frame today, so an endpoint has no counterpart |
| `S-G` embedded surface | `ST-4`, `ST-5`, `ST-6`, `ST-7` | Without modes, layout blocks and a visual canvas the surface is an outline editor |

Only `S-B` and the content-type projection can proceed immediately. **Do not start `S-C` through `S-G`
before their Studio dependency has published**, or the work is written against an interface that does
not exist.

---

## Phase 1 — What can start now

### `AP-1` — Finish `S-B`: pin the whole release, not two packages

Finding `V2-STU-002`. Studio is publishing a single release coordinate (`ST-1`) precisely so a host can
pin one identifier rather than seven staggered versions.

1. When Studio publishes its release record, vendor it beside the corpus and pin every package the
   integration consumes at that coordinate, not per package.
2. Add the dependency check the package requires: a non-exact specifier for any `@kumwe/studio` package
   fails the build. This check does not exist yet.
3. Carry the pin into the release: the signed manifest must record the exact Studio release the build
   qualified, which nothing currently does. This is Gate B criterion 12's explicit requirement.
4. Replay what is vendored. Of the 260 vendored corpus files, the digests are verified but 186 are never
   replayed by any test — the corpus is currently proof of integrity, not proof of conformance. Add
   replay for the command vectors, canonical serialization vectors, host vectors and negative fixtures
   as PHPUnit cases, so the App proves it agrees with the contract rather than merely holding a copy.

**Why it matters.** A digest-verified corpus that nothing replays cannot support the conformance claim
Gate B criterion 12 makes.

### `AP-2` — Project content types through the `model` port

This is the step that makes the builder *this platform's* builder, and it depends on nothing in Studio
beyond schemas that already ship.

1. Implement the projection from `ContentTypeDefinition` and `FieldDefinition` — and, where the vertical
   needs it, `EntityTypeDefinition` — into Studio's `content-model` document: `fields`, `relationships`,
   `label`, `status`, `owner`.
2. Project `ContentEntry` into Studio's `entry` document, which already carries `values`,
   `compositionOverrides`, `locale`, `translationOf` and `workflowState` — these map onto
   `TranslationGroup`, `ContentStatus` and the workflow binding without inventing new state.
3. Keep the direction one-way: Studio reads projections and never writes a definition. Field policy,
   translation state, publication windows and workflow remain this platform's, enforced here.
4. Validate every projection against the pinned schemas before it leaves the port, so a malformed
   projection is refused here rather than diagnosed in the browser.

**Why it matters.** Without it, blocks bind to nothing real and the builder is a generic toy. With it, an
author binds a block to an actual field on an actual content type, with this platform's field policy
still deciding what they may see and change.

---

## Phase 2 — The host adapter, once Studio can call it

Each package below already has its acceptance test in [`findings.json`](findings.json). Implement
against the published port contract, and prove with the vendored corpus rather than hand-written
expectations.

### `AP-3` — `S-C`: identity, policy and session generations

Finding `V2-STU-003`. Ports: `permission.explain`, `permission.refresh`, and the identity half of the
request envelope.

1. Resolve the actor's effective capabilities through the existing deny-by-default gateway. A
   client-asserted capability is never trusted, and a hidden control is never the authorization.
2. Bind the Studio session generation to this platform's own invalidation: a capability change, grant
   revocation or security-epoch advance bumps it, and every later port call under the stale generation
   is refused with the canonical category.
3. Refuse a session mode the actor is not authorized to hold, once Studio's `ST-5` puts modes on the
   wire — a content author receives a content session, never a blueprint one.

### `AP-4` — `S-D`: artifact persistence with optimistic revisions

Finding `V2-STU-004`. Ports: `artifact.load`, `save`, `publish`, `unpublish`, `dependencies`, plus
`recovery.store`, `load`, `discard`.

1. Store composition documents as a versioned artifact with this platform's existing write discipline:
   expected revision on every write, a conflict returning the safe current revision and its diagnostics
   and never a last-write-wins overwrite, idempotent replay under the caller's `Idempotency-Key`, and a
   readable revision history.
2. Refuse at the boundary: a document carrying markup, a style declaration, executable content or a URL
   outside a schema-defined field is rejected before storage, not sanitized afterwards.
3. Prove round-trip fidelity — stored bytes validate against the pinned schema and return unchanged — on
   MariaDB, MySQL and PostgreSQL.

### `AP-5` — `S-E`: media and external sources

Finding `V2-STU-005`. Ports: the seven `media` operations.

1. Drive the existing media module through the canonical upload session state machine.
2. Replay the eleven published media policy vectors against it, so this platform's real limits and the
   contract's declared rejections provably agree.
3. Implement the host runtime obligations Studio's threat registry explicitly assigns to the host and
   states a lexical policy cannot discharge: redirect re-validation, response verification, and defence
   against a host resolving differently between check and fetch. Studio's URL policy runs first; these
   are what make it safe.

### `AP-6` — `S-F`: the authenticated preview endpoint

Finding `V2-STU-006`. Ports: `preview.render`, `preview.cancel`.

1. Add the endpoint this platform does not have: authenticated, capability-gated, short-lived, rendering
   an unpublished composition through the same template and theme path published output uses, so preview
   and publication cannot diverge.
2. Pin the origin and resist replay on both ends; refuse a foreign origin, a replayed sequence and a
   wrong channel, each with its own negative test.
3. Change the administrator content-security policy in exactly one way — same-origin framing for that
   route — and prove the policy is otherwise byte-identical, with no inline script or style allowance.

### `AP-7` — `S-G`: the embedded authoring surface

Finding `V2-STU-007`. Start only when Studio's `ST-7` has delivered the visual canvas; before that the
embedded result is an outline editor and the surface would be declared against a shape that is about to
change.

1. Declare it through the interface standard like any other surface — area, actor, intent, resource,
   purpose, pattern, capabilities, states, customization, responsive behaviour — gated by capability.
2. Feed Studio's published message catalogue from the compiled localization chain so site and
   organization wording overrides reach the composition surface, and resolve locale and direction from
   the same per-request negotiation every other surface uses.
3. Leave the existing server-rendered content editor untouched, so essential operation without
   JavaScript is unchanged.
4. Join the browser, accessibility and visual matrix, including one right-to-left locale.

---

## Phase 3 — Qualification

The Version 2 release gate is this platform's, and the composition work reports into phase 7 exactly as
[`README.md`](README.md) already states.

1. `P7-E` adds a composition journey to accountable human acceptance — an author composes, previews and
   publishes a page, in a non-source language for one journey.
2. `P7-C` covers the preview and media boundaries in security qualification.
3. `P7-F` adds a contributed composition block to the proof portfolio, so the Gate A declarations are
   exercised by a real extension rather than only by a fixture.
4. `P7-G` records the pinned Studio release in the signed manifest.

Gate B criterion 12 closes when those pass and `V2-STU-002` through `V2-STU-007` leave the ledger for
[`CHANGELOG.md`](../../CHANGELOG.md).

---

## Non-goals, restated so they are not rediscovered

- No composition runtime, engine or rule in this repository — the boundary is fixed by
  [ADR 0007](decisions/0007-studio-visual-composition-integration.md).
- No authoritative concern moves behind the composition layer.
- No public composition editing surface in Version 2.
- No floating version on a draft contract.
- The dashboard does not become a composition surface.
