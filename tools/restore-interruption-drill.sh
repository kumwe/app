#!/usr/bin/env bash

# Kills a running restore and proves the re-run finishes it.
#
# An interrupted restore used to leave partially populated targets that the clean-target precondition
# then refused to overwrite, so recovery meant deleting directories by hand and hoping the right ones
# were deleted. tools/restore.sh now records a claim naming exactly the targets it created and a
# completion manifest saying it finished, which makes the re-run safe to automate and the finished
# state possible to check. This drill is the evidence for both: it takes a real backup, restores it
# into a scratch database and scratch targets, sends the restore SIGKILL the moment it starts moving
# targets into place, and then re-runs it and compares the result against the source byte for byte.
#
# It is an operator drill rather than a continuous-integration step because it needs the database dump
# and restore clients on the host, a scratch database it may drop and recreate, and the privilege to
# do so — none of which the unit or integration jobs have. Run it against a disposable database when
# qualifying a release or after changing anything in tools/backup.sh or tools/restore.sh.
#
# Usage:
#   KUMWE_DRILL_DB_DRIVER=mariadb|mysql|pgsql \
#   KUMWE_DRILL_DB_HOST=127.0.0.1 KUMWE_DRILL_DB_PORT=3306 \
#   KUMWE_DRILL_DB_NAME=kumwe_source KUMWE_DRILL_RESTORE_DB_NAME=kumwe_restore_drill \
#   KUMWE_DRILL_DB_USER=kumwe KUMWE_DRILL_DB_PASSWORD_FILE=/run/secrets/db_password \
#   KUMWE_DRILL_DB_TABLE_PREFIX=kumwe_ \
#   KUMWE_DRILL_MEDIA_DIR=... KUMWE_DRILL_PRIVATE_DIR=... \
#   KUMWE_DRILL_EXTENSIONS_DIR=... KUMWE_DRILL_EXTENSION_ASSETS_DIR=... \
#   bash tools/restore-interruption-drill.sh
#
# The scratch restore database named by KUMWE_DRILL_RESTORE_DB_NAME must exist and be empty; the drill
# does not create or drop databases, so it can never be pointed at the wrong one by accident.

set -Eeuo pipefail

fail() {
    echo "Kumwe restore interruption drill failed: $*" >&2
    exit 1
}

