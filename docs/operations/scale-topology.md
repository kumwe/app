# Scale topology, query bounds and storage forecast

Roadmap packages `P5-G`, `P5-H` and `P5-I`. Companion pages: [Retention](retention.md),
[Scalable metrics](scale-metrics.md), [Capacity estimate](capacity-estimate.md).

## Supported topology (P5-H)

```
             load balancer (readiness: /health/ready, deploy gate: bin/kumwe app:health)
                  │
   ┌──────────────┼──────────────┐
   app replica 1 … app replica N        stateless PHP-FPM; no local authority
   └──────────────┼──────────────┘
                  │  bounded pool: PHP-FPM pm.max_children per replica
   worker pools (one queue:work / integration:work process group per class):
     interactive · sequencing · consumer fan-out · queues/schedules/processes ·
     reports/exports/projections · maintenance/retention
                  │
          one authoritative primary database (MariaDB / MySQL / PostgreSQL)
          optional replicas: eventual reporting only
          Redis: sessions, rate limits, metric counters, wake-ups — never authority
```

- **Authority.** Transactions, row locks, record versions, sequence rows, queue permits, fairness turns,
  idempotency claims, inbox receipts and the journal head live only in the primary database. Redis loss
  degrades wake-up latency and caches, never correctness (`docs/operations/monitoring.md`).
- **Connection budget.** Each PHP process holds one connection. Size
  `N × pm.max_children + Σ worker processes + 10` below `max_connections`, and alert when
  `kumwe_database_connections_in_use / kumwe_database_connections_max` passes 0.70 (normal) or 0.85
  (peak), the contract's utilisation limits. There is no application-side pool to exhaust; backpressure is
  the FPM listen queue plus the durable queues: saturated contributed queues defer outbox dispatch
  (`IntegrationDeliveryBackpressure`), claims skip locked rows instead of waiting, and every lock wait is
  bounded by the engine's lock timeout and reported as `kumwe_transaction_failures_total{class}`.
- **Readiness tied to generation and schema.** `/health/ready` answers from the signed local runtime
  marker for the loaded extension generation; `app:health` additionally checks the migration plan is
  complete and compatible, no non-transactional migration is unresolved, the loaded runtime generation is
  still current, retention is configured and not predicting exhaustion, and the database volume keeps its
  30% reserve.
- **Graceful drain and generation leases.** `queue:work` and `integration:work` finish the job in hand on
  `SIGTERM`/`SIGINT`/`SIGHUP`/`SIGQUIT`, bound handlers with a runtime deadline, and check the loaded
  runtime generation before every claim, exiting when it is stale so the supervisor restarts them on the
  current generation. Claims carry the runtime generation and a fencing token; a stale holder cannot
  settle. Record writers take the shared definition-generation fence, which a schema transition waits for
  and which new writers cannot pass once a transition holds it (`SchemaTransitionWriterFenceIntegrationTest`).
- **Fairness.** Contributed queues grant durable round-robin turns per site and organization before
  priority (`DoctrineJobQueueFairness`), consumer receipts rotate per consumer, site and organization and
  share one execution permit per consumer (`DoctrineInboxStore`), and a declared queue ceiling is a fixed
  set of permit rows (`DoctrineQueuePermits`). `QueuePermitOverclaimAndFairnessIntegrationTest` proves a
  site adding five jobs per claim cannot keep two smaller sites from their turns and that six racing
  claimants never exceed a ceiling of three. Report and export work is bounded per artifact by the row cap
  (100,000) and byte ceiling (128 MiB) and runs as queued jobs under the same queue fairness.
- **Per-site export byte budget.** A completed export is charged to its site's cumulative total for the
  current UTC day (`DoctrineExportSiteByteBudget`, default 4 GiB a site a day, one row per site in
  `business_report_export_site_budgets`) inside the completion transaction. The charge is one conditional
  `UPDATE`, so concurrent completions for one site serialize on that row and never both pass on the same
  remaining budget, while other sites never wait; a completion that would pass it fails durably with
  `site_byte_budget` and its bytes are deleted. The window is a fixed UTC day, so a site can publish up to
  twice its budget across one midnight. `ExportSiteByteBudgetIntegrationTest` proves accumulation, refusal,
  the window, rollback, per-site isolation and six racing processes on MariaDB and PostgreSQL.
