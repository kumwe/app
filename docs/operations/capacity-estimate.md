# Capacity estimate from sampled concurrency

`P7-A` under [ADR 0021](../roadmap/decisions/0021-automated-acceptance-and-sampled-capacity.md): no
dedicated hardware and no 24-hour or 72-hour run is required. Every run of the concurrent harness records
what it measured on the host it ran on, fits a labelled scalability model to those measurements, and
estimates larger topologies with the uncertainty and the extrapolation boundary stated. **Measured results
and estimates are kept apart** in the JSON report (`sections.measured` vs `sections.estimated`) and in the
markdown summary (`## Measured` vs `## Estimated (model output, not measurement)`). No figure here is a
supported production capacity, availability or recovery guarantee.

## Running it

```bash
source .agent-env                         # disposable test database, APP_ENV=testing
php tools/perf-harness.php --concurrent --workers=1,2,4 --samples=20 --warmup=5 --repeats=2
# the same on an aged table: each workload is first seeded with the contract's declared dataset
php tools/perf-harness.php --concurrent --workers=1,2,4 --samples=20 --warmup=5 --repeats=2 \
    --dataset-records=2000 --dataset-seed=20260924
# outputs: build/perf/concurrent.json (schema: docs/operations/capacity-report.schema.json)
#          build/perf/concurrent.md   (the summary the capacity workflow publishes)
```

`--dataset-records` creates that many records per workload through the production record service before
the warm-up, then rewrites their creation and update instants to ages drawn by `tools/PerfDataset.php`: a
Mersenne Twister seeded with `--dataset-seed` (default 20260924) picks each row's bucket by the contract's
declared shares (last day 5%, last week 10%, last month 20%, last quarter 25%, last year 25%, one to three
years 15%) and a uniform age inside it. The report binds `dataset_seed` and `dataset_generator` (seed, row
count, distribution, bucket counts and a SHA-256 digest of the drawn ages) in `result_binding`, so two runs
can prove they ran on the same data.

`.github/workflows/capacity.yml` runs the same command on MariaDB, MySQL 8.4 and PostgreSQL 17 for
pull requests and `master` pushes touching its declared performance/runtime paths, merge groups, the
nightly schedule and manual dispatch. The ordinary sample is workers 1,2,4 × 30 samples × 3 repeats,
once on the fresh dataset and once on the declared 2,000-record aged dataset. Manual dispatch accepts
larger bounded inputs. It then samples create, update, ten-line document and aged-create storage/log/backup
amplification (`tools/perf-storage.php`), publishes results to the job summary and retains JSON reports,
raw per-call samples and worker logs for 14 days as `capacity-samples-<driver>` artifacts. Unavailable
measurements remain `null`; a green sample does not turn them into observed values.

## What is recorded

- **Hardware and runtime:** CPU model and exposed logical CPUs, host RAM, visible cgroup CPU/memory limits,
  load average at start, OS, PHP version and extensions, native engine version, runner kind, source commit
  and working-tree digest, harness, lock-file and capacity-contract digests.
- **Database:** driver, server version and an allowlist of durability and sizing settings (MariaDB/MySQL:
  `max_connections`, `innodb_buffer_pool_size`, `innodb_flush_log_at_trx_commit`, `sync_binlog`,
  `innodb_log_file_size`, `transaction_isolation`, `log_bin`, `binlog_format`, `innodb_lock_wait_timeout`;
  PostgreSQL: `max_connections`, `shared_buffers`, `work_mem`, `synchronous_commit`, `fsync`, `wal_level`,
  `max_wal_size`, `default_transaction_isolation`, `checkpoint_timeout`).
- **Design:** worker counts, measured calls per worker per repeat, warm-up calls, repeats; each worker is a
  separate PHP process with its own container and database session, released together by a barrier.
- **Measured per repeat:** attempted/successful/failed calls, successful and failed latency distributions
  (nearest-rank p50/p95/p99, sample standard deviation, CV), wall time from barrier to the last call,
  successful LBT per second, the maximum number of observed overlapping calls, independent sessions, and
  integrity (read-back of every acknowledged record, row count, contiguous sequence numbers).
