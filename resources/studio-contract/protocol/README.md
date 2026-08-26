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

The package exports deterministic schema-generated `Generated*` models for all 47 Version 2 protocol
schemas and every reusable `$defs` definition. `GeneratedProtocolModelMap` binds each schema filename to
its root model, `GENERATED_PROTOCOL_SCHEMA_FILES` exposes the complete checked registry, and
`GENERATED_TYPESCRIPT_MODEL_METADATA` records generator name/version, schema-manifest digest, schema epoch,
document contract revision, and supported wire-protocol range. Regenerate with
`npm run protocol:models:generate`; `npm run protocol:models:check` fails on byte drift.

Generated models are structural compile-time conveniences, not validators. Callers validate untrusted input
against the matching exported JSON Schema before using `roundTripGeneratedProtocolModel`; JSON Schema and
the normative contracts remain authoritative for patterns, numeric/string and maximum array bounds, exact
object closure, integer-ness, uniqueness, conditionals, dependencies, and `oneOf` exclusivity. The generator
models `minItems`, `prefixItems`, and open additional members; it fails closed if a canonical schema introduces
`patternProperties`. Object and array keywords receive a narrower structural projection only when the schema
declares the corresponding `type`; without it, scalar instances remain valid under JSON Schema and the generated
projection deliberately stays broad. The handwritten protocol interfaces remain the ergonomic runtime/port API
and may not widen the generated wire shape.

The test lane directly assigns 234 corpus literals to their exact filename-specific generated roots. The two
maximum-JSON-depth schema-profile vectors are an explicit TypeScript 6 `TS2321` compiler-depth boundary, not a
cast: the boundary test requires that diagnostic, and the runtime lane schema-validates and round-trips all 236
documents.

The typed composition API covers all six canonical contribution payload families. Manifest `block`
declarations map to `BlockDefinition` documents whose discriminator is `block-definition`; patterns, design
vocabulary, migrations, inspectors, and field adapters retain matching discriminators. `InspectorContribution`
and `FieldAdapterContribution` are declarative data types only—execution remains a negotiated host capability.

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
`schemas/manifest.json` records its own generator version, the schema epoch, document contract revision, and
the file, canonical `$id`, and sha256 digest of every published schema. The generated TypeScript metadata
records the digest of those exact manifest bytes, so consumers can identify the corpus it was built against.
Canonical command vectors and the negative-fixture corpus ship through `@kumwe/studio-testkit`.
The protocol package also exports the single-exchange and ordered host-vector schemas. The sequence
schema fixes the idempotency scope/preimage and its deterministic clock/render control steps; the
portable corpora and runner-neutral digest manifest ship through the testkit. The published
`authoring-web-vector` schema similarly carries semantic keyboard, pointer and explicit-control lanes
without selectors or component-library types; its target profile remains unclaimed until the full
browser and accessibility matrix is reproduced.

Canonical production values include chart, drawing, exact-decimal money, text-only tables, and the closed Studio
presentation intent. The latter carries semantic alignment, sizing, spacing, position, print,
scrolling, marker, motion, and responsive-visibility choices without carrying CSS or JavaScript.

`@kumwe/studio-protocol/studio-release.json` is a byte-identical copy of the canonical workspace
release record. It binds the exact fixed eight-package family to the wire protocol and testkit corpus
digest. The publication guard requires all eight versions to equal its `release` coordinate; the
current pre-version alpha record claims no conformance profiles.
