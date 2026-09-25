#!/usr/bin/env bash
# Real-engine recovery matrix. Owns only fresh scratch clusters, filesystem targets and ephemeral signing keys.
set -Eeuo pipefail
umask 077
fail() { printf 'Native recovery drill failed: %s\n' "$*" >&2; exit 1; }
[[ $# == 1 && "$1" =~ ^(mariadb|mysql|pgsql)$ ]] || fail 'usage: recovery-native-drill.sh mariadb|mysql|pgsql'
driver="$1"
repository="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
root="${KUMWE_RECOVERY_EVIDENCE_DIR:-$(mktemp -d)}"
[[ "$root" == /* && -d "$root" && ! -L "$root" ]] || fail 'evidence root must be an existing absolute directory'
[[ -z "$(find "$root" -mindepth 1 -print -quit)" ]] || fail 'evidence directory must be empty'
# PostgreSQL refuses root. Only this fresh evidence directory changes ownership.
if [[ "$driver" == pgsql && $(id -u) == 0 ]]; then
    chown nobody:nogroup "$root"
    exec runuser -u nobody -- env PATH="$PATH" LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}" \
        KUMWE_RECOVERY_MEASURE_RESTIC="${KUMWE_RECOVERY_MEASURE_RESTIC:-no}" \
        KUMWE_RECOVERY_EVIDENCE_DIR="$root" bash "${BASH_SOURCE[0]}" pgsql
fi
mkdir "$root/source-logs" "$root/wal" "$root/sockets" "$root/fixture" "$root/drill" "$root/bin"
pids=()
containers=()
cleanup() {
    local status=$?
    trap - EXIT
    for container in "${containers[@]}"; do docker rm -f "$container" >/dev/null 2>&1 || true; done
    if [[ "$driver" == pgsql ]]; then
        for cluster in "$root/source-db" "$root/drill/before/pgdata" "$root/drill/after/pgdata" \
            "$root/gap/pgdata"; do
            [[ ! -f "$cluster/postmaster.pid" ]] || pg_ctl -D "$cluster" -m immediate -w stop >/dev/null 2>&1 || true
        done
    fi
    for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done
    wait 2>/dev/null || true
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
export KUMWE_RECOVERY_ROOT="$root" KUMWE_DB_DRIVER="$driver" KUMWE_DB_NAME=kumwe_recovery
export KUMWE_DB_HOST=127.0.0.1 KUMWE_RESTORE_DB_HOST=127.0.0.1
export KUMWE_DB_PORT=13306 KUMWE_RESTORE_DB_PORT=13307
export KUMWE_DB_USER=root KUMWE_RESTORE_DB_USER=root
export KUMWE_DB_PASSWORD_FILE="$root/password" KUMWE_RESTORE_DB_PASSWORD_FILE="$root/password"
printf 'native recovery fixture password' > "$root/password"
export KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE="$root/signing.key"
export KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="$root/signing.pub"
minisign -G -W -f -s "$root/signing.key" -p "$root/signing.pub" >/dev/null
export KUMWE_RELEASE=2.0.0 KUMWE_EXPECTED_RELEASE=2.0.0 KUMWE_DB_TABLE_PREFIX=kumwe_
export KUMWE_BACKUP_CONSISTENCY=quiesced
for tree in media private extensions extension-assets; do
    mkdir -p "$root/fixture/$tree/empty"
    printf 'real engine recovery bytes\n' > "$root/fixture/$tree/probe.txt"
done
# Enough incompressible media to distinguish repository delta from total payload on the reference transport.
dd if=/dev/urandom of="$root/fixture/media/unchanged.bin" bs=1M count=4 status=none
export KUMWE_MEDIA_DIR="$root/fixture/media" KUMWE_PRIVATE_DIR="$root/fixture/private"
export KUMWE_EXTENSIONS_DIR="$root/fixture/extensions" KUMWE_EXTENSION_ASSETS_DIR="$root/fixture/extension-assets"
if [[ "$driver" == pgsql ]]; then
    export KUMWE_DB_USER=postgres KUMWE_RESTORE_DB_USER=postgres KUMWE_DB_PORT=15432 KUMWE_RESTORE_DB_PORT=15433
    initdb --pgdata="$root/source-db" --username=postgres --auth=trust --no-locale > "$root/init.log"
    cat >> "$root/source-db/postgresql.conf" <<CONFIG
listen_addresses = '127.0.0.1'
port = 15432
unix_socket_directories = ''
wal_level = replica
archive_mode = on
archive_command = 'test ! -f $root/wal/%f && cp %p $root/wal/%f'
CONFIG
    pg_ctl -D "$root/source-db" -l "$root/source.log" -w start >/dev/null
    createdb -h 127.0.0.1 -p 15432 -U postgres kumwe_recovery
    psql -X -h 127.0.0.1 -p 15432 -U postgres -d kumwe_recovery --set=ON_ERROR_STOP=1 \
        --command="ALTER ROLE postgres PASSWORD 'native recovery fixture password'" >/dev/null
    psql -X -h 127.0.0.1 -p 15432 -U postgres -d kumwe_recovery --set=ON_ERROR_STOP=1 \
        --command="CREATE TABLE kumwe_schema_migrations (version VARCHAR(191) PRIMARY KEY);
        INSERT INTO kumwe_schema_migrations VALUES ('20260808010000_business_transactional_runtime');" >/dev/null
else
    if [[ "$driver" == mysql && -n "${KUMWE_RECOVERY_MYSQL_IMAGE:-}" ]]; then
        [[ $(id -u) == 0 ]] || fail 'Docker MySQL fixture must run as root to own its disposable data directories'
        for name in mysql mysqldump mysqlbinlog; do
            cat > "$root/bin/$name" <<'CLIENT'
#!/usr/bin/env bash
exec docker run --rm --interactive --network host --user 0 -e MYSQL_PWD \
    -v "$KUMWE_RECOVERY_ROOT:$KUMWE_RECOVERY_ROOT" --entrypoint "$(basename -- "$0")" \
    "$KUMWE_RECOVERY_MYSQL_IMAGE" "$@"
CLIENT
            chmod 0700 "$root/bin/$name"
        done
        export PATH="$root/bin:$PATH"
    fi
    for instance in source target; do
        mkdir "$root/$instance-db"
        port=13306
        [[ "$instance" == source ]] || port=13307
        binlog_options=("--log-bin=$root/$instance-logs-binlog")
        [[ "$instance" == source ]] || binlog_options=(--skip-log-bin)
        if [[ "$driver" == mariadb ]]; then
            basedir="${KUMWE_RECOVERY_MARIADB_BASEDIR:-/usr}"
            mariadb-install-db --no-defaults --basedir="$basedir" --datadir="$root/$instance-db" \
                --auth-root-authentication-method=normal --skip-test-db > "$root/$instance-init.log" 2>&1
            mariadbd --no-defaults --basedir="$basedir" --user="$(id -un)" --datadir="$root/$instance-db" \
                --socket='' --pid-file="$root/$instance.pid" \
                --bind-address=127.0.0.1 --port="$port" --server-id="$port" --binlog-format=ROW \
                "${binlog_options[@]}" --gtid-strict-mode=ON \
                --log-error="$root/$instance.log" &
            pids+=("$!")
        elif [[ -n "${KUMWE_RECOVERY_MYSQL_IMAGE:-}" ]]; then
            docker run --rm --user 0 -v "$root:$root" --entrypoint mysqld "$KUMWE_RECOVERY_MYSQL_IMAGE" \
                --no-defaults --initialize-insecure --user=root --datadir="$root/$instance-db" \
                > "$root/$instance-init.log" 2>&1
            container="kumwe-recovery-${instance}-$$"
            containers+=("$container")
            docker run -d --name "$container" --network host --user 0 -v "$root:$root" --entrypoint mysqld \
                "$KUMWE_RECOVERY_MYSQL_IMAGE" --no-defaults --user=root --datadir="$root/$instance-db" \
                --socket="$root/sockets/$instance.sock" --pid-file="$root/$instance.pid" \
                --bind-address=127.0.0.1 --port="$port" --server-id="$port" --binlog-format=ROW \
                "${binlog_options[@]}" --gtid-mode=ON --enforce-gtid-consistency=ON \
                --log-error="$root/$instance.log" >/dev/null
        else
            mysqld --no-defaults --initialize-insecure --user="$(id -un)" --datadir="$root/$instance-db" \
                > "$root/$instance-init.log" 2>&1
            mysqld --no-defaults --user="$(id -un)" --datadir="$root/$instance-db" \
                --socket="$root/sockets/$instance.sock" --pid-file="$root/$instance.pid" \
                --bind-address=127.0.0.1 --port="$port" --server-id="$port" --binlog-format=ROW \
                "${binlog_options[@]}" --gtid-mode=ON --enforce-gtid-consistency=ON \
                --log-error="$root/$instance.log" &
            pids+=("$!")
        fi
        ready=0
        for attempt in {1..60}; do
            if "$driver" --no-defaults --host=127.0.0.1 --port="$port" --user=root --execute='SELECT 1' \
                > /dev/null 2>&1; then ready=1; break; fi
            sleep 0.25
        done
        [[ $ready == 1 ]] || fail "$instance server did not become ready; inspect $root/$instance.log"
        "$driver" --no-defaults --host=127.0.0.1 --port="$port" --user=root \
            --execute="CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY 'native recovery fixture password';
            GRANT ALL ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
            ALTER USER 'root'@'localhost' IDENTIFIED BY 'native recovery fixture password';
            CREATE DATABASE kumwe_recovery;" >/dev/null
    done
    MYSQL_PWD="$(<"$root/password")" "$driver" --host=127.0.0.1 --port=13306 --user=root \
        --database=kumwe_recovery --execute="CREATE TABLE kumwe_schema_migrations (version VARCHAR(191) PRIMARY KEY);
        INSERT INTO kumwe_schema_migrations VALUES ('20260808010000_business_transactional_runtime');" >/dev/null
    if [[ "$driver" == mysql ]]; then
        # A second real SID makes @@gtid_executed multiline, exercising lossless native set capture.
        MYSQL_PWD="$(<"$root/password")" mysql --host=127.0.0.1 --port=13306 --user=root \
            --execute="SET GTID_NEXT='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee:1';
                BEGIN; COMMIT; SET GTID_NEXT='AUTOMATIC';" >/dev/null
    fi
fi
# The minimal fixture schema tests native backup machinery, not App migration or workflow semantics.
cat > "$root/target-hook" <<'TARGET'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "$KUMWE_DB_DRIVER" == pgsql ]]; then
    case "$1" in
        prepare) [[ ! -e "$KUMWE_RESTORE_PGDATA" ]] ;;
        start) pg_ctl -D "$KUMWE_RESTORE_PGDATA" -l "$KUMWE_RESTORE_PGDATA/validation.log" -w \
            -o "-c listen_addresses=127.0.0.1 -p $KUMWE_RESTORE_DB_PORT -c unix_socket_directories='' -c archive_mode=off" start >/dev/null ;;
        stop) pg_ctl -D "$KUMWE_RESTORE_PGDATA" -m fast -w stop >/dev/null ;;
    esac
elif [[ "$1" == prepare ]]; then
    MYSQL_PWD="$(<"$KUMWE_RESTORE_DB_PASSWORD_FILE")" "$KUMWE_DB_DRIVER" --host=127.0.0.1 \
        --port="$KUMWE_RESTORE_DB_PORT" --user=root \
        --execute='DROP DATABASE IF EXISTS kumwe_recovery; CREATE DATABASE kumwe_recovery;'
fi
TARGET
cat > "$root/archive-hook" <<'ARCHIVE'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "$KUMWE_DB_DRIVER" == pgsql ]]; then
    # Force a fresh completed segment and wait for the real archiver to acknowledge it.
    segment="$(psql -XAt -h 127.0.0.1 -p "$KUMWE_DB_PORT" -U postgres -d kumwe_recovery \
        -c 'SELECT pg_walfile_name(pg_switch_wal())')"
    ready=0
    for attempt in {1..120}; do
        if [[ -f "$KUMWE_RECOVERY_ROOT/wal/$segment" ]]; then ready=1; break; fi
        sleep 0.25
    done
    [[ $ready == 1 ]] || { echo 'WAL archiving did not finish' >&2; exit 1; }
    cp -a "$KUMWE_RECOVERY_ROOT/wal/." "$3/"
else
    MYSQL_PWD="$(<"$KUMWE_DB_PASSWORD_FILE")" "$KUMWE_DB_DRIVER" --host=127.0.0.1 --port="$KUMWE_DB_PORT" \
        --user=root --execute='FLUSH BINARY LOGS'
    target="$(jq -r '.pitr.file' "$2/manifest.json")"
    for log in "$KUMWE_RECOVERY_ROOT"/source-logs-binlog.[0-9]*; do
        [[ "$(basename -- "$log")" > "$target" ]] || cp "$log" "$3/"
    done
fi
ARCHIVE
chmod 0700 "$root/target-hook" "$root/archive-hook"
export KUMWE_PITR_DRILL_DISPOSABLE=yes KUMWE_PITR_DRILL_OUTPUT="$root/drill"
export KUMWE_PITR_DRILL_TARGET_HOOK="$root/target-hook" KUMWE_PITR_DRILL_ARCHIVE_HOOK="$root/archive-hook"
bash "$repository/tools/restore-pitr-drill.sh" > "$root/pitr.log" 2>&1 \
    || { cat "$root/pitr.log" >&2; fail 'before/after native replay'; }
mapfile -t snapshots < <(find "$root/drill/backups" -mindepth 1 -maxdepth 1 -type d -name 'kumwe-*' | sort)
[[ ${#snapshots[@]} == 2 ]] || fail 'expected two coherent snapshots'
base="${snapshots[0]}" after="${snapshots[1]}"
# Reusing a MySQL instance retains the source GTIDs even after its database is recreated.
# Prove that a second replay refuses before import instead of silently omitting the committed row.
gtid_overlap_refused=null
if [[ "$driver" == mysql ]]; then
    mkdir "$root/gtid-overlap"
    export KUMWE_RESTORE_DB_NAME=kumwe_recovery KUMWE_RESTORE_DB_DRIVER=mysql
    export KUMWE_RESTORE_MEDIA_DIR="$root/gtid-overlap/media" KUMWE_RESTORE_PRIVATE_DIR="$root/gtid-overlap/private"
    export KUMWE_RESTORE_EXTENSIONS_DIR="$root/gtid-overlap/extensions"
    export KUMWE_RESTORE_EXTENSION_ASSETS_DIR="$root/gtid-overlap/assets"
    export KUMWE_RESTORE_MANIFEST="$root/gtid-overlap/restore.json"
    export KUMWE_RESTORE_PAYLOAD_BACKUP="$after" KUMWE_RESTORE_LOG_ARCHIVE="$root/drill/archive"
    "$root/target-hook" prepare after
    if bash "$repository/tools/restore.sh" "$base" > "$root/gtid-overlap.log" 2>&1; then
        fail 'MySQL replay accepted an already executed source transaction'
    fi
    grep -q 'MySQL GTID history overlaps replay' "$root/gtid-overlap.log" \
        || { cat "$root/gtid-overlap.log" >&2; fail 'GTID overlap failed for an unrelated reason'; }
    [[ "$(MYSQL_PWD="$(<"$root/password")" mysql --host=127.0.0.1 --port=13307 --user=root \
        --batch --skip-column-names --database=kumwe_recovery \
        --execute='SELECT count(*) FROM information_schema.tables WHERE table_schema = DATABASE()')" == 0 ]] \
        || fail 'GTID overlap refusal imported database objects'
    [[ ! -e "$KUMWE_RESTORE_MANIFEST" && ! -e "$KUMWE_RESTORE_MEDIA_DIR" ]] \
        || fail 'GTID overlap refusal published a restore'
    gtid_overlap_refused=true
fi
# A signed, source-bound but empty log archive must fail natively, rather than being accepted at EOF.
source "$repository/tools/recovery-common.sh"
mkdir "$root/gap" "$root/missing-archive"
jq -n --arg source "$(jq -r '.pitr.source_id' "$base/manifest.json")" \
    '{format: "kumwe-log-archive-v1", source_id: $source}' > "$root/missing-archive/archive.json"
recovery_checksums "$root/missing-archive" > "$root/missing-archive/checksums.sha256"
minisign -S -s "$root/signing.key" -m "$root/missing-archive/checksums.sha256" \
    -x "$root/missing-archive/checksums.sha256.minisig" >/dev/null
export KUMWE_RESTORE_DB_NAME=kumwe_recovery KUMWE_RESTORE_DB_DRIVER="$driver"
export KUMWE_RESTORE_MEDIA_DIR="$root/gap/media" KUMWE_RESTORE_PRIVATE_DIR="$root/gap/private"
export KUMWE_RESTORE_EXTENSIONS_DIR="$root/gap/extensions" KUMWE_RESTORE_EXTENSION_ASSETS_DIR="$root/gap/assets"
export KUMWE_RESTORE_MANIFEST="$root/gap/restore.json" KUMWE_RESTORE_PGDATA="$root/gap/pgdata"
export KUMWE_RESTORE_PAYLOAD_BACKUP="$after" KUMWE_RESTORE_LOG_ARCHIVE="$root/missing-archive"
export KUMWE_RESTORE_REPLAY_TIMEOUT=5
if bash "$repository/tools/restore.sh" "$base" > "$root/archive-gap.log" 2>&1; then
    fail 'an unreachable target in an empty native archive was accepted'
fi
if [[ "$driver" == pgsql ]]; then
    grep -Eq 'unreachable WAL target|WAL recovery stopped before target|could not start server' "$root/archive-gap.log" \
        || { cat "$root/archive-gap.log" >&2; fail 'WAL gap failed for an unrelated reason'; }
else
    grep -q 'missing binary log' "$root/archive-gap.log" || fail 'binlog gap failed for an unrelated reason'
fi
[[ ! -e "$root/gap/restore.json" ]] || fail 'failed replay wrote a completion manifest'
unset KUMWE_RESTORE_PAYLOAD_BACKUP KUMWE_RESTORE_LOG_ARCHIVE KUMWE_RESTORE_PGDATA KUMWE_RESTORE_REPLAY_TIMEOUT
# Sixteen real refusals, including native dump parsing, actual signatures and a nonempty database.
export KUMWE_RESTORE_DB_PORT="$KUMWE_DB_PORT" KUMWE_TAMPER_DRILL_OCCUPIED_DB=kumwe_recovery
bash "$repository/tests/Support/backup-tamper-drill.sh" "$after" > "$root/tamper.log" 2>&1 \
    || { cat "$root/tamper.log" >&2; fail 'native tamper refusals'; }
grep -q 'proved 16 fail-closed refusals' "$root/tamper.log" || fail 'tamper coverage was incomplete'
# A real import is killed after its first filesystem publication and then resumed under the same claim.
if [[ "$driver" == pgsql ]]; then
    createdb -h 127.0.0.1 -p 15432 -U postgres kumwe_interrupted
else
    MYSQL_PWD="$(<"$root/password")" "$driver" --host=127.0.0.1 --port=13306 --user=root \
        --execute='CREATE DATABASE kumwe_interrupted'
fi
export KUMWE_DRILL_DB_DRIVER="$driver" KUMWE_DRILL_DB_HOST="$KUMWE_DB_HOST" KUMWE_DRILL_DB_PORT="$KUMWE_DB_PORT"
export KUMWE_DRILL_DB_NAME=kumwe_recovery KUMWE_DRILL_RESTORE_DB_NAME=kumwe_interrupted
export KUMWE_DRILL_DB_USER="$KUMWE_DB_USER" KUMWE_DRILL_DB_PASSWORD_FILE="$root/password"
export KUMWE_DRILL_MEDIA_DIR="$KUMWE_MEDIA_DIR" KUMWE_DRILL_PRIVATE_DIR="$KUMWE_PRIVATE_DIR"
export KUMWE_DRILL_EXTENSIONS_DIR="$KUMWE_EXTENSIONS_DIR" KUMWE_DRILL_EXTENSION_ASSETS_DIR="$KUMWE_EXTENSION_ASSETS_DIR"
unset KUMWE_RESTORE_MANIFEST
bash "$repository/tools/restore-interruption-drill.sh" > "$root/interruption.log" 2>&1 \
    || { cat "$root/interruption.log" >&2; fail 'native interrupted import'; }
# Retention authenticates every candidate, and a cycle failure resumes writers without pruning.
mkdir "$root/retention" "$root/cycle"
cp -a "$base" "$after" "$root/retention/"
KUMWE_BACKUP_DIR="$root/retention" KUMWE_BACKUP_KEEP=1 bash "$repository/tools/backup-retain.sh" \
    > "$root/retention.log" 2>&1
[[ ! -e "$root/retention/$(basename -- "$base")" && -d "$root/retention/$(basename -- "$after")" ]] \
    || fail 'retention did not retain the newest signed snapshot'
for hook in QUIESCE RESUME OFFSITE; do
    printf '#!/usr/bin/env bash\nprintf "%%s\\n" %s >> "$KUMWE_RECOVERY_ROOT/cycle-events"\n' "$hook" > "$root/$hook"
    [[ "$hook" != OFFSITE ]] || printf 'exit 17\n' >> "$root/$hook"
    chmod 0700 "$root/$hook"
done
if KUMWE_BACKUP_DIR="$root/cycle" KUMWE_BACKUP_KEEP=1 KUMWE_BACKUP_QUIESCE_HOOK="$root/QUIESCE" \
    KUMWE_BACKUP_RESUME_HOOK="$root/RESUME" KUMWE_BACKUP_OFFSITE_HOOK="$root/OFFSITE" \
    bash "$repository/tools/backup-cycle.sh" > "$root/cycle.log" 2>&1; then fail 'failed offsite cycle succeeded'; fi
[[ "$(<"$root/cycle-events")" == $'QUIESCE\nOFFSITE\nRESUME' ]] || fail 'failed cycle did not resume writers'
[[ "$(find "$root/cycle" -maxdepth 1 -type d -name 'kumwe-*' | wc -l)" == 1 ]] || fail 'failed cycle pruned its backup'
# Measure a real reference repository delta for logical snapshots with one changed small media file.
if [[ "${KUMWE_RECOVERY_MEASURE_RESTIC:-no}" == yes ]]; then
    command -v restic >/dev/null || fail 'Restic was requested but is unavailable'
    mkdir "$root/dedup-backups"
    export KUMWE_BACKUP_DIR="$root/dedup-backups"
    first="$(bash "$repository/tools/backup.sh")"
    while [[ "$(date -u +%Y%m%dT%H%M%SZ)" == "${first##*-}" ]]; do sleep 0.1; done
    printf 'one changed media file\n' >> "$KUMWE_MEDIA_DIR/probe.txt"
    second="$(bash "$repository/tools/backup.sh")"
    export RESTIC_REPOSITORY="$root/restic" RESTIC_PASSWORD_FILE="$root/password"
    restic init > "$root/restic-init.log"
    bash "$repository/tools/backup-dedup-drill.sh" "$first" "$second" "$root/dedup" > "$root/dedup.log" 2>&1 \
        || { cat "$root/dedup.log" >&2; fail 'real deduplication measurement'; }
    jq -e '.after.total_bytes_processed >= 4194304
        and ((.after.data_added_packed // .after.data_added) < ((.before.data_added_packed // .before.data_added) / 8))
        and .restored_copy_verified == true' "$root/dedup/measurement.json" >/dev/null \
        || fail 'second snapshot storage was not proportional to the small change'
fi
# Record real client/server identities with the actual state assertions.
if [[ "$driver" == pgsql ]]; then
    server_version="$(psql -XAt -h 127.0.0.1 -p 15432 -U postgres -d kumwe_recovery -c 'SELECT version()')"
    client_version="$(pg_basebackup --version)"
else
    server_version="$(MYSQL_PWD="$(<"$root/password")" "$driver" --host=127.0.0.1 --port=13306 \
        --user=root --batch --skip-column-names --execute='SELECT VERSION()')"
    client_version="$("$driver" --version)"
fi
jq -n --arg driver "$driver" --arg server "$server_version" --arg client "$client_version" \
    --argjson gtid_overlap_refused "$gtid_overlap_refused" \
    --slurpfile before "$root/drill/before/evidence.json" --slurpfile after "$root/drill/after/evidence.json" \
    '{driver: $driver, server: $server, client: $client, before: $before[0], after: $after[0],
    newer_payload_target_refused: true, archive_gap_refused: true, tamper_refusals: 16,
    interrupted_restore_resumed: true, retention_verified: true, failed_cycle_resumed: true,
    mysql_gtid_overlap_refused: $gtid_overlap_refused}' > "$root/native-evidence.json"
printf 'Native recovery passed: %s\n' "$root/native-evidence.json"
