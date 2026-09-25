# Recovery integration handoff — PR #152

Scope: P6-A/B, V2-DR-001..004 and GM-BAK-04/08. Base `31349fec`; isolated detached worktree
`/workspace/scratch/3e82709089f7/kumwe-recovery`. The filesystem is shared, but edits stay in this worktree.
No parent worktree files, ContainerFactory, package sources, shipped migrations, generated baselines or
programme records changed. No push, PR creation, merge, shared branch or shared index change was made.

## Patch order

- `recovery-runtime-v2.patch`: complete replacement for the initial `recovery-runtime.patch`, against
  `31349fec`. Apply this if no recovery patch has been integrated.
- `recovery-followup-v2.patch`: only changes since the previously delivered **full**
  `recovery-runtime.patch`. Apply this if that full patch is already present. It is not relative to the
  old `recovery-followup.patch`, and it removes the introduced Python test.
- Do not apply both v2 files. Do not also apply the obsolete initial/follow-up patches after v2.
  Run `git apply --check PATCH`, review scope, then `git apply PATCH`. Parent owns conflict resolution
  against its concurrent work, integration commits, publishing and baseline/full-CI updates.

### v3 follow-up (apply after either v2 integration route)

`recovery-followup-v3.patch` is a small delta against the exact delivered v2 tree. Apply it once, after
either v2 patch above; it is not a replacement full patch. The original v2 files remain unchanged.
It tightens PostgreSQL's actual replay-pause check, refuses MySQL GTID overlap before database import,
preserves multiline native GTID sets, and forwards stdin to the real Docker MySQL client. The MySQL
lane includes a second native GTID origin and proves an empty database on a previously used instance
cannot silently skip the replay. No new production API, DI binding or Dockerfile change is needed.

Changed paths in this follow-up only:

- `.github/workflows/recovery.yml`
- `docs/operations/backup-restore.md`
- `docs/operations/recovery-integration.md`
- `tests/Support/recovery-native-drill.sh`
- `tools/backup.sh`
- `tools/recovery-postgres.sh`
- `tools/restore.sh`

The 2026-09-24 v3 rerun used `KUMWE_SETUP_BINLOG=1 ../kumwe-setup/with-services.sh` and fresh, separate
MariaDB clusters. Before/after native replay, sixteen tamper refusals, missing-archive/newer-target
refusals, SIGKILL/resume, retention, failed-cycle resumption and Restic restore/dedup measurement passed.
The focused PHPUnit shell guard test remains 1 test / 4 assertions. This MariaDB result does not validate
the new MySQL GTID branch or PostgreSQL pause query: their matching native workflow lanes remain pending.
The previously executed real App approval and authenticated HTTP evidence below remains applicable;
those code paths are unchanged by v3. The parent still owns integrated CI and baseline updates.

## Capability reuse review

No production PHP class, public App API, route, DI binding or portable package API is added. The only
new PHP class is test support. The locked ownership inventory is
`ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826`.
Installed charters/source and `composer show --locked "kumwe/*"` were inspected: business-schema v0.1.3
assigns operational recovery to the host; approval v0.1.2 owns request/approval/consumption semantics;
automation v0.2.2 owns portable scheduling; idempotency v0.1.3 owns ledger contracts and assigns
retention/scheduling/external-effect recovery to the host. This change reuses those existing boundaries.
No Core Growth Record is needed for these shell adapters and test support.

## Completed implementation

- v3 plain trees, exact authenticated file/directory inventories, uncompressed logical dumps; v2 reads.
- Native binlog coordinates/GTIDs and PostgreSQL physical base/LSN/timeline/named restore points.
- Signed native archives, coherent selected payload checkpoints, unreachable/newer-target refusal.
- Isolated binlog destination requires `--skip-log-bin`; no global GTID reset or topology mutation.
- Restore claims bind source and destination identities; foreign/overlapping targets stay refused.
- Backup scheduling, verified offsite hook, positive safe retention, failure-path writer resumption.
- Real native fixture across binlog file boundaries, after-target transaction exclusion, archive gaps,
  tamper refusals, SIGKILL/resume, retention and reference Restic measurement.
- Python test replaced by repository PHP/shell conventions; no command or protocol doubles remain.
- Real consumed-approval fixture through existing administration and `ApprovalService`, including
  same-fresh-proof positive control after restore. No approval administration issue blocks this.
- Real authenticated HTTP seed/replay driver and a production-service fixture issuer with exact
  organization membership, required `Kumwe-Site`, CSRF login, original idempotency key and ETag checks.
- Separate native recovery workflow and opt-in MariaDB/PostgreSQL recovery Docker targets.

## Executed evidence (local, 2026-09-24)

- MariaDB server/client **10.11.14**: before/after restore across two real archived binlogs, a third
  committed but excluded transaction, signed empty-archive refusal, all sixteen tamper refusals,
  interrupted import publication/resume, verified retention, failed offsite cycle with writer resume.
