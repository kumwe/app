# Backup and restore

A complete Kumwe backup contains:

- a PostgreSQL custom-format dump;
- the media tree;
- installed extension and template code;
- a versioned manifest and exact SHA-256 checksums;
- an optional minisign signature over the checksum file.

Secrets, Redis data, application images, and signing private keys are never included.

## Create a backup

Stop writes, media changes, the worker, and scheduler so PostgreSQL and filesystem state describe the same point in time. Run the tool from a restricted runner with PostgreSQL client tools, Bash, `flock`, `jq`, `sha256sum`, and GNU tar. Mount media and extensions read-only and keep PostgreSQL on its private network.

```bash
export KUMWE_BACKUP_DIR=/backup
export KUMWE_MEDIA_DIR=/media
export KUMWE_EXTENSIONS_DIR=/extensions
export KUMWE_DB_HOST=postgres
export KUMWE_DB_PORT=5432
export KUMWE_DB_NAME=kumwe
export KUMWE_DB_USER=kumwe
export KUMWE_DB_SCHEMA=kumwe
export KUMWE_DB_PASSWORD_FILE=/run/secrets/database-password
export KUMWE_RELEASE=2.0.0
export KUMWE_BACKUP_CONSISTENCY=quiesced
tools/backup.sh
```

The script verifies the application migration, validates both filesystem trees, stages every artifact, and publishes the completed directory atomically. Copy it to encrypted off-host storage before reopening writes.

For authenticated backups:

```bash
export KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE=/srv/kumwe/secrets/backup-signing.key
tools/backup.sh
```

## Verify

```bash
export KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE=/srv/kumwe/backup-signing.pub
export KUMWE_EXPECTED_RELEASE=2.0.0
tools/restore-verify.sh /srv/kumwe/backups/kumwe-2.0.0-20260804T120000Z
```

Verification checks the exact payload list and digests, optional signature, supported manifest, PostgreSQL archive, and traversal/link/special-file safety for media and extensions. Run it after upload, before restore, and during recovery drills.

## Restore into clean targets

Create an empty database. Choose media and extension target paths that do not exist; their parent directories must exist.

```bash
createdb --host=127.0.0.1 --username=kumwe kumwe_restore
export KUMWE_RESTORE_DB_HOST=127.0.0.1
export KUMWE_RESTORE_DB_PORT=5432
export KUMWE_RESTORE_DB_NAME=kumwe_restore
export KUMWE_RESTORE_DB_USER=kumwe
export KUMWE_RESTORE_DB_PASSWORD_FILE=/run/secrets/database-password
export KUMWE_RESTORE_DB_SCHEMA=kumwe
export KUMWE_RESTORE_MEDIA_DIR=/srv/kumwe/restored/media
export KUMWE_RESTORE_EXTENSIONS_DIR=/srv/kumwe/restored/extensions
tools/restore.sh /srv/kumwe/backups/BACKUP
```

`restore.sh` verifies the backup, refuses a non-empty database or existing filesystem target, extracts into staging, restores PostgreSQL in one transaction, confirms the required migration, and then publishes the restored filesystem directories.

Point a staging deployment at those targets. Run readiness, administrator login, page rendering, extension loading, one reversible draft mutation, `queue:work --once`, and `schedule:run`. Cut over only after application and business fixture checks pass. Never restore over the active database or volume.

CI performs this backup and restore flow on PHP 8.4. Production operators should also run scheduled off-host recovery drills and record recovery time and recovery point evidence.