- **Connections per site.** A PHP process holds one connection whatever site it serves, so the connection
  budget above is per process, not per site: worker processes reach a site's work only through its
  fairness turns and permits, and HTTP connections are bounded by `pm.max_children` and the listen queue.
  There is no per-site HTTP concurrency limit in the application; put one in the load balancer when one
  tenant's request rate must not be able to occupy every FPM child. Rate limits supplement authorization
  and never replace it.

### Stateless replica qualification

`tests/Integration/Scale/ReplicaTopologyQualificationIntegrationTest.php` composes two replicas — two
containers, each with its own connection, caches and in-process state — over one primary and proves: a
command committed through one replica is read after write, replayed from the shared idempotency ledger and
conflict-checked on the other; a job one replica enqueues is run exactly once by whichever replica's worker
claims it; and both replicas drain together when the schema ledger stops matching their code and return
together when it matches again. The six worker pool classes of the capacity contract map to processes and
are qualified across independent operating-system processes by these tests:

| Pool class | Process | Cross-process proof |
|---|---|---|
| High-priority interactive commands | PHP-FPM replicas | `ReplicaTopologyQualificationIntegrationTest`, `SchemaTransitionWriterFenceIntegrationTest` |
| Event sequencing | `integration:work` sequencer | `SequencerCrashAndRaceIntegrationTest` |
| Consumer fan-out | `integration:work` consumers | `HungAndPoisonFanoutIntegrationTest` |
| Queues, schedules and processes | `queue:work --queue=…`, `schedule:run` | `QueuePermitOverclaimAndFairnessIntegrationTest`, `ReplicaTopologyQualificationIntegrationTest` |
| Reports, exports and projections | `queue:work` on the report queue | `ExportSiteByteBudgetIntegrationTest` (racing completions) |
| Maintenance and retention | `queue:work` running `system.retention.drain` | `RetentionStoreDrainIntegrationTest`, `ReplicaTopologyQualificationIntegrationTest` |

What these do not qualify is hardware: the number of replicas and processes a given host sustains is the
capacity sample's question (`docs/operations/capacity-estimate.md`), not a correctness property.

## Query, index and reporting bounds (P5-G)

| Bound | Where enforced | Value |
|---|---|---|
| Page size | `kumwe/record-query` `RecordQuerySpecification` | 1–200 |
| Sorts | same | ≤ 5, no duplicate field |
| Filter depth / relation hops (join depth) / operations | same | ≤ 8 / ≤ 2 / ≤ 64 |
| Bound parameters from a query | `SetFilter` (≤ 100 values) × operations | ≤ 6,400 plus scope and policy bindings |
| Pagination | same; `RecordCursorCodec` | keyset cursor only, signed, bound to the query digest |
| Row and field policy before joins, counts and paging | `DoctrineBusinessRecordQueryCompiler` with `BusinessRecordAccessPlan` | compiled into the `WHERE` |
| Value set | `RecordRequestGuard::values` | ≤ 256 fields, depth/node budget via `RecordValueGuard` |
| History window | `RecordHistoryQuery` | 1–200 revisions, cursor by version |
| Document payload | `DocumentWriteBudget` | declared line and byte budget |
| Interactive report rows | `kumwe/reporting` `ReportDefinition::synchronousRowCap` | 1–1,000 |
| Export rows / columns / cell length | `ReportService`, `RecordExportReportProvider` | ≤ 100,000 / 64 / 4,096 |
| Export bytes | `FilesystemExportArtifactStorage` | ≤ 128 MiB (max 512 MiB) |
| Administrator list depth | `DoctrineAccessControlRepository::MAXIMUM_OFFSET` | offset ≤ 10,000; filtered walks examine ≤ 10,100 rows |
| Content list and browser depth | `DoctrineContentRepository::MAXIMUM_OFFSET`, `ContentService` scan bound | offset ≤ 10,000; authorization-filtered walks examine ≤ 10,100 rows |
| Job list | `DoctrineJobQueue::MAXIMUM_LISTED_SCAN` | authorization-filtered walk examines ≤ 10,100 rows |
| Scrape and readiness probes | `RuntimeMetricCollector::PROBE_CAP`, `DoctrineRetentionObserver::PROBE_CAP` | 10,000 / 100,000 index entries |
| Exact diagnostics | `LedgerCensus` | ≤ 30 s server statement timeout |
| Execution time per browse statement | `DoctrineBusinessRecordReadRepository` through `BoundedStatementExecutor` | 5 s, cancelled by the engine |
| Result bytes per browse statement | same | 8 MiB of column bytes, refused before decoding |
| Relationship include fan-out | `DoctrineBusinessRecordReadRepository::MAX_INCLUDED_ROWS` | ≤ 1,000 rows per include |
| Rows an unindexed ordering may sort | `DoctrineBusinessRecordQueryCompiler::UNINDEXED_SORT_CANDIDATES` | ≤ 10,000; more is refused (HTTP 422) |
| Query count per page | `GeneratedBusinessQueryBudgetIntegrationTest`, `MachineAdapterQueryBudgetIntegrationTest` | constant in page size through REST, MCP and CLI |

