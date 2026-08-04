#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    echo "Kumwe restore verification failed: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

[[ $# -eq 1 ]] || fail 'usage: tools/restore-verify.sh /absolute/path/to/backup'

require_command awk
require_command grep
require_command jq
require_command pg_restore
require_command sha256sum
require_command sort
require_command tar

[[ -d "$1" ]] || fail "backup directory '$1' does not exist"
backup_directory="$(cd -- "$1" && pwd -P)"

case "$backup_directory" in
    / | /home | /root | /workspace)
        fail "refusing unsafe backup directory '$backup_directory'"
        ;;
esac

for required_file in checksums.sha256 database.dump manifest.json media.tar.gz; do
    [[ -f "${backup_directory}/${required_file}" ]] || fail "missing required file '$required_file'"
    [[ ! -L "${backup_directory}/${required_file}" ]] || fail "required file '$required_file' is a symbolic link"
done

if find "$backup_directory" -xdev -type l -print -quit | grep -q .; then
    fail 'backup directory contains symbolic links'
fi

actual_checksum_files="$({ awk '{print $2}' "${backup_directory}/checksums.sha256" || true; } | sort)"
expected_checksum_files="$(printf '%s\n' database.dump manifest.json media.tar.gz | sort)"
[[ "$actual_checksum_files" == "$expected_checksum_files" ]] \
    || fail 'checksum manifest contains an unexpected or missing path'

(
    cd -- "$backup_directory"
    sha256sum --check --strict checksums.sha256
)

jq -e '
    .format == "kumwe-backup-v2"
    and .product == "Kumwe CMS"
    and .product_major == 2
    and (.release | test("^2\\.[0-9]+\\.[0-9]+([+-][0-9A-Za-z.-]+)?$"))
    and (.database_schema | test("^[A-Za-z_][A-Za-z0-9_]{0,62}$"))
    and .contents == ["database.dump", "media.tar.gz"]
' "${backup_directory}/manifest.json" >/dev/null \
    || fail 'manifest is not a supported Kumwe 2.x backup; Kumwe 1.x and unknown formats are refused'

if [[ -n "${KUMWE_EXPECTED_RELEASE:-}" ]]; then
    [[ "$KUMWE_EXPECTED_RELEASE" =~ ^2\. ]] || fail 'KUMWE_EXPECTED_RELEASE must be a 2.x release'
    actual_release="$(jq -r '.release' "${backup_directory}/manifest.json")"
    [[ "$actual_release" == "$KUMWE_EXPECTED_RELEASE" ]] \
        || fail "backup release '$actual_release' does not match expected release '$KUMWE_EXPECTED_RELEASE'"
fi

if [[ -n "${KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE:-}" ]]; then
    require_command minisign
    [[ -r "$KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE" ]] || fail 'backup public key file is not readable'
    [[ -f "${backup_directory}/checksums.sha256.minisig" ]] || fail 'signed verification was requested but signature is missing'
    minisign \
        -V \
        -p "$KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE" \
        -m "${backup_directory}/checksums.sha256" \
        -x "${backup_directory}/checksums.sha256.minisig"
elif [[ -f "${backup_directory}/checksums.sha256.minisig" ]]; then
    fail 'backup is signed; configure KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE to authenticate it'
fi

pg_restore --list "${backup_directory}/database.dump" >/dev/null

if ! archive_listing="$(tar --list --gzip --file="${backup_directory}/media.tar.gz")"; then
    fail 'media archive cannot be read'
fi

if grep -E '(^/|(^|/)\.\.(/|$))' <<< "$archive_listing" >/dev/null; then
    fail 'media archive contains an absolute or parent-traversal path'
fi

if ! archive_verbose_listing="$(tar --list --verbose --gzip --file="${backup_directory}/media.tar.gz")"; then
    fail 'media archive metadata cannot be read'
fi

if awk 'substr($1, 1, 1) !~ /^[-d]$/ { found = 1 } END { exit found ? 0 : 1 }' \
    <<< "$archive_verbose_listing"; then
    fail 'media archive contains a symbolic link or unsupported file type'
fi

echo "Verified Kumwe 2.x backup: $backup_directory"