- **Measured per worker count (`summary`):** mean throughput across repeats with a **Student t 95%
  confidence interval** (`mean ± t₀.₉₇₅,ₙ₋₁ · s/√n`, n = repeats), throughput CV, pooled latency
  distribution, total errors and maximum overlap.

## The model

For each operation the harness fits, by least squares over every repeat that passed all integrity and
overlap checks, the **Universal Scalability Law**

    X(N) = λN / (1 + σ(N − 1) + κN(N − 1))

where N is the number of concurrent callers sharing the one database, λ the single-caller throughput,
σ the contention (serialised fraction) and κ the coherence (crosstalk) penalty, using the linearisation
`N/X(N) = 1/λ + (σ/λ)(N − 1) + (κ/λ)N(N − 1)`. It also fits USL with σ fixed at 0 and **Amdahl's law**
(κ = 0). Fits with a negative σ or κ are physically meaningless; they are reported with a warning and never
used for prediction. The prediction model is the physically valid fit with the highest R² (computed on X),
labelled good (R² ≥ 0.8), fair (≥ 0.5) or poor. With three worker counts USL has three parameters and
little residual freedom; its R² then says little, and more worker counts or repeats are the remedy.

Predictions are made for 1–128 total concurrent callers. **N application servers each running W callers
against one database are N × W callers** — the model describes the shared database and hot rows, and it
assumes application CPU, network and connection limits beyond the measured host do not bind. Every
prediction outside the measured worker range is marked `extrapolation`. The implied daily figure is the
predicted rate × 86,400 against the 5,000,000 LBT/day planning target. The separate `estimates` array
keeps the older per-worker-count time extrapolation (repeat mean × 86,400, observed range, no multiplier).

## Limitations

- Short samples on shared hardware with the database, Redis and all workers on one host: the workers
  compete with the database for the same CPUs, which depresses throughput and inflates κ relative to a
  topology with a separate database host. The fitted curve is a property of this host, not of Kumwe.
- Two or three repeats give very wide t intervals (a lower bound below zero is reported as computed; it
  means the repeat mean is not well determined, not that throughput can be negative).
- The workload is single-site small creates and one shared legal-number counter, on a fresh table or on
  the declared 2,000-record aged table; document lines, reads, background work, retention and the
  four-business mix are not in the throughput figure (document-line storage amplification is measured
  separately, see `docs/operations/scale-topology.md`).
- Physical row-mutation amplification is measured separately by `tools/perf-storage.php` per workload
  (ordinary create: 8.0 PRM/LBT on MariaDB, 15.2 on PostgreSQL's database-wide counters; see
  `docs/operations/scale-topology.md`) and is not folded into these throughput figures.
- Extrapolations assume the same data size and configuration at every concurrency.

## Workflow sample, 2026-10-03

