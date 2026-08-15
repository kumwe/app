# Manifest generation 4 compatibility package

A schema-4 component on contribution SPI 2. It declares one capability, one resource policy and one of
every durable integration surface SPI 2 opened: a versioned event contract, a synchronous domain
listener, a queue-backed consumer, a job, a queue, a schedule, a rebuildable projection, a report and an
outbound webhook adapter. Each executable half is a real implementation of the public contract.

`ExtensionGenerationLifecycleTest` builds this tree reproducibly, signs it with the fixture key published
in `docs/extension-contract/generations.json`, and drives it through install, activate, upgrade, disable,
reactivate and uninstall. Change it only when manifest generation 4 itself changes.
