#!/usr/bin/env bash
# Keep the newest N authenticated snapshots. Never remove unknown or damaged directories.
set -Eeuo pipefail
umask 077
fail() { echo "Kumwe backup retention failed: $*" >&2; exit 1; }
[[ $# == 0 ]] || fail 'usage: backup-retain.sh (configured by environment)'
root="${KUMWE_BACKUP_DIR:?configure backup directory}"
keep="${KUMWE_BACKUP_KEEP:?configure a positive snapshot count}"
[[ "$keep" =~ ^[1-9][0-9]{0,6}$ ]] || fail 'KUMWE_BACKUP_KEEP must be positive; the last backup is never pruned'
[[ -d "$root" && ! -L "$root" && "$root" == /* ]] || fail 'backup root must be an absolute directory'
[[ -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" ]] || fail 'retention requires authenticated backups'
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
[[ ! -L "$root/.kumwe-backup.lock" ]] || fail 'backup lock must not be symbolic'
exec 9>>"$root/.kumwe-backup.lock"
flock -n 9 || fail 'backup or retention already running'
inventory="$(mktemp)"
trap 'rm -f -- "$inventory"' EXIT
while IFS= read -r -d '' backup; do
    [[ "$(basename -- "$backup")" =~ ^kumwe-2\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?-[0-9]{8}T[0-9]{6}Z$ ]] \
        || fail 'unexpected completed backup name; no backups pruned'
    bash "$script_directory/restore-verify.sh" "$backup" >&2
    created="$(jq -r '.created_at' "$backup/manifest.json")"
    epoch="$(date -u -d "$created" +%s)"
    printf '%s\t%s\n' "$epoch" "$backup" >> "$inventory"
done < <(find "$root" -mindepth 1 -maxdepth 1 -type d -name 'kumwe-2.*' -print0)
# Validate every candidate before deleting any; age is based on signed metadata, not release sorting.
mapfile -t expired < <(LC_ALL=C sort -rn "$inventory" | tail -n +"$((keep + 1))" | cut -f2-)
for backup in "${expired[@]}"; do
    [[ ! -L "$backup" && -d "$backup" ]] || fail 'backup changed during retention'
    rm -rf -- "$backup"
    printf 'Pruned verified snapshot: %s\n' "$backup"
done
