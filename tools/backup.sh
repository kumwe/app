#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
source "${script_directory}/recovery-common.sh"

fail() {
    recovery_fail "Kumwe backup failed: $*"
}

recovery_begin backup backup

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

require_value() {
    local variable_name="$1"
    [[ -n "${!variable_name:-}" ]] || fail "required environment variable '$variable_name' is empty"
}

first_available_command() {
    local candidate

    for candidate in "$@"; do
        if command -v "$candidate" >/dev/null 2>&1; then
            command -v "$candidate"
            return 0
        fi
    done

    return 1
}

require_command date
require_command flock
require_command jq
require_command sha256sum
require_command cp
require_command find
require_command sort

require_value KUMWE_BACKUP_DIR
require_value KUMWE_DB_NAME
require_value KUMWE_DB_USER
require_value KUMWE_DB_PASSWORD_FILE
require_value KUMWE_EXTENSION_ASSETS_DIR
require_value KUMWE_EXTENSIONS_DIR
require_value KUMWE_MEDIA_DIR
require_value KUMWE_PRIVATE_DIR
require_value KUMWE_RELEASE

[[ "$KUMWE_RELEASE" =~ ^2\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]] \
    || fail 'KUMWE_RELEASE must identify a Kumwe 2.x release'
database_driver="${KUMWE_DB_DRIVER:-mariadb}"
[[ "$database_driver" =~ ^(mariadb|mysql|pgsql)$ ]] \
    || fail 'KUMWE_DB_DRIVER must be mariadb, mysql or pgsql'
table_prefix="${KUMWE_DB_TABLE_PREFIX:-kumwe_}"
[[ ${#table_prefix} -le 28 && "$table_prefix" =~ ^[a-z][a-z0-9]*(_[a-z0-9]+)*_$ ]] \
    || fail 'KUMWE_DB_TABLE_PREFIX must be a canonical lowercase prefix of at most 28 bytes'
[[ "${KUMWE_BACKUP_CONSISTENCY:-}" == 'quiesced' ]] \
    || fail 'set KUMWE_BACKUP_CONSISTENCY=quiesced after writes and media changes have been stopped'
[[ -d "$KUMWE_BACKUP_DIR" ]] || fail 'KUMWE_BACKUP_DIR must be an existing directory'
[[ -d "$KUMWE_MEDIA_DIR" ]] || fail 'KUMWE_MEDIA_DIR must be an existing directory'
[[ -d "$KUMWE_PRIVATE_DIR" ]] || fail 'KUMWE_PRIVATE_DIR must be an existing directory'
[[ -d "$KUMWE_EXTENSIONS_DIR" ]] || fail 'KUMWE_EXTENSIONS_DIR must be an existing directory'
[[ -d "$KUMWE_EXTENSION_ASSETS_DIR" ]] || fail 'KUMWE_EXTENSION_ASSETS_DIR must be an existing directory'
[[ -r "$KUMWE_DB_PASSWORD_FILE" ]] || fail 'KUMWE_DB_PASSWORD_FILE is not readable'

backup_root="$(cd -- "$KUMWE_BACKUP_DIR" && pwd -P)"
media_root="$(cd -- "$KUMWE_MEDIA_DIR" && pwd -P)"
private_root="$(cd -- "$KUMWE_PRIVATE_DIR" && pwd -P)"
extensions_root="$(cd -- "$KUMWE_EXTENSIONS_DIR" && pwd -P)"
extension_assets_root="$(cd -- "$KUMWE_EXTENSION_ASSETS_DIR" && pwd -P)"

case "$backup_root" in
    / | /home | /root | /workspace) fail "refusing unsafe backup root '$backup_root'" ;;
esac
case "$media_root" in
    / | /home | /root | /workspace) fail "refusing unsafe media root '$media_root'" ;;
esac
case "$private_root" in
    / | /home | /root | /workspace) fail "refusing unsafe private-data root '$private_root'" ;;
