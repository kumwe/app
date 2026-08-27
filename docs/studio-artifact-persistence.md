# Studio artifact and recovery persistence (current low-level primitive)

Kumwe implements Studio's artifact and recovery host ports behind the authenticated session-generation
dispatcher. This is the AP-4 / S-D persistence boundary. It stores and versions Studio documents; it does
not execute the Studio command engine, interpret composition rules, or introduce a Content or BusinessRecord
write path.

This update-only artifact store is an implemented infrastructure primitive, not the limit or completion of Studio
authoring in App. The target contextual journey also needs purpose-specific PHP operations for Content item
create/save, reusable-type creation, and immutable type-version creation. Those operations coordinate authoritative
Content, Model, Blueprint, workflow, migration, and audit services; they do not weaken this store into a generic
repository. See [Studio authoring in Kumwe App](studio-composition-authoring.md) for the single target/status record.

## Port surface

| Operation | Published HTTP `arguments` shape | Mutation contract |
|---|---|---|
| `artifact.dependencies` | `{ "reference": ArtifactReference }` | Read-only; no expected revision or idempotency key |
| `artifact.load` | `{ "reference": ArtifactReference }` | Read-only; current or named immutable revision |
| `artifact.save` | `{ "document": StudioDocument }` | Updates an existing draft at its exact coordinate; requires `expectedRevision`; never changes lifecycle or Blueprint locks |
| `artifact.publish` | `{ "reference": ArtifactReference }` | Requires `expectedRevision` and publish permission |
| `artifact.unpublish` | `{ "reference": ArtifactReference }` | Requires `expectedRevision` and publish permission |
| `recovery.load` | `{}` | Read-only; no expected revision or idempotency key |
| `recovery.store` | `{ "envelope": object }` | Revisionless by the pinned protocol; bounded and optionally idempotent |
| `recovery.discard` | `{}` | Revisionless by the pinned protocol; optionally idempotent |

The common dispatcher validates the closed host envelope, route/operation match, protocol version, trusted
App identity, resource context, mode and current generation before either port runs. A stale generation
always returns `invalid-request` with `studio.host/stale-session-generation`.

## Artifacts, revisions and conflicts

The store admits `blueprint`, `content-model`, and `entry` documents. The document's canonical identity,
version, revision, kind and status are duplicated only as routing columns; the exact canonical document and
dependency-list bytes remain text so a database JSON implementation cannot reorder members or change numeric
representation.

Every artifact mutation compares the caller's `expectedRevision` with the current head. One transaction
conditionally advances the head and appends the same admitted value to immutable revision history. A stale
or raced write returns category `conflict`, diagnostic `studio.artifact/revision-conflict`, and the safe
current revision only when the requested artifact is already bound to the trusted session. There is no
last-write-wins path.

The currently implemented generic save is intentionally update-only and draft-only. It cannot create an absent
version, change artifact identity,
publish, unpublish or retire a head, and it refuses a published or retired current head. A published head
must return to draft through canonical `artifact.unpublish`, including its separate publish permission and
audit event, before another save can run. Blueprint owner, model and complete dependency-lock values are
canonical-byte immutable across save; changing their identifiers, versions, revisions, integrity values or
ordered members is a conflict rather than a new dependency coordinate. These continuity checks run in the
same transaction as the current-head read and compare-and-set.

New-item and new-type outcomes therefore require explicit application use cases and admitted create/version
protocol operations; they are not achieved by relaxing `artifact.save` or manufacturing an initial head in browser
code.

Idempotency scope is the digest of actor, authenticated session binding, resource-context key, session
generation, operation and caller key. Intent separately digests the canonical semantic argument plus
protocol, locale and expected revision. Request and trace IDs are correlation only. Equal completed intent
returns the exact recorded result, pending intent is retryable, and changed intent is refused before another
mutation can run.

## Admission and stored-content safety

Artifact admission validates the complete document against the exact vendored schema and canonicalizes it
without rewriting accepted values. A second recursive policy rejects raw markup, executable syntax,
style-bearing members and declarations, unsafe URL schemes or private/credentialed hosts, and URLs outside
schema-defined URL-shaped members. Recovery envelopes pass the same active-content policy. Refusal happens
before storage; unsafe input is never stored and then sanitized.

## Recovery scope and limits

Recovery bytes are keyed by actor, authenticated browser-session binding and resource-context key. A load or
discard under any different coordinate behaves as absent and cannot reveal the foreign envelope. Store
canonicalizes before its byte cap, preserves canonical numeric fidelity including negative zero normalization,
and consumes an atomic fixed-window write allowance. Rate refusal returns a bounded retry delay. Discard and
subsequent load prove removal, while an equal idempotent store replays without consuming another write unit.

## Audit, storage and upgrade

Artifact save/publish/unpublish and recovery store/discard write one `AuditEvent` inside the same transaction
as persistence and idempotency completion. Metadata includes the canonical operation, trusted site, resource
family/mode, a resource-identity digest, optional result revision and optional idempotency-key digest. It never
contains document or envelope bytes, the resource-context key, browser-session binding, or raw idempotency
key. An audit failure rolls every mutation effect back; completed replay does not write a duplicate event.

Migration `20260824030000_studio_artifact_recovery` creates:

- `studio_artifact_heads` and append-only `studio_artifact_revisions`;
- `studio_host_idempotency` for atomic claims and canonical completed results;
- `studio_recovery_envelopes` for scoped canonical bytes; and
- `studio_recovery_rate_limits` for atomic fixed-window counters.

The DDL is built through DBAL schema objects for MariaDB/MySQL, PostgreSQL and the SQLite integration harness.
CI remains the authoritative three-engine proof. The application suite reads every applicable vendored
artifact/recovery host vector and host-sequence vector as JSON; it does not duplicate their expected values in
PHP.
