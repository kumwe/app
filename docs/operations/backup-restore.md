# Backup and restore

A complete Kumwe backup contains:

- an uncompressed database dump in the native supported format;
- plain directory trees for media, private application data (including immutable report-export objects),
  installed extension/template code, and published extension assets;
- a versioned manifest identifying release, database driver, database name, and table prefix;
- exact SHA-256 checksums;
- an optional Minisign signature over the checksum file.

The database payload includes every generated business entity, junction, and ordered-line table together with
business-schema installations, plans, step journals, fences, recovery evidence, record revisions, command
idempotency outcomes, append-only report-export metadata, and audit rows. JSON control documents are canonical
metadata or historical snapshots;
authoritative business fields remain in their typed physical columns.

Application secrets, Redis data, application images, and signing private keys are never deliberately included.
Keep secrets outside the payload trees; a physical PostgreSQL base can contain database credentials and configuration. Private application data is
not a secrets directory: it contains durable, non-public runtime artifacts such as report-export objects. Their
versioned ownership, policy snapshot, expiry, and checksum metadata live in the relational database. Redis is
disposable coordination state; the relational database is authoritative.

## Supported formats

| Driver | Backup format | Required client tools |
|---|---|---|
| `mariadb` | Transactional SQL | `mariadb`, `mariadb-dump` tested as compatible with the server release |
| `mysql` | Transactional SQL | `mysql`, `mysqldump` compatible with MySQL 8.4 |
| `pgsql` | PostgreSQL custom archive | `psql`, `pg_dump`, `pg_restore` matching the server major line |

New snapshots use `kumwe-backup-v3`: `database.dump`, `media/`, `private/`, `extensions/`,
`extension-assets/`, `manifest.json`, `checksums.sha256`, and an optional Minisign signature.
The signed manifest includes every directory, including empty directories; the checksum inventory covers
exactly every regular file. Added files, missing files, altered bytes, directory drift, traversal paths,
symlinks and special files are refused. Version 2 compressed snapshots remain readable.

A backup restores only to the same driver recorded in its manifest. Engine conversion is a separate logical migration and validation exercise.

## Recovery objectives

The default mode is a full, write-quiesced snapshot. The optional native replay mode adds a physical
PostgreSQL base or binlog coordinate; application payload checkpoints remain write-quiesced.

**Recovery point.** The recovery point is the instant the snapshot was taken, so the recovery point
objective *is the backup interval*. Continuous archiving is configured on the database server, consumed by the native replay adapters below.
There is no application-side write log. With the reference schedule below — hourly
backups — the shipped posture is:

| Objective | Declared value | What meets it |
|---|---|---|
| RPO | **60 minutes**, equal to the backup interval | `tools/backup.sh` on the reference hourly schedule; every write after the last completed snapshot is lost |
| RTO | **60 minutes** for a database up to 20 GB on comparable hardware | `tools/restore-verify.sh` + `tools/restore.sh` + key provisioning + `extension:runtime:materialize` + acceptance |
| Backup write outage | **the duration of one snapshot**, measured and recorded per drill | the quiesce `tools/backup.sh` requires; it refuses to run without `KUMWE_BACKUP_CONSISTENCY=quiesced` |

Those are the numbers the shipped tooling can be held to. Measure your own: the drill records
`backup_quiesce_seconds` and `restore_seconds` into the schema recovery evidence it writes (see
*Recovery acceptance*), so the declared RTO can be replaced with a figure from your own data volume and
hardware instead of an estimate. An installation whose measured restore exceeds the declared RTO has
found a real gap and should say so in its own runbook rather than restate this table.

The write outage lasts until the database and all payload trees have been captured. The shipped hourly
snapshot schedule does not by itself provide continuous filesystem recovery. Native log archives improve
database recovery granularity; a coherent filesystem checkpoint is still required.

## Platform-supported, operator-configured PITR

Set `KUMWE_BACKUP_PITR=on` while all application writes, workers, schedulers, filesystem writers and schema
changes are quiesced. The default `off` produces a logical snapshot with no claim to support log replay.
Archive destinations, credentials, immutable offsite copies and archive retention remain operator-configured.
No application log or imitation WAL replay is used.

**A target later than the payload snapshot is refused; select a verified coherent payload snapshot.**
The supported target is the exact native coordinate recorded in an authenticated v3 payload snapshot.
Choose a base snapshot and set `KUMWE_RESTORE_PAYLOAD_BACKUP` to a later (or the same) signed snapshot.
`KUMWE_RESTORE_TARGET_TIME`, when supplied, must equal that snapshot's `payload_snapshot_at`. Arbitrary
wall-clock targets and a blanket unsafe override are not supported. This also prevents an older database
from being paired with files already deleted at a newer payload checkpoint. All four payload trees come
from the selected target, while the database starts at the base and replays native logs.

### MariaDB and MySQL