esac
case "$extensions_root" in
    / | /home | /root | /workspace) fail "refusing unsafe extensions root '$extensions_root'" ;;
esac
case "$extension_assets_root" in
    / | /home | /root | /workspace) fail "refusing unsafe extension assets root '$extension_assets_root'" ;;
esac

if find "$media_root" -xdev \( -type l -o \( ! -type f -a ! -type d \) \) -print -quit | grep -q .; then
    fail 'media tree contains a symbolic link or unsupported file type'
fi
if find "$private_root" -xdev \( -type l -o \( ! -type f -a ! -type d \) \) -print -quit | grep -q .; then
    fail 'private-data tree contains a symbolic link or unsupported file type'
fi
if find "$extensions_root" -xdev \( -type l -o \( ! -type f -a ! -type d \) \) -print -quit | grep -q .; then
    fail 'extensions tree contains a symbolic link or unsupported file type'
fi
if find "$extension_assets_root" -xdev \( -type l -o \( ! -type f -a ! -type d \) \) -print -quit | grep -q .; then
    fail 'extension assets tree contains a symbolic link or unsupported file type'
fi

while IFS= read -r -d '' source_path; do
    [[ "$source_path" != *$'\n'* && "$source_path" != *$'\r'* ]] \
        || fail 'media, private-data, or extension tree contains a filename with a line break'
done < <(find "$media_root" "$private_root" "$extensions_root" "$extension_assets_root" -xdev -print0)

database_password="$(<"$KUMWE_DB_PASSWORD_FILE")"
[[ -n "$database_password" ]] || fail 'database password file is empty'

pitr_mode="${KUMWE_BACKUP_PITR:-off}"
[[ "$pitr_mode" == on || "$pitr_mode" == off ]] || fail 'KUMWE_BACKUP_PITR must be on or off'
pitr='null'

# Refuse recursive snapshots before creating staging under the backup root.
for source_root in "$media_root" "$private_root" "$extensions_root" "$extension_assets_root"; do
    [[ "$backup_root/" != "$source_root/"* ]] || fail 'backup root is inside a payload tree'
    recovery_tree_safe "$source_root"
done

timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
backup_name="kumwe-${KUMWE_RELEASE}-${timestamp}"
final_directory="${backup_root}/${backup_name}"
staging_directory="${backup_root}/.${backup_name}.partial.$$"
[[ ! -e "$final_directory" ]] || fail "backup '$final_directory' already exists"
[[ ! -e "$staging_directory" ]] || fail "staging path '$staging_directory' already exists"

cleanup() {
    if [[ -d "$staging_directory" ]]; then
        find "$staging_directory" -depth -mindepth 1 -delete
        rmdir "$staging_directory" 2>/dev/null || true
    fi
}
trap 'recovery_finish $?; cleanup' EXIT
trap cleanup INT TERM

lock_path="${backup_root}/.kumwe-backup.lock"
[[ ! -L "$lock_path" ]] || fail 'backup lock path must not be a symbolic link'
[[ ! -e "$lock_path" || -f "$lock_path" ]] || fail 'backup lock path must be a regular file'
exec 9>>"$lock_path"
flock -n 9 || fail 'another backup process holds the destination lock'
install -d -m 0700 "$staging_directory"

database_host="${KUMWE_DB_HOST:-database}"
database_port="${KUMWE_DB_PORT:-$([[ "$database_driver" == pgsql ]] && echo 5432 || echo 3306)}"
migration_table="${table_prefix}schema_migrations"
required_migration='20260808010000_business_transactional_runtime'

