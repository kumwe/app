#!/usr/bin/env bash

# Run the complete observability gate the way CI's observability workflow does:
#
#   1. the offline rule gate (composer observability:rules);
#   2. promtool check rules and promtool test rules over the committed scenarios;
#   3. amtool check-config over the inhibition configuration;
#   4. the alert drills (tools/alert-drill.php) against the database and Redis this environment configures.
#
# promtool and amtool are the pinned upstream releases below, verified by SHA-256 before use and cached in
# build/observability/bin. Put the release tarballs in build/observability/cache to run without network access.
# The drills mutate the database they run against; point DB_* at a disposable one. Arguments are passed to
# tools/alert-drill.php, for example --only=backup-stale or --require-all.

set -Eeuo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

# Pinned together with .github/workflows/observability.yml; change both in one commit.
prometheus_version=3.5.0
prometheus_sha256=e811827af26d822afb09a4f28314f61b618b12cff5369835a67f674d8b46f39a
alertmanager_version=0.28.1
alertmanager_sha256=5ac7ab5e4b8ee5ce4d8fb0988f9cb275efcc3f181b4b408179fafee121693311

bin_directory=build/observability/bin
cache_directory=build/observability/cache
install -d "$bin_directory" "$cache_directory"

# Fetch (or reuse) a release tarball, refuse it unless its digest matches the pin, and extract one binary.
install_tool() {
    local project="$1" version="$2" digest="$3" binary="$4"
    local archive="${project}-${version}.linux-amd64.tar.gz"
    local target="${bin_directory}/${binary}-${version}"

    if [[ -x "$target" ]]; then
        return 0
    fi
    if [[ ! -f "${cache_directory}/${archive}" ]]; then
        curl --fail --location --silent --show-error --retry 3 \
            --output "${cache_directory}/${archive}.partial" \
            "https://github.com/prometheus/${project}/releases/download/v${version}/${archive}"
        mv "${cache_directory}/${archive}.partial" "${cache_directory}/${archive}"
    fi
    if ! echo "${digest}  ${cache_directory}/${archive}" | sha256sum --check --status; then
        echo "Refusing ${archive}: its SHA-256 does not match the pinned ${digest}." >&2
        exit 1
    fi
    tar --extract --gzip --file "${cache_directory}/${archive}" --directory "$bin_directory" \
        --strip-components=1 "${project}-${version}.linux-amd64/${binary}"
    mv "${bin_directory}/${binary}" "$target"
}

install_tool prometheus "$prometheus_version" "$prometheus_sha256" promtool
install_tool alertmanager "$alertmanager_version" "$alertmanager_sha256" amtool
promtool="${project_root}/${bin_directory}/promtool-${prometheus_version}"
amtool="${project_root}/${bin_directory}/amtool-${alertmanager_version}"

php tools/verify-alert-rules.php
"$promtool" check rules deploy/observability/alerts.yaml
"$promtool" test rules deploy/observability/tests/alerts.test.yaml
"$amtool" check-config deploy/observability/alertmanager.yaml

php tools/alert-drill.php --promtool="$promtool" "$@"
