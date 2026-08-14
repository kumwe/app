#!/usr/bin/env bash

# Fail-closed drill for tools/restore-verify.sh and tools/restore.sh.
#
# The happy-path drill proves a good backup restores. This proves the bad ones do not. Every case
# copies the supplied genuine backup, damages exactly one property, and insists the tooling exits
# non-zero with the message that names the property. A case that fails to fail is a finding: silent
# acceptance of a damaged backup is the failure mode a restore drill exists to rule out.
#
# Usage: tests/Support/backup-tamper-drill.sh /absolute/path/to/good/backup
#
# The signature cases run only when KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE and
# KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE both name readable files, because forging a checksum file
# that the tooling would otherwise accept requires re-signing it. The non-empty-database case runs
# only when the KUMWE_RESTORE_DB_* variables describe a database that already holds the restore, so
# the drill never invents a connection of its own.

set -Eeuo pipefail

fail() {
    echo "Kumwe backup tamper drill failed: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

[[ $# -eq 1 ]] || fail 'usage: tests/Support/backup-tamper-drill.sh /absolute/path/to/backup'

require_command cp
require_command dd
require_command od
require_command grep
require_command jq
require_command sha256sum
require_command tar

[[ -d "$1" ]] || fail "backup directory '$1' does not exist"
source_backup="$(cd -- "$1" && pwd -P)"
[[ -f "${source_backup}/manifest.json" ]] || fail 'the supplied backup has no manifest'

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${script_directory}/../.." && pwd -P)"
verify_script="${repository_root}/tools/restore-verify.sh"
restore_script="${repository_root}/tools/restore.sh"
[[ -x "$verify_script" || -f "$verify_script" ]] || fail 'tools/restore-verify.sh is unavailable'
[[ -x "$restore_script" || -f "$restore_script" ]] || fail 'tools/restore.sh is unavailable'

work_root="$(mktemp -d)"
cleanup() {
    chmod -R u+rwX "$work_root" 2>/dev/null || true
    rm -rf -- "$work_root"
}
trap cleanup EXIT INT TERM

signing_available=0
if [[ -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" && -n "${KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE:-}" ]] \
    && [[ -r "$KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE" && -r "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" ]] \
    && command -v minisign >/dev/null 2>&1; then
    signing_available=1
fi

proved=0

# Re-stamp checksums.sha256 over the damaged copy, and re-sign it when a signing key is configured,
# so the case under test is the one the name promises rather than the checksum that guards it.
reseal() {
    local backup="$1"
    (
        cd -- "$backup"
        sha256sum database.dump extension-assets.tar.gz extensions.tar.gz manifest.json media.tar.gz \
            private.tar.gz > checksums.sha256
    )
    if [[ -f "${backup}/checksums.sha256.minisig" && "$signing_available" == 1 ]]; then
        rm -f -- "${backup}/checksums.sha256.minisig"
        minisign -S -s "$KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE" \
            -m "${backup}/checksums.sha256" \
            -x "${backup}/checksums.sha256.minisig" >/dev/null
    fi
}

# Invert one byte in place. Writing a fixed byte would be a no-op wherever the file already holds
# it, which silently turns a tamper case into a clean-backup case.
flip_byte() {
    local file="$1"
    local offset="$2"
    local original flipped
    original="$(dd if="$file" bs=1 skip="$offset" count=1 status=none | od -An -tu1 | tr -d ' \n')"
    [[ "$original" =~ ^[0-9]+$ ]] || fail "could not read byte ${offset} of '${file}'"
    flipped=$((original ^ 255))
    printf "$(printf '\\x%02x' "$flipped")" \
        | dd of="$file" bs=1 seek="$offset" count=1 conv=notrunc status=none
}

copy_backup() {
    local case_name="$1"
    local target="${work_root}/${case_name}"
    cp -a -- "$source_backup" "$target"
    chmod -R u+rwX "$target"
    printf %s "$target"
}

# Run a command that must fail, and must say why in the way the operator will read.
expect_refusal() {
    local case_name="$1"
    local expected="$2"
    shift 2
    local output status
    set +e
    output="$("$@" 2>&1)"
    status=$?
    set -e
    if [[ $status -eq 0 ]]; then
        printf '%s\n' "$output" >&2
        fail "case '${case_name}' was accepted; a damaged backup must be refused"
    fi
    if ! grep --fixed-strings --quiet -- "$expected" <<< "$output"; then
        printf '%s\n' "$output" >&2
        fail "case '${case_name}' failed without the expected refusal '${expected}'"
    fi
    proved=$((proved + 1))
    echo "refused as required: ${case_name}"
}

verify_case() {
    local case_name="$1"
    local expected="$2"
    local backup="$3"
    expect_refusal "$case_name" "$expected" env \
        KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" \
        KUMWE_EXPECTED_RELEASE="${KUMWE_EXPECTED_RELEASE:-}" \
        bash "$verify_script" "$backup"
}

# 1. A single flipped byte in the database payload must not survive checksum verification.
corrupted="$(copy_backup corrupted-database-dump)"
flip_byte "${corrupted}/database.dump" 8
verify_case corrupted-database-dump 'database.dump: FAILED' "$corrupted"

# 2. The same guard has to cover the filesystem payloads, not only the database.
corrupted_media="$(copy_backup corrupted-media-archive)"
flip_byte "${corrupted_media}/media.tar.gz" 12
verify_case corrupted-media-archive 'media.tar.gz: FAILED' "$corrupted_media"

# 3. Editing the manifest is editing a checksummed payload; it must be caught even though the
#    manifest is metadata rather than data.
edited_manifest="$(copy_backup edited-manifest-without-reseal)"
jq '.release = "2.99.0"' "${edited_manifest}/manifest.json" > "${edited_manifest}/manifest.json.tmp"
mv -- "${edited_manifest}/manifest.json.tmp" "${edited_manifest}/manifest.json"
verify_case edited-manifest-without-reseal 'manifest.json: FAILED' "$edited_manifest"

# 4. A payload that is simply absent must be named, not skipped.
missing_payload="$(copy_backup missing-payload)"
rm -f -- "${missing_payload}/private.tar.gz"
verify_case missing-payload "missing required file 'private.tar.gz'" "$missing_payload"

# 5. A checksum file that no longer describes the payload set must be refused before any checksum
#    is compared, so an added or dropped line cannot narrow what gets verified.
narrowed_checksums="$(copy_backup narrowed-checksum-manifest)"
grep --invert-match ' private.tar.gz$' "${narrowed_checksums}/checksums.sha256" \
    > "${narrowed_checksums}/checksums.sha256.tmp"
mv -- "${narrowed_checksums}/checksums.sha256.tmp" "${narrowed_checksums}/checksums.sha256"
verify_case narrowed-checksum-manifest 'checksum manifest contains an unexpected or missing path' \
    "$narrowed_checksums"

# 6. A backup written by an older major must be refused rather than restored on a hope.
old_format="$(copy_backup old-manifest-version)"
jq '.format = "kumwe-backup-v1"' "${old_format}/manifest.json" > "${old_format}/manifest.json.tmp"
mv -- "${old_format}/manifest.json.tmp" "${old_format}/manifest.json"
reseal "$old_format"
verify_case old-manifest-version 'manifest is not a supported Kumwe 2.x backup' "$old_format"

# 7. An archive member that escapes its extraction root must be refused before extraction, not
#    contained during it.
traversal="$(copy_backup traversal-archive)"
traversal_source="${work_root}/traversal-source"
install -d -m 0700 "$traversal_source"
printf 'escaped' > "${traversal_source}/probe.txt"
tar --create --gzip --file="${traversal}/media.tar.gz" \
    --transform 's|^\./probe\.txt$|../escape.txt|' --directory="$traversal_source" . 2>/dev/null
reseal "$traversal"
verify_case traversal-archive 'archive contains an absolute or parent-traversal path' "$traversal"

# 8. A symbolic link in an archive is a write to wherever it points once extraction follows it.
symlinked="$(copy_backup symlink-archive)"
symlink_source="${work_root}/symlink-source"
install -d -m 0700 "$symlink_source"
printf 'linked' > "${symlink_source}/probe.txt"
ln -s /etc/passwd "${symlink_source}/escape"
tar --create --gzip --file="${symlinked}/extensions.tar.gz" --directory="$symlink_source" .
reseal "$symlinked"
verify_case symlink-archive 'archive contains a symbolic link or unsupported file type' "$symlinked"

# 9. A symbolic link beside the payloads redirects what gets read; the directory itself is checked.
linked_directory="$(copy_backup symlink-in-backup-directory)"
ln -s /etc/hostname "${linked_directory}/notes.txt"
verify_case symlink-in-backup-directory 'backup directory contains symbolic links' "$linked_directory"

# 10. Release pinning must actually pin.
release_mismatch="$(copy_backup unexpected-release)"
expect_refusal unexpected-release 'does not match expected release' env \
    KUMWE_EXPECTED_RELEASE=2.99.0 \
    KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" \
    bash "$verify_script" "$release_mismatch"

if [[ "$signing_available" == 1 ]]; then
    # 11. The case checksums alone cannot catch: a payload edited and then re-stamped so that
    #     checksums.sha256 agrees with it, leaving only the signature — which still covers the
    #     original checksum file — to notice. This is why backups are signed at all.
    forged="$(copy_backup resealed-after-tamper)"
    [[ -f "${forged}/checksums.sha256.minisig" ]] || fail 'the supplied backup is unsigned'
    flip_byte "${forged}/database.dump" 12
    (
        cd -- "$forged"
        sha256sum database.dump extension-assets.tar.gz extensions.tar.gz manifest.json media.tar.gz \
            private.tar.gz > checksums.sha256
    )
    verify_case resealed-after-tamper 'Signature verification failed' "$forged"

    # 12. Asking for authentication and getting none must fail, not degrade to checksums only.
    unsigned="$(copy_backup missing-signature)"
    rm -f -- "${unsigned}/checksums.sha256.minisig"
    verify_case missing-signature 'signed verification was requested but signature is missing' "$unsigned"

    # 13. A signed backup verified without a public key is an unauthenticated restore wearing a
    #     signature; the tooling refuses rather than ignoring the file it cannot check.
    unauthenticated="$(copy_backup signed-without-public-key)"
    expect_refusal signed-without-public-key \
        'backup is signed; configure KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE' \
        env --unset=KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE bash "$verify_script" "$unauthenticated"
else
    echo 'skipped: signature cases need KUMWE_BACKUP_SIGNING_{PUBLIC,SECRET}_KEY_FILE and minisign'
fi

# 14. Restoring one engine's dump into another is a data-loss event dressed as a migration.
manifest_driver="$(jq -r '.database_driver' "${source_backup}/manifest.json")"
foreign_driver=pgsql
[[ "$manifest_driver" == pgsql ]] && foreign_driver=mariadb
expect_refusal driver-mismatch \
    "backup database driver '${manifest_driver}' cannot be restored as '${foreign_driver}'" \
    env \
    KUMWE_RESTORE_DB_DRIVER="$foreign_driver" \
    KUMWE_RESTORE_DB_NAME="${KUMWE_RESTORE_DB_NAME:-kumwe_tamper_drill}" \
    KUMWE_RESTORE_DB_USER="${KUMWE_RESTORE_DB_USER:-kumwe}" \
    KUMWE_RESTORE_DB_PASSWORD_FILE="${KUMWE_RESTORE_DB_PASSWORD_FILE:-/dev/null}" \
    KUMWE_RESTORE_MEDIA_DIR="${work_root}/driver-mismatch/media" \
    KUMWE_RESTORE_PRIVATE_DIR="${work_root}/driver-mismatch/private" \
    KUMWE_RESTORE_EXTENSIONS_DIR="${work_root}/driver-mismatch/extensions" \
    KUMWE_RESTORE_EXTENSION_ASSETS_DIR="${work_root}/driver-mismatch/extension-assets" \
    KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" \
    bash "$restore_script" "$source_backup"

# 15. A restore target that already exists is somebody's live data.
install -d -m 0700 "${work_root}/occupied" "${work_root}/occupied/media"
expect_refusal existing-filesystem-target 'media target must not exist' env \
    KUMWE_RESTORE_DB_NAME="${KUMWE_RESTORE_DB_NAME:-kumwe_tamper_drill}" \
    KUMWE_RESTORE_DB_USER="${KUMWE_RESTORE_DB_USER:-kumwe}" \
    KUMWE_RESTORE_DB_PASSWORD_FILE="${KUMWE_RESTORE_DB_PASSWORD_FILE:-/dev/null}" \
    KUMWE_RESTORE_MEDIA_DIR="${work_root}/occupied/media" \
    KUMWE_RESTORE_PRIVATE_DIR="${work_root}/occupied/private" \
    KUMWE_RESTORE_EXTENSIONS_DIR="${work_root}/occupied/extensions" \
    KUMWE_RESTORE_EXTENSION_ASSETS_DIR="${work_root}/occupied/extension-assets" \
    bash "$restore_script" "$source_backup"

# 16. Restoring over an occupied database is the same event at the other end of the pipe. This case
#     needs a real connection, so it runs only when the caller hands one over — normally the
#     database the happy-path restore just filled.
if [[ -n "${KUMWE_TAMPER_DRILL_OCCUPIED_DB:-}" ]]; then
    install -d -m 0700 "${work_root}/occupied-database"
    expect_refusal non-empty-target-database 'restore database is not empty' env \
        KUMWE_RESTORE_DB_DRIVER="$manifest_driver" \
        KUMWE_RESTORE_DB_HOST="${KUMWE_RESTORE_DB_HOST:-database}" \
        KUMWE_RESTORE_DB_PORT="${KUMWE_RESTORE_DB_PORT:-}" \
        KUMWE_RESTORE_DB_NAME="$KUMWE_TAMPER_DRILL_OCCUPIED_DB" \
        KUMWE_RESTORE_DB_USER="$KUMWE_RESTORE_DB_USER" \
        KUMWE_RESTORE_DB_PASSWORD_FILE="$KUMWE_RESTORE_DB_PASSWORD_FILE" \
        KUMWE_RESTORE_DB_TABLE_PREFIX="${KUMWE_RESTORE_DB_TABLE_PREFIX:-}" \
        KUMWE_RESTORE_MEDIA_DIR="${work_root}/occupied-database/media" \
        KUMWE_RESTORE_PRIVATE_DIR="${work_root}/occupied-database/private" \
        KUMWE_RESTORE_EXTENSIONS_DIR="${work_root}/occupied-database/extensions" \
        KUMWE_RESTORE_EXTENSION_ASSETS_DIR="${work_root}/occupied-database/extension-assets" \
        KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" \
        bash "$restore_script" "$source_backup"
else
    echo 'skipped: the non-empty-database case needs KUMWE_TAMPER_DRILL_OCCUPIED_DB'
fi

# The genuine backup must still verify after all of that, proving the drill damaged only its copies.
KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" \
    bash "$verify_script" "$source_backup" >/dev/null \
    || fail 'the genuine backup no longer verifies; the drill damaged its input'

echo "Backup tamper drill proved ${proved} fail-closed refusals."
