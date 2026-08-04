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

[[ $# -eq 1 ]] || fail 'usage: tools/restore.sh /absolute/path/to/backup'

require_command pg_restore
require_command psql
require_command tar
require_value KUMWE_RESTORE_DB_NAME
require_value KUMWE_RESTORE_DB_USER
require_value KUMWE_RESTORE_DB_PASSWORD_FILE
require_value KUMWE_RESTORE_EXTENSIONS_DIR
require_value KUMWE_RESTORE_MEDIA_DIR

[[ "$KUMWE_RESTORE_EXTENSIONS_DIR" = /* ]] || fail 'KUMWE_RESTORE_EXTENSIONS_DIR must be absolute'
[[ "$KUMWE_RESTORE_MEDIA_DIR" = /* ]] || fail 'KUMWE_RESTORE_MEDIA_DIR must be absolute'
[[ ! -e "$KUMWE_RESTORE_EXTENSIONS_DIR" ]] || fail 'extensions target must not exist'
[[ ! -e "$KUMWE_RESTORE_MEDIA_DIR" ]] || fail 'media target must not exist'
[[ -r "$KUMWE_RESTORE_DB_PASSWORD_FILE" ]] || fail 'database password file is not readable'

case "$KUMWE_RESTORE_EXTENSIONS_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe extensions target' ;;
esac
case "$KUMWE_RESTORE_MEDIA_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe media target' ;;
esac

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
"${script_directory}/restore-verify.sh" "$1"
backup_directory="$(cd -- "$1" && pwd -P)"
database_schema="${KUMWE_RESTORE_DB_SCHEMA:-kumwe}"
[[ "$database_schema" =~ ^[A-Za-z_][A-Za-z0-9_]{0,62}$ ]] || fail 'restore database schema is invalid'

database_password="$(<"$KUMWE_RESTORE_DB_PASSWORD_FILE")"
[[ -n "$database_password" ]] || fail 'database password file is empty'
export PGPASSWORD="$database_password"
connection_arguments=(
    --dbname="$KUMWE_RESTORE_DB_NAME"
    --host="${KUMWE_RESTORE_DB_HOST:-postgres}"
    --port="${KUMWE_RESTORE_DB_PORT:-5432}"
    --username="$KUMWE_RESTORE_DB_USER"
    --no-password
)

existing_relations="$(psql "${connection_arguments[@]}" --no-align --tuples-only --set=ON_ERROR_STOP=1 \
    --command="SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND c.relkind IN ('r', 'p', 'v', 'm', 'S')")"
[[ "$existing_relations" == '0' ]] || fail 'restore database is not empty'

media_parent="$(dirname -- "$KUMWE_RESTORE_MEDIA_DIR")"
extensions_parent="$(dirname -- "$KUMWE_RESTORE_EXTENSIONS_DIR")"
[[ -d "$media_parent" && -d "$extensions_parent" ]] || fail 'restore target parent directories must exist'
media_staging="${KUMWE_RESTORE_MEDIA_DIR}.partial.$$"
extensions_staging="${KUMWE_RESTORE_EXTENSIONS_DIR}.partial.$$"

cleanup() {
    if [[ -d "$media_staging" ]]; then
        find "$media_staging" -depth -mindepth 1 -delete
        rmdir "$media_staging" 2>/dev/null || true
    fi
    if [[ -d "$extensions_staging" ]]; then
        find "$extensions_staging" -depth -mindepth 1 -delete
        rmdir "$extensions_staging" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM
install -d -m 0750 "$media_staging" "$extensions_staging"
tar --extract --gzip --file="${backup_directory}/media.tar.gz" --directory="$media_staging" \
    --no-same-owner --no-same-permissions
tar --extract --gzip --file="${backup_directory}/extensions.tar.gz" --directory="$extensions_staging" \
    --no-same-owner --no-same-permissions

pg_restore "${connection_arguments[@]}" --exit-on-error --single-transaction --no-owner --no-privileges \
    "${backup_directory}/database.dump"

required_migration='20260804000800_create_application_runtime'
applied_migration="$(psql "${connection_arguments[@]}" --no-align --tuples-only --set=ON_ERROR_STOP=1 \
    --command="SELECT version FROM \"${database_schema}\".\"schema_migrations\" WHERE version = '${required_migration}'")"
[[ "$applied_migration" == "$required_migration" ]] || fail 'restored database is missing the required runtime migration'

mv -- "$media_staging" "$KUMWE_RESTORE_MEDIA_DIR"
mv -- "$extensions_staging" "$KUMWE_RESTORE_EXTENSIONS_DIR"
trap - EXIT INT TERM
unset PGPASSWORD database_password

echo "Restored Kumwe backup into database '$KUMWE_RESTORE_DB_NAME' and new filesystem targets."
