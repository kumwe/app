#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
source "${script_directory}/recovery-common.sh"

fail() {
    recovery_fail "Kumwe restore failed: $*"
}

recovery_begin restore restore

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

require_command cp
require_command flock
require_command realpath
require_command awk
require_command date
require_command jq
require_command sha256sum
require_command sort
require_command tar
require_command tr
require_command wc
require_command xargs
require_value KUMWE_RESTORE_DB_NAME
require_value KUMWE_RESTORE_DB_USER
require_value KUMWE_RESTORE_DB_PASSWORD_FILE
require_value KUMWE_RESTORE_EXTENSION_ASSETS_DIR
require_value KUMWE_RESTORE_EXTENSIONS_DIR
require_value KUMWE_RESTORE_MEDIA_DIR
require_value KUMWE_RESTORE_PRIVATE_DIR

[[ "$KUMWE_RESTORE_EXTENSIONS_DIR" = /* ]] || fail 'KUMWE_RESTORE_EXTENSIONS_DIR must be absolute'
[[ "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" = /* ]] || fail 'KUMWE_RESTORE_EXTENSION_ASSETS_DIR must be absolute'
[[ "$KUMWE_RESTORE_MEDIA_DIR" = /* ]] || fail 'KUMWE_RESTORE_MEDIA_DIR must be absolute'
[[ "$KUMWE_RESTORE_PRIVATE_DIR" = /* ]] || fail 'KUMWE_RESTORE_PRIVATE_DIR must be absolute'
[[ -r "$KUMWE_RESTORE_DB_PASSWORD_FILE" ]] || fail 'database password file is not readable'

# A restore that is interrupted leaves work behind that only this tool knows it created. The
# completion manifest records that a restore finished, so an operator (and a first-boot check) can
# tell a finished restore from an abandoned one instead of inferring it from the presence of files.
# Its `.partial` companion is the claim that says a restore is under way and which targets it owns:
# a re-run may clear exactly those and start over, and may clear nothing else, so recovering from an
# interruption never becomes a licence to delete data this tool did not put there.
restore_manifest="${KUMWE_RESTORE_MANIFEST:-$(dirname -- "$KUMWE_RESTORE_PRIVATE_DIR")/kumwe-restore-manifest.json}"
[[ "$restore_manifest" = /* ]] || fail 'KUMWE_RESTORE_MANIFEST must be absolute'
restore_claim="${restore_manifest}.partial"

case "$KUMWE_RESTORE_EXTENSIONS_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe extensions target' ;;
esac
case "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe extension assets target' ;;
esac
case "$KUMWE_RESTORE_MEDIA_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe media target' ;;
esac
case "$KUMWE_RESTORE_PRIVATE_DIR" in
    / | /home | /root | /workspace) fail 'refusing unsafe private-data target' ;;
esac

bash "${script_directory}/restore-verify.sh" "$1"
backup_directory="$(cd -- "$1" && pwd -P)"
database_backup_directory="$backup_directory"
pitr_restore=false
if [[ -n "${KUMWE_RESTORE_PAYLOAD_BACKUP:-}" ]]; then
    bash "$script_directory/restore-verify.sh" "$KUMWE_RESTORE_PAYLOAD_BACKUP"
    backup_directory="$(cd -- "$KUMWE_RESTORE_PAYLOAD_BACKUP" && pwd -P)"
    recovery_target_pair "$database_backup_directory" "$backup_directory"
    pitr_restore=true
elif [[ -n "${KUMWE_RESTORE_TARGET_TIME:-}" ]]; then
    fail 'A target later than the payload snapshot is refused; select a verified coherent payload snapshot.'
fi
manifest_driver="$(jq -r '.database_driver' "${backup_directory}/manifest.json")"
database_driver="${KUMWE_RESTORE_DB_DRIVER:-$manifest_driver}"
[[ "$database_driver" == "$manifest_driver" ]] \
    || fail "backup database driver '$manifest_driver' cannot be restored as '$database_driver'"
table_prefix="${KUMWE_RESTORE_DB_TABLE_PREFIX:-$(jq -r '.database_table_prefix' "${backup_directory}/manifest.json")}"
[[ ${#table_prefix} -le 28 && "$table_prefix" =~ ^[a-z][a-z0-9]*(_[a-z0-9]+)*_$ ]] \
    || fail 'restore database table prefix is invalid'

[[ "$table_prefix" == "$(jq -r '.database_table_prefix' "$backup_directory/manifest.json")" ]] \
    || fail 'restore table prefix must match the backup'

database_password="$(<"$KUMWE_RESTORE_DB_PASSWORD_FILE")"
[[ -n "$database_password" ]] || fail 'database password file is empty'
database_host="${KUMWE_RESTORE_DB_HOST:-database}"
database_port="${KUMWE_RESTORE_DB_PORT:-$([[ "$database_driver" == pgsql ]] && echo 5432 || echo 3306)}"
migration_table="${table_prefix}schema_migrations"
required_migration='20260808010000_business_transactional_runtime'

media_parent="$(dirname -- "$KUMWE_RESTORE_MEDIA_DIR")"
private_parent="$(dirname -- "$KUMWE_RESTORE_PRIVATE_DIR")"
extensions_parent="$(dirname -- "$KUMWE_RESTORE_EXTENSIONS_DIR")"
extension_assets_parent="$(dirname -- "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR")"
[[ -d "$media_parent" && -d "$private_parent" && -d "$extensions_parent" && -d "$extension_assets_parent" ]] \
    || fail 'restore target parent directories must exist'

# Canonical, distinct, non-overlapping targets keep a resumed claim from deleting unrelated data.
targets=()
for variable in KUMWE_RESTORE_MEDIA_DIR KUMWE_RESTORE_PRIVATE_DIR KUMWE_RESTORE_EXTENSIONS_DIR \
    KUMWE_RESTORE_EXTENSION_ASSETS_DIR; do
    path="${!variable}"
    [[ "$path" == "$(realpath -m -- "$path")" ]] || fail 'restore targets must be canonical absolute paths'
    for other in "${targets[@]}" "$backup_directory" "$database_backup_directory"; do
        [[ "$path/" != "$other/"* && "$other/" != "$path/"* ]] || fail 'restore targets overlap'
    done
    targets+=("$path")
done
[[ "$restore_manifest" == "$(realpath -m -- "$restore_manifest")" ]] || fail 'manifest path must be canonical'
for path in "${targets[@]}" "$backup_directory" "$database_backup_directory"; do
    [[ "$restore_manifest" != "$path/"* ]] || fail 'manifest must be outside restore and backup trees'
done
[[ ! -L "$restore_claim" && ! -L "$restore_manifest" && ! -L "${restore_manifest}.lock" ]] \
    || fail 'restore metadata must not be symbolic links'
exec 8>>"${restore_manifest}.lock"
flock -n 8 || fail 'another restore owns this completion manifest'
[[ ! -e "$restore_manifest" ]] || fail 'restore already completed; use new targets'
base_id="$(sha256sum "$database_backup_directory/checksums.sha256" | awk '{print $1}')"
recovery_binding="$(jq -cn --arg base "$base_id" --arg host "$database_host" --arg port "$database_port" \
    --arg driver "$database_driver" --arg prefix "$table_prefix" --arg pgdata "${KUMWE_RESTORE_PGDATA:-}" \
    --argjson replay "$pitr_restore" \
    '{base: $base, host: $host, port: $port, driver: $driver, prefix: $prefix, pgdata: $pgdata, replay: $replay}')"

# The backup identifies itself by the digest of its own checksum list, which restore-verify.sh has
# just proven covers every file in the archive. A resumed run therefore has to be resuming the same
# bytes, not merely a backup with the same path.
backup_id="$(sha256sum "${backup_directory}/checksums.sha256" | awk '{print $1}')"
resumed_restore=0
database_imported=0

if [[ -e "$restore_claim" ]]; then
    [[ -f "$restore_claim" && ! -L "$restore_claim" ]] || fail 'the restore claim is not a regular file'
    jq -e '.format == "kumwe-restore-claim-v1"' "$restore_claim" >/dev/null \
        || fail 'the restore claim is not readable; remove it by hand once its targets are dealt with'
    [[ "$(jq -r '.backup_id' "$restore_claim")" == "$backup_id" ]] \
        || fail 'an interrupted restore of a different backup owns these targets; resolve it before re-running'
    claimed_targets="$(jq -Sr '.targets' "$restore_claim")"
    current_targets="$(jq -Snr \
        --arg extensions "$KUMWE_RESTORE_EXTENSIONS_DIR" \
        --arg extension_assets "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" \
        --arg media "$KUMWE_RESTORE_MEDIA_DIR" \
        --arg private "$KUMWE_RESTORE_PRIVATE_DIR" \
        '{extensions: $extensions, extension_assets: $extension_assets, media: $media, private: $private}')"
    [[ "$claimed_targets" == "$current_targets" ]] \
        || fail 'the interrupted restore owns different targets; resolve it before re-running'
    [[ "$(jq -r '.database' "$restore_claim")" == "$KUMWE_RESTORE_DB_NAME" ]] \
        || fail 'the interrupted restore owns a different database; resolve it before re-running'
    [[ "$(jq -cS '.recovery_binding' "$restore_claim")" == "$(jq -cS . <<< "$recovery_binding")" ]] \
        || fail 'the interrupted restore owns a different database connection or recovery target'
    resumed_restore=1
    if [[ "$(jq -r 'if .database_imported then "yes" else "no" end' "$restore_claim")" == 'yes' ]]; then
        database_imported=1
    fi
    echo "Resuming an interrupted restore of backup ${backup_id:0:12}." >&2
fi

# A target may exist only when this tool's own claim says this tool created it. Anything else is
# somebody else's data and the restore refuses, exactly as it always has.
reclaim_target() {
    local path="$1"
    local label="$2"

    [[ -e "$path" || -L "$path" ]] || return 0
    [[ $resumed_restore -eq 1 ]] || fail "$label target must not exist"
    [[ -d "$path" && ! -L "$path" ]] || fail "$label target claimed by the interrupted restore is not a directory"
    find "$path" -depth -mindepth 1 -delete
    rmdir "$path"
}

replay_sql=''
if [[ "$pitr_restore" == true && "$database_driver" != pgsql && $database_imported == 0 ]]; then
    replay_sql="$(mktemp)"
    trap 'recovery_finish $?; rm -f -- "$replay_sql"' EXIT
    bash "$script_directory/recovery-binlog.sh" "$database_backup_directory" "$backup_directory" > "$replay_sql"
fi

reclaim_target "$KUMWE_RESTORE_EXTENSIONS_DIR" 'extensions'
reclaim_target "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" 'extension assets'
reclaim_target "$KUMWE_RESTORE_MEDIA_DIR" 'media'
reclaim_target "$KUMWE_RESTORE_PRIVATE_DIR" 'private-data'

write_claim() {
    local imported="$1"
    local claim_staging="${restore_claim}.$$"

    jq -n \
        --argjson recovery_binding "$recovery_binding" \
        --arg backup_id "$backup_id" \
        --arg started_at "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" \
        --arg database "$KUMWE_RESTORE_DB_NAME" \
        --arg extensions "$KUMWE_RESTORE_EXTENSIONS_DIR" \
        --arg extension_assets "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" \
        --arg media "$KUMWE_RESTORE_MEDIA_DIR" \
        --arg private "$KUMWE_RESTORE_PRIVATE_DIR" \
        --argjson database_imported "$imported" \
        '{
            format: "kumwe-restore-claim-v1",
            backup_id: $backup_id,
            recovery_binding: $recovery_binding,
            started_at: $started_at,
            database: $database,
            database_imported: $database_imported,
            targets: {
                extensions: $extensions,
                extension_assets: $extension_assets,
                media: $media,
                private: $private
            }
        }' > "$claim_staging"
    chmod 0600 "$claim_staging"
    mv -- "$claim_staging" "$restore_claim"
}

if [[ $database_imported -eq 1 ]]; then
    write_claim true
else
    write_claim false
fi
media_staging="${KUMWE_RESTORE_MEDIA_DIR}.partial.$$"
private_staging="${KUMWE_RESTORE_PRIVATE_DIR}.partial.$$"
extensions_staging="${KUMWE_RESTORE_EXTENSIONS_DIR}.partial.$$"
extension_assets_staging="${KUMWE_RESTORE_EXTENSION_ASSETS_DIR}.partial.$$"

not_empty_message='restore database is not empty'
if [[ $resumed_restore -eq 1 ]]; then
    not_empty_message='restore database is not empty and the interrupted restore never finished importing it;'
    not_empty_message+=' drop and recreate an empty database, then re-run this command'
fi

cleanup() {
    [[ -z "$replay_sql" ]] || rm -f -- "$replay_sql"
    if [[ -d "$media_staging" ]]; then
        find "$media_staging" -depth -mindepth 1 -delete
        rmdir "$media_staging" 2>/dev/null || true
    fi
    if [[ -d "$private_staging" ]]; then
        find "$private_staging" -depth -mindepth 1 -delete
        rmdir "$private_staging" 2>/dev/null || true
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
trap 'recovery_finish $?; cleanup' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

install -d -m 0750 "$media_staging" "$private_staging" "$extensions_staging" "$extension_assets_staging"
if [[ "$(jq -r '.format' "$backup_directory/manifest.json")" == kumwe-backup-v3 ]]; then
    cp -a --one-file-system --reflink=auto -- "$backup_directory/media/." "$media_staging/"
    cp -a --one-file-system --reflink=auto -- "$backup_directory/private/." "$private_staging/"
    cp -a --one-file-system --reflink=auto -- "$backup_directory/extensions/." "$extensions_staging/"
    cp -a --one-file-system --reflink=auto -- "$backup_directory/extension-assets/." "$extension_assets_staging/"
else
tar --extract --gzip --file="${backup_directory}/media.tar.gz" --directory="$media_staging" \
    --no-same-owner --no-same-permissions
tar --extract --gzip --file="${backup_directory}/private.tar.gz" --directory="$private_staging" \
    --no-same-owner --no-same-permissions
find "$private_staging" -xdev -type d -exec chmod 0700 {} +
find "$private_staging" -xdev -type f -exec chmod 0600 {} +
tar --extract --gzip --file="${backup_directory}/extensions.tar.gz" --directory="$extensions_staging" \
    --no-same-owner --no-same-permissions
tar --extract --gzip --file="${backup_directory}/extension-assets.tar.gz" \
    --directory="$extension_assets_staging" --no-same-owner --no-same-permissions

fi
find "$private_staging" -xdev -type d -exec chmod 0700 {} +
find "$private_staging" -xdev -type f -exec chmod 0600 {} +

if [[ "$database_driver" == pgsql && "$pitr_restore" == true ]]; then
    [[ $database_imported == 0 ]] || fail 'physical PITR resume requires a new clean target and claim'
    bash "$script_directory/recovery-postgres.sh" "$database_backup_directory" "$backup_directory"
    write_claim true
    applied_migration="$required_migration" # Native recovery verified it before stopping the private cluster.
elif [[ "$database_driver" == pgsql ]]; then
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
    if [[ $database_imported -eq 0 ]]; then
        existing_relations="$(psql "${connection_arguments[@]}" --no-align --tuples-only --set=ON_ERROR_STOP=1 \
            --command="SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')")"
        [[ "$existing_relations" == '0' ]] || fail "$not_empty_message"
        # The import is one transaction, so it either lands whole or leaves the database as empty as
        # it found it. That is what lets an interrupted restore be resumed without a manual drop.
        pg_restore "${connection_arguments[@]}" --exit-on-error --single-transaction --no-owner --no-privileges \
            "${database_backup_directory}/database.dump"
        write_claim true
    fi
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
    if [[ $database_imported -eq 0 ]]; then
        if [[ "$pitr_restore" == true ]]; then
            [[ "$("$database_client" "${connection_arguments[@]}" --execute='SELECT @@GLOBAL.log_bin')" == 0 ]] \
                || fail 'binlog recovery requires an isolated destination started with --skip-log-bin; no GTID state is reset'
            if [[ "$database_driver" == mysql ]]; then
                # Dropping a database does not remove executed GTIDs. An interrupted replay on a
                # reused instance could silently skip source transactions even though its tables
                # are empty. Preserve source GTIDs and refuse any overlap before importing data.
                # Base64 transports native GTID sets (including MySQL 8.4 tags) without SQL quoting.
                base_gtids="$(jq -r '.pitr.gtid | @base64' "$database_backup_directory/manifest.json")"
                target_gtids="$(jq -r '.pitr.gtid | @base64' "$backup_directory/manifest.json")"
                gtid_safe="$("$database_client" "${connection_arguments[@]}" --execute="
                    SET @kumwe_base_gtids = FROM_BASE64('$base_gtids');
                    SET @kumwe_target_gtids = FROM_BASE64('$target_gtids');
                    SET @kumwe_replay_gtids = GTID_SUBTRACT(@kumwe_target_gtids, @kumwe_base_gtids);
                    SELECT GTID_SUBSET(@kumwe_base_gtids, @kumwe_target_gtids)
                        AND GTID_SUBTRACT(@kumwe_replay_gtids,
                            GTID_SUBTRACT(@kumwe_replay_gtids, @@GLOBAL.gtid_executed)) = '';")"
                [[ "$gtid_safe" == 1 ]] \
                    || fail 'MySQL GTID history overlaps replay or changed source history; use a fresh isolated server'
            fi
        fi
        existing_relations="$($database_client "${connection_arguments[@]}" \
            --execute="SELECT count(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
        [[ "$existing_relations" == '0' ]] || fail "$not_empty_message"
        # Unlike the PostgreSQL branch this import is not one transaction, because MySQL and MariaDB
        # commit data definition implicitly. An interruption here therefore leaves a partly populated
        # database that this tool will not silently overwrite: the resumed run says so and asks for a
        # freshly created database, which is the only honest way to get back to a known state.
        "$database_client" "${connection_arguments[@]}" --binary-mode < "${database_backup_directory}/database.dump"
        if [[ "$pitr_restore" == true ]]; then
            "$database_client" "${connection_arguments[@]}" --binary-mode < "$replay_sql"
        fi
        write_claim true
    fi
    applied_migration="$($database_client "${connection_arguments[@]}" \
        --execute="SELECT version FROM \`${migration_table}\` WHERE version = '${required_migration}'")"
    unset MYSQL_PWD
fi

[[ "$applied_migration" == "$required_migration" ]] \
    || fail 'restored database is missing the required runtime migration'

mv -- "$media_staging" "$KUMWE_RESTORE_MEDIA_DIR"
mv -- "$private_staging" "$KUMWE_RESTORE_PRIVATE_DIR"
mv -- "$extensions_staging" "$KUMWE_RESTORE_EXTENSIONS_DIR"
mv -- "$extension_assets_staging" "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR"
[[ -z "$replay_sql" ]] || rm -f -- "$replay_sql"
unset database_password

# The digest of a restored tree is taken over the sorted per-file digests, so it changes if a file
# is missing, added or altered but not if the restore simply ran on a different day. It is what lets
# a later check answer 'is this the tree that restore wrote' without keeping the backup around.
tree_digest() {
    (
        cd -- "$1"
        find . -xdev -type f -print0 | sort --zero-terminated | xargs --null --no-run-if-empty sha256sum
    ) | sha256sum | awk '{print $1}'
}

tree_files() {
    find "$1" -xdev -type f | wc -l | tr -d ' '
}

manifest_staging="${restore_manifest}.complete.$$"
jq -n \
    --arg backup_id "$backup_id" \
    --argjson recovery_binding "$recovery_binding" \
    --argjson recovery_target "$(jq '.pitr // null' "$backup_directory/manifest.json")" \
    --arg backup_created_at "$(jq -r '.created_at' "${backup_directory}/manifest.json")" \
    --arg release "$(jq -r '.release' "${backup_directory}/manifest.json")" \
    --arg completed_at "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" \
    --arg database "$KUMWE_RESTORE_DB_NAME" \
    --arg database_driver "$database_driver" \
    --arg database_table_prefix "$table_prefix" \
    --arg extensions "$KUMWE_RESTORE_EXTENSIONS_DIR" \
    --arg extension_assets "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR" \
    --arg media "$KUMWE_RESTORE_MEDIA_DIR" \
    --arg private "$KUMWE_RESTORE_PRIVATE_DIR" \
    --arg extensions_digest "$(tree_digest "$KUMWE_RESTORE_EXTENSIONS_DIR")" \
    --arg extension_assets_digest "$(tree_digest "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR")" \
    --arg media_digest "$(tree_digest "$KUMWE_RESTORE_MEDIA_DIR")" \
    --arg private_digest "$(tree_digest "$KUMWE_RESTORE_PRIVATE_DIR")" \
    --argjson extensions_files "$(tree_files "$KUMWE_RESTORE_EXTENSIONS_DIR")" \
    --argjson extension_assets_files "$(tree_files "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR")" \
    --argjson media_files "$(tree_files "$KUMWE_RESTORE_MEDIA_DIR")" \
    --argjson private_files "$(tree_files "$KUMWE_RESTORE_PRIVATE_DIR")" \
    --argjson resumed "$([[ $resumed_restore -eq 1 ]] && echo true || echo false)" \
    '{
        format: "kumwe-restore-v1",
        recovery_binding: $recovery_binding,
        recovery_target: $recovery_target,
        backup_id: $backup_id,
        backup_created_at: $backup_created_at,
        release: $release,
        completed_at: $completed_at,
        resumed_after_interruption: $resumed,
        database: $database,
        database_driver: $database_driver,
        database_table_prefix: $database_table_prefix,
        trees: {
            extensions: {path: $extensions, sha256: $extensions_digest, files: $extensions_files},
            extension_assets: {
                path: $extension_assets,
                sha256: $extension_assets_digest,
                files: $extension_assets_files
            },
            media: {path: $media, sha256: $media_digest, files: $media_files},
            private: {path: $private, sha256: $private_digest, files: $private_files}
        }
    }' > "$manifest_staging"
chmod 0600 "$manifest_staging"
mv -- "$manifest_staging" "$restore_manifest"
rm -f -- "$restore_claim"

echo "Restored Kumwe backup into database '$KUMWE_RESTORE_DB_NAME' and new filesystem targets."
echo "Restore completion manifest: $restore_manifest"
