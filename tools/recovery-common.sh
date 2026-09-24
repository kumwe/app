#!/usr/bin/env bash
# Host recovery helpers. Sourced only by the operator tools; callers supply fail().

# Plain trees reject links, devices, sockets and filenames sha256sum cannot represent unambiguously.
recovery_tree_safe() {
    local root="$1" path
    [[ -d "$root" && ! -L "$root" ]] || fail 'payload tree is missing or symbolic'
    while IFS= read -r -d '' path; do
        [[ "$path" != *$'\n'* && "$path" != *$'\r'* && "$path" != *\\* ]] \
            || fail 'payload contains an unsupported filename'
        [[ ! -L "$path" ]] || fail 'backup directory contains symbolic links'
        [[ -f "$path" || -d "$path" ]] || fail 'payload contains an unsupported file type'
    done < <(find "$root" -xdev -print0)
}

# Inventory every file, including the manifest and optional physical database base.
recovery_file_list() {
    (cd -- "$1" && find . -xdev -type f ! -path './checksums.sha256' \
        ! -path './checksums.sha256.minisig' -printf '%P\n' | LC_ALL=C sort)
}

recovery_checksums() {
    (cd -- "$1" && recovery_file_list . | while IFS= read -r path; do sha256sum -- "$path"; done)
}

recovery_directories() {
    (cd -- "$1" && find . -xdev -mindepth 1 -type d -printf '%P\n' | LC_ALL=C sort | jq -R . | jq -s .)
}

