#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

# Every PHP file that sits inside an application layer, wherever that layer lives in the tree:
# the shared `src/Application` root and each module's own `src/<Module>/Application`.
application_layer_files() {
    find src -type f -name '*.php' -path '*/Application/*' -print
}

# The extension migration SPI is the one admitted outward import. A contributed migration is handed
# the connection it runs its own DDL on and the prefix helper that keeps it inside its own table
# namespace, which is a published part of the extension contract rather than a layering slip. The
# exception names the three files exactly, so a fourth one fails this gate instead of inheriting a
# directory-wide waiver.
application_layer_exceptions='^src/Extension/Application/Migration/(ExtensionMigration|ExtensionMigrationRunner|ExtensionTableNames)\.php:'

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

    outward_application_import_matches() {
        application_layer_files \
            | xargs -r rg -n '^use (Doctrine\\|Kumwe\\CMS\\Infrastructure\\)' 2>/dev/null \
            | rg -v "$application_layer_exceptions"
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

    outward_application_import_matches() {
        application_layer_files \
            | xargs -r grep -I -nE '^use (Doctrine\\|Kumwe\\CMS\\Infrastructure\\)' \
            | grep -Ev "$application_layer_exceptions"
    }
fi

# An adapter is named for the technology it binds to, so a technology-prefixed class inside an
# application layer is an adapter that has been filed in the wrong layer even when nothing in it
# imports the driver yet.
application_layer_adapter_matches() {
    find src -type f -path '*/Application/*' \
        \( -name 'Doctrine*.php' -o -name 'Redis*.php' -o -name 'Twig*.php' \
        -o -name 'Mezzio*.php' -o -name 'Laminas*.php' -o -name 'Monolog*.php' \) -print \
        | grep -E '.'
}

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

if outward_application_import_matches; then
    echo 'Policy violation: application code above owns its ports and cannot import Infrastructure' >&2
    echo 'or a database driver. Declare the contract in the application layer and implement it under' >&2
    echo 'src/Infrastructure. This is the regression V2-ARC-003 closed.' >&2
    exit 1
fi

if application_layer_adapter_matches; then
    echo 'Policy violation: the technology-prefixed classes above are adapters and belong under' >&2
    echo 'src/Infrastructure behind an application-owned port, not inside an application layer.' >&2
    echo 'This is the regression V2-ARC-003 closed.' >&2
    exit 1
fi

for legacy_root_file in index.php .htaccess robots.txt.dist web.config.txt; do
    if [[ -e "$legacy_root_file" ]]; then
        echo "Policy violation: legacy root web artifact '$legacy_root_file' must not be shipped." >&2
        exit 1
    fi
done

# Everything above is textual, and textual is all this gate used to be: four predicates that never
# resolved a dependency edge and still printed "verified". The semantic half below reads the layer graph
# in docs/architecture/layers.json, resolves every symbol each file under src/ actually references, and
# fails on any edge that points the wrong way and is not in the recorded baseline. The textual predicates
# stay because the source text is the contract in those four cases; they are no longer the whole check.
php "$project_root/tools/verify-dependency-graph.php"

echo 'Kumwe architecture policy verified: textual predicates and the semantic dependency graph.'
