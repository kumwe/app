# Kumwe extension conformance SDK

This package exposes the same bounded, code-free package checks as Kumwe's `extension:conformance` command. It uses
the production ZIP central-directory reader, package safety policy, strict manifest parser, PHP syntax parser, and
manifest-reference checks. It never autoloads a provider, migration, listener, or other PHP class from the archive.

```php
use Kumwe\ExtensionConformance\ExtensionPackageConformance;

$report = ExtensionPackageConformance::withProductionDefaults()->run('/absolute/component.zip');
if (!$report->conforms()) {
    throw new RuntimeException(implode("\n", $report->violations));
}
```

CI should build a fresh package, run this check, compare its checksum with a second build, and sign only the exact
archive that passed. Consumers that already customize archive safety limits can inject a configured
`StaticConformanceRunner` into the constructor.

## Full lifecycle conformance

Extend `Kumwe\ExtensionConformance\ExtensionLifecycleTestCase` in a platform integration-test suite. Provide a
`LifecycleConformanceAdapter` backed by the real deployment plus canonical base and upgrade package paths. The test
runs static package checks, production package-safety and signature/trust verification, schema planning, install,
signed-definition/runtime reconciliation, authorization and field-policy checks, routes, REST/OpenAPI, CLI/MCP,
jobs/events/reports, portal/administrator, backup/restore, upgrade, disable/reactivate, the database matrix, uninstall,
and unconditional recovery. CI invokes the resulting PHPUnit test on every supported database service.

The adapter's signing gate must use the deployment's real trust-store admission and the exact base and upgrade
archive bytes. Its definition gate runs after installation and reconciles the signed manifest with the active provider,
schema plan, and trusted runtime generation. Browser/accessibility and controlled worker or scheduler restarts are
part of the UI and jobs/events/reports gates respectively; adapters must throw rather than mark an unavailable surface
as skipped.
