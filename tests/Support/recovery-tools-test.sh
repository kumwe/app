#!/usr/bin/env bash
# Filesystem/refusal coverage without database or cryptographic doubles. Native drills cover actual recovery.
set -Eeuo pipefail
fail() { printf 'Recovery tooling test failed: %s\n' "$*" >&2; exit 1; }
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository="$(cd -- "$script_directory/../.." && pwd -P)"
source "$repository/tools/recovery-common.sh"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
backup="$work/backup"
mkdir -p "$backup" "$work/restored"
printf 'fixture password' > "$work/password"
export KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE=''
export KUMWE_EXPECTED_RELEASE=2.0.0
export KUMWE_RESTORE_DB_NAME=fixture KUMWE_RESTORE_DB_USER=fixture
export KUMWE_RESTORE_DB_PASSWORD_FILE="$work/password"
export KUMWE_RESTORE_MEDIA_DIR="$work/restored/media" KUMWE_RESTORE_PRIVATE_DIR="$work/restored/private"
export KUMWE_RESTORE_EXTENSIONS_DIR="$work/restored/extensions"
export KUMWE_RESTORE_EXTENSION_ASSETS_DIR="$work/restored/extension-assets"
export KUMWE_RESTORE_MANIFEST="$work/restored/restore.json"
unset KUMWE_RESTORE_PAYLOAD_BACKUP KUMWE_RESTORE_TARGET_TIME KUMWE_RESTORE_DB_DRIVER
for tree in media private extensions extension-assets; do
    mkdir -p "$backup/$tree/empty"
    printf 'recoverable fixture bytes\n' > "$backup/$tree/probe with spaces.txt"
done
printf 'CREATE TABLE fixture (id INTEGER);\n' > "$backup/database.dump"
jq -n --argjson directories "$(recovery_directories "$backup")" '{
    format: "kumwe-backup-v3", product: "Kumwe App", product_major: 2, release: "2.0.0",
    created_at: "2026-09-24T00:00:00Z", payload_snapshot_at: "2026-09-24T00:00:00Z", pitr: null,
    database: "fixture", database_driver: "mariadb", database_format: "mysql-sql",
    database_table_prefix: "kumwe_", directories: $directories,
    contents: ["database.dump", "extension-assets", "extensions", "media", "private"]
}' > "$backup/manifest.json"
recovery_checksums "$backup" > "$backup/checksums.sha256"
bash "$repository/tools/restore-verify.sh" "$backup" >/dev/null
checks=1
refuses() {
    local reason="$1"
    shift
    if "$@" > "$work/refusal.log" 2>&1; then fail 'a negative case was accepted'; fi
    grep -Fq -- "$reason" "$work/refusal.log" || { cat "$work/refusal.log" >&2; fail "missing refusal: $reason"; }
    checks=$((checks + 1))
}
: > "$backup/media/unlisted"
refuses 'checksum manifest' bash "$repository/tools/restore-verify.sh" "$backup"
rm "$backup/media/unlisted"
mkdir "$backup/media/unlisted"
refuses 'directory inventory' bash "$repository/tools/restore-verify.sh" "$backup"
rmdir "$backup/media/unlisted"
ln -s /etc/passwd "$backup/media/link"
refuses 'symbolic links' bash "$repository/tools/restore-verify.sh" "$backup"
rm "$backup/media/link"
cp "$backup/checksums.sha256" "$work/checksums"
sed -i 's|  database.dump$|  ../database.dump|' "$backup/checksums.sha256"
refuses 'checksum manifest' bash "$repository/tools/restore-verify.sh" "$backup"
cp "$work/checksums" "$backup/checksums.sha256"
refuses 'target later than the payload snapshot is refused' env KUMWE_RESTORE_TARGET_TIME=2099-01-01T00:00:00Z \
    bash "$repository/tools/restore.sh" "$backup"
refuses 'canonical absolute paths' env KUMWE_RESTORE_MEDIA_DIR="$work/restored/../restored/media" \
    bash "$repository/tools/restore.sh" "$backup"
refuses 'restore targets overlap' env KUMWE_RESTORE_PRIVATE_DIR="$KUMWE_RESTORE_MEDIA_DIR" \
    bash "$repository/tools/restore.sh" "$backup"
mkdir "$KUMWE_RESTORE_MEDIA_DIR"
printf 'foreign data' > "$KUMWE_RESTORE_MEDIA_DIR/keep"
refuses 'media target must not exist' bash "$repository/tools/restore.sh" "$backup"
[[ "$(<"$KUMWE_RESTORE_MEDIA_DIR/keep")" == 'foreign data' ]] || fail 'foreign data changed'
refuses 'must be positive' env KUMWE_BACKUP_DIR="$work" KUMWE_BACKUP_KEEP=0 \
    bash "$repository/tools/backup-retain.sh"
# Retained v2 compatibility uses real GNU archives and checksum verification.
for tree in media private extensions extension-assets; do
    tar -czf "$backup/$tree.tar.gz" -C "$backup/$tree" .
    rm -rf -- "$backup/$tree"
done
jq '.format = "kumwe-backup-v2" | .contents = ["database.dump", "extension-assets.tar.gz",
    "extensions.tar.gz", "media.tar.gz", "private.tar.gz"]' "$backup/manifest.json" > "$work/manifest.json"
mv "$work/manifest.json" "$backup/manifest.json"
recovery_checksums "$backup" > "$backup/checksums.sha256"
bash "$repository/tools/restore-verify.sh" "$backup" >/dev/null
checks=$((checks + 1))
printf 'Recovery tooling proved %s filesystem/refusal checks without command doubles.\n' "$checks"
