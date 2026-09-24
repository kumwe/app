<?php

/**
 * Load the observability tooling classes and the Composer autoloader they read the metric catalogue through.
 *
 * The rule gate, the drills and the synthetic probe live under `tools/` rather than `src/` because they are
 * operator and CI tooling, not runtime code. They need the application's `MetricCatalog` and
 * `ObservabilityContract`, so unlike the governance tools they require `composer install`. Loading this file
 * twice is harmless.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
foreach (
    [
        'RuleViolation', 'Duration', 'RuleYaml', 'PromQl', 'MetricInventory', 'PromQlAnalysis', 'AlertRule',
        'AnnotationTemplate', 'InhibitionRules', 'RuleGate', 'PromtoolTests', 'Exposition', 'DashboardGate',
        'SyntheticProbe', 'DrillTimeline',
    ] as $class
) {
    if (is_file(__DIR__ . '/' . $class . '.php')) {
        require_once __DIR__ . '/' . $class . '.php';
    }
}
