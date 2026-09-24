# Sampled performance evidence

`P7-A` and [ADR 0021](roadmap/decisions/0021-automated-acceptance-and-sampled-capacity.md) use the hardware
available to the workflow. The five-million logical-business-transaction daily profile remains a planning
target. A short sample and its daily extrapolation do not establish supported production capacity.

## Run the concurrent workload

Use a disposable database, PHP 8.5 with the admitted native runtime, locked development dependencies,
Redis and the complete testing environment. Install the parent schema and migrate before sampling.
The tool refuses unless `APP_ENV=testing`. It creates unique definitions and records; use a dedicated
test database rather than a production copy carrying sensitive data.

```bash
source .agent-env
php tools/perf-harness.php --concurrent --plan
php tools/perf-harness.php --concurrent --workers=1,2,4 --samples=30 --warmup=5 --repeats=3
```

Samples and warm-up counts are **per worker, per repeat, per operation**. The default records 30 measured
attempts per worker after five warm-up attempts, repeating each worker count three times. Bounds are
1–16 workers, 1–1000 samples, 0–100 warm-ups, 2–10 repeats and a 1–600 second deadline per phase.
Smaller-than-30 samples are explicitly flagged. The sample plan does not claim the four-business
distribution of the serial harness's reference plan.

The two operation classes call the real `BusinessRecordService`: ordinary small creates and numbered
creates contending on one legally constrained counter. Each worker boots its own production container,
authenticates through the existing test helper and owns a distinct database session. The coordinator
installs fixtures once; workers do not race migration or fixture installation. Warm-up finishes before
the coordinator releases a shared barrier. Files carry the handshake; subprocess arguments never carry
database credentials or the application secret.

The output is `build/perf/concurrent.json`. Its `raw_worker_results` paths locate the retained per-call
monotonic intervals and outcomes. Logs and raw observations live beneath `build/perf/samples-<nonce>/`.
The coordinator reports early exits, deadlines, call failures, unexpected replays, missing results,
datastore/session mismatches, missing overlap and record/sequence integrity failures. Any such failure
returns nonzero and withholds the affected group's estimate; no retry removes an inconvenient sample.
Workers are terminated and reaped on deadline failure.
After each batch an atomic checkpoint preserves partial evidence with `complete: false` and `passed: false`.
Only finishing every requested batch permits a complete report, and only error-free batches permit a pass.

Integrity checks run outside timing: every acknowledged record is read back, unique identities and
stored row counts are compared, and generated numbers must remain contiguous across warm-up and measured
commits. This proves those invariants only; it does not claim complete ledger or external-effect coverage.
Fixtures remain subject to the existing test-definition retirement scope. Use disposable databases to
reclaim accumulated records and physical tables.

## Read the report

- **Measured:** successful commits divided by elapsed wall time from barrier release to the final call;
  attempted, successful and failed counts; successful and failed latency distributions; observed maximum
  in-flight calls; distinct database sessions; repeat variation and raw samples.
- **Estimated:** mean observed commits/second multiplied by 86,400 at the same measured worker count.
  The observed repeat range is scaled the same way. It is not a confidence interval, a prediction for
  another machine or worker count, or evidence of sustained daily throughput.
- **Unknown:** physical row-mutation amplification, internal retry counts, database saturation and IOPS,
  larger-data behavior, availability and recovery. Missing measurements stay null or explicitly unmeasured.

Latency percentiles use nearest rank. At small sample sizes p99 is commonly the maximum; three repeated
batches do not justify a narrow confidence claim. Standard deviation uses the sample denominator `n−1`;
a singleton has unknown variance. High variation is published, not discarded to meet a historical CV target.
Observed call overlap includes time waiting for locks and does not imply parallel execution inside the
database. No CPU, RAM or worker-count multiplier is applied to a shared database or one hot sequence.

Metadata records the commit, tracked-diff digest, harness/helper/lock/contract digests, CPU model and exposed
logical CPUs, host RAM, visible cgroup limits, runtime versions and an allowlist of database settings.
Unobservable container limits and the absent application-image digest remain null. This is source-checkout
evidence, not exact release-artifact qualification.

## Workflow and residual scope

`.github/workflows/capacity.yml` runs the short sample on pull requests and default-branch changes against
MariaDB, MySQL and PostgreSQL. Its dispatch inputs allow larger bounded runs. Artifacts retain reports,
raw samples and diagnostics even on failure. Absolute timing is descriptive; correctness and actual overlap
are the gate. The existing serial `--run` and `--breakpoint` modes retain their separate reports.

The default sample does not exercise document lines, reads, aged data, four business scopes, background
workers, retention, outages or long-duration load. Those dimensions remain open; neither the tool nor this
document declares Gate B passed. Maintainer merge of (#152) accepts the implementation and actual evidence,
without an additional manual hardware or browser acceptance checklist.

## Capability reuse and ownership

The locked inventory contains `kumwe/sequence 0.2.1`, `kumwe/transaction 0.1.2`, `kumwe/record-model 0.1.4`
and `kumwe/computation 0.3.3`; capability index digest
`ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826`.
The Sequence and Transaction charters and public manifests retain ownership of numbering declarations,
allocator and transaction ports. The workload reuses App's existing `BusinessRecordService`, commands,
`NeutralBusinessFixture`, authentication helper and production container, so those package/runtime
responsibilities have no second implementation. Statistics, worker lifecycle and host metadata are
development-harness functions under `tools/`; no production class or public runtime signature is added,
and no Core Growth Record or container wiring change is required.