Indexes for scope, identity, the default update-time ordering and the fields a definition declares indexed,
unique or sortable are created by the physical schema compiler when the definition is installed, and
`tests/Integration/Performance` holds the declared hot plans (`docs/quality/hot-plans.json`) to an indexed
access path on every engine.

**Execution-time and byte bounds.** Every statement of a browse — the page, the aggregates, the reference
identities and each relationship include — runs through `BoundedStatementExecutor` under the repository's
`StatementBudget` (5 s, 8 MiB). The engine enforces the time: MariaDB runs the statement as `SET
STATEMENT max_statement_time = 5 FOR SELECT …`, MySQL carries the `MAX_EXECUTION_TIME(5000)` optimizer
hint, and PostgreSQL sets a transaction-local `statement_timeout` inside the read transaction, or a
savepoint of it that is rolled back afterwards so the caller's own timeout and transaction survive. A
cancelled statement stops examining rows and releases its snapshot on the server. Rows are then
materialized one at a time and their column bytes summed, and a page past 8 MiB is released and refused.
Both refusals surface as `InvalidBusinessRecordQuery` — HTTP 422 on REST, the same stable code on MCP and
the CLI — asking the caller to narrow the filter or page, so REST, MCP, CLI and browser pages share one
byte cap. The 5 s default sits below PHP-FPM's request timeout and the 30 s maximum a `StatementBudget`
accepts. `BoundedStatementExecutorIntegrationTest` proves the engine cancellation, the byte refusal and
the untouched caller transaction on MariaDB and PostgreSQL; `BoundedStatementExecutorTest` pins MySQL's
hint, which no local engine runs.

**Examined rows and sort plans.** Pagination is keyset-only and the page statement's `WHERE` carries scope,
row policy and the cursor predicate before `ORDER BY … LIMIT page + 1`. `kumwe/business-schema` 0.1.4
compiles the canonical record-table indexes: `(scope, updated_at, record_id)` for the default ordering (last
update, newest first) and `(scope, field, record_id)` for every `sortable` field stored in one indexable
column (a unique NOT NULL field keeps its unique `(scope, field)` index). A definition installed before
0.1.4 receives them through its next normal schema plan. `DoctrineBusinessRecordQueryCompiler` reads the
installed blueprint and chooses one of three plans:

| Ordering | Plan | Examined rows |
|---|---|---|
| One key an installed index serves, NOT NULL (default updated time, unique, indexed, sortable-only, record identity), either direction | One index range read forwards or backwards; the seek is the row comparison `(key, record_id) > (?, ?)` on PostgreSQL and a leading `>=`/`<=` bound on MariaDB and MySQL, which also name the serving index (`FORCE INDEX`) when the caller supplied no filter or search, because their optimizer otherwise keeps a scope `ref` and walks past every earlier page | ≤ 4 × (page + 1), first page and deep pages alike |
| One nullable key an installed index serves, any direction and null placement | The valued rows and the empty rows are read as two index ranges, each capped at page + 1, then the at most 2 × (page + 1) rows are ordered together; a segment the cursor has passed is left out | ≤ 8 × (page + 1) |
| Several keys, or a sortable field no index covers (stored wider than 191 characters, or installed before 0.1.4) | Bounded candidate plan: the rows matching scope, policy, filter and cursor are capped at `UNINDEXED_SORT_CANDIDATES` + 1 (10,001) in a derived table and only those are sorted; a window count reports the set, and a page whose candidates pass 10,000 is refused as `InvalidBusinessRecordQuery` (HTTP 422) asking for a narrower filter or an indexed sort | ≤ 10,001 record rows, never the whole aged table |

