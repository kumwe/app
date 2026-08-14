# Transactional business runtime

Kumwe compiles immutable business-definition versions into typed relational storage. Publication creates a
canonical, checksummed schema plan; it never runs DDL. An administrator must inspect and approve the exact plan
before a separately authorized executor may change physical tables. Ordinary requests, extension boot,
activation, and upgrade never perform schema changes.

## Schema-plan lifecycle

Open **Structure → Schema plans** to inspect the installed and published versions, physical schema checksums,
risk, portable semantic operations, recovery requirements, and the durable step journal. The lifecycle is:

1. Publication compares the complete published definition graph with installed metadata and live introspection.
2. The planner persists a deterministic plan and step journal in `pending_approval` state.
3. Approval binds the exact plan, target definition, prior physical checksum, actor, and revision. High-impact
   work requires the checksum-derived confirmation and current-password step-up. Locking or destructive work
   also requires recent recovery evidence for the same source schema.
4. Execution re-verifies every checksum, acquires a database advisory lock, allocates a monotonic database fence,
   and applies only persisted semantic operations through Doctrine DBAL.
5. Every operation is journaled before execution and verified through live introspection afterward. PostgreSQL
   uses transactional DDL. MariaDB and MySQL reconcile each implicit-commit step from its pre/postconditions;
   ambiguous state stops as `recovery_required` rather than guessing.

The capabilities `business.schema.read`, `business.schema.plan`, `business.schema.approve`,
`business.schema.execute`, and `business.schema.recover` are independently grantable. Destructive work also
requires `business.schema.destructive`. The page has no SQL input and the application never persists executable
SQL, PHP, Twig, JavaScript, or shell text in a plan.

## Physical storage

Each installed entity has a collision-safe, hash-backed physical name that is persisted in its canonical
blueprint and remains within PostgreSQL's 63-byte identifier limit, including the configured table prefix. The
entity table includes canonical identity, pinned definition version, declared site/organization scope,
optimistic version, workflow state when configured, actor/timestamps, archive metadata, and optional soft-delete
metadata.

Storage is type-specific:

| Definition type | Authoritative storage |
|---|---|
| Decimal | DBAL `DECIMAL(precision, scale)`; PHP canonical base-10 string |
| Money | Exact decimal amount plus three-character ISO currency |
| Quantity | Exact decimal amount plus bounded unit |
| Date, local time, instant | Immutable DBAL temporal values with six fractional digits; instants are canonical UTC |
| Zoned datetime | Six-digit UTC instant plus IANA zone |
| UUID | DBAL GUID |
| Secret | Authenticated-encryption envelope: ciphertext, nonce, key ID, algorithm |
| Entity relation | Typed foreign key or dedicated junction table |
| Ordered lines | Typed child table with owner and unique position |
| Bounded JSON/embedded value | JSON only for the explicitly declared escape/value-object type |

There is no EAV table or universal JSON record table. Unique constraints include the configured scope and remain
portable for archived rows. Relations are scope checked, use declared delete behavior, and increment the owner
version for relation or line-order changes. Published older versions and their required columns remain available
while records are pinned to them.

## Record application boundary

`BusinessRecordService` is the single public application boundary. It provides create, read, browse, update,
archive/delete, restore, action/transition, relate/unrelate, ordered-line reorder, and history operations. Generated
administrator, explicitly enabled portal, REST, CLI, and MCP adapters are thin mappings through the shared
catalog, surface service, query factory, and omission-safe projector; none reimplements record rules or reaches a
generated table. See [Generated business surfaces](architecture/generated-business-surfaces.md).

Every mutation supplies an application operation ID; every existing-record mutation also supplies the expected
record version. One service-owned transaction resolves the exact installed or pinned definition, authorizes the
operation, applies defaults and allowlisted normalization, validates exact values/references/uniqueness and
cross-field invariants, recomputes stored formula dependencies, enforces workflow/action conditions, performs the
CAS write, appends an immutable revision, records redacted audit metadata, and completes command idempotency.

Idempotency is application-owned and separate from HTTP response idempotency. A key is bound to site,
organization, actor, operation, canonical request fingerprint, and authorization fingerprint. Exact repeats
replay the canonical result; the same key with different input is rejected. Failed transactions leave neither an
effect nor a completed key.

Secret plaintext never enters audit metadata, query predicates, search indexes, or history output. Revision
storage retains only the authenticated encrypted envelope and history presentation redacts secret and restricted
fields.

