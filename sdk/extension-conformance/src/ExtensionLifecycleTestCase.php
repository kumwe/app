<?php

declare(strict_types=1);

namespace Kumwe\ExtensionConformance;

use Kumwe\App\Extension\Development\LifecycleConformanceAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Reusable PHPUnit contract that executes the complete Kumwe extension acceptance lifecycle.
 *
 * @since  2.0.0
 */
abstract class ExtensionLifecycleTestCase extends TestCase
{
    /**
     * Supply an adapter backed by the product's real test deployment.
     *
     * @return  LifecycleConformanceAdapter  Platform lifecycle adapter.
     *
     * @since   2.0.0
     */
    abstract protected function lifecycleAdapter(): LifecycleConformanceAdapter;

    /**
     * Supply the canonical absolute initial extension package path.
     *
     * @return  string  Initial extension package path.
     *
     * @since   2.0.0
     */
    abstract protected function basePackage(): string;

    /**
     * Supply the canonical absolute compatible-upgrade package path.
     *
     * @return  string  Upgrade extension package path.
     *
     * @since   2.0.0
     */
    abstract protected function upgradePackage(): string;

    /**
     * Run every static and stateful conformance gate in the defined order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    final public function testExtensionLifecycleConformance(): void
    {
        $report = ExtensionPackageConformance::withProductionDefaults()->runLifecycle(
            $this->lifecycleAdapter(),
            $this->basePackage(),
            $this->upgradePackage(),
        );

        self::assertTrue($report->conforms(), implode("\n", $report->violations));
    }
}
