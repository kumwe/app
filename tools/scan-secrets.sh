#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

# Pinned to the image the "Scan repository for secrets" step of .github/workflows/security.yml runs.
# The value of this script is that it gives the same answer as the build, so the two must move together:
# change the tag here and in that workflow in one commit, or a clean local run stops meaning anything.
gitleaks_image="${GITLEAKS_IMAGE:-ghcr.io/gitleaks/gitleaks:v8.28.0}"

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
    cat >&2 <<MESSAGE
Secret scan NOT run: Docker is unavailable.

composer security:secrets runs ${gitleaks_image} across this repository's entire
history, which is exactly what the security workflow does before a pull request can merge. There is
deliberately no fallback scanner: a different tool, or none, would report a different answer from the
one that decides the build, and a green message that proved nothing is worse than this one.

Start Docker and run it again. If this machine cannot run Docker at all, say so in the pull request so
a reviewer knows the scan is still owed.
MESSAGE
    exit 1
fi

install -d build/security
# A report from an earlier run must never be mistaken for this one's result, so it goes before the scan
# rather than after it: if the container cannot start, the absence of a report is what says so.
rm -f build/security/gitleaks.json

set +e
docker run --rm \
    --volume "$project_root:/repo:ro" \
    --volume "$project_root/build/security:/report" \
    --workdir /repo \
    "$gitleaks_image" \
    detect --source=/repo --no-banner --redact \
    --report-format json --report-path /report/gitleaks.json
scan_status=$?
set -e

if [[ -s build/security/gitleaks.json ]] && command -v php >/dev/null 2>&1; then
    php -r '
        $findings = json_decode((string) file_get_contents("build/security/gitleaks.json"), true);

        if (!is_array($findings)) {
            return;
        }

        foreach ($findings as $finding) {
            printf(
                "%s in %s line %d%s  fingerprint: %s%s",
                (string) $finding["RuleID"],
                (string) $finding["File"],
                (int) $finding["StartLine"],
                PHP_EOL,
                (string) $finding["Fingerprint"],
                PHP_EOL,
            );
        }
    '
elif [[ -s build/security/gitleaks.json ]]; then
    cat build/security/gitleaks.json
fi

if [[ "$scan_status" -ne 0 ]] && [[ ! -f build/security/gitleaks.json ]]; then
    cat >&2 <<MESSAGE

Secret scan NOT run: the scanner exited ${scan_status} without writing a report, so nothing was
checked. Treating this as a pass would be the mistake the check exists to prevent - resolve whatever
stopped the container, usually an image it could not pull, and run it again.
MESSAGE
elif [[ "$scan_status" -ne 0 ]]; then
    cat >&2 <<'MESSAGE'

The scan reads history, not the working tree, so a literal introduced by an earlier commit on this
branch still fails after a later commit changes it. Rewrite the commit that introduced it.

Fix a finding by removing the material, not the alarm. A fixture almost never needs a random-looking
literal: derive it from a readable stem or a fixed label so it is unmistakably synthetic and carries
no entropy. Only where a fixed vector is the point - a compatibility guarantee proved against a
literal input and output - add the fingerprint printed above to .gitleaksignore with a comment saying
why the value is synthetic and what would make the entry wrong. Never allowlist a path or a rule
wholesale; that removes the control instead of the finding.
MESSAGE
fi

exit "$scan_status"
