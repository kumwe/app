# Backup and restore

A complete Kumwe backup contains:

- a database dump in the native supported format;
- media, private application data (including immutable report-export objects), installed
  extension/template code, and published extension assets;
- a versioned manifest identifying release, database driver, database name, and table prefix;
- exact SHA-256 checksums;
- an optional Minisign signature over the checksum file.

The database payload includes every generated business entity, junction, and ordered-line table together with
business-schema installations, plans, step journals, fences, recovery evidence, record revisions, command
idempotency outcomes, append-only report-export metadata, and audit rows. JSON control documents are canonical
metadata or historical snapshots;
authoritative business fields remain in their typed physical columns.

Secrets, Redis data, application images, and signing private keys are never included. Private application data is
not a secrets directory: it contains durable, non-public runtime artifacts such as report-export objects. Their
versioned ownership, policy snapshot, expiry, and checksum metadata live in the relational database. Redis is
disposable coordination state; the relational database is authoritative.

## Supported formats

| Driver | Backup format | Required client tools |
|---|---|---|
| `mariadb` | Transactional SQL | `mariadb`, `mariadb-dump` tested as compatible with the server release |
| `mysql` | Transactional SQL | `mysql`, `mysqldump` compatible with MySQL 8.4 |
| `pgsql` | PostgreSQL custom archive | `psql`, `pg_dump`, `pg_restore` matching the server major line |

A backup restores only to the same driver recorded in its manifest. Engine conversion is a separate logical migration and validation exercise.

## Recovery objectives

The shipped tooling takes one kind of backup: a full, write-quiesced snapshot. That single fact decides
both objectives, and it is stated here plainly rather than left for an operator to work out after an
incident.

**Recovery point.** The recovery point is the instant the snapshot was taken, so the recovery point
objective *is the backup interval*. There is no continuous archiving in this repository and no
application-side write log that could be replayed forward. With the reference schedule below — hourly
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

The write outage is the price of the recovery point. Shortening the backup interval improves RPO and
costs proportionally more availability, because every snapshot quiesces writes. That trade only stops
being a trade with point-in-time recovery, which is the next section, and which this repository does not
own.

## Point-in-time recovery is a database-layer responsibility

Kumwe does not implement continuous archiving, and the honest reason is that it should not: binlog and
WAL archiving are engine features, configured on the database server, retained on the database server's
schedule, and replayed with the engine's own tools. An application-level imitation would be a second,
weaker copy of a mechanism the DBA already has. What follows is therefore guidance for configuring the
engine, not documentation of a Kumwe feature — nothing in `tools/` reads or writes these logs, and no
drill in this repository exercises them.

Two facts make engine-level PITR compose correctly with a Kumwe snapshot:

- The relational database is authoritative. Redis is disposable coordination state, so replaying the
  database forward does not strand it.
- The filesystem payloads are **not** covered by any log. Media, private report-export objects,
  extension code and published assets are captured only at snapshot time. Rolling the database forward
  past the snapshot therefore produces rows referencing report-export objects and media that the
  filesystem does not have. Treat file storage separately — object storage with versioning, or
  filesystem snapshots taken on the same cadence as the log archive — or accept that a point-in-time
  restore recovers the database only.

**MariaDB and MySQL.** Enable binary logging in ROW format with GTIDs, archive the binlogs off-host,
and set a retention that comfortably exceeds the interval between full backups. Recovery is
`tools/restore.sh` for the snapshot, then `mariadb-binlog` / `mysqlbinlog` replayed from the snapshot's
position to the target instant. `tools/backup.sh` passes `--set-gtid-purged=OFF` on MySQL, which keeps
the dump free of GTID state so it can be loaded into a fresh server without claiming another server's
executed-GTID set; the position you replay from must therefore come from the binlog coordinates you
record beside the backup, not from the dump. Note that the dump is taken with `--single-transaction`
and no `--master-data`, so **the tooling does not record a binlog coordinate for you** — capture
`SHOW MASTER STATUS` (or the GTID executed set) during the same quiesce window and store it beside the
backup directory if you intend to replay forward.

**PostgreSQL.** Enable WAL archiving (`archive_mode = on`, an `archive_command` that copies to
off-host storage) or a streaming archive such as `pg_receivewal`, and take base backups with
`pg_basebackup` rather than relying on this repository's `pg_dump` custom archive. A `pg_dump` archive
is a logical export and is **not** a valid base backup for WAL replay: recovery to a point in time
needs a physical base backup plus the WAL segments that follow it. The Kumwe backup remains useful
beside that as a portable, verifiable, engine-version-tolerant copy, and it is what the restore drill
and the destructive-schema gate consume — but it is not the artifact you replay onto.