Configure ROW binary logging and an offsite archive of complete, unmodified, unencrypted binlog files.
Encrypted native binlog files are unsupported by this local-file decoder; protect the archive with
encrypted storage and transport instead. Keep every file
from the oldest retained recovery base through the newest target. While quiesced, backup records the native
file, position, GTID set and source identity. MySQL 8.4 uses `SHOW BINARY LOG STATUS`; MariaDB uses
`SHOW MASTER STATUS`. MySQL's `--set-gtid-purged=OFF` is compensated by recording the coordinate and GTID
set in the signed manifest; replay is position-based, not GTID auto-positioning.

Restore to an isolated instance started with **`--skip-log-bin`**, using the **same database name**.
The tool refuses a destination with binary logging enabled before import: local dump/import GTIDs can
otherwise collide with the archived source sequence under MariaDB strict GTID mode. Recovery does not
reset GTID state, modify global settings or bootstrap a replication topology. Renaming during row-based replay,
cross-schema statements and cross-source log chains are not supported. The source must keep a stable,
unique server identity (MariaDB `server_id`, MySQL `server_uuid`). The native decoder validates binlog
checksums, all numbered files in the range must exist, and the stop position must terminate a decoded
event. Missing files, truncated targets and decoder failures refuse before database import. Replay uses
`mariadb-binlog` or `mysqlbinlog` and retains transaction boundaries. Its session-level logging guard
does not replace the isolated-server prerequisite.
MySQL additionally checks that the base GTID set precedes the target set and that the intervening GTIDs
do not intersect the destination's executed history. Recreating a database after a partly applied replay
does not clear that history; use a fresh isolated instance if this refusal occurs. Recovery preserves
source GTIDs, does not skip them, and never resets global history to make a retry pass.
Run with recovery privileges on an isolated server, never on the source.

### PostgreSQL

Enable `wal_level=replica`, `archive_mode=on` and a tested `archive_command` or archive library that never
silently overwrites different bytes. Retain the complete WAL sequence, not only files newer than a chosen
snapshot. Use a matching server-major toolchain with `pg_basebackup`, `pg_verifybackup`, `pg_ctl` and `psql`.
The backup role needs replication rights, physical-control inspection and `pg_create_restore_point` rights.

PITR-enabled snapshots contain an additional **physical cluster base** in `pg-base/`, with streamed WAL and
a native backup manifest. Kumwe records its system identifier, timeline and start/end LSN, then creates a
named restore point while payload writes remain stopped. External tablespaces and cross-timeline recovery
are refused. Physical bases include the entire cluster, including PostgreSQL roles and potentially other
databases; use a dedicated cluster and restrict access accordingly. A logical `pg_dump` is never used as a
WAL replay base.

Set `KUMWE_RESTORE_PGDATA` to an absent directory owned by the database OS user and run recovery as that
user. The adapter verifies the physical base, uses a fresh recovery configuration with a private Unix
socket and no TCP listener, and waits for the exact named target to reach the actual `paused` state
reported by `pg_get_wal_replay_pause_state()`, not just a pending pause request. A missing/unreachable target
or timeout fails. Only after verifying the migration at that point does it promote and stop the cluster.
The default replay timeout is 300 seconds (`KUMWE_RESTORE_REPLAY_TIMEOUT`). The log remains in
`PGDATA/kumwe-recovery.log`. Review the restored server configuration and provision production secrets
before starting the stopped cluster for acceptance. A failed physical replay leaves its target for
inspection; use a fresh target and claim for the next attempt.

### Authenticate an immutable archive and replay

Materialize an archive into a restricted directory, finish all copying, then write `archive.json` with
`format: "kumwe-log-archive-v1"` and `source_id` exactly matching the signed base's `.pitr.source_id`.
Include only complete native log files for that source. With `tools/recovery-common.sh` sourced and a
`fail()` function that exits nonzero, `recovery_checksums /archive` produces the exact sorted inventory:

```bash
recovery_checksums /archive > /archive/checksums.sha256
minisign -S -s /run/secrets/backup-signing.key -m /archive/checksums.sha256 \
  -x /archive/checksums.sha256.minisig
export KUMWE_RESTORE_LOG_ARCHIVE=/archive
export KUMWE_RESTORE_PAYLOAD_BACKUP=/backups/SELECTED-TARGET
# Set all clean-target connection/filesystem variables documented below.
tools/restore.sh /backups/BASE
```

Archives must remain immutable throughout verification and replay, just like backup directories. Use
read-only mounts or an immutable offsite restore. Signature verification and exact inventory/checksums
precede replay. The completion manifest binds both snapshot identities, the selected native target and
the destination connection. Recovery never truncates or deletes the operator's native archive.

