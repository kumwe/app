# Manifest generation 3 compatibility package

A schema-3 component on contribution SPI 1. On top of the schema-2 administrator shell it declares the
portal surface and the safe field presentation schema 3 opened: a portal workspace, navigation entry and
template, plus a package-owned field type presented through the markup-free presenter contract.

`ExtensionGenerationLifecycleTest` builds this tree reproducibly, signs it with the fixture key published
in `docs/extension-contract/generations.json`, and drives it through install, activate, upgrade, disable,
reactivate and uninstall. Change it only when manifest generation 3 itself changes.