**If you configure PITR, the recovery-point objective becomes the engine's, not this table's.** Say so
in your own runbook, record how far back the archive actually reaches, and drill the forward replay;
none of the drills here will do it for you.

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
against the source. It is an operator drill rather than a continuous-integration step because it needs the dump
and restore clients on the host and a disposable database it may be pointed at; run it when qualifying a release.

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

On a clean-target drill, verify catalog owner/checksum/lifecycle parity, effective access for one allowed and one
denied membership, row and field non-enumeration, stale-session rejection, approval spent state, and TOTP/recovery
replay fences. The automated drill now covers limited login, allow/deny authorization, stale-session rejection, and
the TOTP and recovery-code replay fences. **Approval spent state remains a manual check**, and the reason is worth
knowing: this release ships no administration surface that creates an approval *rule*, so a drill could only reach a
consumed approval by inserting one with raw SQL. That would be a fixture pretending to be a workflow, and it would
pass whether or not the real path works. Check it by hand against an approval your installation actually created, and
treat rule administration as the prerequisite for automating it. Invalidate restored browser sessions and token
families before connecting a drill copy to any network that can reach production resources.

## Scheduling, retention, and off-host copies

This repository ships no backup scheduler, no retention job, and no copy-off-host step, and that is a
deliberate boundary rather than an omission: where backups live, how long they are kept, and what may
reach that storage are decisions about your infrastructure and your regulator, not about this
application. What follows is a reference to adapt, and the list of things that stay yours.

A reference systemd timer, hourly, matching the declared 60-minute RPO:

```ini
# /etc/systemd/system/kumwe-backup.service
[Unit]
Description=Kumwe quiesced backup, verification and off-host copy

[Service]
Type=oneshot
User=kumwe-backup
EnvironmentFile=/etc/kumwe/backup.env
ExecStart=/srv/kumwe/tools/backup-cycle.sh
```

```ini
# /etc/systemd/system/kumwe-backup.timer
[Unit]
Description=Hourly Kumwe backup

[Timer]
OnCalendar=hourly
RandomizedDelaySec=300
Persistent=true

[Install]
WantedBy=timers.target
```

`backup-cycle.sh` is yours to write, and is short. It has to do four things in order, and stop on the
first failure:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

# 1. Quiesce. Stop writes, the worker and the scheduler; the backup refuses to run otherwise.
systemctl stop kumwe-web kumwe-worker kumwe-scheduler
trap 'systemctl start kumwe-web kumwe-worker kumwe-scheduler' EXIT

# 2. Take it, signed.
backup_path="$(/srv/kumwe/tools/backup.sh)"

# 3. Verify before trusting it, and again after it has been copied.
/srv/kumwe/tools/restore-verify.sh "$backup_path"

# 4. Copy off-host, then verify the copy where it landed. An unverified remote copy is a guess.
rsync --archive --checksum "$backup_path" backup-host:/srv/kumwe-backups/
ssh backup-host "/srv/kumwe/tools/restore-verify.sh /srv/kumwe-backups/$(basename "$backup_path")"
```

Retention is a `find` and a policy decision, not a feature:

```bash
# Keep 48 hourly backups locally; the off-host copy keeps its own, longer, series.
find /srv/kumwe/backups -mindepth 1 -maxdepth 1 -type d -name 'kumwe-2.*' \
  | sort | head -n -48 | xargs --no-run-if-empty rm -rf --
```

Prune only backups that have verified, and never prune the newest surviving backup regardless of age: a
retention rule that can empty the directory is a data-loss mechanism.

### What stays operator-owned, and why

- **Cadence, retention, and where copies live.** These are the RPO decision and the compliance
  decision. The tooling cannot make them and should not appear to.
- **Access to backup storage.** A backup is a complete copy of the database. It belongs on encrypted,
  access-controlled storage that production cannot write to, so that a compromise of the application
  host cannot delete its own history.
- **Backup signing key custody.** The private key must not live on the host that takes backups if that
  host is the one you are protecting against. The public key must be somewhere the *recovery* host can
  reach, because a restore needs it (see *Restore keys first*).
- **Binlog or WAL archiving, and its retention.** See *Point-in-time recovery is a database-layer
  responsibility*.
- **Off-host drills.** CI drills a restore on every push, on a small fixture, on a runner. It does not
  drill your data volume, your hardware, or your network. Only you can measure your own RTO.
- **Whole-table parity between source and restore.** The acceptance manifest digests are scoped to the
  drill fixture; whole-database equality rests on the dump plus its SHA-256 checksum and signature,
  which is a strong claim about the bytes and a weaker one about semantics. An installation that wants
  independent confirmation should compare row counts per table between source and restore during its
  own drill, before the restore is written to.
