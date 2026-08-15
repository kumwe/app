# Manifest generation 1 compatibility package

A schema-1 plugin, kept as small as the generation allows. It registers one service of its own, resolves
it during boot, and contributes nothing to the shared contribution registries — which is the whole of
what manifest generation 1 promises.

`ExtensionGenerationLifecycleTest` builds this tree reproducibly, signs it with the fixture key published
in `docs/extension-contract/generations.json`, and drives it through install, activate, upgrade, disable,
reactivate and uninstall. Change it only when manifest generation 1 itself changes.
