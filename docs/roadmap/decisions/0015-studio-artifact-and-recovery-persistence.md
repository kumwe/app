# ADR 0015 — Studio artifacts use immutable revisions and recovery uses scoped canonical envelopes

**Status** Accepted as the S-D / AP-4 implementation of decision D16
**Decided by** ADR 0007 and the pinned Studio artifact, recovery and host-sequence contracts
**Findings** Completes `V2-STU-004`; later host ports remain under `V2-STU-005` through `V2-STU-007`
**Gate** B foundation
**Verified against** `509da426d65d9b5ded66a88c0500495931be31ed`

---

## Context

AP-3 established trusted App identity, effective policy and one stale-generation fence, but deliberately
returned later host operations as unavailable. Studio's published adapter already fixes the artifact and
recovery wire wrappers and mutation semantics. Implementing persistence requires more than storing JSON: a
write must not overwrite after a race, replay must not apply twice, history must remain readable, recovery
must not cross an actor/session/resource boundary, and unsafe active content must never enter storage.

The division of labour in ADR 0007 remains fixed. Studio owns composition and command semantics. Kumwe owns
authorization, persistence, optimistic concurrency, audit and recovery. Content and BusinessRecord remain
parallel domains and neither becomes an artifact-storage shortcut.

## Decision

1. **AP-3 remains the single transport fence.** The existing dispatcher delegates artifact and recovery
   operations only after envelope, route, protocol, trusted context, mode and current session generation have
   succeeded. The ports consume the exact published wrappers: artifact reads/lifecycle use `{reference}`,
   save uses `{document}`, recovery store uses `{envelope}`, and recovery load/discard require `{}`.
2. **Artifact heads move only by compare-and-set.** Blueprint, content-model and entry values are admitted
   against the exact vendored schema. Save, publish and unpublish require `expectedRevision`. A transaction
   conditionally moves the current head and appends the same admitted value to immutable revision history.
   Conflict returns only the safe current revision and canonical diagnostics; no update path is last-write
   wins.
3. **Canonical bytes are authoritative.** Documents and dependency lists are stored as text and round-trip
   unchanged. Before persistence, a neutral domain policy rejects markup, executable syntax, style members or
   declarations, unsafe schemes/private or credentialed hosts, and URLs outside URL-shaped schema fields. It
   refuses rather than sanitizes.
4. **Mutation replay is atomic and intent-bound.** A durable unique claim scopes actor, authenticated session,
   resource, generation, operation and caller key. A separate canonical intent digest includes semantic
   arguments, protocol, locale and expected revision, while request and trace IDs are excluded. Equal
   completed intent replays its exact result; changed intent is invalid; pending or raced mutation is safely
   retryable. Claim, mutation, audit and completion share one transaction.
5. **Recovery is revisionless but strictly scoped and bounded.** The pinned protocol declares no expected
   revision for recovery. Envelopes are keyed by actor, authenticated browser-session binding and resource
   context. Store enforces canonical numeric fidelity, a byte cap and an atomic fixed-window write cap. Load,
   discard, replay and wrong-scope behavior operate only inside that exact scope.
6. **Every successful mutation is audited once.** Artifact save/publish/unpublish and recovery store/discard
   record one platform `AuditEvent` before commit. Metadata carries operation, trusted site/mode/resource kind,
   resource digest, optional result revision and optional idempotency-key digest. Document/envelope bytes,
   context keys, session bindings and raw keys never enter the event. Completed replay does not duplicate it;
   audit failure aborts all effects.
7. **One portable migration owns the stores.** Migration
   `20260824030000_studio_artifact_recovery` creates artifact heads/history, idempotency, recovery-envelope and
   recovery-rate tables through DBAL schema objects. SQLite integration exercises replay/partial upgrade;
   MariaDB, MySQL and PostgreSQL remain required CI legs.

## Alternatives rejected

### Reuse Content revisions

Rejected. Composition artifacts are not Content entries, and importing either domain into the other would
erase the boundary ADR 0007 and the architecture gates protect.

### Store only the current document

Rejected. It cannot satisfy immutable readable history, safe conflict recovery or forensic reconstruction.

### Deduplicate by key after applying the write

Rejected. Two callers could both mutate before either recorded completion. The claim must arbitrate inside
the transaction before effects, and changed intent under one key must be refused.

### Sanitize unsafe fields on read

Rejected. It preserves executable content in the authoritative store, changes canonical bytes and makes the
value returned depend on a later sanitizer version. Unsafe values are refused before persistence.

### Audit after commit

Rejected. An unavailable recorder would leave an unaudited authoritative mutation. Audit joins the same
transaction and fails closed.

## Consequences

- S-D closes `V2-STU-004`; media, external fetches, preview and the embedded surface remain S-E through S-G.
- The storage boundary does not implement Studio commands, AP-2 binding writes, Content writes or
  BusinessRecord projection.
- Cross-engine acceptance is a CI-only obligation when a local MariaDB/MySQL/PostgreSQL service is absent;
  the SQLite integration harness proves DBAL behavior but is not represented as three-engine evidence.
- AP-1 replay accounting covers every currently vendored artifact/recovery single-operation vector and every
  applicable artifact/recovery host-sequence vector by reading their JSON expectations directly.

The exact covered IDs are:

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