Upstream procedures: [MySQL event-position recovery](https://dev.mysql.com/doc/refman/8.4/en/point-in-time-recovery-positions.html),
[MariaDB binlog options](https://mariadb.com/docs/server/clients-and-utilities/logging-tools/mariadb-binlog/mariadb-binlog-options),
[MySQL GTID set functions](https://dev.mysql.com/doc/refman/8.4/en/gtid-functions.html),
[PostgreSQL recovery control](https://www.postgresql.org/docs/17/functions-admin.html#FUNCTIONS-RECOVERY-CONTROL),
and [PostgreSQL continuous archiving](https://www.postgresql.org/docs/17/continuous-archiving.html).

### Deployment tooling

The application Docker build copies the complete `tools/` directory, including the recovery helper
siblings. Optional `docker/php/Dockerfile` build targets `recovery-mariadb` and `recovery-postgres`
include jq, Minisign, Restic and native recovery tools; the ordinary FPM target keeps its existing
dependencies. PostgreSQL's target supplies server-major 17 tools, including `pg_ctl` and `pg_verifybackup`.
Build with `docker build --target recovery-postgres -f docker/php/Dockerfile .`, then mount read-only
authenticated backups/archives, restricted password/key files, and fresh writable destinations. These
targets enter Bash and run as `www-data`; override the UID to the recovery-volume owner when required,
never root for PostgreSQL. Invoke them with `tools/restore.sh /backups/BASE` and the documented environment.

MariaDB's image uses Debian's native MariaDB client. Confirm compatibility with the source release;
do not use that client as a MySQL 8.4 substitute. MySQL deployments need matching Oracle MySQL tools
on the recovery host or in an operator image. The native MySQL workflow uses the same official 8.4 image
for server, dump client and binlog decoder. The optional Docker targets still require the operator's
offsite, credentials, quiesce/resume hooks and native archive configuration.

## Create a consistent backup

Stop writes, media changes, worker, and scheduler so the database and filesystem describe the same point in time. Run the tool from a restricted host or job with Bash, `flock`, `jq`, `sha256sum`, GNU tar, and the selected database client.

```bash
export KUMWE_BACKUP_DIR=/backup
export KUMWE_MEDIA_DIR=/media
export KUMWE_PRIVATE_DIR=/var/www/kumwe/storage/private
export KUMWE_EXTENSIONS_DIR=/extensions
export KUMWE_EXTENSION_ASSETS_DIR=/var/www/kumwe/public/assets/extensions
export KUMWE_DB_DRIVER=mariadb
export KUMWE_DB_HOST=database
export KUMWE_DB_PORT=3306
export KUMWE_DB_NAME=kumwe
export KUMWE_DB_USER=kumwe
export KUMWE_DB_TABLE_PREFIX=kumwe_
export KUMWE_DB_PASSWORD_FILE=/run/secrets/database-password
export KUMWE_RELEASE=2.0.0
export KUMWE_BACKUP_CONSISTENCY=quiesced
tools/backup.sh
```

For PostgreSQL set `KUMWE_DB_DRIVER=pgsql` and port `5432`; for MySQL set `mysql` and port `3306`. The script confirms the required migration, rejects unsafe files and paths, stages every artifact, and publishes the completed directory atomically.

To authenticate backups:

```bash
export KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE=/srv/kumwe/secrets/backup-signing.key
tools/backup.sh
```

Copy the completed directory to encrypted, access-controlled, off-host storage before reopening writes.

## Verify a backup

```bash
export KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE=/srv/kumwe/backup-signing.pub
export KUMWE_EXPECTED_RELEASE=2.0.0
tools/restore-verify.sh /srv/kumwe/backups/kumwe-2.0.0-20260804T120000Z
```

Verification checks the exact payload list and checksums, optional signature, supported manifest and database
format, database archive readability, and traversal/link/special-file safety for media, private data, extension
code, and published assets. Run it immediately after creation, after transfer, before restore, and during
scheduled drills.

## Restore keys first

Backups deliberately contain no key material. That is the right decision — a backup and the keys that
open it should never travel together — but it means **a Kumwe backup is not sufficient to recover a
Kumwe installation.** The keys come from your secret store, they are restored before the database, and
if they are gone the corresponding data is gone with them. Nothing in the tooling can tell you this at
restore time, because a restore under the wrong keys succeeds: the rows land, the checksums match, the
site boots. It fails later, at the first use of each affected feature.

### What is not in the backup

| Secret | Configured as | What it protects |
|---|---|---|
| Application secret | `APP_SECRET` / `APP_SECRET_FILE` | Administrator and portal session binding, API-token digests, step-up TOTP secrets, recovery-code digests, step-up throttling, mutation-plan tokens, record fingerprint and cursor keys, and — unless dedicated record material is configured — the `application-secret-v1` record-encryption key |
| Record-encryption keyring | `RECORD_ENCRYPTION_KEY`, `RECORD_ENCRYPTION_KEY_ID`, `RECORD_ENCRYPTION_PREVIOUS_KEYS`, `RECORD_ENCRYPTION_LEGACY_SECRET` (each also `_FILE`) | Every `core.secret` business-record field. The keyring must carry the **active key and every retired key still named by a stored envelope**, including revision snapshots, which are never re-sealed |
| Extension runtime signing key | `EXTENSION_RUNTIME_SIGNING_KEY_ID`, `EXTENSION_RUNTIME_SIGNING_KEY_FILE`, `EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE` | The signed extension runtime map. Without it `extension:runtime:materialize` cannot produce a trusted generation, and the worker, scheduler and integration runner refuse to run |
| Backup signing keypair | `KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE` (create), `KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE` (verify) | Authenticity of the backup itself. The public key must be available *before* the restore, not after |

Note the asymmetry inside the first two rows. `APP_SECRET` rotates independently of the
record-encryption keyring — that is what `RECORD_ENCRYPTION_LEGACY_SECRET` exists for — so a recovery
may legitimately need the *current* `APP_SECRET` and a *previous* one at the same time. Restore both.

### What a wrong key looks like

Recognising these is the difference between a five-minute fix and a post-mortem:

- **Wrong or missing backup public key.** `tools/restore-verify.sh` refuses before anything is written:
  `backup is signed; configure KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE`, or minisign's own
  `Signature verification failed`. This is the only wrong-key case that fails loudly and early.
- **Wrong `APP_SECRET`.** The restore completes and the site serves pages. Then: every restored
  administrator and portal session is rejected, so operators are signed out; every API token stops
  authenticating; every enrolled second factor is dead, because the TOTP secret was sealed under a key
  derived from `APP_SECRET` and now fails authenticated decryption; recovery codes no longer match
  their digests, so the documented recovery path out of a dead authenticator is dead too. Passwords
  still work, because password hashes are not keyed — which is why the failure looks like "MFA is
  broken" rather than "the secret is wrong".
- **Wrong or incomplete record keyring.** The site boots, records read, and only the `core.secret`
  fields fail. A key the deployment does not hold is reported as *unavailable* rather than as a
  decryption error, because the envelope names its key and exactly one key is ever attempted
  (`SodiumSecretCipher::decrypt`, `KeyRingSecretCipher::decrypt`). A retired key dropped from
  `RECORD_ENCRYPTION_PREVIOUS_KEYS` therefore strands every envelope that names it — live columns are
  re-sealed by `php bin/kumwe business-record-rekey`, but revision snapshots deliberately are not, so
  retired keys must be kept for as long as the revisions that reference them.
- **Wrong extension runtime signing key.** `extension:runtime:materialize` cannot publish a trusted
  generation; the worker and scheduler exit with
  `This process loaded a stale or untrusted extension runtime generation.` No background work runs.

**None of this is recoverable by re-restoring.** An encrypted field whose key is lost is lost. If the
application secret is unrecoverable, plan on rotating it deliberately — reissue API tokens, invalidate
sessions, re-enroll every second factor — and treat the affected `core.secret` fields as data to be
re-entered, not restored.

### Order of a real recovery

1. **Provision secrets first**, into the secret store or files the new host will read: application
   secret, record-encryption keyring including retired keys, extension runtime signing key and its
   previous keys, and the backup **public** key. Do not start the application yet.
2. **Authenticate the backup** with `tools/restore-verify.sh`, with
   `KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE` and `KUMWE_EXPECTED_RELEASE` both set. A backup that will not
   verify must not be restored; go to the previous one.
3. **Create the empty database** and choose non-existent filesystem targets, as below.
4. **Restore** with `tools/restore.sh`. It re-verifies, refuses a non-empty database, refuses existing
   targets, refuses a driver mismatch, and publishes the four filesystem directories only after the
   database is in.
5. **Materialize the extension runtime**: `php bin/kumwe extension:runtime:materialize`. Use `--repair`
   only when a host is being rolled back and the local generation is ahead of database authority — it
   is the explicit decision to discard the local copy.
6. **Run recovery acceptance** before opening any door. The automated drill decrypts a restored
   encrypted field, re-authenticates a restored operator, opens a restored second factor and refuses
   its replays, and makes the restored installation dispatch and drain one job. A wrong `APP_SECRET` or
   an incomplete keyring fails here rather than in production.
7. **Invalidate what should not have survived**: restored browser sessions and token families are still
   valid credentials. Terminate them before the drill copy can reach anything production can.
8. **Cut over**, and only then reopen writes.

Steps 1 and 6 are the two that are usually skipped and are the two that matter. A restore that skipped
step 1 looks identical to a correct one until someone tries to sign in with a second factor.

## Restore into clean targets

Create an empty database using the same driver. Choose media, private-data, extension-code, and extension-asset
paths that do not exist; their parent directories must already exist.

Example MariaDB database creation:

```bash
MYSQL_PWD="$(cat /run/secrets/database-password)" mariadb \
  --host=127.0.0.1 --port=3306 --user=kumwe \
  --execute='CREATE DATABASE kumwe_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
```

For MySQL, use the same arguments with the `mysql` client and a MySQL-compatible collation. The collation
named here is the one the whole schema ends up using: `database:migrate` converges every application table
on the database's default collation after applying the plan, so a server whose `utf8mb4` default differs
from it cannot leave the schema split between two collations. Keep it a `utf8mb4` collation.

Example PostgreSQL creation:

```bash
PGPASSWORD="$(cat /run/secrets/database-password)" createdb \
  --host=127.0.0.1 --port=5432 --username=kumwe kumwe_restore
```

Restore:

```bash
export KUMWE_RESTORE_DB_DRIVER=mariadb
export KUMWE_RESTORE_DB_HOST=127.0.0.1
export KUMWE_RESTORE_DB_PORT=3306
export KUMWE_RESTORE_DB_NAME=kumwe_restore
export KUMWE_RESTORE_DB_USER=kumwe
export KUMWE_RESTORE_DB_PASSWORD_FILE=/run/secrets/database-password
export KUMWE_RESTORE_DB_TABLE_PREFIX=kumwe_
export KUMWE_RESTORE_MEDIA_DIR=/srv/kumwe/restored/media
export KUMWE_RESTORE_PRIVATE_DIR=/srv/kumwe/restored/private
export KUMWE_RESTORE_EXTENSIONS_DIR=/srv/kumwe/restored/extensions
export KUMWE_RESTORE_EXTENSION_ASSETS_DIR=/srv/kumwe/restored/extension-assets
tools/restore.sh /srv/kumwe/backups/BACKUP
```

`restore.sh` authenticates and verifies the backup, requires an empty database and absent filesystem targets,
restores into staging, confirms the required migration, and then publishes all four filesystem directories. It
refuses to restore a MariaDB backup as MySQL or PostgreSQL, or any other driver mismatch.

## Knowing a restore finished, and re-running one that did not

A restore publishes four directories one after another, so a restore that is interrupted — a lost session, an
OOM kill, a power cut — can leave some targets in place and others not. Two files settle what happened.

`kumwe-restore-manifest.json`, written beside the private-data target unless `KUMWE_RESTORE_MANIFEST` names
another absolute path, is the completion record. It exists only when a restore finished, and carries the backup
identity (the digest of the backup's own checksum list), the backup and completion timestamps, the database and
prefix restored into, and for each of the four trees its path, its file count and a digest taken over the sorted
per-file digests. **Check for it before first boot**: a target tree that happens to contain a runtime map is not
evidence that the restore completed, and the manifest is.

```bash
jq -e '.format == "kumwe-restore-v1"' /srv/kumwe/restored/kumwe-restore-manifest.json \
  || echo 'This restore did not finish; do not boot on it.'
```

`kumwe-restore-manifest.json.partial` is the claim, written before the first target is created and removed when
the manifest is written. It names the backup, the database and the exact four targets this restore owns. While it
is present, **re-running the same command against the same backup and the same targets is the recovery**: the
restore clears the targets its own claim names and rebuilds them. No manual cleanup, and no widening of the
fail-closed rule — a target that no claim names is still refused untouched, because it is somebody else's data.

The database half has one bound worth knowing before an incident rather than during one. On PostgreSQL the import
is a single transaction, so an interruption leaves the database exactly as empty as it found it and the re-run
simply imports again. On MySQL and MariaDB data definition commits implicitly, so an interruption part-way
through the import leaves a partly populated database; the re-run says so and asks for a freshly created empty
database rather than importing over the remains. Dropping and recreating the scratch database, then re-running,
recovers it.

`tools/restore-interruption-drill.sh` is the evidence for all of this and the way to re-qualify it after changing
either script. It takes a real backup, restores it into a scratch database and scratch targets, `SIGKILL`s the
restore at the moment it begins publishing targets, re-runs it unchanged, and compares every restored tree
against the source. It needs the dump and restore clients and an explicitly disposable database. It deterministically kills
the process group immediately after the first real filesystem publication through a drill-only command wrapper.
Run it in the recovery CI lane or an isolated operational drill; it is runtime evidence, not release qualification.

## Recovery acceptance

Point an isolated deployment at the restored database and filesystem targets. Confirm readiness, owner and limited-user login, public rendering, content revisions and workflow, business-definition catalog/version/checksum counts, menus, role grants, settings, active extensions/templates, API idempotency, MCP initialization, one reversible mutation, one worker job, and one scheduler iteration. Compare important media and extension checksums. Also compare installed physical-blueprint checksums, generated table/junction/line counts, exact money and quantity values, encrypted secret envelopes, record revisions, command outcomes, and audit checksums; then execute one typed business-record command against each installed fixture.

The automated clean-target gate seeds a stable neutral record and relationship graph through the application
boundaries. Its source manifest hashes canonical blueprints, reconstructed physical schemas, every generated entity,
junction and owned-line row, schema controls, revisions, idempotency outcomes, and business-record audit events. It
records exact decimal, money, quantity, and microsecond temporal values plus hashes of the encrypted secret envelope;
the fixture fails if secret plaintext appears in any inspected row or in the manifest. After an exact manifest match,
the restored application executes and replays an optimistic typed update to prove that the clean target is writable.
The drill also compares private-data bytes and verifies every completed report-export object against the checksum in
its restored append-only database metadata. Keep report-export object files at mode `0600` and their parent
directories at `0700` after moving a restore onto its final volume.

Matching digests are necessary and not sufficient — a restore under the wrong keys reproduces every one
of them — so after the manifest comparison the drill stops comparing and starts using the restored
installation:

- **It decrypts.** The restored `core.secret` envelope is opened through the production cipher, with the
  associated data rebuilt from the record's own coordinates, and the recovered plaintext is checked
  against the fixture value in constant time. Nothing prints it; the manifest carries only a
  domain-separated digest of it, which is what makes a source and a restore that cannot both open the
  envelope disagree. A wrong record key fails here.
- **It signs in.** A deliberately narrow restored operator authenticates with its restored password
  hash, is allowed one business-record read, and is denied a business-record update. A session is
  issued, accepted for its own user agent, refused for another, aged, and then refused.
- **It elevates.** The restored TOTP credential is decrypted through the production step-up cipher — a
  wrong `APP_SECRET` fails here — used to pass a live challenge, and then the same code is presented
  again and refused. Recovery codes are reissued, one is spent, and the spent code is presented again
  and refused.
- **It works.** The restored installation materializes its extension runtime, dispatches the schedule
  the backup carried, and drains the resulting job through `bin/kumwe` itself, in fresh processes so
  the runtime-generation guard is genuinely exercised. The job is the session purge, so its effect is
  visible in restored data: the expired sessions the backup carried are gone afterwards and the live
  one is not.

Record the successful clean-target drill as schema recovery evidence with its source-schema checksum, backup
manifest checksum, release, driver, client/server identity, verifier, and drill reference. Destructive or locking
schema approval rejects absent, stale, mismatched, or untested evidence. The drill writes that evidence itself when
the harness supplies `KUMWE_DRILL_REFERENCE` and `KUMWE_DRILL_BACKUP_MANIFEST_CHECKSUM`, and records the measured
`backup_quiesce_seconds` and `restore_seconds` in its details, so the recovery objectives above can be replaced by
figures from your own hardware. Without those variables the drill still passes and simply records nothing, because a
measurement that was not taken is worse than an absent one.

Cut over only after application and business fixtures pass. Never restore over the active database or active
media/private-data/extension directories.

CI performs backup, verification, empty-target restore, and file comparison for MariaDB, MySQL, and PostgreSQL, with
an ephemeral Minisign keypair so the signing and signature-verification branches execute rather than being skipped.
It then runs `tests/Support/backup-tamper-drill.sh` against copies of the backup it just took, requiring each of
sixteen damaged backups to be refused with the message that names the damage: corrupted database and media payloads,
an edited manifest, a missing payload, a narrowed checksum file, a Kumwe 1.x manifest, a traversal member, a symbolic
link inside an archive and beside the payloads, a release mismatch, a payload re-checksummed after tampering but not
re-signed, a missing signature, a signed backup with no public key configured, a driver mismatch, an existing
filesystem target, and a non-empty target database. Operators must also run scheduled off-host drills and record
recovery time, recovery point, exact client/server versions, and acceptance evidence.

The database backup includes organizations, workspaces, membership versions and roles, owner-bound capability and
resource-policy declarations, conditional record/field policies, SoD rules, approval requests/votes/consumption,
encrypted step-up credentials, recovery-code digests, proof replay fences, scoped token bindings, portal sessions,
resource ownership, and security audit history. Secrets remain encrypted or digested in the archive.

On a clean-target drill, verify catalog owner/checksum/lifecycle parity, effective access for one allowed
and one denied membership, row and field non-enumeration, stale-session rejection, approval spent state,
and TOTP/recovery replay fences. The existing PHP drill covers limited login, allow/deny authorization,
stale-session rejection and the second-factor replay fences. It now also calls
`RestoreApprovalAcceptance`: a separate real policy actor creates a SoD rule through
`BusinessSecurityAdministrationService`; a maker and checker create, approve and consume a request
through `ApprovalService` with persisted step-up proofs. After restore, the same consumed request must
be refused without changing its version or binding. The same fresh proof then consumes an approved
sibling, proving the refusal was spent-state protection rather than unusable credentials. Fixtures expire
after seven days; run this automated drill promptly against its freshly taken backup. No SQL fabricates
rules, votes, requests or proof state, and no missing approval-administration route blocks this evidence.

### HTTP and approval-spent acceptance

`tools/restore-http-drill.sh` exercises the real login form, CSRF token/cookie exchange, authenticated
administrator page, API token, mutation response and `Idempotency-Replayed` header. It records a fresh
source mutation before backup, then requires the restored database to replay that outcome using the
original key and `If-Match`, with identical result digest, status and ETag. A second HTTP replay must
also be identical. It refuses a successful-looking response that lacks replay evidence.

When the selected API fixture has a business-record approval, configure a request created, approved and consumed through
its real administration/workflow services before backup. The drill requires its source and restored HTTP
projection to say `consumed`, with approve/cancel/revoke controls disabled, and compares that projection's
digest. It does not insert synthetic rule or approval rows. Without an applicable approval fixture the
result explicitly says `not_applicable`; this does not close the approval recovery finding or prove its
consumption service. Generic role approvals used by the PHP spent-state drill are deliberately outside
this business-record API projection; their consumption is tested by the service-level drill above.

Configure `KUMWE_DRILL_HTTP_ORIGIN` to the isolated source, then the restored origin. HTTPS is required
except on localhost/127.0.0.1. Supply `KUMWE_DRILL_LOGIN_EMAIL_FILE`, `KUMWE_DRILL_LOGIN_PASSWORD_FILE` and
`KUMWE_DRILL_API_TOKEN_FILE` as restricted credential files. The API token must be issued before backup
and carry the selected fixture mutation and approval-read authority. No token or password enters evidence.
Parent login/CSRF integration is required; the probe refuses forms with no CSRF token.

For a disposable source database, `KUMWE_RECOVERY_FIXTURE_DISPOSABLE=yes php
tests/Support/recovery-http-fixture.php /absolute/new-fixture-directory` provisions a real scoped token
and neutral create request. A separate policy actor uses real step-up to create the required organization
membership. It writes `email`, `password`, `token` and `request.json` as private files; point the three
credential variables at those files. This helper adds fixture data and is only for disposable installations.

Request fixture (replace paths, key, body, original entity version and optional approval UUID with the
real fixture; do not send this example to a production record):

```json
{
  "format": "kumwe-recovery-http-request-v1",
  "site": "default",
  "method": "PATCH",
  "path": "/api/v1/business/records/definition/record",
  "key": "recovery-fixture-one",
  "if_match": "\"v1\"",
  "body": {"values": {"label": "Recovery fixture"}},
  "approval_request_id": null
}
```

```bash
# Before the backup, after provisioning real fixtures through application services:
tools/restore-http-drill.sh seed /secure/request.json /private/recovery-http-receipt.json
# After restoring, point the origin and credentials at the isolated restored installation:
tools/restore-http-drill.sh verify /secure/request.json /restored/private/recovery-http-receipt.json
```

The receipt is secret-free and should be included in the private payload tree so the backup authenticates
it. The `site` field defaults to `default` and supplies the required `Kumwe-Site` header. Keep request bodies
and credentials private. Run HTTP replay before any other drill changes that
record, token, approval or its policy. This probe compares terminal approval state; it does not claim a
new approval can be issued or consumed without the corresponding service-level workflow evidence.

### Native before/after transaction drill

`tools/restore-pitr-drill.sh` creates a small probe table in an explicitly disposable, migrated source
installation, snapshots it, commits one selected transaction, snapshots again, and performs two native
restores. It asserts the exact marker rows before and after that transaction and rejects a newer
wall-clock target. It writes evidence, manifests and logs into an initially empty
`KUMWE_PITR_DRILL_OUTPUT`. Run separately for MariaDB, MySQL and PostgreSQL with matching native clients.
This is operational runtime evidence, with no manual browser or release-artifact acceptance checklist.
Binlog engines rotate between the base and target, so the replay crosses a real file boundary. A third
transaction commits after the target and is archived too; assertions require it to remain excluded.

Set `KUMWE_PITR_DRILL_DISPOSABLE=yes`, the normal quiesced backup/source variables and destination variables.
The source and recovery instances must be distinct; their database name remains the same. Two executable
operator adapters supply environment-specific infrastructure:

- `KUMWE_PITR_DRILL_ARCHIVE_HOOK BASE AFTER ARCHIVE_DIR`: rotate/flush and collect complete native logs
  through the target into `ARCHIVE_DIR`, failing if the archive is incomplete. MariaDB/MySQL can use
  `FLUSH BINARY LOGS` followed by copies of closed binlogs; PostgreSQL copies from its configured WAL
  archive after the backup's `pg_switch_wal` has been archived. The drill signs the resulting inventory.
- `KUMWE_PITR_DRILL_TARGET_HOOK prepare|start|stop before|after`: prepare an empty disposable target,
  start a recovered physical cluster for inspection if needed, and stop it afterward. Binlog targets
  are empty databases on a separate running instance; `start` may be a no-op. PostgreSQL `prepare`
  leaves `KUMWE_RESTORE_PGDATA` absent. The hook receives all exported restore variables. It must keep
  every target isolated and report setup errors; the drill itself performs and asserts recovery.

A source already containing the drill table is refused. Archive gap/corruption and missing-target tests
must accompany the successful native runs; the focused shell suite proves those refusal dispatches with
command doubles and cannot replace real-engine evidence.

## Scheduling, retention, and off-host copies

The executable cycle is `tools/backup-cycle.sh`. Install the reference service and timer from
`docs/operations/systemd/` and configure `/etc/kumwe/backup.env` for the backup OS account:

- `KUMWE_BACKUP_QUIESCE_HOOK`: absolute executable path; stop all writers and exit nonzero on failure.
- `KUMWE_BACKUP_RESUME_HOOK`: absolute executable path; safely resume writers, including after failed quiesce.
- `KUMWE_BACKUP_OFFSITE_HOOK`: absolute executable path; takes one backup directory argument, copies it
  offsite and verifies the stored copy, returning nonzero on any failure.
- `KUMWE_BACKUP_KEEP`: positive local snapshot count, for example `48`.
- Signing/verification keys and all backup variables above.

The cycle takes a lock, quiesces, creates and verifies the signed snapshot, runs the offsite hook, resumes
writers, then prunes. Failure resumes writers and never reaches retention. Hooks are executable paths,
not evaluated shell strings. The timer is hourly with no random delay; missed timers run once at startup.
Monitor cycle failures, duration and archive lag: an hourly timer cannot guarantee a 60-minute RPO when
jobs fail, overrun or the host is down.

`tools/backup-retain.sh` independently verifies every candidate before any deletion and orders by signed
creation time. It refuses zero retention and preserves at least the newest verified snapshot. Unknown,
partial and invalid snapshots are not silently deleted. Native binlog/WAL archive retention is separate:
keep the log chain needed by every retained recovery base, including offsite bases. Snapshot pruning
alone never prunes logs or proves archive usability.

### Reference transport: Restic

Restic is an optional single-binary reference transport with client-side authenticated encryption and
content-addressed deduplication. Send the plain trees directly; do not recompress them into tarballs.
Use a stable parent path and explicit backup tags/retention groups. Use an append-only server/repository
access arrangement for the production writer; keep privileged `forget --prune` credentials off that host.
Append-only protection depends on the backend/access policy, not merely installing the client. Verify
stored data by restoring the selected snapshot to a fresh directory and running `restore-verify.sh` there.
A successful upload or `restic check` alone is not application restore acceptance. Kumwe takes no Restic
dependency and allows other transports with equivalent encryption, integrity, deduplication and custody.

For a measured deduplication drill, take two snapshots with one changed file and run
`tools/backup-dedup-drill.sh BEFORE AFTER /absolute/new-evidence-directory` against an isolated configured
Restic repository. It uploads both with a stable path/group, records the actual JSON stored-byte summaries,
and restores/verifies the second copy. Retain `measurement.json` and compare the second `data_added`/stored
bytes to changed and total payload bytes. Do not call identical-file checksum counts a measurement of repository storage. Native physical
bases and database dumps can change beyond the one application file, so report those separately.

Whole-table parity remains optional: signed exact dump bytes plus the existing typed runtime and security
acceptance prove the supported drill. They do not assert identical row counts for every installation table.
Offsite drills on actual deployment volumes measure recovery point, recovery time, key availability and
archive reachability; the focused filesystem test does not provide those measurements.

### Automated native lanes (#152)

`tests/Unit/Tools/RecoveryToolsTest.php` runs eleven filesystem/refusal checks through the real shell
tools, retaining v2 tar compatibility. There is no Python dependency or command-double recovery evidence.
Run the full isolated engine drill with `tests/Support/recovery-native-drill.sh mariadb|mysql|pgsql`.
It creates fresh source/destination clusters, real signing keys, native archived logs, and before/after
transaction assertions; it also exercises missing-log refusal, sixteen tamper refusals, SIGKILL/resume,
retention, and failed-cycle writer resumption. `KUMWE_RECOVERY_MEASURE_RESTIC=yes` adds a real two-snapshot
repository measurement with restored-copy verification. The small fixture does not establish the 20 GB RTO.

`.github/workflows/recovery.yml` runs those native lanes on pull requests and integration-branch pushes.
MariaDB uses the runner's packaged native server; MySQL uses matching official 8.4 server/client binaries;
PostgreSQL uses a matching 17 toolchain as a non-root user. Evidence records actual server/client versions.
Only explicit secret-free reports are uploaded; signing keys, database clusters, request bodies and token
files stay out of artifacts. The existing App clean-target lane additionally executes the real approval
spent-state controls. The parent integrates HTTP probes with its login/CSRF work and owns full CI/baselines.
