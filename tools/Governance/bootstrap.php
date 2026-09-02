<?php

/**
 * Load the dependency-free governance classes in dependency order.
 *
 * The tools under `tools/` run before `composer install`, so there is no Composer autoloader to lean on. This
 * file is the one place that knows the load order; the CLI scripts and the tests `require_once` it and nothing
 * else. Loading it twice is harmless.
 *
 * Usage:
 *   require_once __DIR__ . '/Governance/bootstrap.php';            (from tools/)
 *   require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';   (from tests/Unit/Governance/)
 *
 * @since  2.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/GovernanceViolation.php';
require_once __DIR__ . '/ToolOutput.php';
require_once __DIR__ . '/StrictYaml.php';
require_once __DIR__ . '/SchemaValidator.php';
require_once __DIR__ . '/PhpDeclarationScanner.php';
require_once __DIR__ . '/LayerClassifier.php';
require_once __DIR__ . '/ComposerLock.php';
require_once __DIR__ . '/LegacyPackageRegistry.php';
require_once __DIR__ . '/PackageManifests.php';
require_once __DIR__ . '/GovernanceRecords.php';
require_once __DIR__ . '/CapabilityIndexBuilder.php';
require_once __DIR__ . '/CapabilityIndexWriter.php';
// The core-growth gate classes (CoreGrowthInventory, CoreGrowthGate) load after the index classes they build on.
foreach (['CoreGrowthInventory', 'CoreGrowthGate'] as $growthClass) {
    if (is_file(__DIR__ . '/' . $growthClass . '.php')) {
        require_once __DIR__ . '/' . $growthClass . '.php';
    }
}
