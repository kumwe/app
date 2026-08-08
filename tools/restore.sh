#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    echo "Kumwe restore failed: $*" >&2
    exit 1
}

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

[[ $# -eq 1 ]] || fail 'usage: tools/restore.sh /absolute/path/to/backup'

require_command jq
require_command tar
require_value KUMWE_RESTORE_DB_NAME
require_value KUMWE_RESTORE_DB_USER
require_value KUMWE_RESTORE_DB_PASSWORD_FILE
require_value KUMWE_RESTORE_EXTENSION_ASSETS_DIR
require_value KUMWE_RESTORE_EXTENSIONS_DIR
require_value KUMWE_RESTORE_MEDIA_DIR

[[ "$KUMWE_RESTORE_EXTENSIONS_DIR" = /* ]] || fail 'KUMWE_RESTORE_EXTENSIONS_DIR must be absolute'
[[ "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" = /* ]] || fail 'KUMWE_RESTORE_EXTENSION_ASSETS_DIR must be absolute'
[[ "$KUMWE_RESTORE_MEDIA_DIR" = /* ]] || fail 'KUMWE_RESTORE_MEDIA_DIR must be absolute'
[[ ! -e "$KUMWE_RESTORE_EXTENSIONS_DIR" ]] || fail 'extensions target must not exist'
[[ ! -e "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" ]] || fail 'extension assets target must not exist'
[[ ! -e "$KUMWE_RESTORE_MEDIA_DIR" ]] || fail 'media target must not exist'
[[ -r "$KUMWE_RESTORE_DB_PASSWORD_FILE" ]] || fail 'database password file is not readable'

case "$KUMWE_RESTORE_EXTENSIONS_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe extensions target' ;;
esac
case "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe extension assets target' ;;
esac
case "$KUMWE_RESTORE_MEDIA_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe media target' ;;
esac

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
bash "${script_directory}/restore-verify.sh" "$1"
backup_directory="$(cd -- "$1" && pwd -P)"
manifest_driver="$(jq -r '.database_driver' "${backup_directory}/manifest.json")"
database_driver="${KUMWE_RESTORE_DB_DRIVER:-$manifest_driver}"
[[ "$database_driver" == "$manifest_driver" ]] \
    || fail "backup database driver '$manifest_driver' cannot be restored as '$database_driver'"
table_prefix="${KUMWE_RESTORE_DB_TABLE_PREFIX:-$(jq -r '.database_table_prefix' "${backup_directory}/manifest.json")}"
[[ ${#table_prefix} -le 28 && "$table_prefix" =~ ^[a-z][a-z0-9]*(_[a-z0-9]+)*_$ ]] \
    || fail 'restore database table prefix is invalid'

database_password="$(<"$KUMWE_RESTORE_DB_PASSWORD_FILE")"
[[ -n "$database_password" ]] || fail 'database password file is empty'
database_host="${KUMWE_RESTORE_DB_HOST:-database}"
database_port="${KUMWE_RESTORE_DB_PORT:-$([[ "$database_driver" == pgsql ]] && echo 5432 || echo 3306)}"
migration_table="${table_prefix}schema_migrations"
required_migration='20260808010000_business_transactional_runtime'

media_parent="$(dirname -- "$KUMWE_RESTORE_MEDIA_DIR")"
extensions_parent="$(dirname -- "$KUMWE_RESTORE_EXTENSIONS_DIR")"
extension_assets_parent="$(dirname -- "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR")"
[[ -d "$media_parent" && -d "$extensions_parent" && -d "$extension_assets_parent" ]] \
    || fail 'restore target parent directories must exist'
media_staging="${KUMWE_RESTORE_MEDIA_DIR}.partial.$$"
extensions_staging="${KUMWE_RESTORE_EXTENSIONS_DIR}.partial.$$"
extension_assets_staging="${KUMWE_RESTORE_EXTENSION_ASSETS_DIR}.partial.$$"

cleanup() {
    if [[ -d "$media_staging" ]]; then
        find "$media_staging" -depth -mindepth 1 -delete
        rmdir "$media_staging" 2>/dev/null || true
    fi
    if [[ -d "$extensions_staging" ]]; then
        find "$extensions_staging" -depth -mindepth 1 -delete
        rmdir "$extensions_staging" 2>/dev/null || true
    fi
    if [[ -d "$extension_assets_staging" ]]; then
        find "$extension_assets_staging" -depth -mindepth 1 -delete
        rmdir "$extension_assets_staging" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

install -d -m 0750 "$media_staging" "$extensions_staging" "$extension_assets_staging"
tar --extract --gzip --file="${backup_directory}/media.tar.gz" --directory="$media_staging" \
    --no-same-owner --no-same-permissions
tar --extract --gzip --file="${backup_directory}/extensions.tar.gz" --directory="$extensions_staging" \
    --no-same-owner --no-same-permissions
tar --extract --gzip --file="${backup_directory}/extension-assets.tar.gz" \
    --directory="$extension_assets_staging" --no-same-owner --no-same-permissions

if [[ "$database_driver" == pgsql ]]; then
    require_command pg_restore
    require_command psql
    export PGPASSWORD="$database_password"
    connection_arguments=(
        --dbname="$KUMWE_RESTORE_DB_NAME"
        --host="$database_host"
        --port="$database_port"
        --username="$KUMWE_RESTORE_DB_USER"
        --no-password
    )
    existing_relations="$(psql "${connection_arguments[@]}" --no-align --tuples-only --set=ON_ERROR_STOP=1 \
        --command="SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')")"
    [[ "$existing_relations" == '0' ]] || fail 'restore database is not empty'
    pg_restore "${connection_arguments[@]}" --exit-on-error --single-transaction --no-owner --no-privileges \
        "${backup_directory}/database.dump"
    applied_migration="$(psql "${connection_arguments[@]}" --no-align --tuples-only --set=ON_ERROR_STOP=1 \
        --command="SELECT version FROM \"${migration_table}\" WHERE version = '${required_migration}'")"
    unset PGPASSWORD
else
    if [[ "$database_driver" == mysql ]]; then
        database_client="$(first_available_command mysql)" \
            || fail 'the MySQL client is required for a MySQL restore'
    else
        database_client="$(first_available_command mariadb)" \
            || fail 'the MariaDB client is required for a MariaDB restore'
    fi
    export MYSQL_PWD="$database_password"
    connection_arguments=(
        --host="$database_host"
        --port="$database_port"
        --user="$KUMWE_RESTORE_DB_USER"
        --database="$KUMWE_RESTORE_DB_NAME"
        --batch
        --skip-column-names
    )
    existing_relations="$($database_client "${connection_arguments[@]}" \
        --execute="SELECT count(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
    [[ "$existing_relations" == '0' ]] || fail 'restore database is not empty'
    "$database_client" "${connection_arguments[@]}" --binary-mode < "${backup_directory}/database.dump"
    applied_migration="$($database_client "${connection_arguments[@]}" \
        --execute="SELECT version FROM \`${migration_table}\` WHERE version = '${required_migration}'")"
    unset MYSQL_PWD
fi

[[ "$applied_migration" == "$required_migration" ]] \
    || fail 'restored database is missing the required runtime migration'

mv -- "$media_staging" "$KUMWE_RESTORE_MEDIA_DIR"
mv -- "$extensions_staging" "$KUMWE_RESTORE_EXTENSIONS_DIR"
mv -- "$extension_assets_staging" "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR"
trap - EXIT INT TERM
unset database_password

echo "Restored Kumwe backup into database '$KUMWE_RESTORE_DB_NAME' and new filesystem targets."
