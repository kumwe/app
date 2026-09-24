<?php

/**
 * Verify the one Version 2 acceptance record and generate every summary that is read from it.
 *
 * `docs/roadmap/acceptance-record.json` holds one entry per requirement this increment accepts by merge — every
 * open finding, every work package not yet delivered, every Gate B criterion and the named requirements with no
 * finding of their own — linking each to its text reference, runtime owner, tests, CI jobs, workflow artifacts,
 * governing decision, outstanding note, state (delivered, pending integration or open) and track. Under ADR 0021
 * the maintainer's merge after every required check is green is the sole human acceptance, so the record must
 * already be truthful inside the pull request. `tools/Governance/AcceptanceRecord.php` proves it against the
 * findings ledger, the README package definitions, the changelog, the workflows and the working tree, and renders
 * `docs/roadmap/acceptance-summary.md` plus the generated blocks of `docs/roadmap/STATUS.md` — the phase board, the
 * open work by phase, the Gate B criteria and the ledger snapshot. Those views are derived, never hand-written.
 *
 * Usage:
 *   php tools/verify-acceptance-record.php --check     # verify the record and every derived view (composer qa)
 *   php tools/verify-acceptance-record.php --write     # verify the record and regenerate every derived view
 *
 * Git is not needed; the check reads only committed files, so it runs before `composer install`.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Tools\Governance\AcceptanceRecord;
use Kumwe\App\Tools\Governance\ToolOutput;

require_once __DIR__ . '/Governance/bootstrap.php';

$arguments = array_slice($argv, 1);
$write = $arguments === ['--write'];
if (!$write && $arguments !== ['--check'] && $arguments !== []) {
    fwrite(STDERR, "Usage: php tools/verify-acceptance-record.php [--check|--write]\n");
    exit(64);
}

$acceptance = new AcceptanceRecord(dirname(__DIR__));
$errors = $acceptance->verify();
if ($errors !== []) {
    exit(ToolOutput::fail('Acceptance record', $errors));
}

if ($write) {
    $changed = $acceptance->writeDerivedDocuments();
    exit(ToolOutput::succeed(sprintf(
        'Acceptance record verified; %s.',
        $changed === [] ? 'every derived view was already current' : 'regenerated ' . implode(' and ', $changed),
    )));
}

$stale = $acceptance->staleDocuments();
if ($stale !== []) {
    exit(ToolOutput::fail('Acceptance record', $stale));
}

$counts = $acceptance->counts();
$states = [];
foreach ($counts['states'] as $state => $count) {
    $states[] = sprintf('%d %s', $count, $state);
}
$tracks = [];
foreach ($counts['tracks'] as $track => $count) {
    $tracks[] = sprintf('%d %s', $count, $track);
}
exit(ToolOutput::succeed(sprintf(
    'Acceptance record verified: %d entries (%s; tracks: %s); the summary and STATUS.md blocks are current.',
    $counts['entries'],
    implode(', ', $states),
    implode(', ', $tracks),
)));
