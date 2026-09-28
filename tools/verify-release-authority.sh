#!/usr/bin/env bash
# Refuse publication before maintainer merge and successful CI on the exact tagged source.
set -euo pipefail

release_tag="${REQUESTED_RELEASE_TAG:?An existing release tag is required.}"
[[ "$release_tag" =~ ^v2\.[0-9]+\.[0-9]+(-[a-z]+\.[0-9]+)?$ ]]
test "${GITHUB_REF:?}" = "refs/tags/$release_tag"
git show-ref --tags --verify --quiet "refs/tags/$release_tag"
release_commit="$(git rev-parse "refs/tags/$release_tag^{commit}")"
test "$(git rev-parse HEAD)" = "$release_commit"
test "${GITHUB_SHA:?}" = "$release_commit"
git fetch origin master --no-tags
git merge-base --is-ancestor "$release_commit" FETCH_HEAD
node tools/release-artifacts.mjs identity "${release_tag#v}"

install -d dist
gh api --paginate --slurp "repos/$GITHUB_REPOSITORY/commits/$release_commit/pulls" \
    > dist/release-pulls.json
gh api --paginate --slurp \
    "repos/$GITHUB_REPOSITORY/actions/workflows/ci.yml/runs?head_sha=$release_commit&event=push&per_page=100" \
    > dist/release-ci.json
node tools/release-artifacts.mjs authority "$release_commit"
# Existing immutable publications are never replaced, including a failed rerun's release.
gh api --paginate --slurp "repos/$GITHUB_REPOSITORY/releases?per_page=100" \
    | jq -e --arg tag "$release_tag" 'all(.[][]; .tag_name != $tag)' >/dev/null
