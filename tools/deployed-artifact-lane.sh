#!/usr/bin/env bash

# Build the deployed artifact and run the regression cases inside it.
#
# Four defects in the last programme were found only in production deployment acceptance, after a full
# deployment had already been stood up. Every cheaper job missed them for one reason: those jobs run under
# the development autoloader, with development dependencies present, in a writable tree. This lane builds
# what a release builds — the archived source, `composer install --no-dev --classmap-authoritative`, a
# read-only tree with a writable storage volume, the real console binary — and runs each of those four
# defects as a regression case inside it, early enough to fail before a deployment is stood up.
#
# The drill directory is copied in rather than archived, exactly as deployment acceptance bind-mounts it:
# `/tests` is export-ignored, so a released artifact never carries it and the lane must supply it the same
# way the container does.
#
# Usage:
#   bash tools/deployed-artifact-lane.sh [--keep]
#
# Environment:
#   KUMWE_ARTIFACT_BUILD_DIR   Where the artifact is built. Defaults to build/deployed-artifact.
#   KUMWE_ARTIFACT_MEMORY      Memory limit the cases run under. Defaults to the image's 256M.

set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

build_dir="${KUMWE_ARTIFACT_BUILD_DIR:-$project_root/build/deployed-artifact}"
memory_limit="${KUMWE_ARTIFACT_MEMORY:-256M}"
package="$build_dir/package"
keep=0

for argument in "$@"; do
    case "$argument" in
        --keep) keep=1 ;;
        *)
            echo "Unknown argument $argument. Usage: bash tools/deployed-artifact-lane.sh [--keep]" >&2
            exit 1
            ;;
    esac
done

if [[ -d "$package" ]]; then
    chmod -R u+w "$package"
fi
rm -rf "$build_dir"
mkdir -p "$build_dir/archive" "$package"

echo '== Building the release artifact from the archived source'
# `git archive` is used rather than `composer archive` because it is the exact released selection — the
# tracked tree minus every `export-ignore` path in .gitattributes — and because Composer's own filters are
# skipped in a linked worktree, where `.git` is a file, which would silently sweep `vendor/` into the
# artifact. A dirty tree is archived through `git stash create`, so the lane always tests what is in front
# of the author rather than the last commit.
archive_ref="$(git stash create)"
if [[ -z "$archive_ref" ]]; then
    archive_ref='HEAD'
fi
git archive --format=tar --worktree-attributes --output="$build_dir/archive/kumwe-artifact.tar" "$archive_ref"
tar --extract --file="$build_dir/archive/kumwe-artifact.tar" --directory="$package"

if [[ -d "$package/tests" ]]; then
    echo 'The released artifact carries the test tree, which a deployment never does.' >&2
    exit 1
fi

echo '== Installing the production dependency set with an authoritative classmap'
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 COMPOSER_ROOT_VERSION=2.0.0 composer install \
    --working-dir="$package" \
    --classmap-authoritative \
    --no-ansi \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

echo '== Mounting the drill directory the deployment mounts, read-only'
mkdir -p "$package/tests"
cp -R "$project_root/tests/Support" "$package/tests/Support"
cp -R "$project_root/tests/Deployment" "$package/tests/Deployment"
cp "$project_root/docs/quality/deployed-artifact-cases.json" "$package/tests/Deployment/cases.json"

echo '== Sealing the tree, leaving storage writable'
chmod -R a-w "$package"
if [[ -d "$package/storage" ]]; then
    chmod -R u+w "$package/storage"
fi

echo '== Running the declared regression cases inside the artifact'
set +e
php -d memory_limit="$memory_limit" "$package/tests/Deployment/run-cases.php" \
    --manifest="$package/tests/Deployment/cases.json" \
    --report="$build_dir/report.json" \
    --memory-limit="$memory_limit"
lane_status=$?
set -e

if [[ "$keep" -eq 0 ]]; then
    chmod -R u+w "$package"
    rm -rf "$build_dir/archive" "$package"
fi

if [[ "$lane_status" -ne 0 ]]; then
    echo 'The deployed-artifact lane failed. The report is at build/deployed-artifact/report.json.' >&2
    exit "$lane_status"
fi

echo "Deployed-artifact lane passed. Report: $build_dir/report.json"
