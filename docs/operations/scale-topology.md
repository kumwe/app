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
  (100,000) and byte ceiling (128 MiB) and runs as queued jobs under the same queue fairness; a per-site
  cumulative export byte budget is not implemented.

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
| Query count per page | `GeneratedBusinessQueryBudgetIntegrationTest`, `MachineAdapterQueryBudgetIntegrationTest` | constant in page size through REST, MCP and CLI |

Indexes for scope, identity, version and the fields a definition declares indexed, unique, sortable or
filterable are created by the physical schema compiler when the definition is installed, and `tests/Integration/Performance` holds the
declared hot plans (`docs/quality/hot-plans.json`) to an indexed access path on every engine.
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

**Examined rows.** Pagination is keyset-only and the page statement's `WHERE` carries scope, row policy
and the cursor predicate before `ORDER BY … LIMIT page + 1`. For a sort on a field the definition declares
`indexed` or `unique`, the installed index leads with the scope columns and the field. The compiler leaves
out the null-rank term for a NOT NULL column (it ranks every row alike), seeks with a bare comparison, and
after a unique NOT NULL key emits no identity tie-breaker (the key is already total inside the
equality-bound scope, and MariaDB cannot extend a unique index with the primary key), so the engine reads
the page in index order. `BrowseExaminedRowsIntegrationTest` measures at most 4 × (page + 1) examined rows
for a unique and for an indexed ordering on a 1,503-row table, on MariaDB (session `Handler_read_*`
counters) and PostgreSQL (`EXPLAIN (ANALYZE)` actual rows); the previous compiled order examined every row
(1,525 handler reads on MariaDB, 1,503 rows on PostgreSQL). Three orderings are not index-served, because
the record table's indexes are compiled by the `kumwe/business-schema` package, not by App: the default
order (last update, newest first — the package emits no `(scope, updated_at)` index), a field that is
`sortable` but neither `indexed` nor `unique`, and a nullable or descending sort (the rank term and the
ascending identity tie-breaker need a sort). Those pages examine every row in scope and are bounded by the
5 s execution-time cap above; the remedy is a package release that indexes `(scope, updated_at, record_id)`
and every sortable field.

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

`php tools/perf-storage.php --records=500` creates 500 ordinary records through `BusinessRecordService`
and measures per logical business transaction (LBT) on the same host as the other samples:

| Measured per LBT | MariaDB 10.11 | PostgreSQL 16 |
|---|---|---|
| Physical row mutations (PRM/LBT) | 8.0 (session handlers) | 10.0 (tuple counters) |
| Table bytes | 12,812 | 6,373 |
| Index bytes | 3,146 | 1,786 |
| Log bytes (binlog / WAL) | 19,946 | 11,671 |
| Undo indicator Δ over 500 LBT | +45 history list length | +500 dead tuples |
| Largest per-table growth | staging 3,146 + 262, revisions 3,047 + 1,081, audit 2,982 + 360, outbox 2,097 | revisions 1,376 + 541, outbox 1,180 + 360, staging 1,180 + 197, audit 1,163 + 180 |

Estimated at 5,000,000 LBT a day from those bytes (ordinary creates only; InnoDB sizes move in pages, so
the MariaDB figure is coarse): **MariaDB ≈ 80 GB/day of table+index growth and ≈ 100 GB/day of binary
log; PostgreSQL ≈ 41 GB/day and ≈ 58 GB/day of WAL**; 30 days ≈ 2.4 TB / 1.2 TB before retention. With the
30% reserve, provision ≈ 3.4 TB (MariaDB) or ≈ 1.7 TB (PostgreSQL) for a 30-day hot window, plus 2× the
largest table for an online rebuild. Replica network amplification equals the log bytes per replica.
Staging rows in the sample were not yet sequenced; in steady state they move to the journal and are
removed. Backup size and restore time come from the backup drills (`docs/operations/backup-restore.md`);
document-line, update-heavy and aged-data amplification are not measured here.

The **30% free-space reserve** is a readiness guardrail: set `KUMWE_DATABASE_DATA_PATH` to a directory on
the database data volume that the application host can see; `app:health` then warns (baseline) or fails
(`KUMWE_CAPACITY_PROFILE=enterprise`) when free space drops below 30%. Without the setting the guardrail
reports nothing and the operator must monitor the volume directly.
