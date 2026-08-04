#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

if rg -n -i '\btumwe\b' \
    --glob '!vendor/**' \
    --glob '!composer.lock' \
    --glob '!tools/verify-policy.sh' .; then
    echo 'Policy violation: the product name is Kumwe, not the transcription error shown above.' >&2
    exit 1
fi

if rg -n '"(?:symfony|laravel)/[^\"]+"\s*:' composer.json; then
    echo 'Policy violation: Symfony and Laravel cannot be direct Composer dependencies.' >&2
    exit 1
fi

if rg -n '^use (Symfony|Illuminate)\\' src tests 2>/dev/null; then
    echo 'Policy violation: first-party code cannot import Symfony or Laravel classes.' >&2
    exit 1
fi

if find . -path './vendor' -prune -o -type f -name '*.php' -print0 \
    | xargs -0 rg -n 'Kumwe\\CMS\\Factory|Factory::getContainer\(' 2>/dev/null; then
    echo 'Policy violation: static service location is forbidden in Kumwe 2.0.' >&2
    exit 1
fi

echo 'Kumwe architecture policy verified.'