- Restic **0.16.4**: first `data_added` 4,206,264 bytes; second 9,076 bytes for a small media change in
  a 4,198,871-byte logical snapshot. The second repository snapshot was restored and verified.
  This client reports uncompressed added data, not packed storage bytes; no packed-byte claim is made.
- Real migrated App backup/restore: consumed approval refused, same fresh proof consumed the approved
  sibling, source/restored canonical manifests equal, typed replay, limited login/authorization,
  stale session refusal, two second-factor replay refusals, restored queue/scheduler work.
- Real App HTTP source and restored database: CSRF login, authenticated administrator access, fresh
  source mutation, repeated source replay and restored original-key replay. Runtime validation used
  an isolated copy with the parent's login handler/template overlay; those parent paths are not in this
  patch. The HTTP fixture has no business-record approval (`not_applicable`); generic approval spent
  state is proved independently by the real service-level drill above.
- PHP 8.5.10: focused PHPUnit **1 test / 4 assertions**, executing eleven real filesystem/refusal cases.
  Scoped PHPStan, PHPCS, docblock verification, Bash syntax and diff-whitespace checks passed.

These small fixtures are correctness evidence, not a production-volume RTO or release qualification.

## Residual validation / parent integration

- MySQL 8.4 and PostgreSQL 17 native lanes are implemented, **not locally passed**. Docker is unavailable
  here; the local sandbox maps only UID 0 and refuses another OS identity, so PostgreSQL cannot start
  under its mandatory non-root account. The workflow uses matching 17 tools as the ordinary runner.
- Optional recovery Docker targets are implemented, not locally built. Parent CI must build/check them
  with the integrated Dockerfile. MariaDB's optional image client must match the deployment's server.
- Parent runs the native workflow and integrated HTTP/App lanes plus full CI and baseline integration.
  Automated evidence and maintainer merge are the sole acceptance gate; no manual Safari/RTL checklist.
  Do not close PostgreSQL/MySQL validation or production-scale RTO from the local MariaDB evidence.
  Programme closure, when justified, cites (#152).

## Exact parent wiring

No ContainerFactory change is required. Deploy all `tools/recovery-*.sh` siblings with backup/restore;
Docker's existing whole-tree copy does this. Optional targets are `recovery-mariadb` and
`recovery-postgres`; MySQL operators provide native Oracle 8.4 clients. `BusinessRuntimeBackupAcceptance`
already calls the new approval seed/manifest/accept hooks; its existing CI invocation gains that evidence
without another service binding. The added test-support file is resolved by the existing drill autoloader.

The separate `recovery.yml` workflow owns native engine proof. Main CI is untouched. For HTTP integration,
run `KUMWE_RECOVERY_FIXTURE_DISPOSABLE=yes php tests/Support/recovery-http-fixture.php NEW_PRIVATE_DIR`
in a disposable source before backup. Use its `email`, `password`, `token` and `request.json`; run
`tools/restore-http-drill.sh seed REQUEST RECEIPT` with the receipt in private application storage.
Quiesce and back up, restore to an isolated App, then run `verify` with the same credentials/request and
restored receipt before another acceptance command changes the fixture's token, record or policy.
Optional `approval_request_id` must name a real business-record workflow exposed by its API; absence is
explicitly not applicable. Generic approvals are exercised by the PHP workflow acceptance separately.

Use `KUMWE_SETUP_BINLOG=1 ../kumwe-setup/with-services.sh COMMAND` for further environment-agent service
runs. Keep startup and tests within that invocation and avoid the parent's actively used databases.
The native harness itself only owns newly initialized clusters and scratch targets.

## Every changed path (complete v2 slice)

- `.github/workflows/recovery.yml`
- `docker/php/Dockerfile`
- `docs/operations/backup-restore.md`
- `docs/operations/recovery-integration.md`
- `docs/operations/systemd/kumwe-backup.service`
- `docs/operations/systemd/kumwe-backup.timer`
- `tests/Support/BusinessRuntimeBackupAcceptance.php`
- `tests/Support/RestoreApprovalAcceptance.php`
- `tests/Support/backup-tamper-drill.sh`
- `tests/Support/recovery-http-fixture.php`
- `tests/Support/recovery-native-drill.sh`
- `tests/Support/recovery-tools-test.sh`
- `tests/Unit/Tools/RecoveryToolsTest.php`
- `tools/backup-cycle.sh`
- `tools/backup-dedup-drill.sh`
- `tools/backup-retain.sh`
- `tools/backup.sh`
- `tools/recovery-binlog.sh`
- `tools/recovery-common.sh`
- `tools/recovery-postgres.sh`
- `tools/restore-http-drill.sh`
- `tools/restore-interruption-drill.sh`
- `tools/restore-pitr-drill.sh`
- `tools/restore-verify.sh`
- `tools/restore.sh`

The v2 follow-up additionally deletes `tests/Tools/recovery_test.py` introduced by v1; it is absent from
the combined v2 slice against `31349fec`.
