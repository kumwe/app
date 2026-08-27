#!/usr/bin/env bash
#
# Hold one built artifact to its declared byte ceiling from docs/quality/artifact-budget.json.
#
# The capacity contract's deterministic per-change budgets include an artifact and image size
# regression budget; this is its enforcement point, called from the workflow that builds each
# artifact. Usage:
#
#   tools/verify-artifact-budget.sh <artifact-key> <measured-bytes>
#
# The measured size always prints, over budget or not, so the workflow log is also the size record
# the budget's next revision is reasoned from.

set -euo pipefail

key="${1:?artifact key required}"
measured="${2:?measured byte size required}"
budget_file="$(dirname "${BASH_SOURCE[0]}")/../docs/quality/artifact-budget.json"

ceiling=$(php -r '
    $document = json_decode((string) file_get_contents($argv[1]), true);
    $ceiling = $document["artifacts"][$argv[2]]["maximum_bytes"] ?? null;
    if (!is_int($ceiling)) {
        fwrite(STDERR, "No declared ceiling for artifact \"{$argv[2]}\".\n");
        exit(1);
    }
    echo $ceiling;
' "$budget_file" "$key")

printf '%s: %s bytes measured, %s bytes budgeted.\n' "$key" "$measured" "$ceiling"
if [ "$measured" -gt "$ceiling" ]; then
    printf '%s exceeds its declared size budget; raising the ceiling is a reviewed change to docs/quality/artifact-budget.json.\n' "$key" >&2
    exit 1
fi
