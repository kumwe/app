<?php

/**
 * Run the real console binary from inside the read-only artifact and require its whole boot path to load.
 *
 * `bin/kumwe` is the entry point every drill, every migration and every operator action goes through, and it
 * is the one that reaches furthest into the tree: the bootstrap, the composition root, the typed
 * configuration and every service the requested command touches. Under the production autoloader that whole
 * path is resolved by an authoritative classmap, which is where the missing autoloader path defect lived.
 *
 * This lane is deliberately database-free, because it has to run early enough to fail before a deployment is
 * stood up and none of the four defects it reproduces needs a database. The case therefore asserts what a
 * database-free run can honestly prove and no more: the binary loads and executes its boot path, and if it
 * stops it stops on the infrastructure it was pointed at rather than on a class or file it could not find.
 * With a database configured the same case sees a clean exit instead; the drills that need one stay in
 * deployment acceptance.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Tests\Deployment\CaseReport;

require __DIR__ . '/../Support/deployment-drill-autoload.php';

$case = 'console-binary-boots';
$root = dirname(__DIR__, 2);
$detail = [];

try {
    $lines = [];
    $status = 0;
    exec(
        sprintf('cd %s && %s bin/kumwe 2>&1', escapeshellarg($root), escapeshellarg(PHP_BINARY)),
        $lines,
        $status,
    );
    $output = implode("\n", $lines);
    $detail['exit'] = $status;

    if (trim($output) === '') {
        throw new RuntimeException('The console binary produced no output at all inside the artifact.');
    }

    foreach (['Class "', 'not found', 'Failed opening required', 'Interface "', 'Trait "'] as $needle) {
        if (!str_contains($output, $needle)) {
            continue;
        }
        throw new RuntimeException(sprintf(
            'The console binary failed to resolve something inside the artifact, which is the defect this '
            . 'lane exists to catch: %s',
            substr($output, 0, 500),
        ));
    }

    if ($status === 0) {
        $detail['boot'] = 'completed';
        CaseReport::pass($case, $detail);
    }

    if (!str_contains($output, 'ContainerFactory')) {
        throw new RuntimeException(sprintf(
            'The console binary stopped before it reached the composition root, so nothing proves the '
            . 'artifact can load it: %s',
            substr($output, 0, 500),
        ));
    }

    // The binary loaded bootstrap, the composition root and the typed configuration, and then stopped on
    // the infrastructure it was pointed at. That is the whole class graph resolved, which is the property
    // this case owns; whether the database answers is deployment acceptance's question, not this lane's.
    $detail['boot'] = 'reached-composition-root';
} catch (Throwable $failure) {
    CaseReport::fail($case, $failure->getMessage(), $detail);
}

CaseReport::pass($case, $detail);
