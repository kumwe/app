#!/usr/bin/env bash
# Prepare native replay before any restore mutation. stdout is SQL, diagnostics are stderr.
set -Eeuo pipefail
fail() { echo "Kumwe binlog recovery failed: $*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'usage: recovery-binlog.sh BASE PAYLOAD'
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
source "$script_directory/recovery-common.sh"
base="$1" payload="$2"
recovery_target_pair "$base" "$payload"
archive="${KUMWE_RESTORE_LOG_ARCHIVE:?configure an authenticated binlog archive}"
recovery_verify_archive "$archive" "$(jq -r '.pitr.source_id' "$base/manifest.json")"
driver="$(jq -r '.database_driver' "$base/manifest.json")"
case "$driver" in
    mariadb) decoder=mariadb-binlog ;;
    mysql) decoder=mysqlbinlog ;;
    *) fail 'native binlog replay requires MariaDB or MySQL' ;;
esac
[[ "${KUMWE_RESTORE_DB_NAME:-}" == "$(jq -r '.database' "$base/manifest.json")" ]] \
    || fail 'binlog replay requires the original database name on an isolated server'
start_file="$(jq -r '.pitr.file' "$base/manifest.json")"
stop_file="$(jq -r '.pitr.file' "$payload/manifest.json")"
start_position="$(jq -r '.pitr.position' "$base/manifest.json")"
stop_position="$(jq -r '.pitr.position' "$payload/manifest.json")"
[[ "${start_file%.*}" == "${stop_file%.*}" ]] || fail 'binlog file families differ'
start_number=$((10#${start_file##*.}))
stop_number=$((10#${stop_file##*.}))
[[ $stop_number -ge $start_number && $((stop_number - start_number)) -le 100000 ]] \
    || fail 'unreachable binlog range'
[[ $start_number != "$stop_number" || $stop_position -ge $start_position ]] \
    || fail 'target binlog coordinate precedes the base'
files=()
for ((number=start_number; number<=stop_number; number++)); do
    # Width comes from the numeric suffix, not the complete basename.
    suffix="${start_file##*.}"
    printf -v file '%s.%0*d' "${start_file%.*}" "${#suffix}" "$number"
    [[ -f "$archive/$file" ]] || fail "unreachable target: missing binary log $file"
    files+=("$archive/$file")
done
[[ $(stat -c %s "$archive/$stop_file") -ge $stop_position ]] || fail 'target exceeds archived binary log'
# Native decoding validates checksums and must encounter the signed target event boundary. A tool
# returning zero after EOF is not evidence of reaching a requested position.
if [[ "$start_file:$start_position" != "$stop_file:$stop_position" ]]; then
    "$decoder" --verify-binlog-checksum --base64-output=DECODE-ROWS "$archive/$stop_file" \
        | awk -v position="$stop_position" '
            $0 ~ ("end_log_pos " position "([[:space:]]|$)") { found = 1 }
            END { exit found ? 0 : 1 }
        ' || fail 'unreachable target: no complete event ends at the target position or native validation failed'
    "$decoder" --verify-binlog-checksum --disable-log-bin --database="$KUMWE_RESTORE_DB_NAME" \
        --start-position="$start_position" --stop-position="$stop_position" "${files[@]}"
fi
