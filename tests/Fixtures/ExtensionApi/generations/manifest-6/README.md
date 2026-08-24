# Manifest 6 compatibility fixture

The signed compatibility package for manifest schema 6 and contribution SPI 4. It declares one
canonical Studio document of every contribution kind — block definition, pattern, field adapter,
inspector, design vocabulary and migration — plus the separate bounded host bindings, and travels
the complete install, activate, upgrade, disable, reactivate and uninstall lifecycle in
`ExtensionGenerationLifecycleTest`. The canonical bytes in `src/Definitions.php` and `kumwe.json`
are intentionally identical: the strict registrar reconciles them byte for byte. Its block binding
also names an explicit owner-local `StudioPreviewBlockRenderer` service. The Gate-B host may execute
that service only after exact document, owner, version, revision, runtime-generation, and live-trust
reconciliation; the manifest value is never interpreted as PHP. Its canonical preview capability is
the separate `kumwe.contract-manifest-six/grid` renderer requirement advertised to Studio only while
that executable service is live.
