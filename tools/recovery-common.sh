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
