# Persistence and database portability

## Decision

Doctrine DBAL is Kumwe's only relational persistence boundary. Kumwe does not run Joomla Database and Doctrine side by side. Application services depend on repository interfaces and a transaction interface; Doctrine implementations translate those contracts to the selected database.

The supported database engines are:

| Engine | Driver | Supported line | Role |
|---|---|---|---|
| MariaDB | `mariadb` | Current LTS | Default deployment |
| MySQL | `mysql` | 8.4 LTS | Alternative |
| PostgreSQL | `pgsql` | 17 | Alternative |

All three run the same domain and application code. Platform differences may exist only inside Doctrine, schema generation, and tightly scoped persistence-dialect helpers. Never fork a content, workflow, authorization, or queue use case by database engine.

## DBAL and ORM

DBAL provides portable connections, transactions, parameter types, schema generation, and query execution. Kumwe currently maps rows into explicit domain records and aggregates through repository implementations. This keeps queries visible and makes high-volume read paths predictable.

| Concern | DBAL | ORM |
|---|---|---|
| Main abstraction | SQL-oriented connection and query API | Objects, identity map, relationships, and unit of work |
| Best fit in Kumwe | Portable repositories, queues, bulk work, reporting, and tuned read models | Cohesive relationship-heavy aggregates inside a bounded component |
| Main tradeoff | Mapping rows is explicit | Mapping is convenient, but query count and object loading require discipline |

Doctrine ORM may be introduced inside a bounded component when automatic identity maps, aggregate persistence, and object relationships materially reduce complexity. It is not a second application architecture and must not become a requirement for every table. An ORM-backed component must still implement application-owned repository interfaces, publish the same audit behavior, and pass the full database matrix. Delivery code and extensions must not receive an entity manager as a shortcut around application services.

For portal-scale workloads, choose deliberately:

- use explicit DBAL queries for reporting, queues, search projections, bulk operations, and latency-sensitive lists;
- use ORM mappings for cohesive write aggregates when lifecycle and relationships justify them;
- use separate read models or search indexes for high-volume discovery rather than hydrating complete aggregates;
- measure query counts and plans before adding caches or denormalized projections.

## Schema and migrations

The schema is expressed through Doctrine's schema API and uses a configurable table prefix, `kumwe_` by default. Migrations are forward-only, serialized by a database-backed lock, and recorded in the migration ledger. New migrations must use portable Doctrine types and pass against every supported engine.

Avoid engine-specific identifiers, JSON operators, partial indexes, triggers, implicit timestamp behavior, and conflict syntax in shared migrations. If a platform-specific optimization is necessary, contain it behind a dialect and provide equivalent behavior plus tests for the other engines.

Extensions declare their own forward-only migrations in the package manifest. Extension tables must use the extension's assigned prefix or namespace and must not modify core tables. Installation applies extension migrations before activation; uninstall preserves data unless the operator explicitly approves a destructive purge.

## Studio artifact and recovery persistence

Studio compositions persist through the same boundary. `DoctrineStudioHostStorage` is the portable DBAL adapter for immutable artifact revisions, compare-and-set artifact heads, idempotent mutation replay and scoped recovery envelopes; migration `20260824030000_studio_artifact_recovery` creates its `studio_artifact_heads`, `studio_artifact_revisions`, `studio_host_idempotency`, `studio_recovery_envelopes` and `studio_recovery_rate_limits` tables with portable types, and canonical documents are stored as TEXT rather than driver JSON so no engine can reorder or renumber their bytes. Every write joins the application transaction.

The application ports over that storage are Producer's, not Kumwe's own: `StudioArtifactHostPort` implements `Kumwe\Producer\Wire\Port\ArtifactPortInterface`, `StudioRecoveryHostPort` implements `Kumwe\Producer\Wire\Port\RecoveryPortInterface`, and the request-scoped `StudioProducerHost` supplies both to Producer's dispatcher. Their proof is a replay of Producer's vendored testkit, at the exact pinned `kumwe/producer` release, through those two ports against the real storage: the eleven single-operation vectors under `vendor/kumwe/producer/resources/studio-contract/testkit/vectors/host/` (`artifact.*`, `recovery.*`) and the seven artifact and recovery sequences under `vectors/host-sequence/`, each asserted against its own published outcome — result or refusal category, revision and non-disclosing message — as written. That replay is `tests/Integration/Studio/StudioArtifactRecoveryVectorReplayIntegrationTest.php`, driver-neutral so it runs unchanged in the SQLite, MariaDB/MySQL and PostgreSQL integration lanes, beside `StudioArtifactRecoveryPersistenceTest` (DDL, migration replay and exact-byte persistence) and `StudioArtifactRecoveryProducerIntegrationTest` (the direct port contract).

## Transactions, concurrency, and scale

One application service owns each transaction boundary. Repository methods must not commit independently. Use optimistic versions for editor/API concurrency and database row locks for queues, schedulers, migration locks, and other lease-based coordination.

Redis is for cache, distributed locks, rate limits, and disposable coordination data. It is never the system of record for content, users, permissions, extension state, or audit history. A Redis outage may reduce performance or temporarily block a protected operation, but it must not create a second CRUD implementation or silently lose durable state.

For large installations, scale stateless web and worker processes horizontally, use a managed relational database with replicas and connection pooling, isolate queue classes, and introduce projections by measured need. Preserve repository and application-service contracts so those changes do not leak into the administrator, REST, CLI, MCP, or extensions.