require_value() {
    local variable_name="$1"
    [[ -n "${!variable_name:-}" ]] || fail "required environment variable '$variable_name' is empty"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

require_command jq
require_command setsid
require_command sha256sum
require_command tar

require_value KUMWE_DRILL_DB_NAME
require_value KUMWE_DRILL_RESTORE_DB_NAME
require_value KUMWE_DRILL_DB_USER
require_value KUMWE_DRILL_DB_PASSWORD_FILE
require_value KUMWE_DRILL_MEDIA_DIR
require_value KUMWE_DRILL_PRIVATE_DIR
require_value KUMWE_DRILL_EXTENSIONS_DIR
require_value KUMWE_DRILL_EXTENSION_ASSETS_DIR

[[ "$KUMWE_DRILL_DB_NAME" != "$KUMWE_DRILL_RESTORE_DB_NAME" ]] \
    || fail 'the drill must restore into a database other than the source'

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
work_root="$(mktemp -d)"
trap 'rm -rf -- "$work_root"' EXIT

mkdir -p "$work_root/backups" "$work_root/restored"

export KUMWE_BACKUP_CONSISTENCY=quiesced
export KUMWE_BACKUP_DIR="$work_root/backups"
export KUMWE_DB_DRIVER="${KUMWE_DRILL_DB_DRIVER:-mariadb}"
export KUMWE_DB_HOST="${KUMWE_DRILL_DB_HOST:-127.0.0.1}"
export KUMWE_DB_PORT="${KUMWE_DRILL_DB_PORT:-}"
export KUMWE_DB_NAME="$KUMWE_DRILL_DB_NAME"
export KUMWE_DB_USER="$KUMWE_DRILL_DB_USER"
export KUMWE_DB_PASSWORD_FILE="$KUMWE_DRILL_DB_PASSWORD_FILE"
export KUMWE_DB_TABLE_PREFIX="${KUMWE_DRILL_DB_TABLE_PREFIX:-kumwe_}"
export KUMWE_MEDIA_DIR="$KUMWE_DRILL_MEDIA_DIR"
export KUMWE_PRIVATE_DIR="$KUMWE_DRILL_PRIVATE_DIR"
export KUMWE_EXTENSIONS_DIR="$KUMWE_DRILL_EXTENSIONS_DIR"
export KUMWE_EXTENSION_ASSETS_DIR="$KUMWE_DRILL_EXTENSION_ASSETS_DIR"
export KUMWE_RELEASE="${KUMWE_DRILL_RELEASE:-2.0.0}"

backup_path="$(bash "${script_directory}/backup.sh")"
[[ -d "$backup_path" ]] || fail 'the drill could not take a backup to restore'

export KUMWE_RESTORE_DB_DRIVER="$KUMWE_DB_DRIVER"
export KUMWE_RESTORE_DB_HOST="$KUMWE_DB_HOST"
export KUMWE_RESTORE_DB_PORT="$KUMWE_DB_PORT"
export KUMWE_RESTORE_DB_NAME="$KUMWE_DRILL_RESTORE_DB_NAME"
export KUMWE_RESTORE_DB_USER="$KUMWE_DRILL_DB_USER"
export KUMWE_RESTORE_DB_PASSWORD_FILE="$KUMWE_DRILL_DB_PASSWORD_FILE"
export KUMWE_RESTORE_DB_TABLE_PREFIX="$KUMWE_DB_TABLE_PREFIX"
export KUMWE_RESTORE_MEDIA_DIR="$work_root/restored/media"
export KUMWE_RESTORE_PRIVATE_DIR="$work_root/restored/private"
export KUMWE_RESTORE_EXTENSIONS_DIR="$work_root/restored/extensions"
export KUMWE_RESTORE_EXTENSION_ASSETS_DIR="$work_root/restored/extension-assets"
export KUMWE_RESTORE_MANIFEST="$work_root/restored/kumwe-restore-manifest.json"

# First, the property that must survive the new behaviour: a target nobody claimed is somebody's data,
# and the restore still refuses it rather than clearing it.
mkdir -p "$KUMWE_RESTORE_MEDIA_DIR"
echo 'not written by this restore' > "$KUMWE_RESTORE_MEDIA_DIR/foreign.txt"
if bash "${script_directory}/restore.sh" "$backup_path" > "$work_root/foreign-run.log" 2>&1; then
    fail 'a restore must refuse targets it did not create'
fi
grep -q 'media target must not exist' "$work_root/foreign-run.log" \
    || fail 'a refused restore must say which target is in the way'
[[ -f "$KUMWE_RESTORE_MEDIA_DIR/foreign.txt" ]] || fail 'a refused restore must leave foreign data untouched'
rm -rf -- "$KUMWE_RESTORE_MEDIA_DIR" "${KUMWE_RESTORE_MANIFEST}.partial"
echo 'Refused a target this restore did not create, and left it untouched.'

# The kill point is the first moment a target is moved into place, which is the state the clean-target
# precondition used to make unrecoverable: the database is populated and some, but not all, of the file
# targets exist.
setsid bash "${script_directory}/restore.sh" "$backup_path" > "$work_root/first-run.log" 2>&1 &
restore_pid=$!
deadline=$(( SECONDS + 300 ))
killed=0

while (( SECONDS < deadline )); do
    if [[ -e "$KUMWE_RESTORE_MEDIA_DIR" ]]; then
        kill -KILL -- "-${restore_pid}" 2>/dev/null || kill -KILL "$restore_pid" 2>/dev/null || true
        killed=1
        break
    fi
    if ! kill -0 "$restore_pid" 2>/dev/null; then
        break
    fi
    sleep 0.05
done

wait "$restore_pid" 2>/dev/null || true
[[ $killed -eq 1 ]] || fail 'the restore finished before the drill could interrupt it; nothing was proven'
[[ ! -e "$KUMWE_RESTORE_MANIFEST" ]] || fail 'an interrupted restore must not leave a completion manifest'
[[ -f "${KUMWE_RESTORE_MANIFEST}.partial" ]] || fail 'an interrupted restore must leave its claim behind'
echo 'Interrupted a restore that had already begun publishing targets.'

# The recovery: the same command, unchanged, with no manual cleanup in between.
bash "${script_directory}/restore.sh" "$backup_path" > "$work_root/second-run.log" 2>&1 \
    || { cat "$work_root/second-run.log" >&2; fail 're-running an interrupted restore must succeed'; }

[[ -f "$KUMWE_RESTORE_MANIFEST" ]] || fail 'a finished restore must leave a completion manifest'
[[ ! -e "${KUMWE_RESTORE_MANIFEST}.partial" ]] || fail 'a finished restore must clear its claim'
jq -e '.format == "kumwe-restore-v1" and .resumed_after_interruption == true' "$KUMWE_RESTORE_MANIFEST" \
    >/dev/null || fail 'the completion manifest must record that this restore resumed an interrupted one'

tree_digest() {
    (
        cd -- "$1"
        find . -xdev -type f -print0 | sort --zero-terminated | xargs --null --no-run-if-empty sha256sum
    ) | sha256sum | awk '{print $1}'
}

check_tree() {
    local label="$1"
    local source_tree="$2"
    local restored_tree="$3"
    local recorded

    [[ "$(tree_digest "$source_tree")" == "$(tree_digest "$restored_tree")" ]] \
        || fail "the restored $label tree does not match the source"
    recorded="$(jq -r --arg tree "$label" '.trees[$tree].sha256' "$KUMWE_RESTORE_MANIFEST")"
    [[ "$recorded" == "$(tree_digest "$restored_tree")" ]] \
        || fail "the completion manifest does not describe the restored $label tree"
}

check_tree media "$KUMWE_MEDIA_DIR" "$KUMWE_RESTORE_MEDIA_DIR"
check_tree private "$KUMWE_PRIVATE_DIR" "$KUMWE_RESTORE_PRIVATE_DIR"
check_tree extensions "$KUMWE_EXTENSIONS_DIR" "$KUMWE_RESTORE_EXTENSIONS_DIR"
check_tree extension_assets "$KUMWE_EXTENSION_ASSETS_DIR" "$KUMWE_RESTORE_EXTENSION_ASSETS_DIR"

echo "Recovered the interrupted restore with no manual cleanup; every tree matches the source."
echo "Completion manifest: $(jq -c '{backup_id, completed_at, resumed_after_interruption}' "$KUMWE_RESTORE_MANIFEST")"
