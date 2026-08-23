# Manifest 6 compatibility fixture

The signed compatibility package for manifest schema 6 and contribution SPI 4. It declares one
canonical Studio document of every contribution kind — block definition, pattern, field adapter,
inspector, design vocabulary and migration — plus the separate bounded host bindings, and travels
the complete install, activate, upgrade, disable, reactivate and uninstall lifecycle in
`ExtensionGenerationLifecycleTest`. The canonical bytes in `src/Definitions.php` and `kumwe.json`
are intentionally identical: the strict registrar reconciles them byte for byte.
