#!/usr/bin/env bash
# Measure actual Restic storage for two real snapshots; keep repository snapshots for independent review.
set -Eeuo pipefail
umask 077
fail() { echo "Kumwe deduplication drill failed: $*" >&2; exit 1; }
[[ $# == 3 ]] || fail 'usage: backup-dedup-drill.sh BEFORE AFTER NEW-EVIDENCE-DIRECTORY'
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
[[ ! -e "$3" && "$3" == /* ]] || fail 'evidence directory must be an absent absolute path'
command -v restic >/dev/null || fail 'configure the optional Restic reference transport'
[[ -n "${RESTIC_REPOSITORY:-}${RESTIC_REPOSITORY_FILE:-}" ]] || fail 'configure an isolated Restic repository'
[[ -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" ]] || fail 'measure authenticated snapshots'
for backup in "$1" "$2"; do
    bash "$script_directory/restore-verify.sh" "$backup" >&2
    [[ "$(jq -r '.format' "$backup/manifest.json")" == kumwe-backup-v3 ]] || fail 'measure v3 plain trees'
done
mkdir -m 0700 -- "$3"
evidence="$(cd -- "$3" && pwd -P)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
# A stable path, hostname and tag avoid accidental restic grouping changes between runs.
tag="kumwe-recovery-drill-$(date -u +%Y%m%dT%H%M%SZ)-$$"
for phase in before after; do
    backup="$1"
    [[ "$phase" == before ]] || backup="$2"
    rm -rf -- "$work/snapshot"
    cp -a --one-file-system -- "$backup" "$work/snapshot"
    restic backup --json --host kumwe-recovery-drill --tag "$tag" "$work/snapshot" \
        > "$evidence/$phase.jsonl"
    jq -se 'map(select(.message_type == "summary")) | length == 1' "$evidence/$phase.jsonl" >/dev/null \
        || fail 'Restic omitted its stored-byte summary'
done
snapshot="$(jq -rs 'map(select(.message_type == "summary"))[0].snapshot_id' "$evidence/after.jsonl")"
[[ "$snapshot" =~ ^[0-9a-f]{8,64}$ ]] || fail 'invalid Restic snapshot identity'
restic restore "$snapshot:$work/snapshot" --target "$work/restored" >&2
bash "$script_directory/restore-verify.sh" "$work/restored" >&2
first="$(jq -sc 'map(select(.message_type == "summary"))[0]' "$evidence/before.jsonl")"
second="$(jq -sc 'map(select(.message_type == "summary"))[0]' "$evidence/after.jsonl")"
jq -n --arg tag "$tag" --argjson first "$first" --argjson second "$second" \
    '{transport: "restic", tag: $tag, before: $first, after: $second, restored_copy_verified: true}' \
    > "$evidence/measurement.json"
printf 'Measured Restic storage and verified the restored second snapshot: %s\n' "$evidence/measurement.json"
