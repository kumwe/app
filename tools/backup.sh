#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    echo "Kumwe backup failed: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

require_value() {
    local variable_name="$1"
    [[ -n "${!variable_name:-}" ]] || fail "required environment variable '$variable_name' is empty"
}

require_command date
require_command flock
require_command jq
require_command pg_dump
require_command psql
require_command sha256sum
require_command tar

require_value KUMWE_BACKUP_DIR
require_value KUMWE_DB_NAME
require_value KUMWE_DB_USER
require_value KUMWE_DB_PASSWORD_FILE
require_value KUMWE_MEDIA_DIR
require_value KUMWE_RELEASE

[[ "$KUMWE_RELEASE" =~ ^2\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]] \
    || fail 'KUMWE_RELEASE must identify a Kumwe 2.x release'
database_schema="${KUMWE_DB_SCHEMA:-kumwe}"
[[ "$database_schema" =~ ^[A-Za-z_][A-Za-z0-9_]{0,62}$ ]] \
    || fail 'KUMWE_DB_SCHEMA must be a PostgreSQL identifier'
[[ "${KUMWE_BACKUP_CONSISTENCY:-}" == 'quiesced' ]] \
    || fail 'set KUMWE_BACKUP_CONSISTENCY=quiesced after writes and media changes have been stopped'
[[ -d "$KUMWE_BACKUP_DIR" ]] || fail 'KUMWE_BACKUP_DIR must be an existing directory'
[[ -d "$KUMWE_MEDIA_DIR" ]] || fail 'KUMWE_MEDIA_DIR must be an existing directory'
[[ -r "$KUMWE_DB_PASSWORD_FILE" ]] || fail 'KUMWE_DB_PASSWORD_FILE is not readable'

backup_root="$(cd -- "$KUMWE_BACKUP_DIR" && pwd -P)"
media_root="$(cd -- "$KUMWE_MEDIA_DIR" && pwd -P)"

case "$backup_root" in
    / | /home | /root | /workspace)
        fail "refusing unsafe backup root '$backup_root'"
        ;;
esac

case "$media_root" in
    / | /home | /root | /workspace)
        fail "refusing unsafe media root '$media_root'"
        ;;
esac

if find "$media_root" -xdev \( -type l -o \( ! -type f -a ! -type d \) \) -print -quit | grep -q .; then
    fail 'media tree contains a symbolic link or unsupported file type'
fi

while IFS= read -r -d '' media_path; do
    [[ "$media_path" != *$'\n'* && "$media_path" != *$'\r'* ]] \
        || fail 'media tree contains a filename with a line break'
done < <(find "$media_root" -xdev -print0)

database_password="$(<"$KUMWE_DB_PASSWORD_FILE")"
[[ -n "$database_password" ]] || fail 'database password file is empty'

timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
backup_name="kumwe-${KUMWE_RELEASE}-${timestamp}"
final_directory="${backup_root}/${backup_name}"
staging_directory="${backup_root}/.${backup_name}.partial.$$"

[[ ! -e "$final_directory" ]] || fail "backup '$final_directory' already exists"
[[ ! -e "$staging_directory" ]] || fail "staging path '$staging_directory' already exists"

cleanup() {
    if [[ -d "$staging_directory" ]]; then
        find "$staging_directory" -mindepth 1 -maxdepth 1 -type f -delete
        rmdir "$staging_directory" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

lock_path="${backup_root}/.kumwe-backup.lock"
[[ ! -L "$lock_path" ]] || fail 'backup lock path must not be a symbolic link'
[[ ! -e "$lock_path" || -f "$lock_path" ]] || fail 'backup lock path must be a regular file'
exec 9>>"$lock_path"
flock -n 9 || fail 'another backup process holds the destination lock'

install -d -m 0700 "$staging_directory"

export PGPASSWORD="$database_password"
connection_arguments=(
    --dbname="$KUMWE_DB_NAME"
    --host="${KUMWE_DB_HOST:-postgres}"
    --port="${KUMWE_DB_PORT:-5432}"
    --username="$KUMWE_DB_USER"
)

required_migration='20260804000700_create_automation_platform'
applied_migration="$(
    psql \
        "${connection_arguments[@]}" \
        --no-align \
        --no-password \
        --set=ON_ERROR_STOP=1 \
        --tuples-only \
        --command="SELECT version FROM \"${database_schema}\".\"schema_migrations\" WHERE version = '${required_migration}'"
)"
[[ "$applied_migration" == "$required_migration" ]] \
    || fail 'database is not a ready Kumwe 2.x schema; legacy and incomplete schemas are refused'

pg_dump \
    "${connection_arguments[@]}" \
    --file="${staging_directory}/database.dump" \
    --format=custom \
    --no-owner \
    --no-password \
    --no-privileges \
    --serializable-deferrable
unset PGPASSWORD database_password

tar \
    --create \
    --gzip \
    --one-file-system \
    --file="${staging_directory}/media.tar.gz" \
    --directory="$media_root" \
    .

if tar --list --verbose --gzip --file="${staging_directory}/media.tar.gz" \
    | awk 'substr($1, 1, 1) !~ /^[-d]$/ { found = 1 } END { exit found ? 0 : 1 }'; then
    fail 'created media archive contains a symbolic link or unsupported file type'
fi

jq -n \
    --arg created_at "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" \
    --arg database "$KUMWE_DB_NAME" \
    --arg database_schema "$database_schema" \
    --arg release "$KUMWE_RELEASE" \
    '{
        format: "kumwe-backup-v2",
        product: "Kumwe CMS",
        product_major: 2,
        release: $release,
        created_at: $created_at,
        database: $database,
        database_schema: $database_schema,
        contents: ["database.dump", "media.tar.gz"]
    }' > "${staging_directory}/manifest.json"

(
    cd -- "$staging_directory"
    sha256sum database.dump manifest.json media.tar.gz > checksums.sha256
)

if [[ -n "${KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE:-}" ]]; then
    require_command minisign
    [[ -r "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" ]] || fail 'backup signing key file is not readable'
    minisign \
        -S \
        -s "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" \
        -m "${staging_directory}/checksums.sha256" \
        -x "${staging_directory}/checksums.sha256.minisig"
fi

chmod -R go-rwx "$staging_directory"
mv -- "$staging_directory" "$final_directory"
trap - EXIT INT TERM

echo "$final_directory"
