#!/usr/bin/env bash
# Restore a physical cluster on a private Unix socket, prove the named WAL target, then stop it.
set -Eeuo pipefail
umask 077
fail() { echo "Kumwe WAL recovery failed: $*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'usage: recovery-postgres.sh BASE PAYLOAD'
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
source "$script_directory/recovery-common.sh"
base="$1" payload="$2"
recovery_target_pair "$base" "$payload"
[[ "$(jq -r '.pitr.kind' "$base/manifest.json")" == postgresql-wal ]] \
    || fail 'WAL replay requires a physical base; a logical dump is not a replay base'
[[ "$(jq -r '.pitr.timeline' "$base/manifest.json")" == "$(jq -r '.pitr.timeline' "$payload/manifest.json")" ]] \
    || fail 'cross-timeline WAL recovery is unsupported'
archive="${KUMWE_RESTORE_LOG_ARCHIVE:?configure an authenticated WAL archive}"
recovery_verify_archive "$archive" "$(jq -r '.pitr.source_id' "$base/manifest.json")"
target="${KUMWE_RESTORE_PGDATA:?configure a new physical cluster target}"
# Paths become PostgreSQL settings and shell restore_command arguments. Deliberately narrow grammar.
[[ "$archive" =~ ^/[a-zA-Z0-9_./-]+$ && "$target" =~ ^/[a-zA-Z0-9_./-]+$ ]] \
    || fail 'physical recovery paths must be absolute simple paths'
[[ ! -e "$target" && ! -L "$target" ]] || fail 'physical cluster target must not exist'
[[ "${KUMWE_RESTORE_DB_NAME:-}" == "$(jq -r '.database' "$base/manifest.json")" ]] \
    || fail 'physical recovery retains the original database name'
[[ -d "$(dirname -- "$target")" ]] || fail 'physical target parent must exist'
pg_verifybackup "$base/pg-base" >&2
work="$(mktemp -d)"
started=0
cleanup() {
    if [[ $started == 1 ]]; then pg_ctl -D "$target" -m immediate -w stop >&2 || true; fi
    rm -rf -- "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
cp -a --one-file-system --reflink=auto -- "$base/pg-base" "$target"
chmod 0700 "$target"
# Never follow inherited primary_conninfo, commands, preload libraries, or includes from the source.
# Required recovery settings are in a new configuration outside the restored cluster.
printf 'local all all trust\n' > "$work/pg_hba.conf"
: > "$target/postgresql.auto.conf"
rm -f -- "$target/standby.signal" "$target/recovery.signal"
cat > "$work/postgresql.conf" <<CONFIG
listen_addresses = ''
unix_socket_directories = '$work'
port = 5432
hba_file = '$work/pg_hba.conf'
restore_command = 'cp -- $archive/%f %p'
recovery_target_name = '$(jq -r '.pitr.target_name' "$payload/manifest.json")'
recovery_target_timeline = '$(jq -r '.pitr.timeline' "$payload/manifest.json")'
recovery_target_action = 'pause'
hot_standby = on
CONFIG
: > "$target/recovery.signal"
limit="${KUMWE_RESTORE_REPLAY_TIMEOUT:-300}"
[[ "$limit" =~ ^[1-9][0-9]{0,4}$ ]] || fail 'invalid replay timeout'
started=1
pg_ctl -D "$target" -l "$target/kumwe-recovery.log" \
    -o "-c config_file=$work/postgresql.conf" -w -t "$limit" start >&2
end=$((SECONDS + limit))
query=(psql -XAt --no-password --set=ON_ERROR_STOP=1 --host="$work" --port=5432 \
    --username="$KUMWE_RESTORE_DB_USER" --dbname="$KUMWE_RESTORE_DB_NAME")
reached=0
while ((SECONDS < end)); do
    if state="$("${query[@]}" --command="SELECT pg_is_in_recovery() AND pg_get_wal_replay_pause_state() = 'paused'
        AND pg_last_wal_replay_lsn() >= '$(jq -r '.pitr.target_lsn' "$payload/manifest.json")'::pg_lsn" 2>/dev/null)" \
        && [[ "$state" == t ]]; then
        reached=1
        break
    fi
    pg_ctl -D "$target" status >/dev/null || fail "WAL recovery stopped before target; inspect $target/kumwe-recovery.log"
    sleep 1
done
[[ $reached == 1 ]] || fail "unreachable WAL target or replay timeout; inspect $target/kumwe-recovery.log"
prefix="$(jq -r '.database_table_prefix' "$payload/manifest.json")"
[[ "$prefix" =~ ^[a-z][a-z0-9]*(_[a-z0-9]+)*_$ ]] || fail 'invalid table prefix'
[[ "$("${query[@]}" --command="SELECT version FROM ${prefix}schema_migrations
    WHERE version = '20260808010000_business_transactional_runtime'")" == 20260808010000_business_transactional_runtime ]] \
    || fail 'recovered cluster is missing the required runtime migration'
# Promote only after the target was observed in paused recovery. The private instance never listens on TCP.
pg_ctl -D "$target" -w promote >&2
pg_ctl -D "$target" -m fast -w stop >&2
started=0
printf '%s\n' "Recovered physical cluster stopped at verified target: $target" >&2
