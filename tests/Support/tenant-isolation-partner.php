<?php

/**
 * One tenant's concurrent writer and reader for the tenant-isolation-under-concurrency qualification.
 *
 * Isolation that only holds while one site is busy is not isolation, so `TenantIsolationIntegrationTest`
 * spawns one of these processes per site and starts them together. Each boots the same kernel from the same
 * environment, acts only through a context whose grants are scoped to its own site, and alternates creating
 * a menu with listing every menu it may manage. The partners wait on a shared start file after booting, so
 * their writes and reads overlap rather than run one after another. Each records every foreign menu it was
 * shown and every one of its own menus that went missing, then writes the tally as JSON for the test.
 *
 * Usage: php tests/Support/tenant-isolation-partner.php <site> <run-marker> <iterations> <result-file>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$site = $argv[1] ?? null;
$marker = $argv[2] ?? null;
$iterations = (int) ($argv[3] ?? 0);
$result = $argv[4] ?? null;
if (!is_string($site) || !is_string($marker) || $iterations < 1 || !is_string($result)) {
    fwrite(STDERR, "Usage: tenant-isolation-partner.php <site> <run-marker> <iterations> <result-file>\n");
    exit(2);
}

$tally = ['created' => [], 'foreign' => [], 'missing' => [], 'error' => null];
try {
    // The test already migrated the database; booting the full container directly keeps three partners from
    // contending for the migration lock, and the barrier below makes their work genuinely overlap.
    $container = (new ContainerFactory())->create(Environment::fromGlobals());
    $navigation = $container->get(NavigationService::class);
    if (!$navigation instanceof NavigationService) {
        throw new RuntimeException('The navigation service is unavailable to the partner process.');
    }
    $context = TestKernelFactory::contextFromGrantRows($container, [[
        'capability' => 'navigation.manage',
        'scope_type' => 'site',
        'scope_identifier' => $site,
    ]], $site);
    $own = 'tenant_' . substr(hash('sha256', $site), 0, 8) . '_' . $marker . '_';
    file_put_contents($result . '.ready', 'ready');
    $deadline = microtime(true) + 60.0;
    while (!is_file(dirname($result) . '/go') && microtime(true) < $deadline) {
        usleep(5_000);
        clearstatcache();
    }
    for ($index = 0; $index < $iterations; $index++) {
        $tally['created'][] = $navigation->createMenu($context, $own . $index, 'Tenant menu ' . $index)->handle;
        $visible = [];
        foreach ($navigation->menus($context) as $menu) {
            $visible[$menu->handle] = true;
            if (str_contains($menu->handle, '_' . $marker . '_') && !str_starts_with($menu->handle, $own)) {
                $tally['foreign'][] = $menu->handle;
            }
        }
        foreach ($tally['created'] as $handle) {
            if (!isset($visible[$handle])) {
                $tally['missing'][] = $handle;
            }
        }
    }
} catch (Throwable $failure) {
    $tally['error'] = $failure::class . ': ' . $failure->getMessage();
}

file_put_contents($result, json_encode($tally, JSON_THROW_ON_ERROR));
exit($tally['error'] === null ? 0 : 1);
