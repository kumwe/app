#!/usr/bin/env bash
# Real App source -> signed backup -> isolated restored App -> original-key HTTP replay.
# The caller provisions two empty disposable databases. All credentials/runtime copies die on exit.
set -Eeuo pipefail
umask 077
fail() { printf 'App HTTP recovery failed: %s\n' "$*" >&2; exit 1; }
[[ $# == 1 && "$1" == /* && ! -e "$1" ]] || fail 'usage: recovery-http-roundtrip.sh NEW_ABSOLUTE_REPORT_DIRECTORY'
[[ "${KUMWE_RECOVERY_FIXTURE_DISPOSABLE:-}" == yes ]] || fail 'declare the source and destination disposable'
[[ "${DB_DRIVER:-}" == mariadb ]] || fail 'this App HTTP lane requires MariaDB; native replay has its own engine matrix'
repository="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
app_source="${KUMWE_RECOVERY_APP_SOURCE:-$repository}"
[[ "$app_source" == /* && -f "$app_source/vendor/autoload.php" ]] || fail 'installed App source is required'
source_database="${DB_NAME:?configure an empty source database}"
restored_database="${KUMWE_RECOVERY_HTTP_RESTORE_DB:?configure an empty restored database}"
for database in "$source_database" "$restored_database"; do
    [[ "$database" =~ ^[a-z][a-z0-9_]{0,63}$ ]] || fail 'invalid disposable database name'
done
[[ "$source_database" != "$restored_database" ]] || fail 'source and restored databases must differ'
password_file="${KUMWE_RECOVERY_DB_PASSWORD_FILE:?configure a private database password file}"
[[ "$password_file" == /* && -f "$password_file" && -s "$password_file" && ! -L "$password_file" ]] \
    || fail 'database password file must be absolute, nonempty and regular'
http_port="${KUMWE_RECOVERY_HTTP_PORT:-18880}"
[[ "$http_port" =~ ^[1-9][0-9]{3,4}$ && "$http_port" -le 65535 ]] || fail 'invalid loopback HTTP port'
for tool in php mariadb mariadb-dump minisign jq curl timeout openssl; do
    command -v "$tool" >/dev/null || fail "missing $tool"
done
report="$1"
mkdir -m 0700 "$report"
work="$(mktemp -d "${TMPDIR:-/tmp}/kumwe-http-recovery.XXXXXXXX")"
http_pid=''
stage=initialize
started="$SECONDS"
cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$http_pid" ]]; then kill "$http_pid" 2>/dev/null || true; wait "$http_pid" 2>/dev/null || true; fi
    # Remove runtime copies, raw responses/logs, keys and credentials on success, failure and timeout.
    cd "$repository"
    rm -r -- "$work"
    jq -n --arg stage "$stage" --argjson exit_code "$status" --argjson seconds "$((SECONDS - started))" \
        '{format: "kumwe-http-recovery-run-v1", stage: $stage, exit_code: $exit_code,
        elapsed_seconds: $seconds, private_work_removed: true}' > "$report/run.json"
    if [[ $status != 0 ]]; then printf 'App HTTP recovery stopped at stage %s (exit %s).\n' "$stage" "$status" >&2; fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
run() {
    stage="$1"
    shift
    # Both execution time and individual private log/dump sizes are bounded. Raw logs are never uploaded.
    (ulimit -f 65536; timeout --kill-after=10s 240 "$@") > "$work/last-stage.log" 2>&1
}
export DB_PASSWORD="$(<"$password_file")"
export MYSQL_PWD="$DB_PASSWORD"
export DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-3306}" DB_TABLE_PREFIX="${DB_TABLE_PREFIX:-kumwe_}"
for database in "$source_database" "$restored_database"; do
    stage=check-empty-databases
    count="$(timeout 15 mariadb --host="$DB_HOST" --port="$DB_PORT" --user="${DB_USER:?configure database user}" \
        --database="$database" --batch --skip-column-names \
        --execute='SELECT count(*) FROM information_schema.tables WHERE table_schema = DATABASE()')"
    [[ "$count" == 0 ]] || fail 'both disposable databases must be empty before fixture creation'
done
unset MYSQL_PWD
mkdir "$work/secrets" "$work/backups"
openssl rand -base64 48 > "$work/secrets/app-secret"
openssl rand -base64 48 > "$work/secrets/runtime-key"
export APP_SECRET_FILE="$work/secrets/app-secret"
unset APP_SECRET RECORD_ENCRYPTION_KEY RECORD_ENCRYPTION_KEY_FILE RECORD_ENCRYPTION_PREVIOUS_KEYS \
    RECORD_ENCRYPTION_PREVIOUS_KEYS_FILE RECORD_ENCRYPTION_LEGACY_SECRET RECORD_ENCRYPTION_LEGACY_SECRET_FILE
export EXTENSION_RUNTIME_SIGNING_KEY="$(<"$work/secrets/runtime-key")"
unset EXTENSION_RUNTIME_PREVIOUS_KEYS EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE
export EXTENSION_RUNTIME_SIGNING_KEY_ID=recovery-http-v1
export APP_ENV=testing APP_DEBUG=false APP_BASE_URL="http://127.0.0.1:$http_port"
export APP_PUBLIC_SITE=default APP_TRUSTED_HOSTS=127.0.0.1,localhost EXTENSIONS_ALLOW_UNSIGNED_LOCAL=true
export KUMWE_SITE_CONTENT_PROFILE=blank KUMWE_BUSINESS_DEMO=false KUMWE_RELEASE=2.0.0
export KUMWE_DEPLOYMENT_ID=recovery-http KUMWE_PROCESS_ID=recovery-http
unset KUMWE_BACKUP_PITR KUMWE_RESTORE_PAYLOAD_BACKUP KUMWE_RESTORE_TARGET_TIME KUMWE_RESTORE_PGDATA
namespace="kumwe.recovery.http.$(openssl rand -hex 8)"
for instance in source restored; do
    app="$work/$instance"
    mkdir "$app"
    copy_source="$app_source"
    [[ "$instance" == source ]] || copy_source="$work/source"
    # Include the installed native App code/dependencies, not its mutable storage or dotenv configuration.
    for path in api bin bootstrap config resources src templates public tools tests vendor composer.json composer.lock; do
        cp -a --reflink=auto -- "$copy_source/$path" "$app/"
    done
    mkdir -p "$app/storage/cache" "$app/storage/logs" "$app/storage/sessions" "$app/storage/tmp"
    if [[ -d "$app/public/assets/extensions" ]]; then rm -r -- "$app/public/assets/extensions"; fi
done
source_app="$work/source"
restored_app="$work/restored"
mkdir -p "$source_app/storage/media" "$source_app/storage/private" "$source_app/extensions" \
    "$source_app/public/assets/extensions"
printf 'HTTP recovery media bytes\n' > "$source_app/storage/media/recovery-probe.txt"
export KUMWE_REPLICA_ID=recovery-http-source KUMWE_INSTANCE_ID=recovery-http-source
export REDIS_NAMESPACE="$namespace.source"
cd "$source_app"
run seed-fixture php tests/Support/recovery-http-fixture.php "$work/credentials"
export KUMWE_DRILL_HTTP_ORIGIN="$APP_BASE_URL" KUMWE_DRILL_LOGIN_EMAIL_FILE="$work/credentials/email"
export KUMWE_DRILL_LOGIN_PASSWORD_FILE="$work/credentials/password" KUMWE_DRILL_API_TOKEN_FILE="$work/credentials/token"
start_http() {
    local instance="$1" ready=0
    stage="$instance-http-start"
    (ulimit -f 65536; exec php -S "127.0.0.1:$http_port" -t public tools/browser-router.php) \
        > "$work/http.log" 2>&1 &
    http_pid=$!
    for attempt in {1..40}; do
        kill -0 "$http_pid" 2>/dev/null || fail 'private HTTP server exited before readiness'
        if curl --silent --fail --connect-timeout 1 --max-time 2 "$APP_BASE_URL/administrator/login" >/dev/null; then
            ready=1
            break
        fi
        sleep 0.25
    done
    [[ $ready == 1 ]] || fail 'private HTTP server did not become ready'
}
stop_http() {
    kill "$http_pid"
    wait "$http_pid" 2>/dev/null || true
    http_pid=''
}
start_http source
run source-http bash "$repository/tools/restore-http-drill.sh" seed "$work/credentials/request.json" \
    "$source_app/storage/private/http-receipt.json"
jq -e '.mode == "seed" and .authenticated_http == true and .idempotent_mutation == true' \
    "$work/last-stage.log" >/dev/null
jq '{mode, authenticated_http, idempotent_mutation, approval_spent_state}' "$work/last-stage.log" > "$report/source.json"
stop_http
# The only App writer is now stopped. Receipt, idempotency ledger and payload are one checkpoint.
export KUMWE_BACKUP_CONSISTENCY=quiesced KUMWE_BACKUP_DIR="$work/backups"
export KUMWE_DB_DRIVER=mariadb KUMWE_DB_HOST="$DB_HOST" KUMWE_DB_PORT="$DB_PORT" KUMWE_DB_NAME="$source_database"
export KUMWE_DB_USER="$DB_USER" KUMWE_DB_PASSWORD_FILE="$password_file" KUMWE_DB_TABLE_PREFIX="$DB_TABLE_PREFIX"
export KUMWE_MEDIA_DIR="$source_app/storage/media" KUMWE_PRIVATE_DIR="$source_app/storage/private"
export KUMWE_EXTENSIONS_DIR="$source_app/extensions" KUMWE_EXTENSION_ASSETS_DIR="$source_app/public/assets/extensions"
export KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE="$work/secrets/signing.key"
export KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="$work/secrets/signing.pub"
run generate-signing-key minisign -G -W -f -s "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" -p "$KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE"
run signed-backup bash "$repository/tools/backup.sh"
mapfile -t backups < <(find "$work/backups" -mindepth 1 -maxdepth 1 -type d -name 'kumwe-*')
[[ ${#backups[@]} == 1 ]] || fail 'expected one completed signed snapshot'
backup="${backups[0]}"
[[ -s "$backup/checksums.sha256.minisig" ]] || fail 'backup signature missing'
export KUMWE_RESTORE_DB_DRIVER=mariadb KUMWE_RESTORE_DB_HOST="$DB_HOST" KUMWE_RESTORE_DB_PORT="$DB_PORT"
export KUMWE_RESTORE_DB_NAME="$restored_database" KUMWE_RESTORE_DB_USER="$DB_USER"
export KUMWE_RESTORE_DB_PASSWORD_FILE="$password_file" KUMWE_RESTORE_DB_TABLE_PREFIX="$DB_TABLE_PREFIX"
export KUMWE_RESTORE_MEDIA_DIR="$restored_app/storage/media" KUMWE_RESTORE_PRIVATE_DIR="$restored_app/storage/private"
export KUMWE_RESTORE_EXTENSIONS_DIR="$restored_app/extensions"
export KUMWE_RESTORE_EXTENSION_ASSETS_DIR="$restored_app/public/assets/extensions"
export KUMWE_RESTORE_MANIFEST="$work/restore.json"
run restore bash "$repository/tools/restore.sh" "$backup"
cmp "$source_app/storage/private/http-receipt.json" "$restored_app/storage/private/http-receipt.json"
cmp "$source_app/storage/media/recovery-probe.txt" "$restored_app/storage/media/recovery-probe.txt"
export DB_NAME="$restored_database" REDIS_NAMESPACE="$namespace.restored"
export KUMWE_REPLICA_ID=recovery-http-restored KUMWE_INSTANCE_ID=recovery-http-restored
cd "$restored_app"
run materialize-restored-runtime php -r 'require "tests/Support/deployment-drill-autoload.php";
    Kumwe\App\Tests\Support\TestKernelFactory::create(Kumwe\App\Shared\Infrastructure\Configuration\Environment::fromGlobals());'
start_http restored
run restored-http bash "$repository/tools/restore-http-drill.sh" verify "$work/credentials/request.json" \
    "$restored_app/storage/private/http-receipt.json"
jq -e '.mode == "verify" and .authenticated_http == true and .idempotent_mutation == true' \
    "$work/last-stage.log" >/dev/null
jq '{mode, authenticated_http, idempotent_mutation, approval_spent_state}' "$work/last-stage.log" > "$report/restored.json"
stop_http
jq -n --arg backup "$(sha256sum "$backup/manifest.json" | cut -d' ' -f1)" \
    '{format: "kumwe-http-recovery-proof-v1", signed_backup_manifest_sha256: $backup,
    source_stopped_before_backup: true, separate_app_directories: true, separate_redis_namespaces: true,
    restored_receipt_and_media_equal: true, original_key_replayed_after_restore: true}' > "$report/proof.json"
stage=complete
printf 'Real App HTTP source/backup/restore/replay passed; bounded proof in %s\n' "$report"
