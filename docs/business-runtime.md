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
archive/delete, restore, action/transition, relate/unrelate, ordered-line reorder, and history operations. Business
records have no REST, CLI, MCP, portal, or generic administrator adapter: every caller uses this application service
directly.

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

## Bounded querying

Browse accepts only the typed `RecordQuerySpecification` AST: boolean/comparison/null/set/text filters, bounded
relation `EXISTS`, search, allowlisted projections and sorts, aggregates, includes, page size, and a signed keyset
cursor. Construction and compilation cap depth, node count, relation hops, strings, sets, projections, sorts,
aggregates, includes, and rows. Every handle is resolved through the pinned definition and installed physical
mapping, every value is bound with its DBAL type, scope and lifecycle predicates are mandatory, and a stable
identity tie-breaker is always present. Raw SQL, table names, column names, DBAL expressions, and offset pagination
are not public inputs.

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
