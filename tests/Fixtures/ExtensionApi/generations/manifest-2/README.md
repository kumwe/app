# Manifest generation 2 compatibility package

A schema-2 component on contribution SPI 1. It declares one capability, one resource policy and the
administrator shell schema 2 opened — a workspace, a navigation entry and a view — and its provider
registers exactly those and nothing else.

`ExtensionGenerationLifecycleTest` builds this tree reproducibly, signs it with the fixture key published
in `docs/extension-contract/generations.json`, and drives it through install, activate, upgrade, disable,
reactivate and uninstall. Change it only when manifest generation 2 itself changes.
