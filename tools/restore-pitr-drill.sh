#!/usr/bin/env bash
# Native before/after transaction drill. Both source and destination must be disposable installations.
# Operator hooks arrange isolated database instances and archive access, never acceptance assertions.
set -Eeuo pipefail
umask 077
fail() { echo "Kumwe PITR drill failed: $*" >&2; exit 1; }
[[ $# == 0 ]] || fail 'usage: restore-pitr-drill.sh (configured by environment)'
[[ "${KUMWE_PITR_DRILL_DISPOSABLE:-}" == yes ]] || fail 'requires explicitly disposable source and restore instances'
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
source "$script_directory/recovery-common.sh"
for variable in KUMWE_PITR_DRILL_TARGET_HOOK KUMWE_PITR_DRILL_ARCHIVE_HOOK; do
    hook="${!variable:-}"
    [[ "$hook" == /* && -x "$hook" && -f "$hook" ]] || fail "$variable must be an executable path"
done
[[ -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" && -n "${KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE:-}" ]] \
    || fail 'the native drill requires real Minisign signing and verification'
[[ "${KUMWE_BACKUP_CONSISTENCY:-}" == quiesced ]] || fail 'stop application writers before this drill'
[[ -d "${KUMWE_PITR_DRILL_OUTPUT:-}" && "$KUMWE_PITR_DRILL_OUTPUT" == /* ]] \
    || fail 'configure an existing absolute KUMWE_PITR_DRILL_OUTPUT directory'
[[ -z "$(find "$KUMWE_PITR_DRILL_OUTPUT" -mindepth 1 -print -quit)" ]] || fail 'drill output must be empty'
root="$KUMWE_PITR_DRILL_OUTPUT"
driver="${KUMWE_DB_DRIVER:-mariadb}"
prefix="${KUMWE_DB_TABLE_PREFIX:-kumwe_}"
[[ "$prefix" =~ ^[a-z][a-z0-9]*(_[a-z0-9]+)*_$ && ${#prefix} -le 28 ]] || fail 'invalid table prefix'
probe="${prefix}recovery_drill_probe"
[[ "$driver" =~ ^(mariadb|mysql|pgsql)$ ]] || fail 'unsupported native drill driver'
for variable in KUMWE_DB_NAME KUMWE_DB_USER KUMWE_DB_PASSWORD_FILE KUMWE_RESTORE_DB_HOST \
    KUMWE_RESTORE_DB_PORT KUMWE_RESTORE_DB_USER KUMWE_RESTORE_DB_PASSWORD_FILE; do
    [[ -n "${!variable:-}" ]] || fail "$variable is required before changing the disposable source"
done
source_host="${KUMWE_DB_HOST:-database}"
source_port="${KUMWE_DB_PORT:-$([[ "$driver" == pgsql ]] && echo 5432 || echo 3306)}"
[[ "$source_host:$source_port" != "$KUMWE_RESTORE_DB_HOST:$KUMWE_RESTORE_DB_PORT" ]] \
    || fail 'source and destination must be different database instances'
source_query() {
    if [[ "$driver" == pgsql ]]; then
        PGPASSWORD="$(<"$KUMWE_DB_PASSWORD_FILE")" psql -XAt --set=ON_ERROR_STOP=1 \
            --host="$source_host" --port="$source_port" --username="$KUMWE_DB_USER" \
            --dbname="$KUMWE_DB_NAME" --command="$1"
    else
        MYSQL_PWD="$(<"$KUMWE_DB_PASSWORD_FILE")" "$driver" --batch --skip-column-names \
            --host="$source_host" --port="$source_port" --user="$KUMWE_DB_USER" \
            --database="$KUMWE_DB_NAME" --execute="$1"
    fi
}
# Deliberately no IF NOT EXISTS: a previously used drill database must not silently pass.
source_query "CREATE TABLE $probe (marker INTEGER PRIMARY KEY)"
source_query "INSERT INTO $probe (marker) VALUES (1)"
mkdir "$root/backups"
export KUMWE_BACKUP_DIR="$root/backups" KUMWE_BACKUP_PITR=on
base="$(bash "$script_directory/backup.sh")"
# Ensure distinct backup names even on a tiny fixture, without any production sleep or acceptance delay.
while [[ "$(date -u +%Y%m%dT%H%M%SZ)" == "${base##*-}" ]]; do sleep 0.1; done
if [[ "$driver" != pgsql ]]; then
    # Exercise a real multi-file archive rather than assuming one log contains the whole range.
    source_query 'FLUSH BINARY LOGS'
fi
source_query "BEGIN; INSERT INTO $probe (marker) VALUES (2); COMMIT;"
after="$(bash "$script_directory/backup.sh")"
# This later committed row is archived too, but neither selected snapshot may recover it.
source_query "BEGIN; INSERT INTO $probe (marker) VALUES (3); COMMIT;"
mkdir "$root/archive"
# Hook receives base, after snapshot and archive destination; must finish archiving all target logs.
"$KUMWE_PITR_DRILL_ARCHIVE_HOOK" "$base" "$after" "$root/archive"
jq -n --arg source "$(jq -r '.pitr.source_id' "$base/manifest.json")" \
    '{format: "kumwe-log-archive-v1", source_id: $source}' > "$root/archive/archive.json"
recovery_tree_safe "$root/archive"
recovery_checksums "$root/archive" > "$root/archive/checksums.sha256"
minisign -S -s "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" -m "$root/archive/checksums.sha256" \
    -x "$root/archive/checksums.sha256.minisig" >&2
export KUMWE_RESTORE_LOG_ARCHIVE="$root/archive" KUMWE_RESTORE_DB_NAME="$KUMWE_DB_NAME"
export KUMWE_RESTORE_DB_DRIVER="$driver"
for point in before after; do
    mkdir "$root/$point"
    export KUMWE_RESTORE_MEDIA_DIR="$root/$point/media" KUMWE_RESTORE_PRIVATE_DIR="$root/$point/private"
    export KUMWE_RESTORE_EXTENSIONS_DIR="$root/$point/extensions"
    export KUMWE_RESTORE_EXTENSION_ASSETS_DIR="$root/$point/extension-assets"
    export KUMWE_RESTORE_MANIFEST="$root/$point/restore.json" KUMWE_RESTORE_PGDATA="$root/$point/pgdata"
    export KUMWE_RESTORE_PAYLOAD_BACKUP="$base"
    [[ "$point" == before ]] || export KUMWE_RESTORE_PAYLOAD_BACKUP="$after"
    # Prepare only: empty destination database for binlog recovery, absent PGDATA for physical recovery.
    "$KUMWE_PITR_DRILL_TARGET_HOOK" prepare "$point"
    started="$(date +%s)"
    bash "$script_directory/restore.sh" "$base"
    # Physical replay intentionally stops its private cluster. Hook may boot it with isolated config.
    "$KUMWE_PITR_DRILL_TARGET_HOOK" start "$point"
    if [[ "$driver" == pgsql ]]; then
        actual="$(PGPASSWORD="$(<"$KUMWE_RESTORE_DB_PASSWORD_FILE")" psql -XAt --set=ON_ERROR_STOP=1 \
            --host="$KUMWE_RESTORE_DB_HOST" --port="$KUMWE_RESTORE_DB_PORT" \
            --username="$KUMWE_RESTORE_DB_USER" --dbname="$KUMWE_RESTORE_DB_NAME" \
            --command="SELECT marker FROM $probe ORDER BY marker")"
    else
        actual="$(MYSQL_PWD="$(<"$KUMWE_RESTORE_DB_PASSWORD_FILE")" "$driver" --batch --skip-column-names \
            --host="$KUMWE_RESTORE_DB_HOST" --port="$KUMWE_RESTORE_DB_PORT" --user="$KUMWE_RESTORE_DB_USER" \
            --database="$KUMWE_RESTORE_DB_NAME" --execute="SELECT marker FROM $probe ORDER BY marker")"
    fi
    expected=1
    [[ "$point" == before ]] || expected=$'1\n2'
    [[ "$actual" == "$expected" ]] || fail "recovered $point state differs: the selected transaction boundary is wrong"
    "$KUMWE_PITR_DRILL_TARGET_HOOK" stop "$point"
    jq -n --arg point "$point" --arg driver "$driver" --argjson seconds "$(( $(date +%s) - started ))" \
        '{point: $point, driver: $driver, transaction_state_asserted: true, restore_seconds: $seconds}' \
        > "$root/$point/evidence.json"
done
# A forged later wall-clock target must be rejected before creating new recovery state.
export KUMWE_RESTORE_TARGET_TIME=9999-12-31T23:59:59Z
if bash "$script_directory/restore.sh" "$base" > "$root/unreachable.log" 2>&1; then
    fail 'a target newer than the payload was accepted'
fi
grep -q 'target later than the payload snapshot is refused' "$root/unreachable.log" \
    || fail 'unreachable target was refused for an unrelated reason'
printf 'Native %s before/after transaction recovery passed; evidence in %s\n' "$driver" "$root"
