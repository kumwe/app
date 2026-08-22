<?php

/**
 * Reproduce the missing production autoloader path.
 *
 * The image installs with `--no-dev` and dumps an authoritative classmap, so Composer resolves `Kumwe\App\`
 * and nothing else: no class under `Kumwe\App\Tests\` is loadable there even when the drill directory is
 * mounted into the container. The drills used to compensate with a hand-maintained `require` list, and the
 * wave that gave a harness three new collaborators did not give that list three new lines. Every cheaper job
 * kept passing, because they all run under the development autoloader.
 *
 * This case asserts both halves in the order they matter, which is why it reaches its own reporter through a
 * plain `require` rather than through any autoloader: the production one alone must resolve the application
 * and not the drills, and the shared drill loader — and only it — must close the gap.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Tests\Deployment\CaseReport;

require __DIR__ . '/CaseReport.php';

$case = 'production-autoloader';
$root = dirname(__DIR__, 2);
$detail = [];

try {
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('The artifact carries no Composer autoloader.');
    }
    require $root . '/vendor/autoload.php';

    $installed = $root . '/vendor/composer/installed.json';
    $raw = is_file($installed) ? file_get_contents($installed) : false;
    /** @var mixed $decoded */
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !array_key_exists('dev', $decoded)) {
        throw new RuntimeException('The installed dependency set does not record whether it is a development one.');
    }
    if ($decoded['dev'] !== false) {
        throw new RuntimeException(
            'The artifact was installed with development dependencies. The defect this case reproduces is '
            . 'only visible without them, so a lane that keeps them proves nothing.',
        );
    }
    $detail['dev_dependencies'] = false;

    $classmap = $root . '/vendor/composer/autoload_classmap.php';
    if (!is_file($classmap)) {
        throw new RuntimeException('The artifact has no dumped classmap.');
    }
    /** @var mixed $map */
    $map = require $classmap;
    if (!is_array($map) || count($map) < 500) {
        throw new RuntimeException('The dumped classmap is too small to be the authoritative one.');
    }
    $detail['classmap_entries'] = count($map);

    if (!class_exists('Kumwe\\App\\Delivery\\Console\\ConsoleApplication')) {
        throw new RuntimeException('The production autoloader cannot resolve the application\'s own classes.');
    }

    $policySources = [
        ['Kumwe\\App\\Infrastructure\\Mcp\\KumweMcpHandlers', 'discover'],
        ['Kumwe\\App\\Infrastructure\\Mcp\\BusinessMcpHandlers', 'create'],
        ['Kumwe\\App\\BusinessSurface\\Application\\BusinessSurfaceService', 'customView'],
        ['Kumwe\\App\\Content\\Application\\ContentService', 'transition'],
    ];
    $sourceRoot = realpath($root . '/src');
    if ($sourceRoot === false) {
        throw new RuntimeException('The artifact carries no readable application source root.');
    }
    foreach ($policySources as [$class, $method]) {
        $source = (new ReflectionMethod($class, $method))->getFileName();
        $canonical = is_string($source) ? realpath($source) : false;
        if (
            $canonical === false
            || !str_starts_with($canonical, $sourceRoot . DIRECTORY_SEPARATOR)
            || file($canonical, FILE_IGNORE_NEW_LINES) === false
        ) {
            throw new RuntimeException(sprintf(
                'The deployed artifact cannot read the reflected MCP policy source for %s::%s.',
                $class,
                $method,
            ));
        }
    }
    $detail['mcp_policy_source_files'] = count($policySources);

    $drillClass = 'Kumwe\\App\\Tests\\Support\\NeutralBusinessFixture';
    if (class_exists($drillClass)) {
        throw new RuntimeException(
            'The production autoloader resolved a class under the test namespace. That namespace is absent '
            . 'from a --no-dev install by design, and a lane where it is present is not the deployed shape.',
        );
    }
    $detail['test_namespace_before_loader'] = 'unresolvable';

    require __DIR__ . '/../Support/deployment-drill-autoload.php';

    if (!class_exists($drillClass)) {
        throw new RuntimeException(sprintf(
            'The shared drill loader did not resolve %s. This is the failure that took production acceptance '
            . 'down on all three engines after a full deployment was already up.',
            $drillClass,
        ));
    }
    $detail['test_namespace_after_loader'] = 'resolvable';
} catch (Throwable $failure) {
    CaseReport::fail($case, $failure->getMessage(), $detail);
}

CaseReport::pass($case, $detail);
