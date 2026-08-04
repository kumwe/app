# Backup and restore

`tools/backup.sh` creates a PostgreSQL custom-format dump, a media archive, a
machine-readable Kumwe 2.x manifest and SHA-256 checksums in a staged directory.
It atomically publishes the completed directory. It never includes application,
database, Redis or signing secrets.

The database dump is transactionally consistent, but PostgreSQL cannot make the
filesystem media snapshot consistent with it. Stop writes and media processing
before setting the required consistency acknowledgement. The script verifies
the Phase 7 migration in the configured schema before dumping, so a legacy or
partially installed database cannot be mislabeled as a Kumwe 2.x backup.

Run the tool from a restricted backup runner that has Bash, PostgreSQL client
tools, `flock`, `jq`, `sha256sum` and GNU tar. For the bundled deployment, attach
that runner to the internal `kumwe_backend` network, mount the media volume
read-only, and mount a protected backup destination read-write. Do not publish
the PostgreSQL port merely to run a backup, and do not install backup tooling in
the application container.

```bash
export KUMWE_BACKUP_DIR=/backup
export KUMWE_MEDIA_DIR=/media
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

For authenticated backups, configure a protected minisign secret key:

```bash
export KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE=/srv/kumwe/secrets/backup-signing.key
tools/backup.sh
```

Copy the completed backup off-host before reopening writes. Checksums detect
corruption; they do not prove authenticity unless the checksum file is signed.
Encryption and retention are storage-policy responsibilities.

Verify before every restore:

```bash
export KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE=/srv/kumwe/backup-signing.pub
tools/restore-verify.sh /srv/kumwe/backups/kumwe-2.0.0-20260804T120000Z
```

The verifier refuses unknown manifests and every non-2.x backup, authenticates a
configured minisign signature, checks exact expected payload names and digests,
parses the PostgreSQL archive, and rejects media traversal, links and special
files. It does not modify a database or filesystem.

Restore only into empty staging targets:

```bash
createdb --host=127.0.0.1 --username=kumwe kumwe_restore_test
pg_restore --exit-on-error --no-owner --no-privileges \
  --dbname=kumwe_restore_test \
  /srv/kumwe/backups/BACKUP/database.dump
install -d -m 0750 /srv/kumwe/media-restore
tar --extract --gzip --file=/srv/kumwe/backups/BACKUP/media.tar.gz \
  --directory=/srv/kumwe/media-restore --no-same-owner --no-same-permissions
```

Run migrations and readiness against staging, compare record/media fixtures,
then switch the database and media targets using an operator-reviewed procedure.
Never restore over the active database or media volume.
