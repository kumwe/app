<?php

declare(strict_types=1);

namespace Kumwe\ExtensionConformance;

use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Development\ConformanceReport;
use Kumwe\CMS\Extension\Development\LifecycleConformanceAdapter;
use Kumwe\CMS\Extension\Development\LifecycleConformanceReport;
use Kumwe\CMS\Extension\Development\LifecycleConformanceRunner;
use Kumwe\CMS\Extension\Development\PackageInspector;
use Kumwe\CMS\Extension\Development\StaticConformanceRunner;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;

/**
 * Public SDK facade for repeatable code-free extension package conformance.
 *
 * @since  2.0.0
 */
final readonly class ExtensionPackageConformance
{
    /**
     * Bind the facade to a configured core conformance runner.
     *
     * @param  StaticConformanceRunner  $runner  Configured core conformance service.
     *
     * @since  2.0.0
     */
    public function __construct(private StaticConformanceRunner $runner)
    {
    }

    /**
     * Create a facade using the exact archive reader and default limits used by Kumwe installation.
     *
     * @return  self  Ready-to-run conformance facade.
     *
     * @since   2.0.0
     */
    public static function withProductionDefaults(): self
    {
        return new self(new StaticConformanceRunner(new PackageInspector(
            new ZipArchiveReader(),
            new PackageSafetyPolicy(),
        )));
    }

    /**
     * Inspect one canonical absolute package path without executing its code.
     *
     * @param   string  $archiveFile  Canonical absolute extension ZIP path.
     *
     * @return  ConformanceReport  Stable package inventory and all static violations.
     *
     * @since   2.0.0
     */
    public function run(string $archiveFile): ConformanceReport
    {
        return $this->runner->run($archiveFile);
    }

    /**
     * Execute static checks and every platform-backed lifecycle acceptance gate.
     *
     * @param   LifecycleConformanceAdapter  $adapter         Real platform test-environment adapter.
     * @param   string                       $basePackage     Canonical absolute initial package path.
     * @param   string                       $upgradePackage  Canonical absolute upgrade package path.
     *
     * @return  LifecycleConformanceReport  Ordered gate and recovery verdicts.
     *
     * @since   2.0.0
     */
    public function runLifecycle(
        LifecycleConformanceAdapter $adapter,
        string $basePackage,
        string $upgradePackage,
    ): LifecycleConformanceReport {
        return (new LifecycleConformanceRunner($this->runner))->run($adapter, $basePackage, $upgradePackage);
    }
}