if [[ "$database_driver" == pgsql ]]; then
    require_command pg_dump
    require_command psql
    export PGPASSWORD="$database_password"
    connection_arguments=(
        --dbname="$KUMWE_DB_NAME"
        --host="$database_host"
        --port="$database_port"
        --username="$KUMWE_DB_USER"
    )
    applied_migration="$(psql "${connection_arguments[@]}" --no-align --no-password --set=ON_ERROR_STOP=1 \
        --tuples-only --command="SELECT version FROM \"${migration_table}\" WHERE version = '${required_migration}'")"
    [[ "$applied_migration" == "$required_migration" ]] \
        || fail 'database is not a ready Kumwe 2.x schema; legacy and incomplete schemas are refused'
    pg_dump "${connection_arguments[@]}" \
        --file="${staging_directory}/database.dump" \
        --format=custom --compress=0 --no-owner --no-password --no-privileges --serializable-deferrable
    database_format='postgresql-custom'
    if [[ "$pitr_mode" == on ]]; then
        require_command pg_basebackup
        require_command pg_verifybackup
        pg_basebackup --host="$database_host" --port="$database_port" --username="$KUMWE_DB_USER" \
            --no-password --pgdata="$staging_directory/pg-base" --format=plain --wal-method=stream \
            --checkpoint=fast --manifest-checksums=SHA256
        recovery_tree_safe "$staging_directory/pg-base"
        pg_verifybackup "$staging_directory/pg-base" >&2
        jq -e '."WAL-Ranges" | length == 1' "$staging_directory/pg-base/backup_manifest" >/dev/null \
            || fail 'PITR base must cover exactly one timeline'
        source_id="$(psql "${connection_arguments[@]}" -XAt --set=ON_ERROR_STOP=1 \
            --command='SELECT system_identifier FROM pg_control_system()')"
        pitr="$(jq --arg source_id "$source_id" '."WAL-Ranges"[0] | {
            kind: "postgresql-wal", source_id: $source_id, timeline: .Timeline,
            start_lsn: ."Start-LSN", end_lsn: ."End-LSN"
        }' "$staging_directory/pg-base/backup_manifest")"
    fi
    unset PGPASSWORD
