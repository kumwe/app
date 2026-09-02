<?php

/**
 * Generate or verify the Kumwe capability index.
 *
 * The index is derived from `composer.lock`, the installed `vendor/kumwe/*` manifests, the legacy registry in
 * `docs/architecture/governance/legacy-packages.json` and the migration ledger. It is written as
 * `build/capability-index/v1.json`, its digest as `build/capability-index/v1.sha256`, and the committed
 * authority `docs/architecture/capability-index.md`, which embeds the digest. Every ambiguity, missing
 * manifest or duplicate owner is a non-zero exit with a message naming the file, the rule and the fix.
 *
 * Usage:
 *   php tools/generate-capability-index.php --write  [--root=PATH]   write build/ and the markdown
 *   php tools/generate-capability-index.php --check  [--root=PATH]   regenerate in memory; exit 1 on drift
 *   php tools/generate-capability-index.php --digest [--root=PATH]   print the current digest hex only
 *
 * `--root` replaces the repository root for fixture runs. Every failure line starts `Capability index: `.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Tools\Governance\CapabilityIndexBuilder;
use Kumwe\App\Tools\Governance\CapabilityIndexWriter;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\ToolOutput;

require_once __DIR__ . '/Governance/bootstrap.php';

$usage = 'Usage: php tools/generate-capability-index.php --write|--check|--digest [--root=PATH]';
$root = dirname(__DIR__);
$mode = null;
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--write', '--check', '--digest'], true)) {
        if ($mode !== null) {
            $errors[] = sprintf('%s conflicts with --%s; pass exactly one mode. %s', $argument, $mode, $usage);
            continue;
        }
        $mode = substr($argument, 2);
        continue;
    }
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, strlen('--root=')), '/');
        continue;
    }
    $errors[] = sprintf('Unknown argument %s. %s', $argument, $usage);
}
if ($mode === null) {
    $errors[] = 'No mode given. ' . $usage;
}
if ($errors !== []) {
    exit(ToolOutput::fail('Capability index', $errors));
}

try {
    $document = (new CapabilityIndexBuilder($root))->build();
    /** @var list<array<string, mixed>> $packages */
    $packages = $document['packages'];
    $count = count($packages);
    if ($mode === 'digest') {
        exit(ToolOutput::succeed(CapabilityIndexWriter::digest(CapabilityIndexWriter::json($document))));
    }
    if ($mode === 'write') {
        $written = CapabilityIndexWriter::write($root, $document);
        exit(ToolOutput::succeed(sprintf(
            'Capability index written (%d packages; digest sha256:%s).',
            $count,
            $written['digest'],
        )));
    }
    $check = CapabilityIndexWriter::check($root, $document);
    if ($check['problems'] !== []) {
        exit(ToolOutput::fail('Capability index', $check['problems']));
    }
    exit(ToolOutput::succeed(sprintf(
        'Capability index verified (%d packages; digest sha256:%s).',
        $count,
        $check['digest'],
    )));
} catch (GovernanceViolation $violation) {
    exit(ToolOutput::fail('Capability index', [$violation->getMessage()]));
}
