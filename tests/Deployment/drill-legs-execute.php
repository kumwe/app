<?php

/**
 * Reproduce the drill leg that had never executed inside the deployed image.
 *
 * A leg can be declared, wired into a workflow, and still never run where it matters. When that happened,
 * the leg that had never executed in the image was the one that died there, and it died on a class the
 * production autoloader could not resolve rather than on anything it was testing.
 *
 * This case executes each declared drill entry point inside the artifact and requires it to fail on its own
 * diagnostic rather than on a loader error, which proves the entry point's own code actually ran. It then
 * walks the transitive class graph each harness reaches under the test namespace and requires every class in
 * it to resolve, so a harness cannot acquire a collaborator without acquiring a way to fail here first.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\CMS\Tests\Deployment\CaseReport;
use Kumwe\CMS\Tests\Deployment\DrillGraph;

require __DIR__ . '/../Support/deployment-drill-autoload.php';

$case = 'drill-legs-execute';
$root = dirname(__DIR__, 2);
$detail = [];

try {
    $manifestPath = __DIR__ . '/cases.json';
    $raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
    /** @var mixed $manifest */
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest) || !is_array($manifest['drill_entry_points'] ?? null)) {
        throw new RuntimeException('The case manifest declares no drill entry points.');
    }

    $executed = [];
    $resolved = [];
    foreach ($manifest['drill_entry_points'] as $entryPoint) {
        if (!is_array($entryPoint) || !is_string($entryPoint['path'] ?? null)) {
            throw new RuntimeException('A drill entry point is declared without a path.');
        }
        $path = $root . '/' . $entryPoint['path'];
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('%s is not in the artifact.', $entryPoint['path']));
        }

        $lines = [];
        $status = 0;
        exec(
            sprintf('cd %s && %s %s 2>&1', escapeshellarg($root), escapeshellarg(PHP_BINARY), escapeshellarg($path)),
            $lines,
            $status,
        );
        $output = implode("\n", $lines);
        if ($output === '' || str_contains($output, 'Fatal error') || str_contains($output, 'Class "')) {
            throw new RuntimeException(sprintf(
                '%s did not run inside the artifact; it produced: %s',
                $entryPoint['path'],
                $output === '' ? '(nothing at all)' : substr($output, 0, 400),
            ));
        }
        if ($status === 0) {
            throw new RuntimeException(sprintf(
                '%s exited zero with no arguments. The case reads its refusal as proof that its own code ran, '
                . 'so a silent success leaves nothing proved.',
                $entryPoint['path'],
            ));
        }
        $executed[] = $entryPoint['path'];

        foreach (DrillGraph::reachedBy($root, $path) as $class) {
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                throw new RuntimeException(sprintf(
                    '%s reaches %s, which does not resolve inside the artifact. A harness that gains a '
                    . 'collaborator without the loader gaining it is exactly the defect this case pins.',
                    $entryPoint['path'],
                    $class,
                ));
            }
            $resolved[$class] = true;
        }

        /** @var mixed $legs */
        $legs = $entryPoint['legs'] ?? [];
        if (!is_array($legs)) {
            throw new RuntimeException(sprintf('%s declares malformed legs.', $entryPoint['path']));
        }
        $harness = DrillGraph::harnessSource($root, $path);
        foreach ($legs as $leg) {
            if (!is_string($leg) || !str_contains($harness, sprintf("'%s'", $leg))) {
                throw new RuntimeException(sprintf(
                    '%s declares leg "%s", which its harness does not dispatch.',
                    $entryPoint['path'],
                    is_string($leg) ? $leg : '(malformed)',
                ));
            }
        }
    }

    $detail['entry_points'] = $executed;
    $detail['test_classes_resolved'] = count($resolved);
} catch (Throwable $failure) {
    CaseReport::fail($case, $failure->getMessage(), $detail);
}

CaseReport::pass($case, $detail);
