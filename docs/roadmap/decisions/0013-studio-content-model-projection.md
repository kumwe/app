# ADR 0013 — Studio reads a lossless Content projection through an App-owned model port

**Status** Accepted as the AP-2 implementation of decision D16
**Decided by** ADR 0007's fixed Studio/App division of labour and the approved AP-2 completion sequence
**Findings** None; `V2-STU-002` remains open for the separate S-B release-pin and corpus-replay work
**Gate** B foundation
**Verified against** `fba50bb8753690809dafe37b61c6e9f9fdebe765`

---

## Context

ADR 0007 fixes the boundary: Studio owns the protocol, command engine, session, and authoring shell;
Kumwe owns identity, policy, persistence, media, preview rendering, localization, and telemetry. The
published protocol already defines `content-model` and `entry` documents, but Kumwe previously had no
model-port application surface. Studio could therefore describe generic fields without binding them to
this platform's immutable `ContentTypeDefinition` versions or authorized `ContentEntry` values.

The only canonical Studio schema interpreter lived under an extension-internal namespace and compiled
only the six extension contribution roots. Reimplementing JSON Schema validation in a Content adapter
would create two interpretations of the pinned protocol. Reusing the extension namespace from a new
host adapter would give the extension bounded context ownership of a cross-context Studio contract.

Kumwe also has a deliberately separate BusinessRecord runtime. Its definitions, field policy,
relationships, exact numeric values, and generated surfaces are not Content concepts. Existing
architecture tests prohibit either context from importing the other.

## Decision

1. **The pinned schema interpreter belongs to `Studio\Domain\Contract`.** Canonical JSON, the
   schema-property profile, diagnostics, the interpreting validator, and the registry move together.
   The registry compiles both extension contribution documents and the `content-model`/`entry` roots
   from the vendored corpus. Extension contribution code is an adapter over that neutral contract; the
   former helper names remain aliases and the former registry remains a contribution-only adapter for
   source and behavioral compatibility.
2. **Projection is read-only and application-owned.** `StudioContentProjectionService` obtains every
   definition, workflow, and entry through `ContentModelService` or `ContentService`. It has no write,
   command-session, artifact, or recovery operation. Repository access cannot bypass the existing
   authorization gateway.
3. **Mappings are deterministic, reversible, and fail closed.** Reserved identifiers and version
   lines separate projected Content coordinates from native Studio artifacts. Title, slug, body,
   locale, translation group, workflow coordinate, publication window, and optimistic version remain
   exact. Recursive closed JSON shapes are mapped; a union, open object, unrepresentable collection,
   unknown member, or coercion refuses the complete projection with a typed, non-disclosing
   diagnostic. Every output validates against the vendored Studio schema.
4. **Field policy is an explicit seam.** Model-description and entry-value decisions are separate.
   Refusal omits the member without naming it. The first implementation reflects Content's current
   record-level policy; a future per-field policy replaces it at the composition root.
5. **Composition coordinates are optional host state beside Content.** One table binds an immutable
   content-type version to an exact Blueprint coordinate; another stores canonical per-entry
   composition overrides. Tenant-composite foreign keys bind both stores to their authoritative
   Content rows, and replay repairs an interrupted partial DDL sequence. Their application repository
   exposes reads only. A later authorized and audited write service must add expected revisions and
   replay semantics rather than widening this AP-2 service.
6. **BusinessRecord remains parallel.** No generic record projector and no Content/BusinessRecord
   import is introduced. A BusinessRecord adapter is deferred until it can apply that context's own
   purpose-specific policy and exact value codecs, sharing only the neutral Studio contract types.

## Alternatives rejected

### Duplicate a small validator in the Content adapter

Rejected. The two implementations would inevitably disagree on references, patterns, canonical JSON,
or first diagnostics, and only one would be replayed against the pinned corpus.

### Put projection methods on `ContentService`

Rejected. Studio document vocabulary is an outbound model-port concern, not Content domain behavior.
It would make Content depend on an integration protocol and mix schema mapping with authoritative
reads and writes.

### Generalize Content and BusinessRecord behind one record interface

Rejected. The apparent similarity ends at having fields. Their authorization, numeric semantics,
relationships, workflows, query policy, and mutation boundaries differ, and the platform explicitly
keeps the two products parallel.

### Add binding and override save methods now

Rejected. There is no published Studio host-session consumer in this AP-2 slice, so a save surface
would lack session-generation invalidation, expected-revision conflict behavior, idempotent replay,
and the final authorization contract. Tables and immutable read values are safe foundations; writes
belong to the later host-adapter packages.

## Consequences

- Studio can consume real, authorized Kumwe Content models and entries as schema-valid canonical
  documents without owning or changing their definitions.
- A malformed or lossy source never produces a plausible partial document, and denied fields do not
  become an existence oracle.
- Existing extension validation keeps the same behavior and retains loadable internal names, while
  new cross-context consumers have a neutral owner.
- The binding stores can be migrated and backed up now, but no caller can mutate them through this
  service until the later write contract is implemented.
- AP-2 is usable independently of Studio's host-session release. S-D later completed under ADR 0015;
  S-B and S-E through S-G remain open under their existing findings and Gate B is not asserted.
