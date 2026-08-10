<?php

declare(strict_types=1);

namespace Kumwe\ExtensionConformance;

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit base class providing one assertion for installable extension fixtures.
 *
 * @since  2.0.0
 */
abstract class ExtensionConformanceTestCase extends TestCase
{
    /**
     * Assert that an absolute package path passes every static conformance check.
     *
     * @param   string  $archiveFile  Canonical absolute extension ZIP path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    final protected function assertExtensionPackageConforms(string $archiveFile): void
    {
        $report = ExtensionPackageConformance::withProductionDefaults()->run($archiveFile);

        self::assertTrue($report->conforms(), implode("\n", $report->violations));
    }
}
