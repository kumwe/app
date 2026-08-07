# Backup and restore

A complete Kumwe backup contains:

- a database dump in the native supported format;
- media, installed extension/template code, and published extension assets;
- a versioned manifest identifying release, database driver, database name, and table prefix;
- exact SHA-256 checksums;
- an optional Minisign signature over the checksum file.

Secrets, Redis data, application images, and signing private keys are never included. Redis is disposable coordination state; the relational database is authoritative.

## Supported formats

| Driver | Backup format | Required client tools |
|---|---|---|
| `mariadb` | Transactional SQL | `mariadb`/`mysql`, `mariadb-dump`/`mysqldump` |
| `mysql` | Transactional SQL | `mariadb`/`mysql`, `mariadb-dump`/`mysqldump` |
| `pgsql` | PostgreSQL custom archive | `psql`, `pg_dump`, `pg_restore` matching the server major line |

A backup restores only to the same driver recorded in its manifest. Engine conversion is a separate logical migration and validation exercise.

## Create a consistent backup

Stop writes, media changes, worker, and scheduler so the database and filesystem describe the same point in time. Run the tool from a restricted host or job with Bash, `flock`, `jq`, `sha256sum`, GNU tar, and the selected database client.

```bash
export KUMWE_BACKUP_DIR=/backup
export KUMWE_MEDIA_DIR=/media
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
format, database archive readability, and traversal/link/special-file safety for media, extension code, and
published assets. Run it immediately after creation, after transfer, before restore, and during scheduled drills.

## Restore into clean targets

Create an empty database using the same driver. Choose media, extension-code, and extension-asset paths that do
not exist; their parent directories must already exist.

Example MariaDB/MySQL database creation:

```bash
MYSQL_PWD="$(cat /run/secrets/database-password)" mariadb \
  --host=127.0.0.1 --port=3306 --user=kumwe \
  --execute='CREATE DATABASE kumwe_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
```

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
export KUMWE_RESTORE_EXTENSIONS_DIR=/srv/kumwe/restored/extensions
export KUMWE_RESTORE_EXTENSION_ASSETS_DIR=/srv/kumwe/restored/extension-assets
tools/restore.sh /srv/kumwe/backups/BACKUP
```

`restore.sh` authenticates and verifies the backup, requires an empty database and absent filesystem targets,
restores into staging, confirms the required migration, and then publishes all three filesystem directories. It
refuses to restore a MariaDB backup as MySQL or PostgreSQL, or any other driver mismatch.

## Recovery acceptance

Point an isolated deployment at the restored database and filesystem targets. Confirm readiness, owner and limited-user login, public rendering, content revisions and workflow, business-definition catalog/version/checksum counts, menus, role grants, settings, active extensions/templates, API idempotency, MCP initialization, one reversible mutation, one worker job, and one scheduler iteration. Compare important media and extension checksums.

Cut over only after application and business fixtures pass. Never restore over the active database or active media/extension directories.

CI performs backup, verification, empty-target restore, and file comparison for MariaDB, MySQL, and PostgreSQL. Operators must also run scheduled off-host drills and record recovery time, recovery point, exact client/server versions, and acceptance evidence.