# A target later than the payload snapshot is refused; select a verified coherent payload snapshot.
recovery_target_pair() {
    local base="$1" payload="$2" key
    [[ -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" ]] || fail 'PITR requires authenticated backups'
    for key in release database database_driver database_table_prefix; do
        [[ "$(jq -r ".$key" "$base/manifest.json")" == "$(jq -r ".$key" "$payload/manifest.json")" ]] \
            || fail "PITR base and payload differ in $key"
    done
    jq -e '.format == "kumwe-backup-v3" and (.pitr | type == "object")' "$base/manifest.json" >/dev/null \
        || fail 'PITR base has no native recovery coordinate'
    jq -e '.format == "kumwe-backup-v3" and (.pitr | type == "object")' "$payload/manifest.json" >/dev/null \
        || fail 'PITR payload has no native recovery coordinate'
    local snapshot target
    snapshot="$(jq -r '.payload_snapshot_at' "$payload/manifest.json")"
    target="${KUMWE_RESTORE_TARGET_TIME:-$snapshot}"
    [[ "$target" == "$snapshot" ]] \
        || fail 'A target later than the payload snapshot is refused; select a verified coherent payload snapshot.'
    [[ "$(date -u -d "$snapshot" +%s)" -ge "$(date -u -d "$(jq -r '.payload_snapshot_at' "$base/manifest.json")" +%s)" ]] \
        || fail 'PITR target precedes the base snapshot'
    [[ "$(jq -r '.pitr.source_id' "$base/manifest.json")" == "$(jq -r '.pitr.source_id' "$payload/manifest.json")" ]] \
        || fail 'PITR source identity differs'
}

# Archive paths are operator materialized, immutable copies, authenticated independently of the base.
recovery_verify_archive() {
    local archive="$1" source_id="$2"
    recovery_tree_safe "$archive"
    [[ -f "$archive/archive.json" && -f "$archive/checksums.sha256.minisig" ]] \
        || fail 'PITR needs an authenticated archive inventory'
    minisign -V -p "$KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE" -m "$archive/checksums.sha256" \
        -x "$archive/checksums.sha256.minisig" >&2
    [[ "$(sed -n 's/^[0-9a-f]\{64\}  //p' "$archive/checksums.sha256")" == \
        "$(recovery_file_list "$archive")" ]] || fail 'archive checksum inventory differs'
    (cd -- "$archive" && sha256sum --check --strict checksums.sha256 >&2)
    jq -e --arg source "$source_id" '.format == "kumwe-log-archive-v1" and .source_id == $source' \
        "$archive/archive.json" >/dev/null || fail 'archive source identity differs'
}

# Structured operator logging and outcome records (docs/operations/monitoring.md#logs).
#
# Each tool writes one JSON object per line to stderr in the shape the PHP runtime writes, so the backup and
# restore paths land in the same log pipeline with the same fields: correlation_id (shared by every tool a
# backup cycle runs, through KUMWE_CORRELATION_ID), request_id, causation_id (the parent tool's request),
# release, runtime (backup or restore) and outcome. Keys naming a credential lose their value and every
# value is scrubbed of URI userinfo and credential assignments before it is written. Stdout is untouched:
# callers such as backup-cycle.sh read a tool's result from it.
#
# When KUMWE_OPERATIONS_STATUS_DIR names an existing directory, the outcome of each run is also written
# atomically to <operation>.json there. The application reads those files at scrape time and publishes
# kumwe_recovery_last_success_timestamp_seconds and kumwe_recovery_last_failure_timestamp_seconds, which is
# what the backup-age and restore-failure alerts evaluate.
recovery_random_hex() {
    od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'
}

recovery_begin() {
    local runtime="$1" operation="$2"
    KUMWE_RECOVERY_RUNTIME="$runtime"
    KUMWE_RECOVERY_OPERATION="$operation"
    KUMWE_RECOVERY_LABEL="${3:-${operation//_/ }}"
    if [[ ! "${KUMWE_CORRELATION_ID:-}" =~ ^[A-Za-z0-9._-]{8,64}$ ]]; then
        KUMWE_CORRELATION_ID="${runtime}-$(recovery_random_hex 16)"
    fi
    KUMWE_RECOVERY_CAUSATION_ID=''
    if [[ "${KUMWE_RECOVERY_PARENT_REQUEST_ID:-}" =~ ^[A-Za-z0-9._-]{8,64}$ ]]; then
        KUMWE_RECOVERY_CAUSATION_ID="$KUMWE_RECOVERY_PARENT_REQUEST_ID"
    fi
    KUMWE_RECOVERY_REQUEST_ID="${operation//_/-}-$(recovery_random_hex 12)"
    KUMWE_RECOVERY_PARENT_REQUEST_ID="$KUMWE_RECOVERY_REQUEST_ID"
    recovery_failure_logged=''
    recovery_outcome_recorded=''
    export KUMWE_CORRELATION_ID KUMWE_RECOVERY_PARENT_REQUEST_ID
    trap 'recovery_finish $?' EXIT
    recovery_log info "Kumwe ${KUMWE_RECOVERY_LABEL} started." outcome started
}

# Write one structured line: recovery_log LEVEL MESSAGE [KEY VALUE]...
recovery_log() {
    local level="$1" message="$2" code
    shift 2
    case "$level" in
        debug) code=100 ;; info) code=200 ;; notice) code=250 ;; warning) code=300 ;;
        error) code=400 ;; critical) code=500 ;; *) code=200; level=info ;;
    esac
    local pairs=()
    while (($# >= 2)); do
        pairs+=("$1" "$2")
        shift 2
    done
    if ! command -v jq >/dev/null 2>&1; then
        printf '%s\n' "$message" >&2
        return 0
    fi
    jq -cn \
        --arg message "$message" --arg level "$level" --argjson code "$code" \
        --arg datetime "$(date -u +%Y-%m-%dT%H:%M:%S.%6N+00:00)" \
        --arg correlation "${KUMWE_CORRELATION_ID:-unknown}" --arg request "${KUMWE_RECOVERY_REQUEST_ID:-}" \
        --arg causation "${KUMWE_RECOVERY_CAUSATION_ID:-}" \
        --arg release "${KUMWE_RELEASE:-${KUMWE_EXPECTED_RELEASE:-unknown}}" \
        --arg runtime "${KUMWE_RECOVERY_RUNTIME:-recovery}" \
        --arg operation "${KUMWE_RECOVERY_OPERATION:-}" \
        --args '
        def scrub: gsub("(?<a>://)[^/\\s:@]+:[^/\\s@]+@"; "\(.a)[redacted]@")
            | gsub("(?<k>(authorization|cookie|password|secret|token|session|credential|passphrase|api_key|private_key)[A-Za-z_-]*\\s*[=:]\\s*)(\"[^\"]*\"|[^\\s,;)&]+)"; "\(.k)[redacted]"; "i");
        def redacted($key): $key | test("authorization|cookie|password|secret|token|session|credential|passphrase|api_key|private_key"; "i");
        ([range(0; $ARGS.positional | length; 2) as $i
            | {key: $ARGS.positional[$i], value: $ARGS.positional[$i + 1]}]
            | map(if redacted(.key) then .value = "[redacted]" else .value |= scrub end)
            | from_entries) as $context
        | {
            message: ($message | scrub),
            context: ({operation: $operation} + $context + {
                correlation_id: $correlation,
                release: $release,
                runtime: $runtime,
                outcome: ($context.outcome // (if $code >= 300 then "failure" else "success" end))
            }
            + (if $request == "" then {} else {request_id: $request} end)
            + (if $causation == "" then {} else {causation_id: $causation} end)),
            level: $code,
            level_name: ($level | ascii_upcase),
            channel: "kumwe",
            datetime: $datetime
        }' "${pairs[@]}" >&2 || printf '%s\n' "$message" >&2
}

# Log a refusal once and exit; the exit hook then records the failed outcome.
recovery_fail() {
    recovery_log error "$1" outcome failure
    recovery_failure_logged=1
    exit 1
}

# Record the latest outcome of an operation for the metrics endpoint; never fails the operation itself.
recovery_record_status() {
    local operation="$1" outcome="$2" directory="${KUMWE_OPERATIONS_STATUS_DIR:-}" now target staging
    [[ -n "$directory" ]] || return 0
    if [[ ! -d "$directory" || -L "$directory" ]] || ! command -v jq >/dev/null 2>&1; then
        recovery_log warning 'Kumwe operation status directory is unusable; outcome not recorded.' \
            status_directory "$directory" outcome unrecorded
        return 0
    fi
    now="$(date -u +%s)"
    target="$directory/${operation}.json"
    staging="$directory/.${operation}.json.$$"
    if ! jq -n \
        --arg operation "$operation" --arg outcome "$outcome" --argjson now "$now" \
        --arg correlation "${KUMWE_CORRELATION_ID:-unknown}" \
        --arg release "${KUMWE_RELEASE:-${KUMWE_EXPECTED_RELEASE:-unknown}}" \
        --slurpfile previous <(if [[ -f "$target" && ! -L "$target" ]]; then cat -- "$target"; else echo '{}'; fi) '
        ($previous[0] // {}) as $old
        | {
            schema: "kumwe-operation-status/v1",
            operation: $operation,
            last_outcome: $outcome,
            last_success_at: (if $outcome == "success" then $now else ($old.last_success_at // null) end),
            last_failure_at: (if $outcome == "failure" then $now else ($old.last_failure_at // null) end),
            correlation_id: $correlation,
            release: $release,
            updated_at: $now
        }' > "$staging" 2>/dev/null || ! mv -f -- "$staging" "$target"; then
        rm -f -- "$staging"
        recovery_log warning 'Kumwe operation status could not be written; outcome not recorded.' \
            status_directory "$directory" outcome unrecorded
    fi
}

# EXIT hook installed by recovery_begin: record the outcome and write the closing line exactly once.
recovery_finish() {
    local status="$1"
    [[ -n "${KUMWE_RECOVERY_OPERATION:-}" && -z "${recovery_outcome_recorded:-}" ]] || return 0
    recovery_outcome_recorded=1
    if [[ "$status" == 0 ]]; then
        recovery_record_status "$KUMWE_RECOVERY_OPERATION" success
        recovery_log info "Kumwe ${KUMWE_RECOVERY_LABEL:-${KUMWE_RECOVERY_OPERATION//_/ }} completed." outcome success
    else
        recovery_record_status "$KUMWE_RECOVERY_OPERATION" failure
        if [[ -z "${recovery_failure_logged:-}" ]]; then
            recovery_log error "Kumwe ${KUMWE_RECOVERY_LABEL:-${KUMWE_RECOVERY_OPERATION//_/ }} stopped before completing." \
                exit_status "$status" outcome failure
        fi
    fi
}
