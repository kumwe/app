<?php

/**
 * Prove the artifact is the shape a deployment runs: a read-only tree with one writable volume.
 *
 * Every other case in this lane is only meaningful if the tree really is read-only, so this one establishes
 * the precondition instead of assuming it. It judges the tree by its permission bits, which are a fact
 * whoever is looking, and separately records whether a write attempt was refused or bypassed — a process
 * running as the superuser walks through mode bits, and saying so is more useful than a check that quietly
 * passes for the wrong reason.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\CMS\Tests\Deployment\CaseReport;

require __DIR__ . '/../Support/deployment-drill-autoload.php';

$case = 'read-only-artifact';
$root = dirname(__DIR__, 2);
$detail = [];

try {
    foreach (['src', 'vendor', 'bin', 'config'] as $directory) {
        $path = $root . '/' . $directory;
        if (!is_dir($path)) {
            continue;
        }
        $mode = fileperms($path);
        if ($mode === false) {
            throw new RuntimeException(sprintf('The mode of %s could not be read.', $directory));
        }
        if (($mode & 0o222) !== 0) {
            throw new RuntimeException(
                sprintf('%s is writable in the artifact; a deployed tree carries no write bit.', $directory),
            );
        }
    }

    $superuser = function_exists('posix_geteuid') && posix_geteuid() === 0;
    $probe = $root . '/kumwe-write-probe';
    $wrote = @file_put_contents($probe, 'probe');
    if ($wrote !== false) {
        @unlink($probe);
        $detail['write_attempt'] = $superuser ? 'bypassed-by-root' : 'accepted';
        if (!$superuser) {
            throw new RuntimeException('The artifact accepted a write into its own tree.');
        }
    } else {
        $detail['write_attempt'] = 'refused';
    }

    $storage = $root . '/storage';
    if (is_dir($storage)) {
        $mode = fileperms($storage);
        if ($mode === false || ($mode & 0o200) === 0) {
            throw new RuntimeException(
                'storage/ is not writable. A deployment mounts it as the one writable volume, so a lane that '
                . 'seals it too is not testing the deployed shape.',
            );
        }
        $detail['storage'] = 'writable';
    }

    if (!is_file($root . '/bin/kumwe')) {
        throw new RuntimeException('The artifact ships no console binary at bin/kumwe.');
    }
    if (is_dir($root . '/tests/Unit')) {
        throw new RuntimeException(
            'The artifact carries the test suite. A released tree carries the drill directory the deployment '
            . 'mounts and nothing else, and a lane that ships the suite is not testing the deployed shape.',
        );
    }
} catch (Throwable $failure) {
    CaseReport::fail($case, $failure->getMessage(), $detail);
}

CaseReport::pass($case, $detail);
