# Manifest generation 5 compatibility package

A schema-5 component on contribution SPI 3. It declares one of every composition contribution kind the
generation froze at Gate A: a block with its bounded property schema, slots and renderer binding, a
pattern arranged from that block, a field control for a published property type, an inspector for the
block, a design vocabulary of tokens, a recipe and the size roles a theme remaps, and a composition
migration stepping the block's documents between its declared revisions. Everything is declarative and
inert: the Gate B surface is what will consume it, and this package must install unchanged when it does.

`ExtensionGenerationLifecycleTest` builds this tree reproducibly, signs it with the fixture key published
in `docs/extension-contract/generations.json`, and drives it through install, activate, upgrade, disable,
reactivate and uninstall. Change it only when manifest generation 5 itself changes.
