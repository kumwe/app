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

## Restore into clean targets

Create an empty database using the same driver. Choose media, private-data, extension-code, and extension-asset
paths that do not exist; their parent directories must already exist.

Example MariaDB database creation:

```bash
MYSQL_PWD="$(cat /run/secrets/database-password)" mariadb \
  --host=127.0.0.1 --port=3306 --user=kumwe \
  --execute='CREATE DATABASE kumwe_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
```

For MySQL, use the same arguments with the `mysql` client and a MySQL-compatible collation.

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

Record the successful clean-target drill as schema recovery evidence with its source-schema checksum, backup
manifest checksum, release, driver, client/server identity, verifier, and drill reference. Destructive or locking
schema approval rejects absent, stale, mismatched, or untested evidence.

Cut over only after application and business fixtures pass. Never restore over the active database or active
media/private-data/extension directories.

CI performs backup, verification, empty-target restore, and file comparison for MariaDB, MySQL, and PostgreSQL. Operators must also run scheduled off-host drills and record recovery time, recovery point, exact client/server versions, and acceptance evidence.

The database backup includes organizations, workspaces, membership versions and roles, owner-bound capability and
resource-policy declarations, conditional record/field policies, SoD rules, approval requests/votes/consumption,
encrypted step-up credentials, recovery-code digests, proof replay fences, scoped token bindings, portal sessions,
resource ownership, and security audit history. Secrets remain encrypted or digested in the archive.

On a clean-target drill, verify catalog owner/checksum/lifecycle parity, effective access for one allowed and one
denied membership, row and field non-enumeration, stale-session rejection, approval spent state, and TOTP/recovery
replay fences. Invalidate restored browser sessions and token families before connecting a drill copy to any
network that can reach production resources.
