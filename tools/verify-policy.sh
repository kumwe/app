#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

if command -v rg >/dev/null 2>&1; then
    product_name_matches() {
        rg -n -i '\btumwe\b' \
            --glob '!vendor/**' \
            --glob '!composer.lock' \
            --glob '!tools/verify-policy.sh' .
    }

    direct_dependency_matches() {
        rg -n '"(?:symfony|laravel)/[^\"]+"\s*:' composer.json
    }

    framework_import_matches() {
        rg -n '^use (Symfony|Illuminate)\\' src tests 2>/dev/null
    }

    static_locator_matches() {
        find . -path './vendor' -prune -o -type f -name '*.php' -print0 \
            | xargs -0 -r rg -n 'Kumwe\\CMS\\Factory|Factory::getContainer\(' 2>/dev/null
    }
else
    product_name_matches() {
        find . \
            \( -path './.git' -o -path './vendor' -o -path './build' \) -prune -o \
            -type f ! -path './composer.lock' ! -path './tools/verify-policy.sh' -print0 \
            | xargs -0 -r grep -I -nE -i '\btumwe\b'
    }

    direct_dependency_matches() {
        grep -nE '"(symfony|laravel)/[^\"]+"[[:space:]]*:' composer.json
    }

    framework_import_matches() {
        grep -R -I -nE --include='*.php' '^use (Symfony|Illuminate)\\' src tests
    }

    static_locator_matches() {
        find . -path './vendor' -prune -o -type f -name '*.php' -print0 \
            | xargs -0 -r grep -I -nE 'Kumwe\\CMS\\Factory|Factory::getContainer\('
    }
fi

if product_name_matches; then
    echo 'Policy violation: the product name is Kumwe, not the transcription error shown above.' >&2
    exit 1
fi

if direct_dependency_matches; then
    echo 'Policy violation: Symfony and Laravel cannot be direct Composer dependencies.' >&2
    exit 1
fi

if framework_import_matches; then
    echo 'Policy violation: first-party code cannot import Symfony or Laravel classes.' >&2
    exit 1
fi

if static_locator_matches; then
    echo 'Policy violation: static service location is forbidden in Kumwe 2.0.' >&2
    exit 1
fi

echo 'Kumwe architecture policy verified.'