[Capacity run 37110028732](https://github.com/kumwe/app/actions/runs/37110028732) passed all three engines
on unchanged merged source [`4fb53353`](https://github.com/kumwe/app/commit/4fb533531c0c28bf4cbb902d9fa7214cdcf6986f).
The plan remained 1, 2 and 4 workers, 30 measured calls per worker per repeat, five warm-ups and three
repeats, on fresh and 2,000-record aged workloads with seed 20260924. Across both operations, both
datasets and all engines, 7,560 measured calls had zero failures; integrity checks passed and the
four-worker batches reached four overlapping calls. Each four-worker row pools 360 calls.

Each host exposed four logical CPUs and 15.6 GiB RAM, with PHP 8.5.11 and native engine 1.0.3. CPU models
differed: MariaDB used AMD EPYC 9V74, MySQL EPYC 9V45 and PostgreSQL EPYC 7763. The database and workers
shared each host. Database versions remained MariaDB 12.3.3, MySQL 8.4.11 and PostgreSQL 17.11;
`application_image_digest=null` still identifies source-workflow samples, not a qualified release image.

### Latest aged small-create throughput at four workers

| Engine | LBT/s mean | 95% CI | Throughput CV | p95 / p99 ms | Failed calls |
|---|---:|---|---:|---|---:|
| MariaDB | 168.5 | 20.2–316.9 | 0.35 | 88.4 / 108.6 | 0 |
| MySQL | 243.3 | 234.8–251.7 | 0.01 | 19.5 / 21.2 | 0 |
| PostgreSQL | 117.3 | 114.3–120.3 | 0.01 | 39.9 / 46.0 | 0 |

MariaDB's wide interval reflects substantial repeat variation. The higher MySQL rate than October 2
comes from the same source on a different host; neither change establishes a product improvement or
regression. Durability still differs: MariaDB has `log_bin=0`, `sync_binlog=0`; MySQL has `log_bin=1`,
`sync_binlog=1`; PostgreSQL has `fsync=on`, `synchronous_commit=on`. These are not an engine ranking under
identical production conditions or an observed daily capacity.

### Latest create and document storage samples

The run sampled 200 LBT per workload. A document contains one header and ten owned lines. These are
net allocated table/index growth including platform ledgers, not raw record payloads or physical writes.

| Engine | Workload | Table + index bytes/LBT | WAL bytes/LBT | Logical dump growth bytes/LBT |
|---|---|---:|---:|---:|
| MariaDB | Create | 21,626.9 | unavailable | unavailable |
| MariaDB | Ten-line document | 23,183.3 | unavailable | unavailable |
| MySQL | Create | 6,307.8 | unavailable | 7,148.0 |
| MySQL | Ten-line document | 12,943.4 | unavailable | 8,121.3 |
| PostgreSQL | Create | 9,543.7 | 15,418.5 | unavailable |
| PostgreSQL | Ten-line document | 9,625.6 | 17,734.1 | unavailable |

MariaDB's create allocation rose from 11,386.9 to 21,626.9 bytes/LBT on unchanged source. Coarse page
and extent allocation makes these short storage deltas variable; use repeated representative samples
and a declared workload mix for sizing. MySQL-family binary-log growth and MariaDB/PostgreSQL logical
dump growth remain unavailable, not zero. Update and aged-create measurements remain in the full reports.

The latest artifacts are [capacity-samples-mariadb](https://github.com/kumwe/app/actions/runs/37110028732/artifacts/11269676254),
[capacity-samples-mysql](https://github.com/kumwe/app/actions/runs/37110028732/artifacts/11269526472) and
[capacity-samples-pgsql](https://github.com/kumwe/app/actions/runs/37110028732/artifacts/11269017835), retained until October 17.
They contain fresh/aged summaries, storage reports and raw observations/logs. The October 2 sample below
is retained for comparison with its own host and measurement limits.

## Workflow sample, 2026-10-02

[Capacity run 36987101673](https://github.com/kumwe/app/actions/runs/36987101673) passed all three engines
on merged source [`4fb53353`](https://github.com/kumwe/app/commit/4fb533531c0c28bf4cbb902d9fa7214cdcf6986f).
Each separate workflow host exposed 4 AMD EPYC 7763 logical CPUs and 15.6 GiB RAM, running PHP 8.5.11
with native engine 1.0.3; the database and workers shared that host. MariaDB was 12.3.3, MySQL 8.4.11 and
PostgreSQL 17.11. The archived JSON records exact configuration and digests; these are source-workflow
samples, with `application_image_digest=null`, not measurements of a qualified release image.

The plan was 1, 2 and 4 workers, 30 measured calls per worker per repeat, five unmeasured warm-up calls,
and three repeats, on fresh and aged tables. Each operation had 630 measured calls per dataset per
engine; the four-worker rows below each pool 360 calls. All observed calls succeeded, integrity checks
passed, and four-worker batches reached four overlapping calls on independent database sessions.
The aged workload was seeded with 2,000 records per operation using seed 20260924 and age digest
`266d674e6ccbda6900d3fef234ec02c0d59bdf2a742d2a3771e99b62bbb6f140`.

### Measured aged throughput at four workers

| Engine | Operation | LBT/s mean | 95% CI | Throughput CV | p95 / p99 ms | Failed calls |
|---|---|---:|---|---:|---|---:|
| MariaDB | Ordinary small create | 145.7 | 137.2–154.2 | 0.02 | 32.5 / 36.7 | 0 |
| MySQL | Ordinary small create | 137.2 | 133.1–141.2 | 0.01 | 34.8 / 38.8 | 0 |
| PostgreSQL | Ordinary small create | 118.5 | 116.4–120.5 | 0.01 | 38.7 / 44.0 | 0 |
| MariaDB | Create with one shared legal-number counter | 130.0 | 126.4–133.7 | 0.01 | 36.4 / 44.7 | 0 |
| MySQL | Create with one shared legal-number counter | 116.1 | 111.4–120.7 | 0.02 | 39.9 / 46.7 | 0 |
| PostgreSQL | Create with one shared legal-number counter | 108.5 | 107.1–109.8 | 0.01 | 42.2 / 49.3 | 0 |

These short observed rates exceed the planning target's arithmetic equivalent of 57.9 LBT/s. Extending
them to an entire day would be an estimate, not an observed daily workload. The complete fresh/aged
reports include the other worker counts, model fits and extrapolation warnings. This result gives no
multi-site, large-document, mixed read/write, replica or continuous-production capacity guarantee.

The engines also had different durability settings: MariaDB reported `log_bin=0`, `sync_binlog=0`,
MySQL `log_bin=1`, `sync_binlog=1`, and PostgreSQL `fsync=on`, `synchronous_commit=on`. Both MySQL-family
engines used `innodb_flush_log_at_trx_commit=1`. The rows must not be presented as a ranking under
identical production durability or topology.

### Measured storage samples

The same run sampled 200 LBT per workload. A document LBT comprised one header and ten owned lines.
The table reports net allocated table/index growth per logical transaction, including platform ledgers;
it is neither just the business record's payload size nor physical bytes written to disk.

| Engine | Workload | Table + index bytes/LBT | WAL bytes/LBT | Logical dump growth bytes/LBT |
|---|---|---:|---:|---:|
| MariaDB | Create | 11,386.9 | unavailable | unavailable |
| MariaDB | Update | 819.2 | unavailable | unavailable |
| MariaDB | Ten-line document | 23,347.2 | unavailable | unavailable |
| MariaDB | Aged create | 16,957.5 | unavailable | unavailable |
| MySQL | Create | 6,225.9 | unavailable | 7,147.8 |
| MySQL | Update | 409.6 | unavailable | 5,300.4 |
| MySQL | Ten-line document | 12,943.4 | unavailable | 8,121.3 |
| MySQL | Aged create | 11,796.5 | unavailable | 7,147.5 |
| PostgreSQL | Create | 8,683.5 | 15,969.4 | unavailable |
| PostgreSQL | Update | 5,939.2 | 12,333.0 | unavailable |
| PostgreSQL | Ten-line document | 9,748.5 | 17,823.8 | unavailable |
| PostgreSQL | Aged create | 8,765.4 | 13,626.4 | unavailable |

MySQL-family binary-log growth was unavailable. MariaDB and PostgreSQL logical dump observations were
also unavailable (`dump_tool=null`); only MySQL retained a measured dump delta. Those omissions are
visible limits of this run, not zero log or backup cost. InnoDB allocation moves in pages/extents, so
some updates show no new table pages despite real writes; PostgreSQL tuple counts are database-wide,
and MySQL-family handler counts include internal temporary tables. Use the complete reports and a
representative workload mix before sizing storage or comparing physical mutation counts.

Download `capacity-samples-mariadb`, `capacity-samples-mysql` and `capacity-samples-pgsql` from the linked
run while retained. Each contains `concurrent-fresh.json/.md`, `concurrent-aged.json/.md`,
`storage-<driver>-{create,update,document,aged}.json` and raw worker observations/logs. Rerun the existing
workflow for new source or an operator topology; no long endurance prerequisite is added.

## Local run, 2026-09-24

Host: 4 × Intel Xeon @ 2.80 GHz, 15.7 GiB RAM, no container limits visible; PHP 8.5.10 with
`kumwe_engine` 1.0.3; MariaDB 10.11.14 (binlog on, `innodb_flush_log_at_trx_commit=1`) and PostgreSQL
16.15, both stock and on the same host. Plan: workers 1, 2, 4; 20 measured calls per worker per repeat
after 5 warm-up; 2 repeats. All 12 repeats per engine passed integrity and overlap checks, 0 errors.

### Measured

| Engine | Operation | Workers | LBT/s mean | 95% CI | CV | p50 / p95 / p99 ms |
|---|---|---|---|---|---|---|
| MariaDB | ordinary_small_mutation | 1 | 17.0 | 0.8–33.1 | 0.11 | 41.6 / 117.6 / 119.9 |
| MariaDB | ordinary_small_mutation | 2 | 30.8 | 26.9–34.7 | 0.01 | 49.6 / 151.9 / 221.3 |
| MariaDB | ordinary_small_mutation | 4 | 24.9 | −14.3–64.2 | 0.17 | 131.7 / 325.8 / 531.4 |
| MariaDB | hot_sequence_commit | 1 | 19.7 | −15.7–55.1 | 0.20 | 41.2 / 88.9 / 274.1 |
| MariaDB | hot_sequence_commit | 2 | 19.5 | −14.8–53.9 | 0.20 | 86.6 / 244.7 / 617.7 |
| MariaDB | hot_sequence_commit | 4 | 25.5 | 11.1–39.9 | 0.06 | 133.2 / 261.8 / 406.0 |
| PostgreSQL | ordinary_small_mutation | 1 | 34.8 | −15.9–85.5 | 0.16 | 26.7 / 47.4 / 58.1 |
| PostgreSQL | ordinary_small_mutation | 2 | 31.1 | −6.6–68.8 | 0.13 | 42.8 / 147.8 / 203.5 |
| PostgreSQL | ordinary_small_mutation | 4 | 47.1 | −17.8–112.1 | 0.15 | 66.7 / 183.4 / 253.2 |
| PostgreSQL | hot_sequence_commit | 1 | 26.1 | −53.6–105.9 | 0.34 | 34.3 / 66.1 / 177.4 |
| PostgreSQL | hot_sequence_commit | 2 | 35.4 | −60.0–130.8 | 0.30 | 46.3 / 183.9 / 230.1 |
| PostgreSQL | hot_sequence_commit | 4 | 41.5 | −3.1–86.1 | 0.12 | 80.7 / 195.8 / 267.4 |

### Estimated (model output, not measurement)

| Engine | Operation | Model (quality) | λ | σ | κ | R² | 8 callers* | 16* | 64* | Daily at best predicted N |
|---|---|---|---|---|---|---|---|---|---|---|
| MariaDB | ordinary | USL σ=0 (fair) | 18.6 | 0 | 0.168 | 0.79 | 14.3 | 7.2 | 1.8 | peak N≈2.4 at 28.6 LBT/s: ≈ 2.5 M/day (49%) |
| MariaDB | hot sequence | Amdahl (poor) | 16.9 | 0.572 | 0 | 0.27 | 27.0 | 28.2 | 29.2 | asymptote 29.5 LBT/s: ≈ 2.5 M/day (51%) |
| PostgreSQL | ordinary | Amdahl (poor) | 27.5 | 0.488 | 0 | 0.25 | 49.9 | 53.0 | 55.5 | asymptote 56.4 LBT/s: ≈ 4.9 M/day (97%) |
| PostgreSQL | hot sequence | USL (fair) | 24.6 | 0.452 | 0.0029 | 0.50 | 45.6 | 46.6 | 38.5 | peak N≈13.9 at 46.6 LBT/s: ≈ 4.0 M/day (81%) |

\* LBT/s, extrapolations beyond the measured 1–4 callers. On this single 4-CPU host no model reaches the
5,000,000 LBT/day planning target within its valid region, and the MariaDB ordinary-create fit predicts
retrograde throughput past about 2–3 callers — consistent with four PHP workers and the database
competing for four CPUs rather than with a database-side limit. The poor and fair fit qualities and the
wide intervals mean these estimates only rank bottlenecks on this host; a larger sample on the capacity
workflow, or an operator run with the database on its own host, is required before any planning use.
Raw reports: `build/perf/concurrent-mariadb.json` and `build/perf/concurrent-pgsql.json` (not committed).

## Aged dataset sample, 2026-09-24

Same host and plan (workers 1, 2, 4; 20 measured calls per worker per repeat after 5 warm-up; 2 repeats),
with each workload first seeded with the declared dataset: seed 20260924, 2,000 records, age digest
`266d674e6ccbda6900d3fef234ec02c0d59bdf2a742d2a3771e99b62bbb6f140` on both engines. Seeding took 68 s and
148 s on MariaDB and is not timed. All 12 repeats per engine passed integrity and overlap checks, 0 errors.

### Measured

| Engine | Operation | Workers | LBT/s mean | 95% CI | CV | p50 / p95 / p99 ms |
|---|---|---|---|---|---|---|
| MariaDB | ordinary_small_mutation | 1 | 17.3 | −62.9–97.4 | 0.52 | 52.0 / 135.3 / 236.9 |
| MariaDB | ordinary_small_mutation | 2 | 37.0 | 5.3–68.8 | 0.10 | 51.1 / 98.0 / 118.2 |
| MariaDB | ordinary_small_mutation | 4 | 49.3 | 21.9–76.6 | 0.06 | 66.4 / 152.4 / 189.9 |
| MariaDB | hot_sequence_commit | 1 | 22.7 | −52.9–98.2 | 0.37 | 34.4 / 110.8 / 217.8 |
| MariaDB | hot_sequence_commit | 2 | 37.9 | 13.5–62.3 | 0.07 | 43.8 / 95.8 / 176.6 |
| MariaDB | hot_sequence_commit | 4 | 37.9 | 24.1–51.7 | 0.04 | 81.0 / 230.1 / 322.3 |
| PostgreSQL | ordinary_small_mutation | 1 | 33.6 | −37.0–104.3 | 0.23 | 25.6 / 53.7 / 71.1 |
| PostgreSQL | ordinary_small_mutation | 2 | 59.5 | 46.7–72.4 | 0.02 | 26.3 / 51.7 / 76.5 |
| PostgreSQL | ordinary_small_mutation | 4 | 82.4 | −176.4–341.3 | 0.35 | 39.5 / 106.0 / 134.0 |
| PostgreSQL | hot_sequence_commit | 1 | 46.4 | −7.6–100.5 | 0.13 | 20.5 / 27.9 / 32.7 |
| PostgreSQL | hot_sequence_commit | 2 | 38.8 | −3.8–81.4 | 0.12 | 40.8 / 100.7 / 203.5 |
| PostgreSQL | hot_sequence_commit | 4 | 34.4 | 22.1–46.7 | 0.04 | 92.2 / 250.8 / 447.8 |

### Estimated (model output, not measurement)

| Engine | Operation | Model (quality) | λ | σ | κ | R² | 8 callers* | 16* | 64* | Best predicted |
|---|---|---|---|---|---|---|---|---|---|---|
| MariaDB | ordinary | USL σ=0 (good) | 16.8 | 0 | 0.0285 | 0.86 | 51.8 | 34.3 | 9.3 | peak N≈5.9* at 54.4 LBT/s ≈ 4.7 M/day (94%) |
| MariaDB | hot sequence | USL σ=0 (fair) | 22.1 | 0 | 0.110 | 0.78 | 24.6 | 12.9 | 3.2 | peak N≈3.0 at 39.9 LBT/s ≈ 3.4 M/day (69%) |
| PostgreSQL | ordinary | USL σ=0 (fair) | 33.0 | 0 | 0.0585 | 0.71 | 61.7 | 35.1 | 8.9 | peak N≈4.1 at 77.5 LBT/s ≈ 6.7 M/day (134%) |
| PostgreSQL | hot sequence | USL (fair) | 46.0 | 1.33 | 0.0311 | 0.71 | 30.6 | 25.9 | 14.0 | N=1 at 46.0 LBT/s ≈ 4.0 M/day (80%) |

\* Extrapolation beyond the measured 1–4 callers. The aged table did not lower single-site throughput on
this host: every aged mean lies inside the fresh run's wide intervals, and the higher aged means at two and
four callers are within the host's run-to-run variation rather than an effect of age. The hot-sequence
fits are still retrograde past two to four callers, as on the fresh table, because every commit serializes
on one counter row. As before, the models rank bottlenecks on one shared 4-CPU host; they are not capacity
guarantees. Raw reports: `build/perf/concurrent.json` from each run (not committed).