Every envelope records the identifier of the key that sealed it, and the runtime resolves that identifier against
a key ring holding one active key plus the retired keys the deployment still needs, so a key can be rotated without
making stored values unreadable. Re-sealing stored envelopes under a new active key is a separate bounded pass,
`business-record-rekey`; it decrypts and re-encrypts under the same associated-data binding and does not touch
revision snapshots, whose checksums are their integrity evidence. See
[record encryption key lifecycle](business-security.md#record-encryption-key-lifecycle).

## Atomic documents: a header and its owned lines

Nearly every business object worth the name is a document: a header and the lines that belong to it. An
invoice, a purchase order, an attendance batch, a job card, a stock movement, a pay run. Core owns the write
and none of the meaning — what makes something an invoice is an extension's definition, and there is no
invoice, order or ledger rule anywhere in this runtime.

`BusinessRecordService::writeDocument()` writes one whole document as one command. Header and lines are
settled together and committed together inside the one transaction the definition's exclusive fence is held
for, so there is no instant at which a reader can see a header without its lines, or lines whose header has
already moved on. A refusal anywhere — a field rule, an aggregate invariant, a stale version, a unique
collision on the nine hundredth line — takes the whole document with it and leaves no row, no revision, no
audit entry and no idempotency claim behind.

### The line list is the collection, not a set of edits

A `WriteDocumentCommand` carries the header's values and the document's whole line list, in the order it is to
be stored. That list is declarative:

- a line naming an identity the document already holds is **amended**;
- a line naming none is **added**;
- a stored line the list never names is **removed**;
- every line's position is **its index in the list**.

Two consequences follow, and they are the reason the shape was chosen. No two lines can occupy one slot and
no caller can leave a hole, because position is not an input. And a line's identity is meaningful only inside
its document: it is addressed through the header, it is removed with it, and a delete of the header takes the
whole collection with it.

`DocumentWriteIntent` says which of the two things a command is. `Create` writes the document at version one;
`Amend` names the aggregate version the caller read.

### Concurrency is settled at the document

`expectedVersion` is the header's version, and it guards every line write in the command. Two callers
amending one document therefore contend for one value: the second to arrive is refused with
`BusinessRecordVersionConflict` rather than interleaved into a document that neither of them wrote. A
line-level change against a header that has moved on is refused for the same reason and by the same
mechanism.

### What a document write costs

Statement count follows the change rather than the collection.

| Command | Statements against the line table |
|---|---|
| Create of N lines | one batched insert per 100 lines |
| Amend, values only | one update per line whose values actually changed |
| Amend, lines removed | one batched delete per 100 removed keys |
| Amend, order changed | one renumbering statement, then one update per surviving line |
| Amend, nothing changed | none |

A thousand-line document is written in ten insert statements, and a document resubmitted unchanged writes
nothing at all. Batch size is derived from the line entity's own column count against a bounded parameter
budget, so a wide line batches fewer rows rather than failing on the strictest engine. One version, one
revision, one audit action and one bounded event describe the whole write, and the event carries a change
summary — counts and a keyed digest over the line identities — rather than a thousand-line payload.

### Rules that span the whole document

A `record_invariant` may reduce an owned-line collection, which is what makes the most fundamental document
rule there is expressible at all:

```json
{
  "handle": "total_agrees_with_lines",
  "message": "The document total must equal the sum of its lines.",
  "condition": {
    "op": "eq",
    "type": "boolean",
    "args": [
      {"op": "field", "type": "decimal", "field": "total"},
      {"op": "line_aggregate", "type": "decimal", "lines": "lines", "field": "amount", "aggregate": "sum"}
    ]
  }
}
```

The `line_aggregate` leaf is deliberately narrow: one collection the entity declares as an owned-line
relationship, one reduction from a closed set — `sum` over one line field, or `count` over the collection —
and the same 32-kilobyte, 128-node, 12-level budget every other expression lives under. It is a rule about a
document, not a query language inside a definition.

Four things are settled before a definition is ever published. The aggregation must name a collection the
entity actually declares as an owned-line relationship. The summed handle must be a field the line entity
carries, and must be `core.decimal` or `core.integer` — an exact number, never text the runtime would have to
guess at, and never a value the line keeps restricted or secret, because folding one into a header total
would leak its magnitude. A `count` takes no field and a `sum` requires one. And a field formula, a
visibility or editability condition and an action condition may not aggregate at all, because each of those
is evaluated for one record at a time.

Arithmetic stays exact end to end. A thousand `core.decimal` line values fold through the same exact decimal
type the columns store, producing a canonical base-10 string and never a float. Equality between decimals is
judged by value rather than by spelling, so a total stored at scale three agrees with a sum that spells one
fewer digit.

The rule is evaluated **once per command**, over the collection the write is about to store — not once per
line, and not by re-reading rows. A violation is reported against the invariant's own handle carrying the
definition's own message, so an operator is told that a total disagrees with its lines rather than that
something, somewhere, was unavailable.

Because the rule belongs to the document rather than to one command, it is enforced by every command that can
move either side of it: the document write, an ordinary header update, and a single-line `relate()` or
`unrelate()`. A `reorder()` moves no value and changes no count, so it is not re-judged. Definitions that
declare no aggregate invariant pay nothing for any of this — no extra lock, no extra statement.

### Declaring one from an extension

Nothing above requires a core edit. An extension contributes an entity definition through the ordinary
package contribution path, and if that definition declares an aggregate invariant, core enforces it without
having heard of the rule, the vertical, or the document. That is the test of the boundary: if a new vertical
needs core to change, either a primitive is missing or the boundary was drawn wrong.

### Limits

- A command writes at most 1,000 lines, and writes exactly one owned-line collection.
- A line type that declares an aggregate invariant of its own cannot be written through this command; a
  nested document needs a command that writes its own lines too, and that is refused rather than half-applied.
- The whole stored collection must be visible to the caller. A document command replaces a collection, so
  working from a filtered view of it would silently destroy the lines the actor was never shown; a stored line
  the row policy hides fails the whole command closed.
- The single-line `relate()`, `unrelate()` and `reorder()` commands are unchanged and remain supported.

## Bounded querying

Browse accepts only the typed `RecordQuerySpecification` AST: boolean/comparison/null/set/text filters, bounded
relation `EXISTS`, search, allowlisted projections and sorts, aggregates, includes, page size, and a signed keyset
cursor. Construction and compilation cap depth, node count, relation hops, strings, sets, projections, sorts,
aggregates, includes, and rows. Every handle is resolved through the pinned definition and installed physical
mapping, every value is bound with its DBAL type, scope and lifecycle predicates are mandatory, and a stable
identity tie-breaker is always present. Raw SQL, table names, column names, DBAL expressions, and offset pagination
are not public inputs.

The repository also receives the immutable [business-security access plan](business-security.md) before it
constructs SQL. Row policy is therefore applied before identity lookup, count, aggregation, keyset pagination,
relation traversal, includes, report, or export. The signed cursor binds the access-plan digest, so a membership,
policy, trust, or field-disclosure change invalidates an old cursor. List, detail, filter, search, sort, aggregate,
report, export, history, relation, include, and public-reference field usages are independently allowlisted.

Record actions require the base transition capability, the action's declared capability, and the exact workflow
transition capability. A high-impact action additionally consumes one immutable maker-checker approval and one
fresh step-up proof bound to the action, record identity, expected version, and canonical payload. Requesting an
approval performs the same definition, policy, capability, condition, and transition validation as execution.

## Disablement, uninstall, purge, and recovery

Disablement and uninstall make owned definitions unavailable to executable record commands but preserve catalog
history, installations, physical tables, records, revisions, idempotency outcomes, and audit history. Compatible
reactivation reuses the preserved installation. Upgrade publishes a pending plan; the newer version is not used
for creates until that plan completes. Purge is a separately authorized destructive plan, never an uninstall
side effect.

An authorized schema plan may be applied or recovered while its owning extension is inactive. The executor
current-locks both owner and installation state before DDL, keeps record traffic unavailable, and finalizes the
new exact blueprint as `preserved`, not `active`. Reactivation is rejected while a plan is executing or requires
recovery; after the plan completes, the normal compatibility and live-introspection checks may reactivate it.

Destructive approval requires a verified backup manifest, matching source-schema checksum, database/release
identity, and a successful clean-target restore drill. Recovery evidence is durable and plan-bound. On an
interrupted execution, use the schema-plan recovery action only after inspecting the journal and live checksum;
the executor resumes satisfied or safely replayable work under a new fence and refuses ambiguous changes.

Older rows remain pinned to their immutable definition version. A rename, type rewrite, or tightened constraint
cannot run over those rows unless compatibility metadata declares the bounded backfill/transform and an exact
self-repin to the newly published version. Repinning is a separately journaled keyset operation under the same
database fence. Each row is decoded through the target mapping, normalized, revalidated against target field and
cross-field invariants, checked for a valid workflow state, and has stored computations recomputed before its
version advances. A self-repin never authorizes an untransformed column/table removal; that remains blocked while
older rows exist. Every record mutation holds the installation row lock for its full transaction, so the
`installing` transition waits for already-started writes and prevents late inserts or stale updates from crossing
the repin cursor. During upgrade or purge the installation is non-executable, so ordinary record traffic fails
closed until the plan completes or recovery reconciles every postcondition.

Complete database backups include generated entity, junction, line, revision, idempotency, installation, plan,
journal, fence, and recovery-evidence tables. Recovery acceptance compares blueprints and record/revision/audit
checksums on a clean target and executes a typed command before the evidence can authorize destructive work.

## Verification

Before release run `composer qa`, `npm run check`, `npm run build`, and `npm run test:browser`. CI repeats schema
planning/execution, typed round trips, optimistic concurrency, command idempotency, query limits, interruption
recovery, lifecycle preservation, and clean-target backup/restore on MariaDB LTS, MySQL 8.4, and PostgreSQL 17.