else
    dump_arguments=()
    if [[ "$database_driver" == mysql ]]; then
        database_client="$(first_available_command mysql)" \
            || fail 'the MySQL client is required for a MySQL backup'
        database_dump="$(first_available_command mysqldump)" \
            || fail 'mysqldump is required for a MySQL backup'
        dump_arguments+=(--no-tablespaces --set-gtid-purged=OFF)
    else
        database_client="$(first_available_command mariadb)" \
            || fail 'the MariaDB client is required for a MariaDB backup'
        database_dump="$(first_available_command mariadb-dump)" \
            || fail 'mariadb-dump is required for a MariaDB backup'
    fi
    export MYSQL_PWD="$database_password"
    connection_arguments=(
        --host="$database_host"
        --port="$database_port"
        --user="$KUMWE_DB_USER"
        --database="$KUMWE_DB_NAME"
        --batch
        --skip-column-names
    )
    applied_migration="$($database_client "${connection_arguments[@]}" \
        --execute="SELECT version FROM \`${migration_table}\` WHERE version = '${required_migration}'")"
    [[ "$applied_migration" == "$required_migration" ]] \
        || fail 'database is not a ready Kumwe 2.x schema; legacy and incomplete schemas are refused'
    if [[ "$pitr_mode" == on ]]; then
        status_sql='SHOW MASTER STATUS'
        gtid_sql='SELECT @@GLOBAL.gtid_binlog_pos'
        identity_sql='SELECT @@GLOBAL.server_id'
        if [[ "$database_driver" == mysql ]]; then
            status_sql='SHOW BINARY LOG STATUS'
            gtid_sql='SELECT @@GLOBAL.gtid_executed'
            identity_sql='SELECT @@GLOBAL.server_uuid'
        fi
        [[ "$($database_client "${connection_arguments[@]}" --execute='SELECT @@GLOBAL.binlog_format')" == ROW ]] \
            || fail 'PITR requires ROW binary logging'
        coordinate="$($database_client "${connection_arguments[@]}" --execute="$status_sql")"
        IFS=$'\t' read -r binlog_file binlog_position ignored <<< "$coordinate"
        [[ "$binlog_file" =~ ^[a-zA-Z0-9_-]+\.[0-9]+$ && "$binlog_position" =~ ^[0-9]+$ ]] \
            || fail 'binary logging is disabled or its coordinate is unavailable'
        pitr="$(jq -n --arg file "$binlog_file" --argjson position "$binlog_position" \
            --arg gtid "$($database_client "${connection_arguments[@]}" --raw --execute="$gtid_sql")" \
            --arg source_id "$($database_client "${connection_arguments[@]}" --execute="$identity_sql")" \
            '{kind: "binlog", file: $file, position: $position, gtid: $gtid, source_id: $source_id}')"
    fi
    "$database_dump" \
        --host="$database_host" \
        --port="$database_port" \
        --user="$KUMWE_DB_USER" \
        --single-transaction \
        --quick \
        --skip-dump-date \
        --skip-lock-tables \
        --triggers \
        --hex-blob \
        --default-character-set=utf8mb4 \
        "${dump_arguments[@]}" \
        "$KUMWE_DB_NAME" > "${staging_directory}/database.dump"
    database_format='mysql-sql'
    unset MYSQL_PWD
fi
unset database_password

for tree in media private extensions extension-assets; do
    case "$tree" in
        media) source_root="$media_root" ;;
        private) source_root="$private_root" ;;
        extensions) source_root="$extensions_root" ;;
        extension-assets) source_root="$extension_assets_root" ;;
    esac
    cp -a --one-file-system --reflink=auto -- "$source_root" "$staging_directory/$tree"
done
recovery_tree_safe "$staging_directory"
if [[ "$database_driver" == pgsql && "$pitr_mode" == on ]]; then
    export PGPASSWORD="$(<"$KUMWE_DB_PASSWORD_FILE")"
    target_name="kumwe_${timestamp}_$$"
    target_lsn="$(psql "${connection_arguments[@]}" -XAt --set=ON_ERROR_STOP=1 \
        --command="SELECT pg_create_restore_point('$target_name')")"
    pitr="$(jq --arg name "$target_name" --arg lsn "$target_lsn" \
        '. + {target_name: $name, target_lsn: $lsn}' <<< "$pitr")"
    psql "${connection_arguments[@]}" -XAt --set=ON_ERROR_STOP=1 --command='SELECT pg_switch_wal()' >&2
    unset PGPASSWORD
fi
payload_snapshot_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

jq -n \
    --argjson directories "$(recovery_directories "$staging_directory")" \
    --argjson pitr "$pitr" \
    --arg payload_snapshot_at "$payload_snapshot_at" \
    --arg created_at "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" \
    --arg database "$KUMWE_DB_NAME" \
    --arg database_driver "$database_driver" \
    --arg database_format "$database_format" \
    --arg database_table_prefix "$table_prefix" \
    --arg release "$KUMWE_RELEASE" \
    '{
        format: "kumwe-backup-v3",
        directories: $directories,
        pitr: $pitr,
        payload_snapshot_at: $payload_snapshot_at,
        product: "Kumwe App",
        product_major: 2,
        release: $release,
        created_at: $created_at,
        database: $database,
        database_driver: $database_driver,
        database_format: $database_format,
        database_table_prefix: $database_table_prefix,
        contents: ["database.dump", "extension-assets", "extensions", "media", "private"]
    }' > "${staging_directory}/manifest.json"

recovery_checksums "$staging_directory" > "$staging_directory/checksums.sha256"

if [[ -n "${KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE:-}" ]]; then
    require_command minisign
    [[ -r "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" ]] || fail 'backup signing key file is not readable'
    minisign -S -s "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" \
        -m "${staging_directory}/checksums.sha256" \
        -x "${staging_directory}/checksums.sha256.minisig" >&2
fi

chmod -R go-rwx "$staging_directory"
mv -- "$staging_directory" "$final_directory"
trap 'recovery_finish $?' EXIT
trap - INT TERM
recovery_log info 'Kumwe backup snapshot written.' backup "$backup_name" database_driver "$database_driver"

echo "$final_directory"
