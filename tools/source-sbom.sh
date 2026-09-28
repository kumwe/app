#!/usr/bin/env bash
# Verify pinned native archive bytes before generating the full declared-source licence inventory.
set -euo pipefail
output="${1:?Output directory required}"
native_scratch="$(mktemp -d)"
trap 'rm -rf -- "$native_scratch"' EXIT
if [[ -n "${2:-}" ]]; then
    cp -- "$2" "$native_scratch/source.tar.gz"
else
    curl --fail --location --silent --show-error --retry 3 --proto '=https' --tlsv1.2 \
        "$(jq -r '.binding.url' resources/native-runtime/source.json)" --output "$native_scratch/source.tar.gz"
fi
printf '%s  %s\n' "$(jq -r '.binding.sha256' resources/native-runtime/source.json)" "$native_scratch/source.tar.gz" \
    | sha256sum --check --status
tar -xzf "$native_scratch/source.tar.gz" -C "$native_scratch"
node tools/source-sbom.mjs "$native_scratch/kumwe-engine-php" "$output"
