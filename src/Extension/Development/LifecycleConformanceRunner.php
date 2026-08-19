<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Development;

use RuntimeException;
use Throwable;

/**
 * Executes static package checks and the complete platform-backed lifecycle gate sequence.
 *
 * @since  2.0.0
 */
final readonly class LifecycleConformanceRunner
{
    /**
     * Bind full lifecycle execution to the static package gate.
     *
     * @param  StaticConformanceRunner  $static  Code-free package conformance leg.
     *
     * @since  2.0.0
     */
    public function __construct(private StaticConformanceRunner $static)
    {
    }

    /**
     * Run every acceptance surface in dependency order and always invoke idempotent recovery.
     *
     * The first failed gate stops dependent mutations, preserving evidence and avoiding misleading
     * secondary failures. Recovery is still attempted and has its own explicit verdict.
     *
     * @param   LifecycleConformanceAdapter  $adapter         Real platform test-environment adapter.
     * @param   string                       $basePackage     Canonical absolute initial package path.
     * @param   string                       $upgradePackage  Canonical absolute upgrade package path.
     *
     * @return  LifecycleConformanceReport  Ordered gate and cleanup verdicts.
     *
     * @since   2.0.0
     */
    public function run(
        LifecycleConformanceAdapter $adapter,
        string $basePackage,
        string $upgradePackage,
    ): LifecycleConformanceReport {
        $checks = array_fill_keys([
            'static_base_package',
            'static_upgrade_package',
            'package_safety_and_signing',
            'schema_plan',
            'install',
            'definitions',
            'authorization_and_field_policies',
            'routes',
            'rest_and_openapi',
            'cli_and_mcp',
            'jobs_events_and_reports',
            'portal_and_administrator',
            'backup_and_restore',
            'upgrade',
            'disable',
            'reactivate',
            'database_matrix',
            'uninstall',
            'recovery',
        ], false);
        $gates = [
            'static_base_package' => function () use ($basePackage): void {
                $this->assertStatic($basePackage);
            },
            'static_upgrade_package' => function () use ($upgradePackage): void {
                $this->assertStatic($upgradePackage);
            },
            'package_safety_and_signing' => function () use ($adapter, $basePackage, $upgradePackage): void {
                $adapter->assertPackageSafetyAndSigning($basePackage, $upgradePackage);
            },
            'schema_plan' => function () use ($adapter, $basePackage, $upgradePackage): void {
                $adapter->assertSchemaPlan($basePackage, $upgradePackage);
            },
            'install' => function () use ($adapter, $basePackage): void {
                $adapter->install($basePackage);
            },
            'definitions' => function () use ($adapter): void {
                $adapter->assertDefinitions();
            },
            'authorization_and_field_policies' => function () use ($adapter): void {
                $adapter->assertAuthorizationAndFieldPolicies();
            },
            'routes' => function () use ($adapter): void {
                $adapter->assertRoutes();
            },
            'rest_and_openapi' => function () use ($adapter): void {
                $adapter->assertRestAndOpenApi();
            },
            'cli_and_mcp' => function () use ($adapter): void {
                $adapter->assertCliAndMcp();
            },
            'jobs_events_and_reports' => function () use ($adapter): void {
                $adapter->assertJobsEventsAndReports();
            },
            'portal_and_administrator' => function () use ($adapter): void {
                $adapter->assertPortalAndAdministrator();
            },
            'backup_and_restore' => function () use ($adapter): void {
                $adapter->assertBackupAndRestore();
            },
            'upgrade' => function () use ($adapter, $upgradePackage): void {
                $adapter->upgrade($upgradePackage);
            },
            'disable' => function () use ($adapter): void {
                $adapter->disable();
            },
            'reactivate' => function () use ($adapter): void {
                $adapter->reactivate();
            },
            'database_matrix' => function () use ($adapter, $basePackage, $upgradePackage): void {
                $adapter->assertDatabaseMatrix($basePackage, $upgradePackage);
            },
            'uninstall' => function () use ($adapter): void {
                $adapter->uninstall();
            },
        ];
        $violations = [];
        try {
            foreach ($gates as $name => $gate) {
                try {
                    $gate();
                    $checks[$name] = true;
                } catch (Throwable $failure) {
                    $violations[] = sprintf('%s: %s', $name, $failure->getMessage());
                    break;
                }
            }
        } finally {
            try {
                $adapter->recover();
                $checks['recovery'] = true;
            } catch (Throwable $failure) {
                $violations[] = 'recovery: ' . $failure->getMessage();
            }
        }

        return new LifecycleConformanceReport($checks, $violations);
    }

    /**
     * Require one package to pass the complete code-free static leg.
     *
     * @param   string  $archiveFile  Canonical absolute extension ZIP path.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the package has any static conformance violation.
     *
     * @since   2.0.0
     */
    private function assertStatic(string $archiveFile): void
    {
        $report = $this->static->run($archiveFile);
        if (!$report->conforms()) {
            throw new RuntimeException(implode('; ', $report->violations));
        }
    }
}