`BrowseExaminedRowsIntegrationTest` grows a generated table with the capacity contract's declared 2,000-row
aged dataset (`tools/PerfDataset.php`, seed 20260924, ages backdated into `created_at` and `updated_at`), walks
every ordering through `BusinessRecordService` at 100 rows a page and measures the first page and the tenth
by the engine: MariaDB and MySQL through the session's `Handler_read_*` counters, PostgreSQL through `EXPLAIN
(ANALYZE)` actual rows. Measured on MariaDB 10.11 and PostgreSQL 16 on 2026-09-30 (2,003 rows):

| Ordering | MariaDB page 1 / page 10 | PostgreSQL page 1 / page 10 |
|---|---|---|
| Default updated time, unique, indexed descending, sortable-only, record identity | ≤ 102 / ≤ 102 | 101 / 101 |
| Nullable, empty values last (ascending or descending) | 506 / 507 handler reads (202 record rows by `ANALYZE`) | 202 / 202 |
| Nullable, empty values first (ascending or descending) | 506 / 102 | 202 / 101 |
| Wide sortable field; two keys (bounded candidate plan) | 2,003 record rows | 2,003 record rows |
| The same after growth to 12,003 rows | refused, 10,001 record rows read | refused, 10,001 record rows read |

In the same test, before the seek became a row comparison and the index was named, a tenth default-order
page examined 1,001 rows on MariaDB (the scope `ref` walk) and a tenth sortable-only page 1,001 rows on
PostgreSQL (an index scan filtering every earlier row); before business-schema 0.1.4 and this plan choice the
default, sortable-only, nullable and several-key orderings sorted every row in scope under the 5 s cap alone. MySQL 8.4 runs the same test in CI's database lane; for the candidate plan its handler
counters also count the derived set, so it is held to a multiple of the candidates. The bounds are
examined-row counts on the declared dataset, not throughput: capacity is estimated from concurrent samples
on CI hardware (ADR 0021, [Capacity estimate](capacity-estimate.md)).

**Core listings.** The content browser's default and oldest-first orderings are served by
`(site_identifier, updated_at, id)`. Migration `20260930120000_core_listing_sort_indexes` adds
`(site_identifier, title, id)` for its title orderings and `(updated_at, process_id)` for the operator's
recent-process listing, which PostgreSQL reads backwards directly and MariaDB and MySQL read as the newest
identities from the index followed by a primary-key fetch, because their cost model sorts a small table
instead. `CoreListingSortPlanIntegrationTest` grows both tables with the same aged dataset and holds every
listing statement to ≤ 4 × the 50-row batch on each engine. Administrator, content and job lists keep their
offset bounds above; retention drains keep the batch indexes documented in [Retention](retention.md).

**Query-count growth.** `GeneratedBusinessQueryBudgetIntegrationTest` holds generated discovery, operation
maps and relationship hydration to budgets that do not grow with definitions, relationship width or page
size, and `MachineAdapterQueryBudgetIntegrationTest` holds the REST, MCP and CLI adapters to the same
statement count at a one-row and a twelve-row page with a relationship include, so no adapter adds a
per-row statement on top of the shared service.

**Replicas.** Kumwe routes every read to the authoritative primary: authorization, generation checks,
stale-sensitive workflows and read-after-write responses never read a replica. A read replica serves only
explicitly eventual reporting that an operator points at it (for example a BI tool or an export copied
from a replica snapshot); nothing in the application reads one.

## Storage forecast (P5-I)

`php tools/perf-storage.php --workload=create|update|document|aged --records=300 --lines=10
--dataset-records=2000 --backup` runs 300 LBTs of one workload through `BusinessRecordService` and measures
per LBT: physical row mutations (MariaDB session `Handler_write/update/delete`; PostgreSQL
`pg_stat_database` tuple counters, database-wide), table and index bytes from the catalogue, log bytes
(binary log position or WAL LSN distance), an undo indicator (InnoDB history list length, PostgreSQL dead
tuples; purge and autovacuum run concurrently, so it can fall) and, with `--backup`, the growth of a
data-only logical dump (`mariadb-dump --single-transaction`, `pg_dump`) of every installation table. The
`aged` workload first seeds the contract's declared 2,000-row aged dataset. Measured on the capacity host
(4 × Xeon 2.80 GHz, 15.7 GiB, MariaDB 10.11.14 and PostgreSQL 16.15 beside PHP) on 2026-09-24, and
published per engine in `docs/roadmap/capacity-contract.json` (`storage_amplification`):

| Engine | Workload (300 LBT) | PRM/LBT | Table B/LBT | Index B/LBT | Log B/LBT (= replica B/LBT) | Logical dump B/LBT | Undo indicator Δ |
|---|---|---|---|---|---|---|---|
| MariaDB 10.11 | Ordinary create | 8.0 | 4,041 | 1,420 | 20,056 | 6,897 | -199 |
| MariaDB 10.11 | Update | 7.0 | 10,595 | 1,475 | 22,670 | 5,146 | -225 |
| MariaDB 10.11 | Document, 10 lines | 18.0 | 9,011 | 8,410 | 27,691 | 7,983 | -715 |
| MariaDB 10.11 | Create on 2,000-row aged table | 8.0 | 6,990 | 983 | 21,897 | 6,897 | -305 |
| PostgreSQL 16 | Ordinary create | 15.2 | 4,533 | 1,502 | 15,126 | 6,324 | 300 |
| PostgreSQL 16 | Update | 14.4 | 3,714 | 956 | 12,007 | 4,696 | 600 |
| PostgreSQL 16 | Document, 10 lines | 25.5 | 5,734 | 3,304 | 20,440 | 7,316 | 300 |
| PostgreSQL 16 | Create on 2,000-row aged table | 15.6 | 6,335 | 1,338 | 13,088 | 6,324 | 300 |

Document lines cost MariaDB 1.8 PRM and 1,742 table and index bytes per line, PostgreSQL 2.55 PRM and 904
bytes. **Replica amplification** is the log column: a MySQL-family replica receives the binary log and a
PostgreSQL streaming replica the WAL byte for byte, so each replica adds those bytes per LBT in network and
relay storage. **MySQL** runs in no environment available to this change; `.github/workflows/capacity.yml`
measures every workload on MySQL 8.4 (and MariaDB and PostgreSQL 17) on each run and publishes the figures
in its summary. InnoDB grows in 16 KiB pages and extents, so the MariaDB byte figures at 300 LBTs are coarse
(an earlier 500-create run measured 12,812 table bytes per LBT where this one measured 4,041).

**Estimated** (arithmetic on the measured figures, not a measurement): at the 5,000,000 LBT/day target
split as 100,000 documents carrying about 15,000,000 lines and 4,900,000 ordinary mutations, half creates
and half updates, table and index growth is ≈ 69 GB/day on MariaDB and ≈ 40 GB/day on PostgreSQL before
retention, log volume (and bytes per replica) ≈ 118 GB/day of binary log and ≈ 76 GB/day of WAL, and
logical-dump growth ≈ 32 GB/day and ≈ 29 GB/day. The dump ran at 3.2–5.8 MB/s on MariaDB and 41–71 MB/s on
PostgreSQL on this host, so a logical dump of a 30-day window (≈ 0.9 TB) takes about two days on MariaDB
and three to four hours on PostgreSQL: at the target volume MariaDB and MySQL need physical or binary-log
based backups (`docs/operations/backup-restore.md`), which copy the table and index bytes above. With the
30% reserve, provision ≈ 3 TB (MariaDB) or ≈ 1.7 TB (PostgreSQL) for a 30-day hot window, plus the rebuild
space below. Restore time is measured by the backup drills, not by this tool.

The **30% free-space reserve and the rebuild space** are one readiness guardrail: set
`KUMWE_DATABASE_DATA_PATH` to a directory on the database data volume that the application host can see;
`app:health` then warns (baseline) or fails (`KUMWE_CAPACITY_PROFILE=enterprise`) when free space drops
below 30% of the volume **or** below twice the installation's largest table (`DoctrineLargestTable`, read
from the engine's catalogue: `pg_total_relation_size`, or `data_length + index_length`), the temporary space
an online rebuild of that table or its indexes can need. The report carries `largest_table_bytes` and
`rebuild_bytes_required` beside the free fraction. Without the setting the guardrail reports nothing and the
operator must monitor the volume directly.
