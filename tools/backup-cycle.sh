#!/usr/bin/env bash
# Operator hooks are executable paths, never shell strings. All hooks must fail closed.
set -Eeuo pipefail
umask 077
fail() { echo "Kumwe backup cycle failed: $*" >&2; exit 1; }
[[ $# == 0 ]] || fail 'usage: backup-cycle.sh (configured by environment)'
script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
for variable in KUMWE_BACKUP_QUIESCE_HOOK KUMWE_BACKUP_RESUME_HOOK KUMWE_BACKUP_OFFSITE_HOOK; do
    hook="${!variable:-}"
    [[ "$hook" == /* && -f "$hook" && -x "$hook" ]] || fail "$variable must name an executable absolute path"
done
[[ -n "${KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE:-}" && -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" ]] \
    || fail 'scheduled backups require signing and verification keys'
root="${KUMWE_BACKUP_DIR:?configure backup directory}"
[[ -d "$root" && ! -L "$root/.kumwe-cycle.lock" ]] || fail 'invalid backup root or cycle lock'
exec 8>>"$root/.kumwe-cycle.lock"
flock -n 8 || fail 'another scheduled backup cycle is running'
resume() {
    local status=$?
    trap - EXIT
    "$KUMWE_BACKUP_RESUME_HOOK" || status=1
    exit "$status"
}
trap resume EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
"$KUMWE_BACKUP_QUIESCE_HOOK"
export KUMWE_BACKUP_CONSISTENCY=quiesced
backup="$(bash "$script_directory/backup.sh")"
bash "$script_directory/restore-verify.sh" "$backup"
# The hook must copy AND verify the offsite copy, returning nonzero if either fails.
"$KUMWE_BACKUP_OFFSITE_HOOK" "$backup"
"$KUMWE_BACKUP_RESUME_HOOK"
trap - EXIT
bash "$script_directory/backup-retain.sh"
printf 'Backup cycle completed: %s\n' "$backup"
