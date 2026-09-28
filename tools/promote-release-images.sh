#!/usr/bin/env bash
# Copy verified manifests; refuse to overwrite an existing version or source-commit alias.
set -euo pipefail

version="${KUMWE_RELEASE_VERSION:?}"
commit="${KUMWE_RELEASE_COMMIT:?}"
for image in application web; do
    reference="$(jq -er --arg name "$image" '.images[$name].reference' dist/kumwe-release-manifest.json)"
    digest="$(jq -er --arg name "$image" '.images[$name].digest' dist/kumwe-release-manifest.json)"
    repository="${reference#ghcr.io/}"
    registry_token="$(curl --fail --silent --show-error --user "$GITHUB_ACTOR:$GH_TOKEN" \
        --get --data-urlencode service=ghcr.io --data-urlencode "scope=repository:$repository:pull" \
        https://ghcr.io/token | jq -er .token)"
    echo "::add-mask::$registry_token"
    accept='application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json'
    accept+=', application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'
    for tag in "$version" "$commit"; do
        headers="$(mktemp)"
        status="$(curl --silent --show-error --dump-header "$headers" --output /dev/null --write-out '%{http_code}' \
            --header "Authorization: Bearer $registry_token" \
            --header "Accept: $accept" \
            "https://ghcr.io/v2/$repository/manifests/$tag")"
        if [[ "$status" == 200 ]]; then
            existing="$(awk 'tolower($1) == "docker-content-digest:" {gsub("\r", "", $2); print $2}' "$headers")"
            test "$existing" = "$digest" || {
                echo "Refusing to replace immutable image alias $reference:$tag." >&2
                exit 1
            }
        elif [[ "$status" != 404 ]]; then
            echo "Cannot establish whether $reference:$tag exists (HTTP $status)." >&2
            exit 1
        fi
        rm "$headers"
    done
    unset registry_token
done

# Check both images before mutating either. A tag copy must preserve its subject digest exactly.
for image in application web; do
    reference="$(jq -er --arg name "$image" '.images[$name].reference' dist/kumwe-release-manifest.json)"
    digest="$(jq -er --arg name "$image" '.images[$name].digest' dist/kumwe-release-manifest.json)"
    tags=("$version" "$commit")
    if [[ "$version" =~ ^2\.[0-9]+\.[0-9]+$ ]]; then
        tags+=("${version%.*}" 2 latest)
    fi
    for tag in "${tags[@]}"; do
        docker buildx imagetools create --prefer-index=false --tag "$reference:$tag" "$reference@$digest"
        actual="$(docker buildx imagetools inspect "$reference:$tag" --format '{{json .Manifest.Digest}}' | jq -er .)"
        test "$actual" = "$digest"
    done
done
