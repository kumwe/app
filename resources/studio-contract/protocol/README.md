# `@kumwe/studio-protocol`

Status: pre-Gate-A foundation alpha. Its draft contracts may change and are not a supported protocol.

Serializable contracts shared by Studio hosts, renderers, extensions, and non-JavaScript clients.
The package deliberately contains no DOM or framework dependency. JSON Schemas are published under
the `schemas` export for authoritative validation outside TypeScript.

The initial contract covers blueprints, block definitions, design profiles, host capabilities,
commands and canonical command vectors, preview messages and identity vectors, host port errors, media asset
projections, persisted media references, media upload sessions, portable rich text, plugin
manifests, and entries. `MediaAsset` describes host-owned library state; the smaller
`MediaReference` is the portable value stored in an artifact. Consumers validate the document's
`contractVersion` and separately negotiate the SemVer wire `protocolVersion`; neither value may be
inferred from the other or from the `/studio/v1/` schema epoch URI.

The TypeScript projection covers all six canonical composition payload families. Manifest `block`
declarations map to `BlockDefinition` documents whose discriminator is `block-definition`; patterns,
design vocabulary, migrations, inspectors, and field adapters retain matching discriminators.
`InspectorContribution` and `FieldAdapterContribution` are declarative data types only—execution
remains a negotiated host capability.

The authoring message-catalog schema defines versioned locale bundles with closed message entries,
default text, and explicit named parameters. The canonical English shell catalog is published by
`@kumwe/studio` and replayed as a byte-identical testkit fixture.

Beyond document shapes, the package projects the host adapter surface (`HostAdapter` and its nine
typed ports sharing one request envelope), the stable host error taxonomy with the
`isHostPortError` guard, and the JavaScript `HostPortFailure` rejection wrapper with its
`isHostPortFailure` guard. A stale generation remains `invalid-request` and is distinguished by the
stable `studio.host/stale-session-generation` diagnostic, also exported as
`STUDIO_STALE_SESSION_GENERATION_DIAGNOSTIC_CODE`, allowing a composed session to invalidate the
complete handle without treating every invalid request as stale. The package also projects the
extension lifecycle state vocabulary and preview guards for the message, rendered inventory, and
canonical marker grammar. Preview draft.2 closes its message discriminator,
requires artifact/revision/digest plus session-unique render-attempt correlation, and applies the same
safe local-name and 240–10000 pixel viewport bounds in schema and runtime guards.
`schemas/manifest.json` records the schema epoch plus the file, canonical `$id`, and sha256 digest
of every published schema so generated SDKs can pin the exact corpus they were built against.
Canonical command vectors and the negative-fixture corpus ship through `@kumwe/studio-testkit`.
The protocol package also exports the single-exchange and ordered host-vector schemas. The sequence
schema fixes the idempotency scope/preimage and its deterministic clock/render control steps; the
portable corpora and runner-neutral digest manifest ship through the testkit.

`@kumwe/studio-protocol/studio-release.json` is a byte-identical copy of the canonical workspace
release record. It binds the exact fixed seven-package family to the wire protocol and testkit corpus
digest. The publication guard requires all seven versions to equal its `release` coordinate; the
current pre-version alpha record claims no conformance profiles.
